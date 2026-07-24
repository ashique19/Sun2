<?php

namespace App\Services\Channels;

use App\Models\ChannelConversation;
use App\Models\ChannelMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ChannelConversationService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreate(string $channel, string $externalUserId, array $attributes = []): ChannelConversation
    {
        $conversation = ChannelConversation::query()->firstOrCreate(
            [
                'channel' => $channel,
                'external_user_id' => $externalUserId,
            ],
            array_merge([
                'external_account_id' => $attributes['external_account_id'] ?? null,
                'customer_name' => $attributes['customer_name'] ?? null,
                'meta' => $attributes['meta'] ?? null,
            ], array_filter([
                'customer_phone' => $attributes['customer_phone'] ?? null,
            ], fn ($v) => $v !== null)),
        );

        $dirty = false;
        foreach (['external_account_id', 'customer_name', 'customer_phone'] as $field) {
            if (! empty($attributes[$field]) && $conversation->{$field} !== $attributes[$field]) {
                $conversation->{$field} = $attributes[$field];
                $dirty = true;
            }
        }
        if ($dirty) {
            $conversation->save();
        }

        return $conversation;
    }

    /**
     * @param  array{
     *     external_message_id?: ?string,
     *     direction: string,
     *     body?: ?string,
     *     media_url?: ?string,
     *     media_mime?: ?string,
     *     raw_payload?: ?array,
     *     sent_at?: ?\DateTimeInterface|string
     * }  $payload
     */
    public function storeMessage(ChannelConversation $conversation, array $payload): ChannelMessage
    {
        return DB::transaction(function () use ($conversation, $payload) {
            $externalId = $payload['external_message_id'] ?? null;

            if (is_string($externalId) && $externalId !== '') {
                $existing = ChannelMessage::query()
                    ->where('channel_conversation_id', $conversation->id)
                    ->where('external_message_id', $externalId)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $sentAt = isset($payload['sent_at'])
                ? Carbon::parse($payload['sent_at'])
                : now();

            $message = ChannelMessage::query()->create([
                'channel_conversation_id' => $conversation->id,
                'external_message_id' => $externalId,
                'direction' => $payload['direction'],
                'body' => $payload['body'] ?? null,
                'media_url' => $payload['media_url'] ?? null,
                'media_mime' => $payload['media_mime'] ?? null,
                'raw_payload' => $payload['raw_payload'] ?? null,
                'sent_at' => $sentAt,
            ]);

            if ($payload['direction'] === ChannelMessage::DIRECTION_INBOUND) {
                $conversation->forceFill(['last_inbound_at' => $sentAt])->save();
            } else {
                $conversation->forceFill(['last_outbound_at' => $sentAt])->save();
            }

            return $message;
        });
    }
}
