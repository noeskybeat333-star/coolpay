<?php

namespace App\Services;

use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Jobs\SyncMarketplacePrices;
use App\Integrations\MarketplaceDriverManager;
use App\Integrations\Results\CatalogImportResult;
use App\Models\MarketplaceAccount;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Единая точка запуска импорта каталога — брат-близнец
 * MarketplaceOrderSyncRunner.
 *
 * Раньше эта логика жила прямо в замыкании кнопки Filament, из-за чего
 * запустить импорт можно было только из браузера, а любой сбой на середине
 * терял всю страницу карточек.
 */
class MarketplaceCatalogSyncRunner
{
    public const OPERATION = 'catalog_import';

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

        if (
            ! (
                $driver->capabilities()['catalog_read'] ?? false
            )
        ) {
            return new CatalogImportResult(
                failed: 1,
                errors: [
                    'Импорт каталога для площадки «'
                        .$integrationType->name
                        .'» пока не реализован.',
                ],
            );
        }

        // Общая пауза на кабинет — см. комментарий в MarketplaceCooldown.
        $cooldown = $this->cooldown->secondsLeft($account);

        if ($cooldown > 0) {
            throw new MarketplaceRateLimitException(
                'Кабинет на паузе после отказа Wildberries.',
                $cooldown,
            );
        }

        $lock = Cache::lock(
            'marketplace-catalog-import:'.$account->getKey(),
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            return new CatalogImportResult(
                failed: 0,
                errors: [
                    'Импорт каталога для этого подключения уже выполняется.',
                ],
            );
        }

        $log = $account->syncLogs()->create([
            'operation' => self::OPERATION,
            'status' => 'running',
            'message' => 'Импорт каталога запущен.',
            'started_at' => now(),
        ]);

        try {
            $result = $driver->importCatalog($account);

            // Карточки дошли, а цены нет — догоняем их отдельной
            // задачей. Она пойдёт только по сохранённым карточкам и
            // при отказе будет повторять один запрос, а не весь импорт.
            if ($result->pricesDeferred && $result->failed === 0) {
                // Отказ по ценам всё равно означает, что кабинет
                // упёрся в лимит. Драйвер проглотил исключение, чтобы
                // спасти карточки, но объявить паузу на кабинет обязаны
                // мы — иначе планировщик заказов продолжит ходить в WB
                // и продлит блокировку всем остальным.
                if ($result->pricesRetryAfterSeconds > 0) {
                    $this->cooldown->start(
                        $account,
                        $result->pricesRetryAfterSeconds,
                    );
                }

                SyncMarketplacePrices::dispatch($account->getKey());
            }

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
            // Не ошибка, а «приходите позже»: помечаем повтором
            // и отдаём наверх, чтобы джоба вернула задачу в очередь.
            // Паузу объявляем на весь кабинет, а не только для себя.
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
                'details' => [
                    'exception' => $exception::class,
                ],
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
            return (bool) (
                $this->drivers
                    ->for($integrationType)
                    ->capabilities()['catalog_read'] ?? false
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function describe(
        CatalogImportResult $result,
    ): string {
        if ($result->failed > 0) {
            return $result->errors[0]
                ?? 'Импорт каталога завершился с ошибками.';
        }

        $message = sprintf(
            'Получено: %d, создано: %d, обновлено: %d.',
            $result->received,
            $result->created,
            $result->updated,
        );

        if ($result->pricesDeferred) {
            $message .= ' Цены и остатки не получены из-за лимита '
                .'маркетплейса, поставлена отдельная задача.';
        }

        return $message;
    }
}
