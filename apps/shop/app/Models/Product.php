<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sku',
        'brand',
        'category',
        'description',
        'purchase_price',
        'base_price',
        'discount_percent',
        'sale_price',
        'stock_quantity',
        'is_active',
        'is_featured',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            $product->productImages()
                ->get()
                ->each(
                    fn (ProductImage $image) => $image->delete()
                );
        });
    }

    public function marketplaceListings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function primaryMarketplaceListing(): HasOne
    {
        return $this->hasOne(MarketplaceListing::class)
            ->latestOfMany('synced_at');
    }

    public function productImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)
            ->orderBy('sort_order');
    }

    public function primaryProductImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)
            ->oldestOfMany('sort_order');
    }

    public function getPrimaryImageUrlAttribute(): ?string
    {
        return $this->primaryProductImage?->url
            ?? $this->primaryMarketplaceListing?->primary_image_url;
    }

    public function getImageUrlsAttribute(): array
    {
        $localImages = $this->productImages
            ->map(
                fn (ProductImage $image): ?string => $image->url
            )
            ->filter()
            ->values()
            ->toBase();

        $marketplaceImages = collect(
            $this->primaryMarketplaceListing?->image_urls ?? []
        );

        return $localImages
            ->concat($marketplaceImages)
            ->unique()
            ->values()
            ->all();
    }

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'base_price' => 'decimal:2',
            'discount_percent' => 'integer',
            'sale_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }
}
