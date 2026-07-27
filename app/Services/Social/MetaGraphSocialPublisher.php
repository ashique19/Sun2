<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use App\Models\SocialPostPublication;
use App\Services\Facebook\FacebookPageTokenService;
use App\Support\StorefrontAssets;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaGraphSocialPublisher
{
    public function __construct(
        private FacebookPageTokenService $tokens,
    ) {}

    /**
     * Publish (or re-publish) a social post to the given Meta channels.
     *
     * Creates new rows in `social_post_publications` for each channel attempt.
     * When a selected channel is not configured, a failed row is still stored with a reason.
     *
     * @param  list<string>  $channels
     * @return array<string, SocialPostPublication>
     */
    public function publish(SocialPost $post, array $channels = [
        SocialPostPublication::CHANNEL_FACEBOOK,
        SocialPostPublication::CHANNEL_INSTAGRAM,
    ]): array
    {
        $post->loadMissing(['products', 'publications']);

        $results = [];

        foreach ($this->normalizeChannels($channels) as $channel) {
            $results[$channel] = $this->publishChannel($post, $channel);
        }

        return $results;
    }

    /**
     * Publish a single channel and persist a publication row.
     */
    public function publishChannel(SocialPost $post, string $channel): SocialPostPublication
    {
        $post->loadMissing(['products', 'publications']);

        return match ($channel) {
            SocialPostPublication::CHANNEL_FACEBOOK => $this->publishFacebook($post, requireAttempt: true),
            SocialPostPublication::CHANNEL_INSTAGRAM => $this->publishInstagram($post, requireAttempt: true),
            default => throw new RuntimeException('Unsupported social channel: '.$channel),
        };
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function normalizeChannels(array $channels): array
    {
        $allowed = [
            SocialPostPublication::CHANNEL_FACEBOOK,
            SocialPostPublication::CHANNEL_INSTAGRAM,
        ];

        $normalized = [];
        foreach ($channels as $channel) {
            $channel = strtolower(trim((string) $channel));
            if (in_array($channel, $allowed, true) && ! in_array($channel, $normalized, true)) {
                $normalized[] = $channel;
            }
        }

        return $normalized;
    }

    private function fbEnabled(): bool
    {
        return $this->pageToken() !== '' && $this->pageId() !== '';
    }

    private function igEnabled(): bool
    {
        // We currently infer the IG account id from the FB Page via Graph.
        return $this->fbEnabled();
    }

    private function graphVersion(): string
    {
        return $this->tokens->graphVersion();
    }

    private function pageToken(): string
    {
        return $this->tokens->token();
    }

    private function pageId(): string
    {
        return $this->tokens->pageId();
    }

    private function noteTokenFailure(string $message): void
    {
        if (str_contains(strtolower($message), 'access token')
            || str_contains(strtolower($message), 'oauth')
            || str_contains(strtolower($message), 'session has expired')) {
            $this->tokens->invalidateStatusCache();
        }
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

    private function publishFacebook(SocialPost $post, bool $requireAttempt = false): ?SocialPostPublication
    {
        if (! $this->fbEnabled()) {
            if (! $requireAttempt) {
                return null;
            }

            return $this->failedPublication(
                $post,
                SocialPostPublication::CHANNEL_FACEBOOK,
                'Facebook Page access token or Page ID is not configured.',
            );
        }

        $token = $this->pageToken();
        $pageId = $this->pageId();
        $version = $this->graphVersion();

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

            $imageUrls = $this->primaryFacebookImageUrls($post);
            if ($imageUrls === []) {
                throw new RuntimeException('No images available for publishing.');
            }

            // Facebook /feed rejects `picture` without `link`. Use Photos API instead.
            if (count($imageUrls) === 1) {
                $result = $this->publishFacebookSinglePhoto($pageId, $version, $token, $imageUrls[0], $message);
            } else {
                $result = $this->publishFacebookAlbum($pageId, $version, $token, $imageUrls, $message);
            }

            $externalId = $result['id'];
            $permalink = $result['permalink'] ?? null;

            $publication->fill([
                'external_id' => $externalId,
                'external_url' => $permalink ?: ($externalId ? 'https://www.facebook.com/'.$externalId : null),
                'status' => SocialPostPublication::STATUS_SUCCESS,
                'published_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->noteTokenFailure($e->getMessage());
            $publication->fill([
                'status' => SocialPostPublication::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }

        $publication->save();

        return $publication;
    }

    /**
     * @return list<string>
     */
    private function primaryFacebookImageUrls(SocialPost $post): array
    {
        $layout = (string) $post->layout;

        if ($layout === SocialPost::LAYOUT_COLLAGE) {
            $collagePath = $post->collage_path ?: $post->thumbnail_path;
            $url = StorefrontAssets::mediumUrl($collagePath) ?? StorefrontAssets::url($collagePath);

            return $url ? [$url] : $this->imageUrlsForPost($post);
        }

        return $this->imageUrlsForPost($post);
    }

    /**
     * @return array{id: string, permalink: ?string}
     */
    private function publishFacebookSinglePhoto(
        string $pageId,
        string $version,
        string $token,
        string $imageUrl,
        string $caption,
    ): array {
        $response = Http::timeout(30)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post('https://graph.facebook.com/'.$version.'/'.$pageId.'/photos', [
                'url' => $imageUrl,
                'caption' => $caption,
                'published' => true,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Facebook publish failed: '.$response->body());
        }

        $postId = $response->json('post_id') ?? $response->json('id');
        $postId = is_string($postId) || is_numeric($postId) ? (string) $postId : '';

        if ($postId === '') {
            throw new RuntimeException('Facebook photo publish response missing id.');
        }

        return [
            'id' => $postId,
            'permalink' => $this->facebookPermalink($pageId, $version, $token, $postId),
        ];
    }

    /**
     * @param  list<string>  $imageUrls
     * @return array{id: string, permalink: ?string}
     */
    private function publishFacebookAlbum(
        string $pageId,
        string $version,
        string $token,
        array $imageUrls,
        string $message,
    ): array {
        $attachedMedia = [];

        foreach ($imageUrls as $imageUrl) {
            $upload = Http::timeout(30)
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->post('https://graph.facebook.com/'.$version.'/'.$pageId.'/photos', [
                    'url' => $imageUrl,
                    'published' => false,
                ]);

            if (! $upload->successful()) {
                throw new RuntimeException('Facebook album photo upload failed: '.$upload->body());
            }

            $mediaId = $upload->json('id');
            if (! is_string($mediaId) && ! is_numeric($mediaId)) {
                throw new RuntimeException('Facebook album photo upload missing id.');
            }

            $attachedMedia[] = ['media_fbid' => (string) $mediaId];
        }

        $response = Http::timeout(30)
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post('https://graph.facebook.com/'.$version.'/'.$pageId.'/feed', [
                'message' => $message,
                'attached_media' => $attachedMedia,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Facebook album publish failed: '.$response->body());
        }

        $externalId = $response->json('id');
        $externalId = is_string($externalId) || is_numeric($externalId) ? (string) $externalId : '';

        if ($externalId === '') {
            throw new RuntimeException('Facebook album publish response missing id.');
        }

        return [
            'id' => $externalId,
            'permalink' => $this->facebookPermalink($pageId, $version, $token, $externalId)
                ?? (is_string($response->json('permalink_url')) ? $response->json('permalink_url') : null),
        ];
    }

    private function facebookPermalink(string $pageId, string $version, string $token, string $objectId): ?string
    {
        try {
            $response = Http::timeout(12)
                ->withToken($token)
                ->acceptJson()
                ->get('https://graph.facebook.com/'.$version.'/'.$objectId, [
                    'fields' => 'permalink_url',
                ]);

            $permalink = $response->json('permalink_url');

            return is_string($permalink) && $permalink !== '' ? $permalink : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function publishInstagram(SocialPost $post, bool $requireAttempt = false): ?SocialPostPublication
    {
        if (! $this->igEnabled()) {
            if (! $requireAttempt) {
                return null;
            }

            return $this->failedPublication(
                $post,
                SocialPostPublication::CHANNEL_INSTAGRAM,
                'Instagram requires a configured Facebook Page access token and Page ID.',
            );
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
            $this->noteTokenFailure($e->getMessage());
            $publication->fill([
                'status' => SocialPostPublication::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }

        $publication->save();

        return $publication;
    }

    private function failedPublication(SocialPost $post, string $channel, string $error): SocialPostPublication
    {
        $publication = new SocialPostPublication([
            'channel' => $channel,
            'status' => SocialPostPublication::STATUS_FAILED,
            'error' => $error,
        ]);
        $publication->social_post_id = $post->id;
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

