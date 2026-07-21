<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\MarketplaceDriver;
use App\Integrations\Results\CatalogImportResult;
use App\Integrations\Results\ConnectionTestResult;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class WildberriesDriver implements MarketplaceDriver
{
    private const COMMON_API_URL =
        'https://common-api.wildberries.ru';

    private const CONTENT_API_URL =
        'https://content-api.wildberries.ru';

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
                    $failed++;

                    $errors[] = match ($response->status()) {
                        401 => 'Токен Wildberries недействителен '
                            .'или истёк.',
                        403 => 'У токена нет доступа к карточкам.',
                        429 => 'Превышен лимит запросов Wildberries.',
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

                $pageCreated = 0;
                $pageUpdated = 0;
                $syncedAt = now();

                DB::transaction(function () use (
                    $account,
                    $cards,
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
        } catch (ConnectionException) {
            $failed++;
            $errors[] =
                'Не удалось соединиться с API Wildberries.';
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

    public function capabilities(): array
    {
        return [
            'connection_test' => true,
            'catalog_read' => true,
            'prices_read' => false,
            'stocks_read' => false,
            'orders_read' => false,
            'prices_write' => false,
            'stocks_write' => false,
        ];
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
