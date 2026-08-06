<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_NEW = 'new';

    public const PAYMENT_PENDING = 'pending';

    protected $fillable = [
        'user_id',
        'number',
        'status',
        'payment_status',
        'payment_method',
        'delivery_method',
        'customer_name',
        'customer_phone',
        'customer_email',
        'delivery_address',
        'customer_comment',
        'subtotal',
        'delivery_price',
        'total',
        'currency',
        'placed_at',
    ];

    protected static function booted(): void
    {
        static::created(function (Order $order): void {
            if ($order->number !== null) {
                return;
            }

            $order->forceFill([
                'number' => sprintf(
                    'CP-%s-%06d',
                    $order->created_at->format('Ymd'),
                    $order->getKey(),
                ),
            ])->saveQuietly();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'delivery_price' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
        ];
    }
}