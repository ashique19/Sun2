<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Material extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'stock_quantity' => 'decimal:3',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_materials')
            ->withPivot(['id', 'quantity', 'is_primary'])
            ->withTimestamps();
    }
}
