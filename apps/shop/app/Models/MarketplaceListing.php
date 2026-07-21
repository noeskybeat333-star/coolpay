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
        'old_price',
        'stock_quantity',
        'status',
        'characteristics',
        'images',
        'raw_data',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
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
