<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI draft orders from channel conversations
    |--------------------------------------------------------------------------
    |
    | Only recent inbound messages are parsed. Weak / empty parses do not
    | create Draft by AI rows (historic Graph sync must not invent orders).
    |
    */

    'ai_draft' => [
        // Include inbound messages with sent_at within this many hours.
        'lookback_hours' => (int) env('CHANNEL_AI_DRAFT_LOOKBACK_HOURS', 48),

        // Cap how many recent inbound messages are fed to the parser.
        'max_inbound_messages' => (int) env('CHANNEL_AI_DRAFT_MAX_MESSAGES', 15),

        // Minimum parser confidence (0–1) required to create/update a draft.
        // Scoring: name/phone/address 0.25 each; matched product_id 0.25.
        'min_confidence' => (float) env('CHANNEL_AI_DRAFT_MIN_CONFIDENCE', 0.5),

        // Require a valid Bangladesh mobile before creating a draft.
        'require_phone' => filter_var(env('CHANNEL_AI_DRAFT_REQUIRE_PHONE', true), FILTER_VALIDATE_BOOL),
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
    ],

];
