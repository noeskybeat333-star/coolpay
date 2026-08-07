<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{

    public const SOURCE_STOREFRONT = 'storefront';

    public const SOURCE_MARKETPLACE = 'marketplace';

    public const STATUS_NEW = 'new';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_REFUNDED = 'refunded';

    protected $fillable = [
        'user_id',
        'source',
        'marketplace_account_id',
        'external_id',
        'external_number',
        'external_status',
        'fulfillment_type',
        'external_created_at',
        'synced_at',
        'metadata',
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
        'stock_restored_at',
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

        public function marketplaceAccount(): BelongsTo
    {
        return $this->belongsTo(
            MarketplaceAccount::class
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }


    /**
     * @return array<string, string>
     */
    public static function sourceOptions(): array
    {
        return [
            self::SOURCE_STOREFRONT => 'Сайт CoolPay',
            self::SOURCE_MARKETPLACE => 'Маркетплейс',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Новый',
            self::STATUS_CONFIRMED => 'Подтверждён',
            self::STATUS_PROCESSING => 'В обработке',
            self::STATUS_SHIPPED => 'Отправлен',
            self::STATUS_COMPLETED => 'Завершён',
            self::STATUS_CANCELLED => 'Отменён',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paymentStatusOptions(): array
    {
        return [
            self::PAYMENT_PENDING => 'Ожидает оплаты',
            self::PAYMENT_PAID => 'Оплачен',
            self::PAYMENT_FAILED => 'Ошибка оплаты',
            self::PAYMENT_REFUNDED => 'Возврат',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function deliveryMethodOptions(): array
    {
        return [
            'delivery' => 'Доставка',
            'pickup' => 'Самовывоз',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paymentMethodOptions(): array
    {
        return [
            'bank_transfer' => 'Перевод',
            'cash' => 'Наличными',
        ];
    }

    protected function casts(): array
    {
        return [
            'external_created_at' => 'datetime',
            'synced_at' => 'datetime',
            'metadata' => 'array',
            'subtotal' => 'decimal:2',
            'delivery_price' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
            'stock_restored_at' => 'datetime',
        ];
    }
}
