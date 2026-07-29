<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI draft orders from channel conversations
    |--------------------------------------------------------------------------
    |
    | Used when ChannelOrderDraftService parses a conversation on demand.
    | Webhooks and Graph sync do not auto-create Draft by AI rows — staff
    | start orders from Inbox. Weak / empty parses still create nothing.
    |
    */

    'ai_draft' => [
        // Include inbound messages with sent_at within this many hours.
        'lookback_hours' => (int) env('CHANNEL_AI_DRAFT_LOOKBACK_HOURS', 48),

        // Cap how many recent inbound messages are fed to the parser.
        'max_inbound_messages' => (int) env('CHANNEL_AI_DRAFT_MAX_MESSAGES', 15),

        // Minimum parser confidence (0–1) required to create/update a draft.
        // Local scoring: phone 0.30; name 0.10–0.20; address 0.10–0.25; product 0.15–0.25.
        'min_confidence' => (float) env('CHANNEL_AI_DRAFT_MIN_CONFIDENCE', 0.5),

        // Require a valid Bangladesh mobile before creating a draft.
        'require_phone' => filter_var(env('CHANNEL_AI_DRAFT_REQUIRE_PHONE', true), FILTER_VALIDATE_BOOL),

        // Auto-attach catalog product when inbound image dHash match is at least this %.
        'image_match_auto_percent' => (float) env(
            'CHANNEL_AI_DRAFT_IMAGE_AUTO_PERCENT',
            90,
        ),

        // Keep weaker image matches in ai_parse_meta for staff review.
        'image_match_min_percent' => (float) env(
            'CHANNEL_AI_DRAFT_IMAGE_MIN_PERCENT',
            80,
        ),

        // Skip tiny attachments (stickers / emoji) below this many bytes.
        'image_min_bytes' => (int) env('CHANNEL_AI_DRAFT_IMAGE_MIN_BYTES', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox retention
    |--------------------------------------------------------------------------
    |
    | Conversations with no activity older than retention_days are deleted
    | (messages cascade; linked AI drafts are discarded). Confirmed orders kept.
    |
    */
    'inbox' => [
        'retention_days' => (int) env('CHANNEL_INBOX_RETENTION_DAYS', 7),

        // Run purge when staff open /admin/inbox (throttled to once/minute).
        'purge_on_inbox_load' => filter_var(env('CHANNEL_INBOX_PURGE_ON_LOAD', true), FILTER_VALIDATE_BOOL),

        // Optional daily schedule: php artisan schedule:run
        'purge_schedule_enabled' => filter_var(env('CHANNEL_INBOX_PURGE_SCHEDULE_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Open threads initially show only messages with sent_at within this window.
        'thread_lookback_hours' => (int) env('CHANNEL_INBOX_THREAD_LOOKBACK_HOURS', 24),

        // When true (BROADCAST_CONNECTION=reverb|pusher by default), Echo pushes
        // inbox updates and Graph poll runs on a slower fallback interval.
        'realtime_enabled' => filter_var(
            env(
                'CHANNEL_INBOX_REALTIME',
                in_array(env('BROADCAST_CONNECTION', 'log'), ['reverb', 'pusher'], true) ? 'true' : 'false',
            ),
            FILTER_VALIDATE_BOOL,
        ),

        // Graph Conversations API poll while /admin/inbox is open.
        'graph_poll_seconds' => (int) env('CHANNEL_INBOX_GRAPH_POLL_SECONDS', 10),

        // Slower Graph backfill when Echo realtime is enabled.
        'graph_poll_seconds_realtime' => (int) env('CHANNEL_INBOX_GRAPH_POLL_SECONDS_REALTIME', 60),

        // Composer chips — label shown in UI, body inserted into the reply box.
        'quick_replies' => [
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
            ['label' => 'Address?', 'body' => 'আপনার সম্পূর্ণ ঠিকানাটা একটু দিবেন?'],
            ['label' => 'Phone?', 'body' => 'ডেলিভারির জন্য মোবাইল নাম্বারটা একটু কনফার্ম করবেন?'],
            ['label' => 'Thanks', 'body' => 'ধন্যবাদ, অর্ডার কনফার্ম করা হয়েছে।'],
        ],
    ],

];
