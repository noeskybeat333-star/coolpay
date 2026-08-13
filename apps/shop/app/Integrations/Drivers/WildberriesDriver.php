<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\ImportsMarketplaceOrders;
use App\Integrations\Contracts\MarketplaceDriver;
use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Integrations\Results\CatalogImportResult;
use App\Integrations\Results\ConnectionTestResult;
use App\Integrations\Results\OrderImportResult;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\WildberriesOrderImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class WildberriesDriver implements
    MarketplaceDriver,
    ImportsMarketplaceOrders
{
    private const COMMON_API_URL =
        'https://common-api.wildberries.ru';

    private const CONTENT_API_URL =
        'https://content-api.wildberries.ru';

    private const PRICES_API_URL =
        'https://discounts-prices-api.wildberries.ru';

    private const MARKETPLACE_API_URL =
        'https://marketplace-api.wildberries.ru';

    private const PAGE_LIMIT = 100;

    private const MAX_PAGES = 1000;

    public function testConnection(
        MarketplaceAccount $account,
    ): ConnectionTestResult {
        $token = data_get(
            $account->credentials,
            'api_token',
        );

        if (blank($token)) {
            return ConnectionTestResult::failure(
                'API-токен Wildberries не указан.',
            );
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(5)
                ->timeout(10)
                ->get(self::COMMON_API_URL.'/ping');

            if ($response->successful()) {
                return ConnectionTestResult::success(
                    'Подключение к Wildberries работает.',
                    [
                        'http_status' => $response->status(),
                        'checked_at' => now()->toIso8601String(),
                    ],
                );
            }

            $message = match ($response->status()) {
                401 => 'Токен Wildberries недействителен или истёк.',
                403 => 'У токена недостаточно разрешений.',
                429 => 'Превышен лимит проверок. Повторите позднее.',
                default => 'Wildberries вернул ошибку HTTP '
                    .$response->status().'.',
            };

            return ConnectionTestResult::failure(
                $message,
                [
                    'http_status' => $response->status(),
                ],
            );
        } catch (ConnectionException) {
            return ConnectionTestResult::failure(
                'Не удалось соединиться с API Wildberries.',
            );
        } catch (Throwable $exception) {
            report($exception);

            return ConnectionTestResult::failure(
                'Во время проверки произошла внутренняя ошибка.',
            );
        }
    }

    public function importCatalog(
        MarketplaceAccount $account,
    ): CatalogImportResult {
        $token = data_get(
            $account->credentials,
            'api_token',
        );

        if (blank($token)) {
            return new CatalogImportResult(
                failed: 1,
                errors: [
                    'API-токен Wildberries не указан.',
                ],
            );
        }

        $received = 0;
        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        $cursor = [
            'limit' => self::PAGE_LIMIT,
        ];

        $previousCursorKey = null;
        $completed = false;

        try {
            $sellerWarehouseIds =
                $this->fetchSellerWarehouseIds(
                    $token
                );

            for (
                $page = 1;
                $page <= self::MAX_PAGES;
                $page++
            ) {
                $response = Http::acceptJson()
                    ->withToken($token)
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->post(
                        self::CONTENT_API_URL
                            .'/content/v2/get/cards/list',
                        [
                            'settings' => [
                                'sort' => [
                                    'ascending' => true,
                                ],
                                'filter' => [
                                    'withPhoto' => -1,
                                ],
                                'cursor' => $cursor,
                            ],
                        ],
                    );

                if (! $response->successful()) {
                    // Лимит частоты не считаем провалом импорта:
                    // отдаём наверх, чтобы очередь повторила задачу позже.
                    if ($response->status() === 429) {
                        throw MarketplaceRateLimitException::fromResponse(
                            $response,
                            'Превышен лимит запросов к API карточек '
                            .'Wildberries.',
                        );
                    }

                    $failed++;

                    $errors[] = match ($response->status()) {
                        401 => 'Токен Wildberries недействителен '
                            .'или истёк.',
                        403 => 'У токена нет доступа к карточкам.',
                        default => 'Wildberries вернул ошибку HTTP '
                            .$response->status().'.',
                    };

                    break;
                }

                $cards = $response->json('cards', []);

                if (! is_array($cards)) {
                    throw new RuntimeException(
                        'Wildberries вернул некорректный список карточек.',
                    );
                }

                $received += count($cards);
                $pricesByExternalId = $this->fetchPrices(
                    $token,
                    $cards,
                );

                $stocksByExternalId =
                    $this->fetchSellerStocks(
                        $token,
                        $cards,
                        $sellerWarehouseIds,
                    );

                $pageCreated = 0;
                $pageUpdated = 0;
                $syncedAt = now();

                DB::transaction(function () use (
                    $account,
                    $cards,
                    $pricesByExternalId,
                    $stocksByExternalId,
                    $syncedAt,
                    &$pageCreated,
                    &$pageUpdated,
                ): void {
                    foreach ($cards as $card) {
                        $externalId = $this->nullableString(
                            data_get($card, 'nmID'),
                        );

                        if ($externalId === null) {
                            throw new RuntimeException(
                                'Карточка Wildberries не содержит nmID.',
                            );
                        }

                        $vendorCode = $this->nullableString(
                            data_get($card, 'vendorCode'),
                        );

                        $name = $this->nullableString(
                            data_get($card, 'title'),
                        ) ?? $vendorCode
                            ?? 'Товар Wildberries '.$externalId;

                        $priceAttributes =
                            $pricesByExternalId[$externalId] ?? [];

                        $stockQuantity = (int) (
                            $stocksByExternalId[$externalId] ?? 0
                        );

                        $status = $this->resolveListingStatus(
                            $priceAttributes['price'] ?? null,
                            $stockQuantity,
                        );

                        $listing = MarketplaceListing::query()
                            ->updateOrCreate(
                                [
                                    'marketplace_account_id' => $account->getKey(),

                                    'external_id' => $externalId,
                                ],
                                [
                                    'offer_id' => $vendorCode,
                                    'seller_sku' => $vendorCode,
                                    'barcode' => $this->firstBarcode($card),
                                    'name' => $name,
                                    'brand' => $this->nullableString(
                                        data_get($card, 'brand'),
                                    ),
                                    'category' => $this->nullableString(
                                        data_get($card, 'subjectName'),
                                    ),
                                    'description' => $this->nullableString(
                                        data_get(
                                            $card,
                                            'description',
                                        ),
                                    ),
                                    ...$priceAttributes,
                                    'stock_quantity' => $stockQuantity,
                                    'status' => $status,
                                    'characteristics' => data_get(
                                        $card,
                                        'characteristics',
                                        [],
                                    ),
                                    'images' => data_get(
                                        $card,
                                        'photos',
                                        [],
                                    ),
                                    'raw_data' => $card,
                                    'synced_at' => $syncedAt,
                                ],
                            );

                        if ($listing->wasRecentlyCreated) {
                            $pageCreated++;
                        } else {
                            $pageUpdated++;
                        }
                    }
                });

                $created += $pageCreated;
                $updated += $pageUpdated;

                $responseCursor = $response->json(
                    'cursor',
                    [],
                );

                $pageTotal = (int) (
                    $responseCursor['total']
                    ?? count($cards)
                );

                if ($pageTotal < self::PAGE_LIMIT) {
                    $completed = true;

                    break;
                }

                $nextUpdatedAt =
                    $responseCursor['updatedAt'] ?? null;

                $nextNmId =
                    $responseCursor['nmID'] ?? null;

                if (
                    blank($nextUpdatedAt)
                    || $nextNmId === null
                ) {
                    $failed++;
                    $errors[] =
                        'Wildberries не вернул курсор следующей страницы.';

                    break;
                }

                $cursorKey = $nextUpdatedAt.':'.$nextNmId;

                if ($cursorKey === $previousCursorKey) {
                    $failed++;
                    $errors[] =
                        'Wildberries повторил курсор пагинации.';

                    break;
                }

                $previousCursorKey = $cursorKey;

                $cursor = [
                    'limit' => self::PAGE_LIMIT,
                    'updatedAt' => $nextUpdatedAt,
                    'nmID' => $nextNmId,
                ];

                // Лимит WB Content API: интервал от 600 мс.
                usleep(650000);
            }

            if (
                ! $completed
                && $failed === 0
            ) {
                $failed++;
                $errors[] =
                    'Достигнут защитный лимит количества страниц.';
            }

            if ($failed === 0) {
                $account->update([
                    'last_synced_at' => now(),
                ]);
            }
        } catch (MarketplaceRateLimitException $exception) {
            // Временный отказ, а не провал импорта. Отдаём наверх, чтобы
            // очередь повторила задачу с паузой.
            throw $exception;
        } catch (ConnectionException) {
            $failed++;
            $errors[] =
                'Не удалось соединиться с API Wildberries.';
        } catch (RuntimeException $exception) {
            // Собственные исключения драйвера написаны для человека
            // («у токена нет доступа к ценам» и подобные). Прятать их
            // за словом «внутренняя ошибка» — значит терять диагностику.
            $failed++;
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            $failed++;
            $errors[] =
                'Во время импорта произошла внутренняя ошибка.';
        }

        return new CatalogImportResult(
            received: $received,
            created: $created,
            updated: $updated,
            failed: $failed,
            errors: $errors,
        );
    }

    public function importOrders(
        MarketplaceAccount $account,
        ?CarbonImmutable $since = null,
    ): OrderImportResult {
        return app(WildberriesOrderImporter::class)
            ->import($account, $since);
    }

    public function capabilities(): array
    {
        return [
            'connection_test' => true,
            'catalog_read' => true,
            'prices_read' => true,
            'stocks_read' => true,
            'orders_read' => true,
            'orders_fbs' => true,
            'orders_dbs' => false,
            'orders_dbw' => false,
            'orders_fbo' => false,
            'prices_write' => false,
            'stocks_write' => false,
        ];
    }

    /**
     * @return array<string, array{
     *     base_price: float,
     *     discount_percent: int,
     *     price: float
     * }>
     */
    /**
     * @return array<int, int>
     */
    private function fetchSellerWarehouseIds(
        string $token,
    ): array {
        $response = Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(10)
            ->timeout(30)
            ->get(
                self::MARKETPLACE_API_URL
                    .'/api/v3/warehouses'
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                match ($response->status()) {
                    401 => 'Токен Wildberries не имеет доступа '
                        .'к складам продавца.',
                    403 => 'У токена нет разрешения на чтение '
                        .'складов продавца.',
                    429 => 'Превышен лимит запросов API '
                        .'складов Wildberries.',
                    default => 'API складов Wildberries вернул '
                        .'ошибку HTTP '
                        .$response->status().'.',
                }
            );
        }

        $warehouses = $response->json();

        if (! is_array($warehouses)) {
            throw new RuntimeException(
                'Wildberries вернул некорректный список складов.'
            );
        }

        $warehouseIds = [];

        foreach ($warehouses as $warehouse) {
            $warehouseId = data_get(
                $warehouse,
                'id'
            );

            if (is_numeric($warehouseId)) {
                $warehouseIds[] =
                    (int) $warehouseId;
            }
        }

        return array_values(
            array_unique($warehouseIds)
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<int, int>  $warehouseIds
     * @return array<string, int>
     */
    private function fetchSellerStocks(
        string $token,
        array $cards,
        array $warehouseIds,
    ): array {
        $externalIdByChrtId = [];
        $stocksByExternalId = [];

        foreach ($cards as $card) {
            $externalId = $this->nullableString(
                data_get($card, 'nmID')
            );

            if ($externalId === null) {
                continue;
            }

            $stocksByExternalId[$externalId] = 0;

            foreach (
                data_get($card, 'sizes', []) as $size
            ) {
                $chrtId = data_get(
                    $size,
                    'chrtID'
                );

                if (! is_numeric($chrtId)) {
                    continue;
                }

                $externalIdByChrtId[
                    (int) $chrtId
                ] = $externalId;
            }
        }

        if (
            $externalIdByChrtId === []
            || $warehouseIds === []
        ) {
            return $stocksByExternalId;
        }

        $chrtIdChunks = array_chunk(
            array_keys($externalIdByChrtId),
            1000,
        );

        foreach ($warehouseIds as $warehouseId) {
            foreach ($chrtIdChunks as $chrtIds) {
                $response = Http::acceptJson()
                    ->withToken($token)
                    ->connectTimeout(10)
                    ->timeout(30)
                    ->post(
                        self::MARKETPLACE_API_URL
                            .'/api/v3/stocks/'
                            .$warehouseId,
                        [
                            'chrtIds' => $chrtIds,
                        ],
                    );

                if (! $response->successful()) {
                    throw new RuntimeException(
                        match ($response->status()) {
                            401 => 'Токен Wildberries не имеет '
                                .'доступа к остаткам.',
                            403 => 'У токена нет разрешения '
                                .'на чтение остатков.',
                            409 => 'Склад Wildberries временно '
                                .'обрабатывается.',
                            429 => 'Превышен лимит запросов '
                                .'остатков Wildberries.',
                            default => 'API остатков Wildberries '
                                .'вернул ошибку HTTP '
                                .$response->status().'.',
                        }
                    );
                }

                $stocks = $response->json(
                    'stocks',
                    [],
                );

                if (! is_array($stocks)) {
                    throw new RuntimeException(
                        'Wildberries вернул некорректные остатки.'
                    );
                }

                foreach ($stocks as $stock) {
                    $chrtId = data_get(
                        $stock,
                        'chrtId'
                    );

                    $amount = data_get(
                        $stock,
                        'amount'
                    );

                    if (
                        ! is_numeric($chrtId)
                        || ! is_numeric($amount)
                    ) {
                        continue;
                    }

                    $externalId =
                        $externalIdByChrtId[
                            (int) $chrtId
                        ] ?? null;

                    if ($externalId === null) {
                        continue;
                    }

                    $stocksByExternalId[$externalId] +=
                        max(0, (int) $amount);
                }
            }
        }

        return $stocksByExternalId;
    }

    private function resolveListingStatus(
        mixed $price,
        int $stockQuantity,
    ): string {
        if (
            ! is_numeric($price)
            || (float) $price <= 0
        ) {
            return 'no_price';
        }

        if ($stockQuantity <= 0) {
            return 'out_of_stock';
        }

        return 'active';
    }

    private function fetchPrices(
        string $token,
        array $cards,
    ): array {
        $nmList = [];

        foreach ($cards as $card) {
            $nmId = data_get($card, 'nmID');

            if (is_numeric($nmId)) {
                $nmList[] = (int) $nmId;
            }
        }

        $nmList = array_values(
            array_unique($nmList)
        );

        if ($nmList === []) {
            return [];
        }

        // Лимитер Wildberries глобальный на весь кабинет, а не на отдельный
        // эндпоинт. Поэтому паузу держим не только между страницами
        // карточек, но и перед каждым обращением за ценами.
        usleep(700000);

        $response = Http::acceptJson()
            ->withToken($token)
            ->connectTimeout(10)
            ->timeout(30)
            ->post(
                self::PRICES_API_URL
                    .'/api/v2/list/goods/filter',
                [
                    'nmList' => $nmList,
                ],
            );

        if (! $response->successful()) {
            if ($response->status() === 429) {
                throw MarketplaceRateLimitException::fromResponse(
                    $response,
                    'Превышен лимит запросов API цен Wildberries.',
                );
            }

            throw new RuntimeException(
                match ($response->status()) {
                    401 => 'Токен Wildberries не имеет доступа к ценам.',
                    403 => 'У токена нет разрешения на чтение цен.',
                    default => 'API цен Wildberries вернул ошибку HTTP '
                        .$response->status().'.',
                }
            );
        }

        if ((bool) $response->json('error', false)) {
            throw new RuntimeException(
                $this->nullableString(
                    $response->json('errorText')
                ) ?? 'Wildberries вернул ошибку при получении цен.'
            );
        }

        $goods = $response->json(
            'data.listGoods',
            [],
        );

        if (! is_array($goods)) {
            throw new RuntimeException(
                'Wildberries вернул некорректный список цен.'
            );
        }

        $prices = [];

        foreach ($goods as $good) {
            $externalId = $this->nullableString(
                data_get($good, 'nmID')
            );

            if ($externalId === null) {
                continue;
            }

            $bestPrice = null;
            $bestBasePrice = null;

            foreach (
                data_get($good, 'sizes', []) as $size
            ) {
                $basePrice = data_get(
                    $size,
                    'price'
                );

                $salePrice = data_get(
                    $size,
                    'discountedPrice'
                );

                if (! is_numeric($salePrice)) {
                    $salePrice = $basePrice;
                }

                if (! is_numeric($salePrice)) {
                    continue;
                }

                $salePrice = (float) $salePrice;

                $basePrice = is_numeric($basePrice)
                    ? (float) $basePrice
                    : $salePrice;

                if (
                    $bestPrice === null
                    || $salePrice < $bestPrice
                ) {
                    $bestPrice = $salePrice;
                    $bestBasePrice = $basePrice;
                }
            }

            if (
                $bestPrice === null
                || $bestBasePrice === null
            ) {
                continue;
            }

            $discount = data_get(
                $good,
                'discount'
            );

            $discountPercent = is_numeric($discount)
                ? (int) $discount
                : 0;

            $prices[$externalId] = [
                'base_price' => $bestBasePrice,
                'discount_percent' => $discountPercent,
                'price' => $bestPrice,
            ];
        }

        return $prices;
    }

    private function firstBarcode(array $card): ?string
    {
        foreach (
            data_get($card, 'sizes', []) as $size
        ) {
            foreach (
                data_get($size, 'skus', []) as $barcode
            ) {
                $barcode = $this->nullableString($barcode);

                if ($barcode !== null) {
                    return $barcode;
                }
            }
        }

        return null;
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        return (string) $value;
    }
}
