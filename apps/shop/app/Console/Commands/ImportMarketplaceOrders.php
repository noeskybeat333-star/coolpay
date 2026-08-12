<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Services\MarketplaceOrderSyncRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ImportMarketplaceOrders extends Command
{
    protected $signature = 'marketplace:import-orders
        {--account=* : ID подключений, по умолчанию все активные}
        {--days=30 : Глубина импорта в днях}';

    protected $description =
        'Импортирует заказы маркетплейсов в CRM';

    public function handle(
        MarketplaceOrderSyncRunner $runner,
    ): int {
        $days = max(1, (int) $this->option('days'));

        $since = CarbonImmutable::now()->subDays($days);

        $accountIds = array_filter(
            (array) $this->option('account')
        );

        $accounts = MarketplaceAccount::query()
            ->where('is_active', true)
            ->when(
                $accountIds !== [],
                fn ($query) => $query->whereIn(
                    'id',
                    $accountIds,
                ),
            )
            ->with('integrationType')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn(
                'Активные подключения маркетплейсов не найдены.'
            );

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        foreach ($accounts as $account) {
            $title = $account->name
                .' ('.($account->integrationType?->name ?? '—').')';

            // Подключения без драйвера заказов пропускаем молча:
            // планировщик ходит часто, и писать им ошибку каждый раз
            // означало бы засорять журнал синхронизаций.
            if (! $runner->supportsAccount($account)) {
                $this->line(
                    $title.' — импорт заказов не поддерживается, пропуск.'
                );

                continue;
            }

            $result = $runner->run($account, $since);

            $message = $runner->describe($result);

            if ($result->failed > 0) {
                $this->error($title.' — '.$message);

                $exitCode = self::FAILURE;
            } elseif ($result->skipped > 0 && $result->received === 0) {
                $this->warn($title.' — '.$message);
            } else {
                $this->info($title.' — '.$message);
            }

            foreach ($result->errors as $error) {
                $this->line('    '.$error);
            }
        }

        return $exitCode;
    }
}
