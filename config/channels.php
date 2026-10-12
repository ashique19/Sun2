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

        // Optional Gemini subject-box helper (disabled by default). Not used on
        // inbox conversation load — that path must stay local/heuristic-only.
        'image_subject_detect' => filter_var(
            env('CHANNEL_AI_DRAFT_IMAGE_SUBJECT_DETECT', false),
            FILTER_VALIDATE_BOOL,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound screenshot → product matching
    |--------------------------------------------------------------------------
    |
    | Scores are 0–1 visual similarity from ProductImageMatcher. A product is
    | attached automatically only when the top score clears auto_score AND leads
    | the runner-up by auto_margin; otherwise the top candidates are offered to
    | staff as one-click suggestions (when at least suggest_score).
    |
    */
    'image_match' => [
        'auto_score' => (float) env('CHANNEL_IMAGE_MATCH_AUTO_SCORE', 0.86),
        'auto_margin' => (float) env('CHANNEL_IMAGE_MATCH_AUTO_MARGIN', 0.05),
        'suggest_score' => (float) env('CHANNEL_IMAGE_MATCH_SUGGEST_SCORE', 0.75),

        // Match new inbound photos right after the webhook response is sent, so
        // results are ready before staff open the thread.
        'prewarm_on_receive' => filter_var(env('CHANNEL_IMAGE_MATCH_PREWARM', true), FILTER_VALIDATE_BOOL),
        'prewarm_max_per_request' => (int) env('CHANNEL_IMAGE_MATCH_PREWARM_MAX', 6),

        // Parallel match requests the inbox fires while a thread is open, the browser's
        // hard timeout per request, and how many times it retries a failed request.
        'client_concurrency' => (int) env('CHANNEL_IMAGE_MATCH_CONCURRENCY', 3),
        'client_timeout_ms' => (int) env('CHANNEL_IMAGE_MATCH_CLIENT_TIMEOUT_MS', 30000),
        'client_retries' => (int) env('CHANNEL_IMAGE_MATCH_CLIENT_RETRIES', 2),

        // A photo still "Matching product…" this long after its attempt started (or after
        // it was first shown, if the browser never sent the request) shows an error instead.
        'stall_seconds' => (int) env('CHANNEL_IMAGE_MATCH_STALL_SECONDS', 120),

        // How long a match request waits for another request (or the webhook prewarm) that is
        // already matching the same photo before answering "busy" (the browser then retries).
        'lock_wait_seconds' => (int) env('CHANNEL_IMAGE_MATCH_LOCK_WAIT_SECONDS', 10),

        // Time a match request may spend signing catalog images that have no visual
        // signature yet. Admin → Image Hashes builds them all in batches; 0 disables it.
        'lazy_index_budget_ms' => (int) env('CHANNEL_IMAGE_MATCH_LAZY_INDEX_MS', 1500),
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
        'graph_poll_seconds' => (int) env('CHANNEL_INBOX_GRAPH_POLL_SECONDS', 45),

        // Slower Graph backfill when Echo realtime is enabled.
        'graph_poll_seconds_realtime' => (int) env('CHANNEL_INBOX_GRAPH_POLL_SECONDS_REALTIME', 60),

        // Thin poll: newest updated threads, then messages only if newer than last sync.
        'graph_poll_conversation_limit' => (int) env('CHANNEL_INBOX_GRAPH_POLL_CONVERSATIONS', 15),

        'graph_poll_messages_per_thread' => (int) env('CHANNEL_INBOX_GRAPH_POLL_MESSAGES', 8),

        // Composer chips — label shown in UI, body inserted into the reply box.
        'quick_replies' => [
            ['label' => 'Salaam', 'body' => 'আসসালামু আলাইকুম'],
            ['label' => 'Address?', 'body' => 'আপনার সম্পূর্ণ ঠিকানাটা একটু দিবেন?'],
            ['label' => 'Phone?', 'body' => 'ডেলিভারির জন্য মোবাইল নাম্বারটা একটু কনফার্ম করবেন?'],
            ['label' => 'Thanks', 'body' => 'ধন্যবাদ, অর্ডার কনফার্ম করা হয়েছে।'],
        ],
    ],

];
