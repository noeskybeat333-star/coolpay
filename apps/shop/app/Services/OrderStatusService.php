<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderStatusService
{
    public function changeStatus(
        Order $order,
        string $status,
    ): Order {
        if (
            ! array_key_exists(
                $status,
                Order::statusOptions(),
            )
        ) {
            throw ValidationException::withMessages([
                'status' => 'Выбран неизвестный статус заказа.',
            ]);
        }

        return DB::transaction(
            function () use ($order, $status): Order {
                $lockedOrder = Order::query()
                    ->with('items')
                    ->lockForUpdate()
                    ->findOrFail($order->getKey());

                if (
                    $lockedOrder->status
                        === Order::STATUS_CANCELLED
                    && $status
                        !== Order::STATUS_CANCELLED
                ) {
                    throw ValidationException::withMessages([
                        'status' =>
                            'Отменённый заказ нельзя вернуть в работу автоматически.',
                    ]);
                }

                $updates = [
                    'status' => $status,
                ];

                if (
                    $status === Order::STATUS_CANCELLED
                    && $lockedOrder->stock_restored_at === null
                ) {
                    foreach ($lockedOrder->items as $item) {
                        if (
                            $item->product_id === null
                            || $item->quantity < 1
                        ) {
                            continue;
                        }

                        Product::query()
                            ->whereKey($item->product_id)
                            ->increment(
                                'stock_quantity',
                                $item->quantity,
                            );
                    }

                    $updates['stock_restored_at'] = now();
                }

                $lockedOrder->forceFill($updates)->save();

                return $lockedOrder->refresh();
            },
            3,
        );
    }

    public function changePaymentStatus(
        Order $order,
        string $paymentStatus,
    ): Order {
        if (
            ! array_key_exists(
                $paymentStatus,
                Order::paymentStatusOptions(),
            )
        ) {
            throw ValidationException::withMessages([
                'payment_status' =>
                    'Выбран неизвестный статус оплаты.',
            ]);
        }

        return DB::transaction(
            function () use (
                $order,
                $paymentStatus,
            ): Order {
                $lockedOrder = Order::query()
                    ->lockForUpdate()
                    ->findOrFail($order->getKey());

                $lockedOrder->forceFill([
                    'payment_status' => $paymentStatus,
                ])->save();

                return $lockedOrder->refresh();
            },
            3,
        );
    }
}
