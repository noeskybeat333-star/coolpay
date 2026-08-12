<?php

namespace App\Services;

use App\Integrations\Results\OrderImportResult;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class WildberriesOrderImporter
{
    private const API_URL =
        'https://marketplace-api.wildberries.ru';

    private const PAGE_LIMIT = 100;

    private const MAX_PAGES = 200;

    private const STATUS_CHUNK = 100;

    /**
     * Глубина импорта по умолчанию, если период не задан явно.
     */
    private const DEFAULT_DEPTH_DAYS = 30;

    /**
     * Предельная глубина одного запроса.
     *
     * Проверено на живом кабинете: при dateFrom за 360 дней API отвечает
     * HTTP 200 с пустым списком, а за 90 дней возвращает данные. Поэтому
     * период жёстко ограничивается — иначе импорт молча ничего не найдёт.
     */
    private const MAX_DEPTH_DAYS = 90;

    /**
     * Модели работы с Wildberries. Ключ пишется в orders.fulfillment_type.
     *
     * Путь статусов выводится как <path>/status. Чтобы включить DBS или DBW,
     * достаточно уточнить путь и поднять enabled: разбор записи, группировка
     * по orderUid и сопоставление товара у всех моделей общие.
     */
    private const SOURCES = [
        'fbs' => [
            'label' => 'Маркетплейс (FBS)',
            'path' => '/api/v3/orders',
            'enabled' => true,
        ],
        'dbs' => [
            'label' => 'Витрина (DBS)',
            'path' => '/api/v3/dbs/orders',
            'enabled' => false,
        ],
        'dbw' => [
            'label' => 'Курьером (DBW)',
            'path' => '/api/v3/dbw/orders',
            'enabled' => false,
        ],
    ];

    /**
     * @var array<string, MarketplaceListing|null>
     */
    private array $listingCache = [];

    public function __construct(
        private readonly MarketplaceOrderUpserter $upserter,
    ) {
    }

    public function import(
        MarketplaceAccount $account,
        ?CarbonImmutable $since = null,
    ): OrderImportResult {
        $token = data_get(
            $account->credentials,
            'api_token',
        );

        if (blank($token)) {
            return new OrderImportResult(
                failed: 1,
                errors: [
                    'API-токен Wildberries не указан.',
                ],
            );
        }

        $this->listingCache = [];

        $since = $this->clampSince($since);

        $received = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach (self::SOURCES as $type => $source) {
            if (! $source['enabled']) {
                continue;
            }

            try {
                $rows = $this->fetchOrders(
                    $token,
                    $source['path'],
                    $since,
                );
            } catch (ConnectionException) {
                $failed++;

                $errors[] = $source['label']
                    .': не удалось соединиться с API Wildberries.';

                continue;
            } catch (Throwable $exception) {
                $failed++;

                $errors[] = $source['label']
                    .': '.$exception->getMessage();

                continue;
            }

            if ($rows === []) {
                continue;
            }

            $received += count($rows);

            try {
                $statuses = $this->fetchStatuses(
                    $token,
                    $source['path'],
                    array_column($rows, 'id'),
                );
            } catch (Throwable $exception) {
                $statuses = [];

                $errors[] = $source['label']
                    .': статусы не получены — '
                    .$exception->getMessage();
            }

            $groups = collect($rows)->groupBy('orderUid');

            foreach ($groups as $orderUid => $group) {
                $orderUid = trim((string) $orderUid);

                if ($orderUid === '') {
                    $skipped++;

                    continue;
                }

                try {
                    $result = $this->upserter->upsert(
                        $account,
                        $this->makeOrderData(
                            $account,
                            $type,
                            $orderUid,
                            $group->values()->all(),
                            $statuses,
                        ),
                    );

                    if ($result['created']) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (Throwable $exception) {
                    $failed++;

                    $errors[] = 'Заказ '.$orderUid.': '
                        .$exception->getMessage();
                }
            }
        }

        return new OrderImportResult(
            received: $received,
            created: $created,
            updated: $updated,
            skipped: $skipped,
            failed: $failed,
            errors: array_slice($errors, 0, 20),
        );
    }

    private function clampSince(
        ?CarbonImmutable $since,
    ): CarbonImmutable {
        if ($since === null) {
            return CarbonImmutable::now()
                ->subDays(self::DEFAULT_DEPTH_DAYS);
        }

        $earliest = CarbonImmutable::now()
            ->subDays(self::MAX_DEPTH_DAYS);

        return $since->lessThan($earliest)
            ? $earliest
            : $since;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrders(
        string $token,
        string $path,
        CarbonImmutable $since,
    ): array {
        $rows = [];
        $cursor = 0;
        $seenCursors = [];

        for (
            $page = 1;
            $page <= self::MAX_PAGES;
            $page++
        ) {
            $response = $this->http($token)->get(
                self::API_URL.$path,
                [
                    'limit' => self::PAGE_LIMIT,
                    'next' => $cursor,
                    'dateFrom' => $since->getTimestamp(),
                ],
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    $this->describeHttpError(
                        $response->status(),
                        $response->body(),
                    )
                );
            }

            $chunk = $response->json('orders');

            if (! is_array($chunk) || $chunk === []) {
                break;
            }

            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            // Особенность API: next остаётся ненулевым даже когда страница
            // неполная, поэтому признаком конца выборки он служить не может.
            // Останавливаемся по короткой странице или повторению курсора.
            if (count($chunk) < self::PAGE_LIMIT) {
                break;
            }

            $cursor = $response->json('next');

            if (! is_numeric($cursor)) {
                break;
            }

            $cursorKey = (string) $cursor;

            if (in_array($cursorKey, $seenCursors, true)) {
                break;
            }

            $seenCursors[] = $cursorKey;
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function fetchStatuses(
        string $token,
        string $path,
        array $ids,
    ): array {
        $ids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $ids),
                    fn (int $id): bool => $id > 0,
                )
            )
        );

        if ($ids === []) {
            return [];
        }

        $statuses = [];

        foreach (
            array_chunk($ids, self::STATUS_CHUNK)
            as $chunk
        ) {
            $response = $this->http($token)->post(
                self::API_URL.$path.'/status',
                ['orders' => $chunk],
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    $this->describeHttpError(
                        $response->status(),
                        $response->body(),
                    )
                );
            }

            foreach (
                (array) $response->json('orders', [])
                as $row
            ) {
                if (! is_array($row)) {
                    continue;
                }

                $id = (int) data_get($row, 'id');

                if ($id > 0) {
                    $statuses[$id] = $row;
                }
            }
        }

        return $statuses;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    private function makeOrderData(
        MarketplaceAccount $account,
        string $fulfillmentType,
        string $orderUid,
        array $rows,
        array $statuses,
    ): array {
        $rows = array_values($rows);
        $first = $rows[0];

        $items = [];
        $ids = [];
        $lines = [];

        foreach ($rows as $row) {
            $items[] = $this->makeItem($account, $row);

            $id = (int) data_get($row, 'id');
            $ids[] = $id;

            $lines[] = [
                'id' => $id,
                'supplier_status' => data_get(
                    $statuses[$id] ?? null,
                    'supplierStatus',
                ),
                'wb_status' => data_get(
                    $statuses[$id] ?? null,
                    'wbStatus',
                ),
            ];
        }

        sort($ids);

        [$status, $paymentStatus] =
            $this->resolveOrderStatus($ids, $statuses);

        $firstStatus = $statuses[$ids[0]] ?? null;

        $externalStatus = implode(
            '/',
            array_filter([
                $this->nullableString(
                    data_get($firstStatus, 'supplierStatus')
                ),
                $this->nullableString(
                    data_get($firstStatus, 'wbStatus')
                ),
            ])
        );

        $createdAt = $this->nullableString(
            data_get($first, 'createdAt')
        );

        return [
            'external_id' => $orderUid,
            'external_number' => (string) $ids[0],
            'external_status' => $externalStatus !== ''
                ? $externalStatus
                : null,
            'fulfillment_type' => $fulfillmentType,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'delivery_address' => $this->formatAddress(
                data_get($first, 'address')
            ),
            'customer_comment' => $this->nullableString(
                data_get($first, 'comment')
            ),
            'delivery_price' => 0,
            'currency' => 'RUB',
            'external_created_at' => $createdAt !== null
                ? CarbonImmutable::parse($createdAt)
                : null,
            'items' => $items,
            'metadata' => [
                'order_uid' => $orderUid,
                'assembly_task_ids' => $ids,
                'supply_id' => data_get($first, 'supplyId'),
                'warehouse_id' => data_get($first, 'warehouseId'),
                'office_id' => data_get($first, 'officeId'),
                'offices' => data_get($first, 'offices'),
                'delivery_type' => data_get($first, 'deliveryType'),
                'cargo_type' => data_get($first, 'cargoType'),
                'is_b2b' => (bool) data_get(
                    $first,
                    'options.isB2B',
                    false,
                ),
                'statuses' => $lines,
            ],
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array{0: string, 1: string}
     */
    private function resolveOrderStatus(
        array $ids,
        array $statuses,
    ): array {
        $mapped = [];

        foreach ($ids as $id) {
            $mapped[] = $this->mapStatus(
                $statuses[$id] ?? null
            );
        }

        if ($mapped === []) {
            return [
                Order::STATUS_NEW,
                Order::PAYMENT_PENDING,
            ];
        }

        // Заказ считается отменённым, только если отменены все задания.
        // Иначе берём первое неотменённое: частичный отказ не должен
        // прятать оставшуюся часть заказа из работы.
        foreach ($mapped as $pair) {
            if ($pair[0] !== Order::STATUS_CANCELLED) {
                return $pair;
            }
        }

        return $mapped[0];
    }

    /**
     * @param  array<string, mixed>|null  $status
     * @return array{0: string, 1: string}
     */
    private function mapStatus(?array $status): array
    {
        $supplier = mb_strtolower(
            trim(
                (string) data_get(
                    $status,
                    'supplierStatus',
                    '',
                )
            )
        );

        $wb = mb_strtolower(
            trim(
                (string) data_get(
                    $status,
                    'wbStatus',
                    '',
                )
            )
        );

        $cancelled = [
            'canceled',
            'cancelled',
            'canceled_by_client',
            'cancelled_by_client',
            'declined_by_client',
            'defect',
            'marriage',
        ];

        if (
            in_array($wb, $cancelled, true)
            || $supplier === 'cancel'
        ) {
            return [
                Order::STATUS_CANCELLED,
                Order::PAYMENT_REFUNDED,
            ];
        }

        if ($wb === 'sold') {
            return [
                Order::STATUS_COMPLETED,
                Order::PAYMENT_PAID,
            ];
        }

        $mapped = match ($supplier) {
            'new' => Order::STATUS_NEW,
            'confirm' => Order::STATUS_CONFIRMED,
            'complete', 'deliver', 'receive' =>
                Order::STATUS_SHIPPED,
            default => Order::STATUS_NEW,
        };

        return [$mapped, Order::PAYMENT_PENDING];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function makeItem(
        MarketplaceAccount $account,
        array $row,
    ): array {
        $nmId = data_get($row, 'nmId');

        $article = trim(
            (string) data_get($row, 'article', '')
        );

        $listing = $this->findListing(
            $account,
            $nmId,
            $article,
        );

        // Цена приходит в копейках — подтверждено сверкой с кабинетом WB.
        $price = round(
            ((float) data_get(
                $row,
                'convertedPrice',
                data_get($row, 'price', 0),
            )) / 100,
            2,
        );

        $name = $this->nullableString($listing?->name)
            ?? ($article !== '' ? $article : 'Товар Wildberries');

        $sku = $this->nullableString($listing?->seller_sku)
            ?? $this->nullableString($listing?->offer_id)
            ?? ($article !== '' ? $article : null);

        return [
            'product_id' => $listing?->product_id,
            'product_name' => $name,
            'product_sku' => $sku,
            'unit_price' => $price,
            'quantity' => 1,
            'external_product_id' => $nmId !== null
                ? (string) $nmId
                : null,
            'external_line_id' => (string) data_get($row, 'id'),
            'product_snapshot' => [
                'nm_id' => $nmId,
                'chrt_id' => data_get($row, 'chrtId'),
                'article' => $article !== '' ? $article : null,
                'skus' => data_get($row, 'skus'),
                'rid' => data_get($row, 'rid'),
                'price_kopecks' => data_get($row, 'price'),
            ],
        ];
    }

    private function findListing(
        MarketplaceAccount $account,
        mixed $nmId,
        string $article,
    ): ?MarketplaceListing {
        $key = $account->getKey()
            .'|'.(string) $nmId
            .'|'.$article;

        if (array_key_exists($key, $this->listingCache)) {
            return $this->listingCache[$key];
        }

        $listing = null;

        if ($nmId !== null && (string) $nmId !== '') {
            $listing = MarketplaceListing::query()
                ->where(
                    'marketplace_account_id',
                    $account->getKey(),
                )
                ->where('external_id', (string) $nmId)
                ->first();
        }

        if ($listing === null && $article !== '') {
            $needle = mb_strtolower(trim($article));

            $listing = MarketplaceListing::query()
                ->where(
                    'marketplace_account_id',
                    $account->getKey(),
                )
                ->where(function ($query) use ($needle): void {
                    $query
                        ->whereRaw(
                            'lower(trim(coalesce(seller_sku, \'\'))) = ?',
                            [$needle],
                        )
                        ->orWhereRaw(
                            'lower(trim(coalesce(offer_id, \'\'))) = ?',
                            [$needle],
                        );
                })
                ->first();
        }

        return $this->listingCache[$key] = $listing;
    }

    private function formatAddress(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $parts = collect([
            'province',
            'area',
            'city',
            'street',
            'home',
            'flat',
            'entrance',
        ])
            ->map(
                fn (string $key): string => trim(
                    (string) data_get($address, $key, '')
                )
            )
            ->filter()
            ->values()
            ->all();

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $this->nullableString(
            data_get($address, 'fullAddress')
        );
    }

    private function http(string $token): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(10)
            ->timeout(30);
    }

    private function describeHttpError(
        int $status,
        string $body,
    ): string {
        $message = match ($status) {
            400 => 'API заказов Wildberries отклонил параметры запроса.',
            401 => 'Токен Wildberries недействителен или истёк.',
            403 => 'У токена нет прав на раздел заказов Marketplace API.',
            404 => 'Раздел заказов Wildberries не найден по этому адресу.',
            429 => 'Превышен лимит запросов к API заказов Wildberries.',
            default => 'API заказов Wildberries вернул ошибку HTTP '
                .$status.'.',
        };

        $detail = trim(mb_substr($body, 0, 200));

        return $detail !== ''
            ? $message.' Ответ: '.$detail
            : $message;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
