<?php

namespace App\Services;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketplaceOrderUpserter
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *     order: Order,
     *     created: bool
     * }
     */
    public function upsert(
        MarketplaceAccount $account,
        array $data,
    ): array {
        $externalId = trim(
            (string) ($data['external_id'] ?? '')
        );

        if ($externalId === '') {
            throw new InvalidArgumentException(
                'Внешний ID заказа не указан.'
            );
        }

        $items = $this->normalizeItems(
            $data['items'] ?? []
        );

        if ($items === []) {
            throw new InvalidArgumentException(
                "Заказ {$externalId} не содержит товаров."
            );
        }

        return DB::transaction(
            function () use (
                $account,
                $data,
                $externalId,
                $items,
            ): array {
                $order = Order::query()
                    ->where(
                        'marketplace_account_id',
                        $account->getKey(),
                    )
                    ->where(
                        'external_id',
                        $externalId,
                    )
                    ->lockForUpdate()
                    ->first();

                $created = $order === null;

                if ($order === null) {
                    $order = new Order();
                }

                $subtotal = round(
                    (float) collect($items)->sum('total'),
                    2,
                );

                $deliveryPrice = round(
                    (float) (
                        $data['delivery_price'] ?? 0
                    ),
                    2,
                );

                $order->fill([
                    'source' =>
                        Order::SOURCE_MARKETPLACE,
                    'marketplace_account_id' =>
                        $account->getKey(),
                    'external_id' => $externalId,
                    'external_number' =>
                        $data['external_number']
                        ?? $externalId,
                    'external_status' =>
                        $data['external_status']
                        ?? null,
                    'fulfillment_type' =>
                        $data['fulfillment_type']
                        ?? null,
                    // Импортёр, не сумевший определить статус, не передаёт
                    // его вовсе — и тогда уже известный статус сохраняется.
                    // Иначе сбой метода статусов откатывал бы выкупленные
                    // заказы обратно в «новые» при каждом импорте.
                    'status' =>
                        $data['status']
                        ?? $order->status
                        ?? Order::STATUS_NEW,
                    'payment_status' =>
                        $data['payment_status']
                        ?? $order->payment_status
                        ?? Order::PAYMENT_PAID,
                    'payment_method' =>
                        $data['payment_method']
                        ?? null,
                    'delivery_method' =>
                        $data['delivery_method']
                        ?? 'marketplace',
                    'customer_name' =>
                        $data['customer_name']
                        ?? null,
                    'customer_phone' =>
                        $data['customer_phone']
                        ?? null,
                    'customer_email' =>
                        $data['customer_email']
                        ?? null,
                    'delivery_address' =>
                        $data['delivery_address']
                        ?? null,
                    'customer_comment' =>
                        $data['customer_comment']
                        ?? null,
                    'subtotal' => $subtotal,
                    'delivery_price' => $deliveryPrice,
                    'total' => round(
                        $subtotal + $deliveryPrice,
                        2,
                    ),
                    'currency' =>
                        $data['currency'] ?? 'RUB',
                    'placed_at' =>
                        $data['external_created_at']
                        ?? now(),
                    'external_created_at' =>
                        $data['external_created_at']
                        ?? null,
                    'synced_at' => now(),
                    'metadata' =>
                        $data['metadata'] ?? null,
                ]);

                $order->save();

                $order->items()->delete();

                foreach ($items as $item) {
                    $order->items()->create($item);
                }

                return [
                    'order' => $order->fresh('items'),
                    'created' => $created,
                ];
            },
            3,
        );
    }

    /**
     * @param mixed $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(
        mixed $items,
    ): array {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->filter(
                fn (mixed $item): bool =>
                    is_array($item)
            )
            ->groupBy(function (array $item): string {
                return implode('|', [
                    (string) (
                        $item['product_id'] ?? ''
                    ),
                    (string) (
                        $item['external_product_id']
                        ?? ''
                    ),
                    (string) (
                        $item['product_sku'] ?? ''
                    ),
                    number_format(
                        (float) (
                            $item['unit_price'] ?? 0
                        ),
                        2,
                        '.',
                        '',
                    ),
                ]);
            })
            ->map(function ($group): array {
                $first = $group->first();

                $quantity = (int) $group->sum(
                    fn (array $item): int =>
                        max(
                            1,
                            (int) (
                                $item['quantity'] ?? 1
                            ),
                        )
                );

                $unitPrice = round(
                    (float) (
                        $first['unit_price'] ?? 0
                    ),
                    2,
                );

                $snapshot = is_array(
                    $first['product_snapshot'] ?? null
                )
                    ? $first['product_snapshot']
                    : [];

                $externalLineIds = $group
                    ->pluck('external_line_id')
                    ->filter()
                    ->map(
                        fn (mixed $id): string =>
                            (string) $id
                    )
                    ->values()
                    ->all();

                if ($externalLineIds !== []) {
                    $snapshot['external_line_ids'] =
                        $externalLineIds;
                }

                return [
                    'product_id' =>
                        $first['product_id'] ?? null,
                    'product_name' =>
                        $first['product_name']
                        ?? 'Товар маркетплейса',
                    'product_sku' =>
                        $first['product_sku'] ?? null,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'total' => round(
                        $unitPrice * $quantity,
                        2,
                    ),
                    'product_snapshot' =>
                        $snapshot ?: null,
                ];
            })
            ->values()
            ->all();
    }
}
