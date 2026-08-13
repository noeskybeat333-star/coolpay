<?php

namespace App\Jobs;

use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Models\MarketplaceAccount;
use App\Services\MarketplaceCatalogSyncRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMarketplaceCatalog implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Пауза при лимите берётся из заголовка ответа WB и доходит до ~400 с,
    // поэтому попыток нужно больше: восемь дают около часа терпения.
    public int $tries = 8;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $accountId,
    ) {
    }

    /**
     * Две задачи на один кабинет не имеют смысла: вторая всё равно
     * упрётся в блокировку внутри runner'а.
     */
    public function uniqueId(): string
    {
        return 'catalog:'.$this->accountId;
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
        MarketplaceCatalogSyncRunner $runner,
    ): void {
        $account = MarketplaceAccount::query()
            ->with('integrationType')
            ->find($this->accountId);

        if ($account === null) {
            return;
        }

        try {
            $runner->run($account);
        } catch (MarketplaceRateLimitException $exception) {
            // Лимит частоты — не провал, а «позже».
            // Возвращаем задачу в очередь, не расходуя попытку зря.
            $this->release($exception->retryAfterSeconds);
        }
    }
}
