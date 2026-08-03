<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'path',
        'alt_text',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::deleted(function (ProductImage $image): void {
            if (filled($image->path)) {
                Storage::disk('public')->delete($image->path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): ?string
    {
        if (blank($this->path)) {
            return null;
        }

        return Storage::disk('public')->url($this->path);
    }
}
