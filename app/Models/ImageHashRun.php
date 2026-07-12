<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageHashRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'force' => 'boolean',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    public function progressPercent(): int
    {
        if ($this->progress_total <= 0) {
            return $this->status === 'completed' ? 100 : 0;
        }

        return (int) min(100, round(($this->progress_current / $this->progress_total) * 100));
    }
}
