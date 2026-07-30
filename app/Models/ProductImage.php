<?php

namespace App\Models;

use App\Support\ImageFileMeta;
use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array{width: int|null, height: int|null, bytes: int|null, label: string}|null
     */
    public function fileMeta(): ?array
    {
        return ImageFileMeta::forPublicPath($this->path);
    }
}
