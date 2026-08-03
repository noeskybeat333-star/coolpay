<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceListing extends Model
{
    protected $fillable = [
        'product_id',
        'marketplace_account_id',
        'external_id',
        'offer_id',
        'seller_sku',
        'barcode',
        'name',
        'brand',
        'category',
        'description',
        'price',
        'base_price',
        'discount_percent',
        'stock_quantity',
        'status',
        'characteristics',
        'images',
        'raw_data',
        'synced_at',
    ];

    public function getImageUrlsAttribute(): array
    {
        return collect($this->images ?? [])
            ->map(function (mixed $image): ?string {
                if (is_string($image)) {
                    return filled($image) ? $image : null;
                }

                if (! is_array($image)) {
                    return null;
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

                return null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getPrimaryImageUrlAttribute(): ?string
    {
        return $this->image_urls[0] ?? null;
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'discount_percent' => 'integer',
            'stock_quantity' => 'integer',
            'characteristics' => 'array',
            'images' => 'array',
            'raw_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            MarketplaceAccount::class,
            'marketplace_account_id'
        );
    }
}
