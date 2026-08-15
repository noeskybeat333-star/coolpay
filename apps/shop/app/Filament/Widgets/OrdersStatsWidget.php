<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasConfigurableWidth;
use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrdersStatsWidget extends StatsOverviewWidget
{
    use HasConfigurableWidth;

    public static function configurableLabel(): string
    {
        return 'Счётчики заказов';
    }

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    /**
     * Отменённые исключаются из выручки везде: это не продажи, а
     * несостоявшиеся заказы, и в среднем чеке они дают заниженную
     * картину.
     */
    private const EXCLUDED = ['cancelled', 'returned'];

    protected function getStats(): array
    {
        $since = now()->subDays(30);

        $sold = Order::query()
            ->where('placed_at', '>=', $since)
            ->whereNotIn('status', self::EXCLUDED);

        $count = (clone $sold)->count();
        $revenue = (float) (clone $sold)->sum('total');

        $inProgress = Order::query()
            ->whereNotIn(
                'status',
                ['completed', 'cancelled', 'returned'],
            )
            ->count();

        $cancelled = Order::query()
            ->where('placed_at', '>=', $since)
            ->where('status', 'cancelled')
            ->count();

        return [
            Stat::make('Заказов за 30 дней', (string) $count)
                ->description('Без отменённых и возвратов')
                ->color('primary'),

            Stat::make(
                'Выручка за 30 дней',
                $this->money($revenue),
            )
                ->description('Сумма по всем каналам')
                ->color('success'),

            Stat::make(
                'Средний чек',
                $count > 0
                    ? $this->money($revenue / $count)
                    : '—',
            )
                ->description('Выручка ÷ количество заказов'),

            Stat::make('В работе', (string) $inProgress)
                ->description(
                    $cancelled > 0
                        ? 'Отменено за 30 дней: '.$cancelled
                        : 'Отмен за 30 дней нет'
                )
                ->color($inProgress > 0 ? 'warning' : 'gray'),
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 0, ',', ' ').' ₽';
    }
}
