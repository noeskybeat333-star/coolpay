<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChannelPrice extends Model
{
    protected $table = 'channel_prices';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'product_id' => 'integer',
            'marketplace_account_id' => 'integer',
            'images' => 'array',
            'base_price' => 'decimal:2',
            'discount_percent' => 'integer',
            'sale_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getPrimaryImageUrlAttribute(): ?string
    {
        if (filled($this->local_image_path)) {
            $path = (string) $this->local_image_path;

            if (
                Str::startsWith(
                    $path,
                    [
                        'http://',
                        'https://',
                    ],
                )
            ) {
                return $path;
            }

            return Storage::disk('public')
                ->url($path);
        }

        foreach ($this->images ?? [] as $image) {
            if (is_string($image)) {
                if (filled($image)) {
                    return $image;
                }

                continue;
            }

            if (! is_array($image)) {
                continue;
            }

            foreach ([
                'big',
                'c516x688',
                'square',
                'c246x328',
                'tm',
            ] as $key) {
                if (filled($image[$key] ?? null)) {
                    return (string) $image[$key];
                }
            }
        }

        return null;
    }
}
