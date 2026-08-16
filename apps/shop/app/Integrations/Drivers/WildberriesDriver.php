<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\ImportsMarketplaceOrders;
use App\Integrations\Contracts\ImportsMarketplacePrices;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class WildberriesDriver implements
    MarketplaceDriver,
    ImportsMarketplaceOrders,
    ImportsMarketplacePrices
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

    /**
     * Сколько товаров запрашивать за один вызов API цен.
     *
     * Документация допускает до 1000. Раньше здесь было 100, как в
     * пагинации карточек, и на 117 карточек уходило два запроса.
     * При нынешнем ограничении кабинета — один запрос на 15 минут —
     * это означало, что обновление цен не может завершиться в
     * принципе. С порцией в 1000 весь каталог укладывается в один
     * запрос.
     */
    private const PRICES_CHUNK = 1000;

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
                429 => 'Превышен лимит запросов. Повторить можно через '
                    .((int) (
                        $response->header('X-Ratelimit-Retry') ?: 0
                    ))
                    .' с.',
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

        $pricesDeferred = false;
        $priceFailure = null;

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

                // Цены и остатки живут в других API со своими
                // лимитерами. Их отказ не должен отменять уже
                // полученные карточки: иначе каждый повтор заново
                // выкачивает содержимое, которое и так дошло, и
                // впустую жжёт квоту Content API.
                try {
                    $pricesByExternalId = $this->fetchPrices(
                        $token,
                        $cards,
                    );
                } catch (MarketplaceRateLimitException $exception) {
                    $pricesByExternalId = [];
                    $pricesDeferred = true;

                    $priceFailure = $exception;
                } catch (RuntimeException $exception) {
                    $pricesByExternalId = [];
                    $pricesDeferred = true;

                    $errors[] = $exception->getMessage();
                }

                try {
                    // Лимитер у WB общий на кабинет. Если цены уже
                    // получили отказ, идти за остатками — значит
                    // добавить ещё один запрос в тот же счётчик и
                    // продлить блокировку.
                    $stocksByExternalId = $priceFailure !== null
                        ? []
                        : $this->fetchSellerStocks(
                            $token,
                            $cards,
                            $sellerWarehouseIds,
                        );
                } catch (MarketplaceRateLimitException $exception) {
                    $stocksByExternalId = [];
                    $pricesDeferred = true;

                    $priceFailure ??= $exception;
                } catch (RuntimeException $exception) {
                    $stocksByExternalId = [];
                    $pricesDeferred = true;

                    $errors[] = $exception->getMessage();
                }

                $pageCreated = 0;
                $pageUpdated = 0;
                $syncedAt = now();

                DB::transaction(function () use (
                    $account,
                    $cards,
                    $pricesByExternalId,
                    $stocksByExternalId,
                    $pricesDeferred,
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

                        // Если цены и остатки не доехали, эти поля не
                        // пишем совсем. Записать ноль и «нет цены»
                        // означало бы подменить настоящие данные
                        // выдумкой из-за чужого лимита частоты.
                        if ($pricesDeferred) {
                            $marketAttributes = [];
                        } else {
                            $priceAttributes =
                                $pricesByExternalId[$externalId] ?? [];

                            $stockQuantity = (int) (
                                $stocksByExternalId[$externalId] ?? 0
                            );

                            $marketAttributes = [
                                ...$priceAttributes,
                                'stock_quantity' => $stockQuantity,
                                'status' => $this->resolveListingStatus(
                                    $priceAttributes['price'] ?? null,
                                    $stockQuantity,
                                ),
                            ];
                        }

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
                                    ...$marketAttributes,
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

            // Полной синхронизацией считаем только ту, где доехало всё.
            if ($failed === 0 && ! $pricesDeferred) {
                $account->update([
                    'last_synced_at' => now(),
                ]);
            }

            if ($pricesDeferred && $priceFailure !== null) {
                $errors[] = $priceFailure->getMessage()
                    .' Карточки сохранены, цены и остатки '
                    .'будут получены отдельной задачей.';
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
            pricesDeferred: $pricesDeferred,
            pricesRetryAfterSeconds: $priceFailure
                ?->retryAfterSeconds ?? 0,
        );
    }

    /**
     * Догоняет цены и остатки по уже сохранённым карточкам.
     *
     * Content API здесь не трогаем вовсе: всё, что нужно для запроса,
     * уже лежит в raw_data сохранённых карточек. Поэтому повтор при
     * лимите бьёт только в тот лимитер, который нас и остановил.
     */
    public function importPrices(
        MarketplaceAccount $account,
    ): CatalogImportResult {
        $token = data_get(
            $account->credentials,
            'api_token',
        );

        if (blank($token)) {
            return new CatalogImportResult(
                failed: 1,
                errors: ['API-токен Wildberries не указан.'],
            );
        }

        $received = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        try {
            $sellerWarehouseIds =
                $this->fetchSellerWarehouseIds($token);

            $listings = MarketplaceListing::query()
                ->where(
                    'marketplace_account_id',
                    $account->getKey(),
                )
                ->orderBy('id')
                ->get(['id', 'external_id', 'raw_data']);

            if ($listings->isEmpty()) {
                return new CatalogImportResult(
                    errors: [
                        'Нет сохранённых карточек: сначала '
                        .'импортируйте каталог.',
                    ],
                );
            }

            $chunks = $listings->chunk(self::PRICES_CHUNK);

            foreach ($chunks as $index => $chunk) {
                $cards = $chunk
                    ->pluck('raw_data')
                    ->filter(fn ($card): bool => is_array($card))
                    ->values()
                    ->all();

                if ($cards === []) {
                    continue;
                }

                $received += count($cards);

                $pricesByExternalId = $this->fetchPrices(
                    $token,
                    $cards,
                );

                $stocksByExternalId = $this->fetchSellerStocks(
                    $token,
                    $cards,
                    $sellerWarehouseIds,
                );

                $syncedAt = now();

                DB::transaction(function () use (
                    $chunk,
                    $pricesByExternalId,
                    $stocksByExternalId,
                    $syncedAt,
                    &$updated,
                ): void {
                    foreach ($chunk as $listing) {
                        $externalId = $listing->external_id;

                        $priceAttributes =
                            $pricesByExternalId[$externalId] ?? [];

                        $stockQuantity = (int) (
                            $stocksByExternalId[$externalId] ?? 0
                        );

                        $listing->forceFill([
                            ...$priceAttributes,
                            'stock_quantity' => $stockQuantity,
                            'status' => $this->resolveListingStatus(
                                $priceAttributes['price'] ?? null,
                                $stockQuantity,
                            ),
                            'synced_at' => $syncedAt,
                        ])->save();

                        $updated++;
                    }
                });

                if ($index < $chunks->count() - 1) {
                    // Лимитер WB общий на кабинет — держим паузу
                    // между порциями так же, как в импорте карточек.
                    usleep(700000);
                }
            }

            if ($failed === 0) {
                $account->update(['last_synced_at' => now()]);
            }
        } catch (MarketplaceRateLimitException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            $failed++;
            $errors[] = 'Не удалось соединиться с API Wildberries.';
        } catch (RuntimeException $exception) {
            $failed++;
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            $failed++;
            $errors[] = 'Во время обновления цен произошла '
                .'внутренняя ошибка.';
        }

        return new CatalogImportResult(
            received: $received,
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
            'orders_dbs' => true,
            'orders_dbw' => true,
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
    /**
     * Список складов продавца с суточным кэшем.
     *
     * Склады заводят раз в жизни, а запрос за ними уходил при каждом
     * импорте — и первым, то есть выбирал бюджет до того, как дело
     * доходило до нужных данных. При лимите в один запрос на 15 минут
     * это делало импорт цен невыполнимым в принципе.
     *
     * @return array<int, int>
     */
    private function fetchSellerWarehouseIds(
        string $token,
    ): array {
        $cacheKey = 'wb-warehouses:'.md5($token);

        $cached = Cache::get($cacheKey);

        // В кэше только скаляры и массивы: cache.serializable_classes
        // запрещает восстанавливать объекты (см. MarketplaceCooldown).
        if (is_array($cached)) {
            return $cached;
        }

        $ids = $this->requestSellerWarehouseIds($token);

        if ($ids !== []) {
            Cache::put($cacheKey, $ids, now()->addDay());
        }

        return $ids;
    }

    /**
     * @return array<int, int>
     */
    private function requestSellerWarehouseIds(
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
            // Лимит частоты — временный отказ, а не провал: пусть
            // очередь повторит позже, как и в остальных запросах.
            if ($response->status() === 429) {
                throw MarketplaceRateLimitException::fromResponse(
                    $response,
                    'Превышен лимит запросов API складов Wildberries.',
                );
            }

            throw new RuntimeException(
                match ($response->status()) {
                    401 => 'Токен Wildberries не имеет доступа '
                        .'к складам продавца.',
                    403 => 'У токена нет разрешения на чтение '
                        .'складов продавца.',
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
                    if ($response->status() === 429) {
                        throw MarketplaceRateLimitException::fromResponse(
                            $response,
                            'Превышен лимит запросов остатков '
                            .'Wildberries.',
                        );
                    }

                    throw new RuntimeException(
                        match ($response->status()) {
                            401 => 'Токен Wildberries не имеет '
                                .'доступа к остаткам.',
                            403 => 'У токена нет разрешения '
                                .'на чтение остатков.',
                            409 => 'Склад Wildberries временно '
                                .'обрабатывается.',
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
