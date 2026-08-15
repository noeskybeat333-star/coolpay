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
use App\Services\YandexMarketOrderImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Драйвер Яндекс Маркета.
 *
 * Устроен заметно проще драйвера Wildberries, и это следствие самого
 * API, а не разной тщательности. Проверено 15.08.2026:
 *
 * - цены приходят вместе с карточкой (`basicPrice`), отдельного API цен
 *   нет — значит нет и всей истории с отложенными ценами;
 * - лимиты щедрые: 10000 запросов в час на заказы, 100 на каталог,
 *   против одного запроса на 15 минут у WB;
 * - `offerId` карточки — это SKU продавца, поэтому связь с товаром CRM
 *   получается прямой.
 *
 * Один кабинет («бизнес») содержит несколько кампаний. Каталог общий на
 * бизнес, заказы — у каждой кампании свои. Поэтому подключение в CRM
 * заводится одно на бизнес, а кампании обнаруживаются при проверке
 * связи и хранятся в `settings`.
 */
class YandexMarketDriver implements
    MarketplaceDriver,
    ImportsMarketplaceOrders
{
    private const API_URL =
        'https://api.partner.market.yandex.ru';

    private const PAGE_LIMIT = 200;

    private const MAX_PAGES = 100;

    public function __construct(
        private readonly YandexMarketOrderImporter $orders,
    ) {
    }

    public function testConnection(
        MarketplaceAccount $account,
    ): ConnectionTestResult {
        $key = data_get($account->credentials, 'api_key');

        if (blank($key)) {
            return ConnectionTestResult::failure(
                'API-ключ Яндекс Маркета не указан.'
            );
        }

        try {
            $response = $this->client($key)
                ->get(self::API_URL.'/campaigns');
        } catch (ConnectionException) {
            return ConnectionTestResult::failure(
                'Не удалось соединиться с API Яндекс Маркета.'
            );
        }

        if (! $response->successful()) {
            return ConnectionTestResult::failure(
                match ($response->status()) {
                    401 => 'Ключ недействителен. Проверьте, что он '
                        .'скопирован целиком, вместе с префиксом.',
                    403 => 'У ключа нет нужных доступов. Выдайте ему '
                        .'права на заказы и каталог.',
                    429 => 'Превышен лимит запросов Яндекс Маркета.',
                    default => 'Яндекс Маркет вернул ошибку HTTP '
                        .$response->status().'.',
                }
            );
        }

        $campaigns = $response->json('campaigns', []);

        if (! is_array($campaigns) || $campaigns === []) {
            return ConnectionTestResult::failure(
                'Ключ принят, но кампаний в кабинете не найдено.'
            );
        }

        // Идентификаторы бизнеса и кампаний нужны почти каждому
        // запросу. Спрашивать их у человека незачем — API отдаёт
        // сам, и мы их запоминаем.
        $businessId = data_get($campaigns[0], 'business.id');

        $account->update([
            'external_account_id' => $businessId !== null
                ? (string) $businessId
                : null,

            'settings' => array_replace(
                $account->settings ?? [],
                [
                    'business_id' => $businessId,
                    'business_name' => data_get(
                        $campaigns[0],
                        'business.name'
                    ),
                    'campaigns' => array_map(
                        fn (array $campaign): array => [
                            'id' => data_get($campaign, 'id'),
                            'domain' => data_get($campaign, 'domain'),
                            'placementType' => data_get(
                                $campaign,
                                'placementType'
                            ),
                            'apiAvailability' => data_get(
                                $campaign,
                                'apiAvailability'
                            ),
                        ],
                        $campaigns,
                    ),
                ],
            ),
        ]);

        $models = collect($campaigns)
            ->pluck('placementType')
            ->filter()
            ->unique()
            ->implode(', ');

        return ConnectionTestResult::success(
            'Подключение работает. Бизнес «'
            .data_get($campaigns[0], 'business.name')
            .'», кампаний: '.count($campaigns)
            .' ('.$models.').',
        );
    }

    public function importCatalog(
        MarketplaceAccount $account,
    ): CatalogImportResult {
        $key = data_get($account->credentials, 'api_key');

        if (blank($key)) {
            return new CatalogImportResult(
                failed: 1,
                errors: ['API-ключ Яндекс Маркета не указан.'],
            );
        }

        $businessId = data_get($account->settings, 'business_id');

        if (blank($businessId)) {
            return new CatalogImportResult(
                failed: 1,
                errors: [
                    'Идентификатор бизнеса неизвестен. Выполните '
                    .'проверку подключения — она его определит.',
                ],
            );
        }

        $received = 0;
        $created = 0;
        $updated = 0;
        $failed = 0;
        $archived = 0;
        $errors = [];

        $pageToken = null;
        $completed = false;

        // Отметка времени до первого запроса: всё, что не обновилось
        // в этом проходе, площадка больше не отдаёт.
        $startedAt = now();

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $query = ['limit' => self::PAGE_LIMIT];

                if ($pageToken !== null) {
                    $query['page_token'] = $pageToken;
                }

                // limit и page_token идут именно в query-строке:
                // в теле запроса Маркет их молча игнорирует и всегда
                // отдаёт 50 записей. Проверено 15.08.2026.
                $response = $this->client($key)->post(
                    self::API_URL.'/businesses/'.$businessId
                        .'/offer-mappings?'.http_build_query($query),
                    (object) [],
                );

                if (! $response->successful()) {
                    throw $this->httpError($response);
                }

                $mappings = $response->json(
                    'result.offerMappings',
                    [],
                );

                if (! is_array($mappings)) {
                    throw new RuntimeException(
                        'Яндекс Маркет вернул некорректный каталог.'
                    );
                }

                $received += count($mappings);

                $pageCreated = 0;
                $pageUpdated = 0;
                $syncedAt = now();

                DB::transaction(function () use (
                    $account,
                    $mappings,
                    $syncedAt,
                    &$pageCreated,
                    &$pageUpdated,
                ): void {
                    foreach ($mappings as $mapping) {
                        $listing = $this->saveListing(
                            $account,
                            $mapping,
                            $syncedAt,
                        );

                        if ($listing === null) {
                            continue;
                        }

                        if ($listing->wasRecentlyCreated) {
                            $pageCreated++;
                        } else {
                            $pageUpdated++;
                        }
                    }
                });

                $created += $pageCreated;
                $updated += $pageUpdated;

                $pageToken = $response->json(
                    'result.paging.nextPageToken'
                );

                if (blank($pageToken)) {
                    $completed = true;

                    break;
                }
            }

            // Архивируем только после полного обхода. Если импорт
            // оборвался на середине, «непришедшие» карточки — это
            // просто те, до которых мы не дошли, и пометить их
            // архивными значило бы соврать.
            if ($completed && $failed === 0) {
                $archived = $this->archiveMissing(
                    $account,
                    $startedAt,
                );

                $account->update(['last_synced_at' => now()]);
            }
        } catch (MarketplaceRateLimitException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            $failed++;
            $errors[] =
                'Не удалось соединиться с API Яндекс Маркета.';
        } catch (RuntimeException $exception) {
            $failed++;
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            $failed++;
            $errors[] = 'Во время импорта произошла внутренняя ошибка.';
        }

        return new CatalogImportResult(
            received: $received,
            created: $created,
            updated: $updated,
            failed: $failed,
            errors: $errors,
            archived: $archived,
        );
    }

    /**
     * Пометить архивными карточки, которых площадка больше не отдаёт.
     *
     * Удалять нельзя: на карточку могут ссылаться заказы, и удаление
     * оборвало бы историю продаж. Поэтому меняем только статус —
     * данные остаются, но видно, что товара на площадке уже нет.
     */
    private function archiveMissing(
        MarketplaceAccount $account,
        mixed $startedAt,
    ): int {
        return MarketplaceListing::query()
            ->where('marketplace_account_id', $account->getKey())
            ->where('status', '!=', 'archived')
            ->where(function ($query) use ($startedAt): void {
                $query
                    ->whereNull('synced_at')
                    ->orWhere('synced_at', '<', $startedAt);
            })
            ->update([
                'status' => 'archived',
                'stock_quantity' => 0,
            ]);
    }

    public function importOrders(
        MarketplaceAccount $account,
        ?CarbonImmutable $since = null,
    ): OrderImportResult {
        return $this->orders->import($account, $since);
    }

    public function capabilities(): array
    {
        return [
            'connection_test' => true,
            'catalog_read' => true,
            'prices_read' => true,
            'stocks_read' => false,
            'orders_read' => true,
            'orders_fbs' => true,
            'orders_dbs' => true,
            'orders_fbo' => false,
            'prices_write' => false,
            'stocks_write' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapping
     */
    private function saveListing(
        MarketplaceAccount $account,
        array $mapping,
        mixed $syncedAt,
    ): ?MarketplaceListing {
        $offer = data_get($mapping, 'offer', []);

        $offerId = $this->nullableString(
            data_get($offer, 'offerId')
        );

        if ($offerId === null) {
            return null;
        }

        $price = data_get($offer, 'basicPrice.value');

        // discountBase — зачёркнутая цена «до скидки». На живых данных
        // она втрое выше реальной (110000 против 245000), поэтому
        // хранится как old_price и не участвует в расчётах.
        $oldPrice = data_get($offer, 'basicPrice.discountBase');

        return MarketplaceListing::query()->updateOrCreate(
            [
                'marketplace_account_id' => $account->getKey(),
                'external_id' => $offerId,
            ],
            [
                'offer_id' => $offerId,
                'seller_sku' => $offerId,
                'barcode' => $this->firstBarcode($offer),
                'name' => $this->nullableString(
                    data_get($offer, 'name')
                ) ?? $offerId,
                'brand' => $this->nullableString(
                    data_get($offer, 'vendor')
                ),
                'category' => $this->nullableString(
                    data_get($offer, 'category')
                ),
                'description' => $this->nullableString(
                    data_get($offer, 'description')
                ),
                'price' => is_numeric($price)
                    ? (float) $price
                    : null,
                'old_price' => is_numeric($oldPrice)
                    ? (float) $oldPrice
                    : null,
                'status' => $this->resolveStatus($offer),
                'characteristics' => [
                    'market_sku' => data_get(
                        $mapping,
                        'mapping.marketSku'
                    ),
                    'market_model_id' => data_get(
                        $mapping,
                        'mapping.marketModelId'
                    ),
                    'market_category' => data_get(
                        $mapping,
                        'mapping.marketCategoryName'
                    ),
                    'card_status' => data_get($offer, 'cardStatus'),
                    'weight_dimensions' => data_get(
                        $offer,
                        'weightDimensions'
                    ),
                    'campaigns' => data_get($offer, 'campaigns'),
                ],
                'images' => data_get($offer, 'pictures', []),
                'raw_data' => $mapping,
                'synced_at' => $syncedAt,
            ],
        );
    }

    /**
     * Остатков в каталоге Маркета нет, зато у каждой кампании есть
     * собственный статус размещения. Сводим их к одному: товар
     * считается активным, если хоть где-то продаётся.
     *
     * @param  array<string, mixed>  $offer
     */
    private function resolveStatus(array $offer): string
    {
        $statuses = collect(data_get($offer, 'campaigns', []))
            ->pluck('status')
            ->filter()
            ->all();

        if ($statuses === []) {
            return 'unknown';
        }

        if (in_array('READY', $statuses, true)
            || in_array('PUBLISHED', $statuses, true)
        ) {
            return 'active';
        }

        if (in_array('NO_STOCKS', $statuses, true)) {
            return 'out_of_stock';
        }

        return 'inactive';
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function firstBarcode(array $offer): ?string
    {
        foreach (data_get($offer, 'barcodes', []) as $barcode) {
            $barcode = $this->nullableString($barcode);

            if ($barcode !== null) {
                return $barcode;
            }
        }

        return null;
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
        if ($response->status() === 429) {
            return MarketplaceRateLimitException::fromResponse(
                $response,
                'Превышен лимит запросов Яндекс Маркета.',
            );
        }

        return new RuntimeException(
            match ($response->status()) {
                401 => 'API-ключ Яндекс Маркета недействителен.',
                403 => 'У ключа нет доступа к каталогу.',
                404 => 'Бизнес не найден.',
                default => 'Яндекс Маркет вернул ошибку HTTP '
                    .$response->status().'.',
            }
        );
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
