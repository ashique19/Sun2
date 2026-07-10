<?php

namespace App\Models;

use App\Services\Couriers\CourierApiRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Courier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'charge' => 'decimal:2',
            'osd_charge' => 'decimal:2',
            'customer_charge' => 'decimal:2',
            'customer_osd_charge' => 'decimal:2',
            'cod_percentage' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function balanceEntries(): HasMany
    {
        return $this->hasMany(CourierBalanceEntry::class)->latest('id');
    }

    public function supportsApiDispatch(): bool
    {
        if (! $this->slug) {
            return false;
        }

        return app(CourierApiRegistry::class)->isConfigured($this->slug);
    }
}
