<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostPublication;
use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaGraphSocialPublisher
{
    /**
     * Publish (or re-publish) a social post to configured Meta channels.
     *
     * Creates new rows in `social_post_publications` for each channel attempt.
     *
     * @return array{facebook: ?SocialPostPublication, instagram: ?SocialPostPublication}
     */
    public function publish(SocialPost $post): array
    {
        $post->loadMissing(['products', 'publications']);

        return [
            'facebook' => $this->publishFacebook($post),
            'instagram' => $this->publishInstagram($post),
        ];
    }

    private function fbEnabled(): bool
    {
        $token = (string) config('facebook.messenger.page_access_token', '');
        $pageId = (string) config('facebook.messenger.page_id', '');

        return $token !== '' && $pageId !== '';
    }

    private function igEnabled(): bool
    {
        // We currently infer the IG account id from the FB Page via Graph.
        return $this->fbEnabled();
    }

    private function graphVersion(): string
    {
        return (string) config('facebook.graph_version', 'v25.0');
    }

    private function pageToken(): string
    {
        return (string) config('facebook.messenger.page_access_token', '');
    }

    private function pageId(): string
    {
        return (string) config('facebook.messenger.page_id', '');
    }

    private function imageUrlsForPost(SocialPost $post): array
    {
        $urls = [];

        foreach ($post->products as $product) {
            $pivot = $product->pivot;

            $path = match ((string) $post->image_source) {
                SocialPost::IMAGE_SOURCE_PRICED => $pivot?->priced_snapshot_path,
                default => $pivot?->thumb_snapshot_path,
            };

            $url = StorefrontAssets::mediumUrl($path) ?? StorefrontAssets::url($path);

            if ($url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function publishFacebook(SocialPost $post): ?SocialPostPublication
    {
        if (! $this->fbEnabled()) {
            return null;
        }

        $token = $this->pageToken();
        $pageId = $this->pageId();
        $version = $this->graphVersion();
        $url = 'https://graph.facebook.com/'.$version.'/'.$pageId.'/feed';

        $publication = new SocialPostPublication([
            'channel' => SocialPostPublication::CHANNEL_FACEBOOK,
            'status' => SocialPostPublication::STATUS_PENDING,
        ]);
        $publication->social_post_id = $post->id;

        try {
            $message = trim($post->body);
            if ($message === '') {
                throw new RuntimeException('Post body is empty.');
            }

            $imageUrls = $this->imageUrlsForPost($post);
            if ($imageUrls === []) {
                throw new RuntimeException('No images available for publishing.');
            }

            // v1 pragmatic approach:
            // - collage => publish a single composed image (collage_path) if present, else thumbnail_path
            // - album   => publish the first image with caption (multi-photo can be added later)
            $layout = (string) $post->layout;
            $pictureUrl = null;
            if ($layout === SocialPost::LAYOUT_COLLAGE) {
                $collagePath = $post->collage_path ?: $post->thumbnail_path;
                $pictureUrl = StorefrontAssets::mediumUrl($collagePath) ?? StorefrontAssets::url($collagePath);
            }

            if (! $pictureUrl) {
                $pictureUrl = $imageUrls[0];
            }

            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'message' => $message,
                    'picture' => $pictureUrl,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Facebook publish failed: '.$response->body());
            }

            $externalId = $response->json('id');
            $permalink = $response->json('permalink_url');

            $publication->fill([
                'external_id' => is_string($externalId) ? $externalId : null,
                'external_url' => is_string($permalink) ? $permalink : ($externalId ? 'https://www.facebook.com/'.$externalId : null),
                'status' => SocialPostPublication::STATUS_SUCCESS,
                'published_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $publication->fill([
                'status' => SocialPostPublication::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }

        $publication->save();

        return $publication;
    }

    private function publishInstagram(SocialPost $post): ?SocialPostPublication
    {
        if (! $this->igEnabled()) {
            return null;
        }

        $token = $this->pageToken();
        $pageId = $this->pageId();
        $version = $this->graphVersion();

        $publication = new SocialPostPublication([
            'channel' => SocialPostPublication::CHANNEL_INSTAGRAM,
            'status' => SocialPostPublication::STATUS_PENDING,
        ]);
        $publication->social_post_id = $post->id;

        try {
            // Infer IG Business account id from the linked FB Page.
            $igAccountId = $this->instagramAccountId($token, $pageId, $version);
            if ($igAccountId === '') {
                throw new RuntimeException('Unable to resolve Instagram business account id from FB Page.');
            }

            $layout = (string) $post->layout;
            $imageUrl = null;
            if ($layout === SocialPost::LAYOUT_COLLAGE) {
                $collagePath = $post->collage_path ?: $post->thumbnail_path;
                $imageUrl = StorefrontAssets::mediumUrl($collagePath) ?? StorefrontAssets::url($collagePath);
            }

            if (! $imageUrl) {
                $imageUrl = $this->imageUrlsForPost($post)[0] ?? null;
            }

            if (! $imageUrl) {
                throw new RuntimeException('No images available for Instagram publishing.');
            }

            $caption = trim($post->body);
            if ($caption === '') {
                throw new RuntimeException('Post body is empty.');
            }

            // v1: publish single media post (carousel support can be added later).
            $create = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post('https://graph.facebook.com/'.$version.'/'.$igAccountId.'/media', [
                    'image_url' => $imageUrl,
                    'caption' => $caption,
                ]);

            if (! $create->successful()) {
                throw new RuntimeException('Instagram create media failed: '.$create->body());
            }

            $creationId = $create->json('id') ?? $create->json('creation_id');
            if (! is_string($creationId) || $creationId === '') {
                throw new RuntimeException('Instagram create media response missing id.');
            }

            $publish = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post('https://graph.facebook.com/'.$version.'/'.$igAccountId.'/media_publish', [
                    'creation_id' => $creationId,
                ]);

            if (! $publish->successful()) {
                throw new RuntimeException('Instagram publish failed: '.$publish->body());
            }

            $externalId = $publish->json('id') ?? $creationId;
            $permalink = $publish->json('permalink_url');

            $publication->fill([
                'external_id' => is_string($externalId) ? $externalId : null,
                'external_url' => is_string($permalink) ? $permalink : null,
                'status' => SocialPostPublication::STATUS_SUCCESS,
                'published_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $publication->fill([
                'status' => SocialPostPublication::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }

        $publication->save();

        return $publication;
    }

    private function instagramAccountId(string $token, string $pageId, string $version): string
    {
        $response = Http::timeout(15)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->get('https://graph.facebook.com/'.$version.'/'.$pageId, [
                'fields' => 'instagram_business_account{id}',
            ]);

        if (! $response->successful()) {
            return '';
        }

        $id = data_get($response->json(), 'instagram_business_account.id');

        return is_string($id) ? $id : '';
    }
}

