<?php

namespace App\Models;

use Database\Factories\SteadfastWebhookLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SteadfastWebhookLog extends Model
{
    /** @use HasFactory<SteadfastWebhookLogFactory> */
    use HasFactory;

    public const OUTCOME_PROCESSED = 'processed';

    public const OUTCOME_UNMATCHED = 'unmatched';

    public const OUTCOME_EMPTY = 'empty';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'http_status' => 'integer',
            'has_signature' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function prettyPayload(): string
    {
        $data = is_array($this->payload) ? $this->payload : null;

        if ($data === null || $data === []) {
            $raw = trim((string) $this->raw_body);
            if ($raw === '') {
                return '(empty)';
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return $raw;
            }

            $data = $decoded;
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : (string) $this->raw_body;
    }
}
