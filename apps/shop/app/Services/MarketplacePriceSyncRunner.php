<?php

namespace App\Services;

use App\Integrations\Contracts\ImportsMarketplacePrices;
use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Integrations\MarketplaceDriverManager;
use App\Integrations\Results\CatalogImportResult;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Обновление цен и остатков по уже сохранённым карточкам.
 *
 * Отделено от импорта каталога намеренно. Цены живут в
 * discounts-prices-api, остатки — в marketplace-api, карточки — в
 * content-api, и лимитеры у них разные. Пока всё это было одной
 * операцией, отказ по ценам обнулял и карточки, а каждый повтор заново
 * выкачивал их целиком. Измерено 13.08.2026: восемь попыток подряд
 * тянули карточки успешно и падали на ценах, разогревая общий лимитер.
 */
class MarketplacePriceSyncRunner
{
    public const OPERATION = 'prices_import';

    private const LOCK_SECONDS = 1800;

    public function __construct(
        private readonly MarketplaceDriverManager $drivers,
        private readonly MarketplaceCooldown $cooldown,
    ) {
    }

    public function run(
        MarketplaceAccount $account,
    ): CatalogImportResult {
        $integrationType = $account->integrationType;

        if ($integrationType === null) {
            return new CatalogImportResult(
                failed: 1,
                errors: ['Для подключения не выбрана площадка.'],
            );
        }

        try {
            $driver = $this->drivers->for($integrationType);
        } catch (Throwable $exception) {
            return new CatalogImportResult(
                failed: 1,
                errors: [$exception->getMessage()],
            );
        }

        if (! $driver instanceof ImportsMarketplacePrices) {
            return new CatalogImportResult(
                failed: 1,
                errors: [
                    'Обновление цен для площадки «'
                        .$integrationType->name
                        .'» пока не реализовано.',
                ],
            );
        }

        $cooldown = $this->cooldown->secondsLeft($account);

        if ($cooldown > 0) {
            throw new MarketplaceRateLimitException(
                'Кабинет на паузе после отказа маркетплейса.',
                $cooldown,
            );
        }

        $lock = Cache::lock(
            'marketplace-price-import:'.$account->getKey(),
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            return new CatalogImportResult(
                failed: 0,
                errors: [
                    'Обновление цен для этого подключения уже выполняется.',
                ],
            );
        }

        $log = $account->syncLogs()->create([
            'operation' => self::OPERATION,
            'status' => 'running',
            'message' => 'Обновление цен и остатков запущено.',
            'started_at' => now(),
        ]);

        try {
            $result = $driver->importPrices($account);

            $log->update([
                'status' => $result->failed === 0
                    ? 'success'
                    : 'failed',
                'received_count' => $result->received,
                'created_count' => $result->created,
                'updated_count' => $result->updated,
                'failed_count' => $result->failed,
                'message' => $this->describe($result),
                'details' => ['errors' => $result->errors],
                'finished_at' => now(),
            ]);

            return $result;
        } catch (MarketplaceRateLimitException $exception) {
            $this->cooldown->start(
                $account,
                $exception->retryAfterSeconds,
            );

            $log->update([
                'status' => 'retrying',
                'message' => $exception->getMessage()
                    .' Повтор произойдёт автоматически.',
                'finished_at' => now(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $log->update([
                'status' => 'failed',
                'failed_count' => 1,
                'message' => $exception->getMessage(),
                'details' => ['exception' => $exception::class],
                'finished_at' => now(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function supportsAccount(
        MarketplaceAccount $account,
    ): bool {
        $integrationType = $account->integrationType;

        if ($integrationType === null) {
            return false;
        }

        try {
            return $this->drivers->for($integrationType)
                instanceof ImportsMarketplacePrices;
        } catch (Throwable) {
            return false;
        }
    }

    public function describe(
        CatalogImportResult $result,
    ): string {
        if ($result->failed > 0) {
            return $result->errors[0]
                ?? 'Обновление цен завершилось с ошибками.';
        }

        if ($result->received === 0) {
            return $result->errors[0]
                ?? 'Обновлять нечего: карточек нет.';
        }

        return sprintf(
            'Обработано карточек: %d, обновлено: %d.',
            $result->received,
            $result->updated,
        );
    }
}
