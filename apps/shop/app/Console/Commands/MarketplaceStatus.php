<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceSyncLog;
use App\Services\MarketplaceCooldown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarketplaceStatus extends Command
{
    protected $signature = 'marketplace:status';

    protected $description =
        'Состояние интеграций: паузы кабинетов, очередь, последние синхронизации';

    public function handle(
        MarketplaceCooldown $cooldown,
    ): int {
        $accounts = MarketplaceAccount::query()
            ->where('is_active', true)
            ->with('integrationType')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('Активных подключений нет.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $title = $account->name
                .' ('.($account->integrationType?->name ?? '—').')';

            $this->line('');
            $this->line('<options=bold>'.$title.'</>');

            $left = $cooldown->secondsLeft($account);

            if ($left > 0) {
                $this->warn(
                    '  Пауза после отказа маркетплейса: осталось '
                    .$this->humanize($left)
                    .' (до '.now()->addSeconds($left)->format('H:i:s').')'
                );
            } else {
                $this->info('  Паузы нет, запросы разрешены.');
            }

            $last = MarketplaceSyncLog::query()
                ->where('marketplace_account_id', $account->getKey())
                ->orderByDesc('started_at')
                ->first();

            if ($last === null) {
                $this->line('  Синхронизаций ещё не было.');

                continue;
            }

            $this->line(
                '  Последняя: '
                .$last->started_at?->format('d.m.Y H:i:s')
                .'  '.$last->operation
                .'  ['.$last->status.']'
            );

            $this->line('  '.$last->message);
        }

        $this->line('');
        $this->line('<options=bold>Очередь</>');
        $this->line(
            '  Ожидают: '.DB::table('jobs')->count()
            .'   провалены: '.DB::table('failed_jobs')->count()
        );

        return self::SUCCESS;
    }

    private function humanize(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' с';
        }

        return intdiv($seconds, 60).' мин '
            .str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).' с';
    }
}
