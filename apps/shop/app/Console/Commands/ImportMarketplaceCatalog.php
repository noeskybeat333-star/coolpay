<?php

namespace App\Console\Commands;

use App\Integrations\Exceptions\MarketplaceRateLimitException;
use App\Jobs\SyncMarketplaceCatalog;
use App\Models\MarketplaceAccount;
use App\Services\MarketplaceCatalogSyncRunner;
use App\Services\MarketplaceCooldown;
use Illuminate\Console\Command;

class ImportMarketplaceCatalog extends Command
{
    protected $signature = 'marketplace:import-catalog
        {--account=* : ID подключений, по умолчанию все активные}
        {--sync : Выполнить сразу, минуя очередь (для отладки)}';

    protected $description =
        'Импортирует карточки маркетплейсов в CRM';

    public function handle(
        MarketplaceCatalogSyncRunner $runner,
        MarketplaceCooldown $cooldown,
    ): int {
        $accountIds = array_filter(
            (array) $this->option('account')
        );

        $accounts = MarketplaceAccount::query()
            ->where('is_active', true)
            ->when(
                $accountIds !== [],
                fn ($query) => $query->whereIn('id', $accountIds),
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

            if (! $runner->supportsAccount($account)) {
                $this->line(
                    $title.' — импорт каталога не поддерживается, пропуск.'
                );

                continue;
            }

            $left = $cooldown->secondsLeft($account);

            if ($left > 0) {
                $this->warn(
                    $title.' — кабинет на паузе после отказа '
                    .'маркетплейса, осталось '.$left.' с. Пропуск.'
                );

                continue;
            }

            if (! $this->option('sync')) {
                SyncMarketplaceCatalog::dispatch($account->getKey());

                $this->info($title.' — задача поставлена в очередь.');

                continue;
            }

            // При лимите частоты runner пробрасывает исключение наружу,
            // чтобы задача в очереди ушла на повтор. В консоли повторять
            // нечему — показываем причину и идём дальше.
            try {
                $result = $runner->run($account);
            } catch (MarketplaceRateLimitException $exception) {
                $this->error($title.' — '.$exception->getMessage());

                $exitCode = self::FAILURE;

                continue;
            }

            $message = $runner->describe($result);

            if ($result->failed > 0) {
                $this->error($title.' — '.$message);

                $exitCode = self::FAILURE;
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
