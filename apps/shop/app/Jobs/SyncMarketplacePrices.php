<?php

namespace App\Jobs;

use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Models\MarketplaceAccount;
use App\Services\MarketplacePriceSyncRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMarketplacePrices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Одна попытка, без повторов.
     *
     * У Wildberries лимит на API цен устроен так, что отказанный
     * запрос перезапускает окно ожидания: повторы не приближают
     * успех, а отодвигают его. Поэтому задача либо проходит сразу,
     * либо честно пишет отказ в журнал и заканчивается — решение
     * повторить принимает человек.
     */
    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $accountId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'prices:'.$this->accountId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [120, 600, 1800];
    }

    public function handle(
        MarketplacePriceSyncRunner $runner,
    ): void {
        $account = MarketplaceAccount::query()
            ->with('integrationType')
            ->find($this->accountId);

        if ($account === null) {
            return;
        }

        try {
            $runner->run($account);
        } catch (MarketplaceRateLimitException) {
            // Отказ уже записан в журнал синхронизаций runner'ом.
            // Возвращать задачу в очередь нечего: следующая попытка
            // лишь отодвинет окно. Завершаемся молча.
        }
    }
}
