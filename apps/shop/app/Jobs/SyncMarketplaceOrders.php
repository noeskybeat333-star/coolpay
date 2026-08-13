<?php

namespace App\Jobs;

use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Models\MarketplaceAccount;
use App\Services\MarketplaceOrderSyncRunner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMarketplaceOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // См. комментарий в SyncMarketplaceCatalog: паузы при лимите длинные.
    public int $tries = 8;

    public int $timeout = 900;

    /**
     * Глубина передаётся числом дней, а не объектом даты: так задача
     * остаётся корректной, даже если полежит в очереди несколько часов.
     */
    public function __construct(
        public readonly int $accountId,
        public readonly int $days = 30,
    ) {
    }

    public function uniqueId(): string
    {
        return 'orders:'.$this->accountId;
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
        MarketplaceOrderSyncRunner $runner,
    ): void {
        $account = MarketplaceAccount::query()
            ->with('integrationType')
            ->find($this->accountId);

        if ($account === null) {
            return;
        }

        try {
            $runner->run(
                $account,
                CarbonImmutable::now()->subDays(
                    max(1, $this->days)
                ),
            );
        } catch (MarketplaceRateLimitException $exception) {
            $this->release($exception->retryAfterSeconds);
        }
    }
}
