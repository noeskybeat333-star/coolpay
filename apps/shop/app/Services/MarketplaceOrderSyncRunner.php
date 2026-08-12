<?php

namespace App\Services;

use App\Integrations\Contracts\ImportsMarketplaceOrders;
use App\Integrations\MarketplaceDriverManager;
use App\Integrations\Results\OrderImportResult;
use App\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Единая точка запуска импорта заказов маркетплейса.
 *
 * Используется и кнопкой в Filament, и планировщиком, чтобы блокировка,
 * журналирование и разбор результата были одинаковыми независимо от того,
 * кто запустил синхронизацию.
 */
class MarketplaceOrderSyncRunner
{
    public const OPERATION = 'orders_import';

    private const LOCK_SECONDS = 900;

    public function __construct(
        private readonly MarketplaceDriverManager $drivers,
    ) {
    }

    public function run(
        MarketplaceAccount $account,
        ?CarbonImmutable $since = null,
    ): OrderImportResult {
        $integrationType = $account->integrationType;

        if ($integrationType === null) {
            return new OrderImportResult(
                failed: 1,
                errors: ['Для подключения не выбрана площадка.'],
            );
        }

        try {
            $driver = $this->drivers->for($integrationType);
        } catch (Throwable $exception) {
            return new OrderImportResult(
                failed: 1,
                errors: [$exception->getMessage()],
            );
        }

        if (! $this->supportsOrders($driver)) {
            return new OrderImportResult(
                failed: 1,
                errors: [
                    'Импорт заказов для площадки «'
                        .$integrationType->name
                        .'» пока не реализован.',
                ],
            );
        }

        $lock = Cache::lock(
            'marketplace-orders-import:'.$account->getKey(),
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            return new OrderImportResult(
                skipped: 1,
                errors: [
                    'Импорт заказов для этого подключения уже выполняется.',
                ],
            );
        }

        $log = $account->syncLogs()->create([
            'operation' => self::OPERATION,
            'status' => 'running',
            'message' => 'Импорт заказов запущен.',
            'started_at' => now(),
        ]);

        try {
            $result = $driver->importOrders($account, $since);

            $log->update([
                'status' => $result->failed === 0
                    ? 'success'
                    : 'failed',
                'received_count' => $result->received,
                'created_count' => $result->created,
                'updated_count' => $result->updated,
                'failed_count' => $result->failed,
                'message' => $this->describe($result),
                'details' => [
                    'skipped' => $result->skipped,
                    'errors' => $result->errors,
                    'since' => $since?->toIso8601String(),
                ],
                'finished_at' => now(),
            ]);

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            $log->update([
                'status' => 'failed',
                'failed_count' => 1,
                'message' => 'Внутренняя ошибка импорта заказов.',
                'details' => [
                    'exception' => $exception->getMessage(),
                ],
                'finished_at' => now(),
            ]);

            return new OrderImportResult(
                failed: 1,
                errors: ['Внутренняя ошибка импорта заказов.'],
            );
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
            return $this->supportsOrders(
                $this->drivers->for($integrationType)
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function supportsOrders(mixed $driver): bool
    {
        if (! $driver instanceof ImportsMarketplaceOrders) {
            return false;
        }

        return (bool) (
            $driver->capabilities()['orders_read'] ?? false
        );
    }

    public function describe(OrderImportResult $result): string
    {
        if ($result->skipped > 0 && $result->received === 0) {
            return $result->errors[0]
                ?? 'Импорт пропущен.';
        }

        if ($result->failed > 0) {
            return $result->errors[0]
                ?? 'Импорт заказов завершился с ошибками.';
        }

        return sprintf(
            'Получено: %d, создано: %d, обновлено: %d.',
            $result->received,
            $result->created,
            $result->updated,
        );
    }
}
