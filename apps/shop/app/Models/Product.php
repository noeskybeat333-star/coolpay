<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'sale_price',
        'stock_quantity',
        'is_active',
        'is_featured',
    ];

   public function marketplaceListings(): HasMany
{
    return $this->hasMany(MarketplaceListing::class);
} 

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }
}
