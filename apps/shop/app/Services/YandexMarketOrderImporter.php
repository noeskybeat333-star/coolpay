<?php

namespace App\Services;

use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Integrations\Results\OrderImportResult;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Разбор заказов Яндекс Маркета.
 *
 * Проверено на живом кабинете 15.08.2026. Отличия от Wildberries,
 * из-за которых нельзя переиспользовать тот импортёр:
 *
 * - заказ приходит целиком, одной записью со всеми позициями, а не
 *   пачкой заданий на сборку, которые надо группировать;
 * - цены в рублях целым числом, а не в копейках;
 * - валюта называется RUR, а не RUB;
 * - даты в формате d-m-Y H:i:s;
 * - статус двухуровневый: status + substatus;
 * - `offerId` позиции — это наш собственный SKU, поэтому товар
 *   находится напрямую, без сопоставления по чужим идентификаторам.
 */
class YandexMarketOrderImporter
{
    private const API_URL =
        'https://api.partner.market.yandex.ru';

    private const PAGE_LIMIT = 50;

    private const MAX_PAGES = 200;

    private const DEFAULT_DEPTH_DAYS = 30;

    public function __construct(
        private readonly MarketplaceOrderUpserter $upserter,
    ) {
    }

    public function import(
        MarketplaceAccount $account,
        ?CarbonImmutable $since = null,
    ): OrderImportResult {
        $key = data_get($account->credentials, 'api_key');

        if (blank($key)) {
            return new OrderImportResult(
                failed: 1,
                errors: ['API-ключ Яндекс Маркета не указан.'],
            );
        }

        $campaigns = $this->campaigns($account);

        if ($campaigns === []) {
            return new OrderImportResult(
                failed: 1,
                errors: [
                    'Кампании не найдены. Выполните проверку '
                    .'подключения — она их обнаружит.',
                ],
            );
        }

        $since ??= CarbonImmutable::now()
            ->subDays(self::DEFAULT_DEPTH_DAYS);

        $received = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($campaigns as $campaign) {
            $campaignId = (int) data_get($campaign, 'id');

            if ($campaignId === 0) {
                continue;
            }

            $label = $this->campaignLabel($campaign);

            try {
                $orders = $this->fetchOrders(
                    $key,
                    $campaignId,
                    $since,
                );
            } catch (MarketplaceRateLimitException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $failed++;

                $errors[] = $label.': '.$exception->getMessage();

                continue;
            }

            $received += count($orders);

            foreach ($orders as $order) {
                $externalId = trim(
                    (string) data_get($order, 'id')
                );

                if ($externalId === '') {
                    $skipped++;

                    continue;
                }

                try {
                    $result = $this->upserter->upsert(
                        $account,
                        $this->makeOrderData(
                            $account,
                            $order,
                            $campaign,
                        ),
                    );

                    if ($result['created']) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (Throwable $exception) {
                    $failed++;

                    $errors[] = 'Заказ '.$externalId.': '
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function campaigns(
        MarketplaceAccount $account,
    ): array {
        $campaigns = data_get($account->settings, 'campaigns', []);

        return is_array($campaigns) ? $campaigns : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchOrders(
        string $key,
        int $campaignId,
        CarbonImmutable $since,
    ): array {
        $orders = [];
        $pageToken = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $query = [
                // Маркет ждёт дату в виде d-m-Y, а не ISO.
                'fromDate' => $since->format('d-m-Y'),
                'limit' => self::PAGE_LIMIT,
            ];

            if ($pageToken !== null) {
                $query['page_token'] = $pageToken;
            }

            $response = $this->client($key)->get(
                self::API_URL.'/campaigns/'.$campaignId.'/orders',
                $query,
            );

            if (! $response->successful()) {
                throw $this->httpError($response);
            }

            $chunk = $response->json('orders', []);

            if (! is_array($chunk)) {
                throw new RuntimeException(
                    'Яндекс Маркет вернул некорректный список заказов.'
                );
            }

            foreach ($chunk as $order) {
                $orders[] = $order;
            }

            $pageToken = $response->json('paging.nextPageToken');

            if (blank($pageToken)) {
                return $orders;
            }
        }

        throw new RuntimeException(
            'Достигнут защитный лимит количества страниц заказов.'
        );
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $campaign
     * @return array<string, mixed>
     */
    private function makeOrderData(
        MarketplaceAccount $account,
        array $order,
        array $campaign,
    ): array {
        $status = (string) data_get($order, 'status');
        $substatus = (string) data_get($order, 'substatus');

        $items = [];

        foreach (data_get($order, 'items', []) as $item) {
            $items[] = $this->makeItemData($account, $item);
        }

        $delivery = data_get($order, 'delivery', []);

        return [
            'external_id' => (string) data_get($order, 'id'),
            'external_number' => (string) (
                data_get($order, 'externalOrderId')
                    ?: data_get($order, 'id')
            ),
            'external_status' => $substatus !== ''
                ? $status.'/'.$substatus
                : $status,
            'fulfillment_type' => $this->fulfillmentType($campaign),
            'status' => $this->mapStatus($status, $substatus),
            'payment_status' => $this->mapPaymentStatus(
                $status,
                $substatus,
                (string) data_get($order, 'paymentType'),
            ),
            'delivery_address' => $this->formatAddress(
                data_get($delivery, 'address', [])
            ),
            'customer_comment' => $this->nullableString(
                data_get($order, 'notes')
            ),
            'delivery_price' => (float) (
                data_get($order, 'deliveryTotal') ?? 0
            ),
            // Маркет называет рубль RUR, в CRM всюду RUB.
            'currency' => data_get($order, 'currency') === 'RUR'
                ? 'RUB'
                : (string) data_get($order, 'currency', 'RUB'),
            'external_created_at' => $this->parseDate(
                data_get($order, 'creationDate')
            ),
            'items' => $items,
            'metadata' => [
                'campaign_id' => data_get($campaign, 'id'),
                'placement_type' => data_get(
                    $campaign,
                    'placementType'
                ),
                'payment_type' => data_get($order, 'paymentType'),
                'payment_method' => data_get($order, 'paymentMethod'),
                'buyer_type' => data_get($order, 'buyer.type'),
                'tax_system' => data_get($order, 'taxSystem'),
                'source_platform' => data_get(
                    $order,
                    'sourcePlatform'
                ),
                'cancel_requested' => (bool) data_get(
                    $order,
                    'cancelRequested',
                    false,
                ),
                'delivery_service_id' => data_get(
                    $delivery,
                    'deliveryServiceId'
                ),
                'delivery_partner_type' => data_get(
                    $delivery,
                    'deliveryPartnerType'
                ),
                'delivery_dates' => data_get($delivery, 'dates'),
                'region' => data_get($delivery, 'region.name'),
                'shipments' => data_get($delivery, 'shipments'),
                // Субсидии Маркета: часть скидки компенсирует площадка,
                // и без этой суммы выручка продавца читается неверно.
                'subsidies' => data_get($order, 'subsidies'),
                'buyer_total' => data_get($order, 'buyerTotal'),
                'items_total' => data_get($order, 'itemsTotal'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function makeItemData(
        MarketplaceAccount $account,
        array $item,
    ): array {
        $offerId = $this->nullableString(
            data_get($item, 'offerId')
                ?? data_get($item, 'shopSku')
        );

        $listing = $this->findListing($account, $offerId);

        $count = max(1, (int) data_get($item, 'count', 1));

        // Цены у Маркета в рублях, приводить порядок не нужно.
        $buyerPrice = (float) (data_get($item, 'price') ?? 0);

        // Но `price` — это то, что заплатил покупатель, а не то, что
        // получит продавец. Разницу Маркет компенсирует баллами, и она
        // лежит в subsidies. Проверено на живых заказах 15.08.2026:
        //   134715 + 65285 = 200000
        //   127040 + 52960 = 180000  (совпало с «Итого» в кабинете)
        // Без этого слагаемого выручка в CRM занижена на всю скидку
        // Маркета, а CRM задумана как единая точка правды по деньгам.
        $subsidy = $this->subsidyTotal($item);

        return [
            // Обычно товар находится через карточку. Но если каталог
            // ещё не импортирован или карточка не связана, ищем по
            // артикулу напрямую: у Маркета offerId и есть артикул
            // продавца, так что промахнуться тут негде.
            'product_id' => $listing?->product_id
                ?? $this->findProductIdBySku($offerId),

            'product_name' => $this->nullableString(
                data_get($item, 'offerName')
            ) ?? $offerId ?? 'Товар Яндекс Маркета',
            'product_sku' => $offerId,
            'unit_price' => round(
                $buyerPrice + $subsidy / $count,
                2,
            ),
            'quantity' => $count,
            'external_product_id' => $offerId,
            'external_line_id' => $this->nullableString(
                data_get($item, 'id')
            ),
            'product_snapshot' => [
                'offer_id' => $offerId,
                'shop_sku' => data_get($item, 'shopSku'),
                'market_sku' => data_get($item, 'marketSku'),
                'vat' => data_get($item, 'vat'),
                // Раскладка цены хранится целиком: деньгами от
                // покупателя пришло одно, баллами от Маркета другое,
                // и финансовому модулю понадобится и то и другое.
                'paid_by_buyer' => $buyerPrice,
                'subsidy_total' => $subsidy,
                'buyer_price' => data_get($item, 'buyerPrice'),
                'price_before_discount' => data_get(
                    $item,
                    'priceBeforeDiscount'
                ),
                'promos' => data_get($item, 'promos'),
                'subsidies' => data_get($item, 'subsidies'),
            ],
        ];
    }

    /**
     * Сумма компенсаций Маркета по позиции.
     *
     * На живых данных `subsidies` позиции совпадает с суммой
     * `promos[].subsidy` (10140 + 55145 = 65285), то есть это уже
     * агрегат, и складывать оба источника нельзя — получится вдвое.
     *
     * @param  array<string, mixed>  $item
     */
    private function subsidyTotal(array $item): float
    {
        $subsidies = data_get($item, 'subsidies');

        if (! is_array($subsidies) || $subsidies === []) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($subsidies as $subsidy) {
            $amount = data_get($subsidy, 'amount');

            if (is_numeric($amount)) {
                $total += (float) $amount;
            }
        }

        return $total;
    }

    private function findProductIdBySku(?string $sku): ?int
    {
        if ($sku === null) {
            return null;
        }

        return Product::query()
            ->whereRaw(
                'lower(trim(sku)) = ?',
                [Str::lower(trim($sku))],
            )
            ->value('id');
    }

    private function findListing(
        MarketplaceAccount $account,
        ?string $offerId,
    ): ?MarketplaceListing {
        if ($offerId === null) {
            return null;
        }

        return MarketplaceListing::query()
            ->where(
                'marketplace_account_id',
                $account->getKey(),
            )
            ->where(function ($query) use ($offerId): void {
                $query
                    ->where('external_id', $offerId)
                    ->orWhere('offer_id', $offerId)
                    ->orWhere('seller_sku', $offerId);
            })
            ->first();
    }

    /**
     * Двухуровневый статус Маркета сводим к нашему.
     *
     * Значения взяты с живого кабинета: DELIVERY, DELIVERED,
     * CANCELLED. Остальные добавлены по документации и будут
     * уточняться по мере появления в реальных данных.
     */
    private function mapStatus(
        string $status,
        string $substatus,
    ): string {
        return match ($status) {
            'DELIVERED' => 'completed',
            'PICKUP' => 'shipped',
            'DELIVERY' => 'shipped',
            'PROCESSING' => 'processing',
            'PENDING' => 'pending',
            'UNPAID' => 'pending',
            'CANCELLED' => 'cancelled',
            'RETURNED',
            'PARTIALLY_RETURNED' => 'returned',
            default => 'processing',
        };
    }

    private function mapPaymentStatus(
        string $status,
        string $substatus,
        string $paymentType,
    ): string {
        if ($status === 'CANCELLED') {
            return $substatus === 'USER_NOT_PAID'
                ? 'unpaid'
                : 'refunded';
        }

        if ($status === 'DELIVERED') {
            return 'paid';
        }

        // POSTPAID — оплата при получении, деньги ещё не у нас.
        return $paymentType === 'PREPAID'
            ? 'paid'
            : 'unpaid';
    }

    private function fulfillmentType(array $campaign): string
    {
        $type = strtolower(
            (string) data_get($campaign, 'placementType')
        );

        return match ($type) {
            'fbs' => 'fbs',
            'dbs' => 'dbs',
            'fby', 'fbo' => 'fbo',
            default => 'fbs',
        };
    }

    private function campaignLabel(array $campaign): string
    {
        $domain = $this->nullableString(
            data_get($campaign, 'domain')
        );

        $name = $domain !== null && $domain !== 'none'
            ? $domain
            : 'кампания '.data_get($campaign, 'id');

        return $name.' ('
            .data_get($campaign, 'placementType', '—').')';
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function formatAddress(mixed $address): ?string
    {
        if (! is_array($address) || $address === []) {
            return null;
        }

        $parts = array_filter([
            $this->nullableString(
                data_get($address, 'postcode')
            ),
            $this->nullableString(
                data_get($address, 'country')
            ),
            $this->nullableString(data_get($address, 'city')),
            $this->nullableString(data_get($address, 'street')),
            $this->nullableString(data_get($address, 'house')),
            $this->nullableString(data_get($address, 'block')),
            $this->nullableString(data_get($address, 'apartment')),
        ]);

        return $parts === []
            ? null
            : implode(', ', $parts);
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                'd-m-Y H:i:s',
                $value,
            ) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function client(string $key): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['Api-Key' => $key])
            ->connectTimeout(10)
            ->timeout(60);
    }

    private function httpError(Response $response): RuntimeException
    {
        $message = match ($response->status()) {
            401 => 'API-ключ Яндекс Маркета недействителен.',
            403 => 'У ключа нет доступа к заказам этой кампании.',
            404 => 'Кампания не найдена.',
            default => 'Яндекс Маркет вернул ошибку HTTP '
                .$response->status().'.',
        };

        return $response->status() === 429
            ? MarketplaceRateLimitException::fromResponse(
                $response,
                'Превышен лимит запросов Яндекс Маркета.',
            )
            : new RuntimeException($message);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
