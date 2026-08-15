<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasConfigurableWidth;
use App\Models\IntegrationType;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/**
 * Заказы по каналам во времени.
 *
 * Линии, а не столбцы: вопрос здесь про изменение во времени, а не
 * про сравнение величин в одной точке. Ось одна — считаем количество
 * заказов; выручку на ту же ось класть нельзя, это разные величины
 * с разным масштабом, и совмещённые шкалы врут.
 */
class OrdersByChannelChart extends ChartWidget
{
    use HasConfigurableWidth;

    public static function configurableLabel(): string
    {
        return 'График заказов по каналам';
    }

    /**
     * Высоту холста ограничиваем только когда карточка не растянута
     * пользователем. Если высота задана, за размер отвечает CSS
     * дашборда: flexbox сам вычтет шапку, а вычислять её высоту
     * в пикселях — заведомо промахнуться, что и произошло.
     */
    public function getMaxHeight(): ?string
    {
        return static::configuredHeight() > 0
            ? null
            : '320px';
    }

    protected ?string $heading = 'Заказы по каналам';

    protected ?string $description =
        'Количество заказов по дням за выбранный период. '
        .'Отменённые не учитываются.';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30';

    /**
     * Категориальные цвета в фиксированном порядке: канал всегда
     * своего цвета, независимо от того, сколько каналов на графике.
     * Значения проверены валидатором на различимость при дальтонизме
     * и на светлой, и на тёмной подложке.
     */
    private const SERIES_COLORS = [
        '#2a78d6',
        '#eb6834',
        '#1baf7a',
        '#eda100',
        '#e87ba4',
    ];

    protected function getFilters(): ?array
    {
        return [
            '7' => '7 дней',
            '30' => '30 дней',
            '90' => '90 дней',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);

        $from = CarbonImmutable::now()
            ->subDays($days - 1)
            ->startOfDay();

        $labels = [];
        $buckets = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $from->addDays($i)->format('Y-m-d');

            $labels[] = $from->addDays($i)->format('d.m');
            $buckets[$date] = 0;
        }

        $datasets = [];
        $slot = 0;

        foreach ($this->channels() as $channel) {
            $rows = Order::query()
                ->where('placed_at', '>=', $from)
                ->whereNotIn('status', ['cancelled', 'returned'])
                ->tap($channel['scope'])
                ->selectRaw(
                    'date(placed_at) as day, count(*) as total'
                )
                ->groupBy('day')
                ->pluck('total', 'day');

            $series = $buckets;

            foreach ($rows as $day => $total) {
                $key = CarbonImmutable::parse($day)
                    ->format('Y-m-d');

                if (array_key_exists($key, $series)) {
                    $series[$key] = (int) $total;
                }
            }

            // Канал без единого заказа за период на графике только
            // мешает: лишняя плоская линия у нуля и лишний цвет.
            if (array_sum($series) === 0) {
                $slot++;

                continue;
            }

            $color = self::SERIES_COLORS[
                $slot % count(self::SERIES_COLORS)
            ];

            $datasets[] = [
                'label' => $channel['label'],
                'data' => array_values($series),
                'borderColor' => $color,
                'backgroundColor' => $color,
                'borderWidth' => 2,
                'pointRadius' => 0,
                'pointHoverRadius' => 5,
                'tension' => 0,
            ];

            $slot++;
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            // Холст занимает высоту контейнера, а не держит
            // собственные пропорции: иначе при растянутой карточке
            // график остаётся маленьким, а при узкой — вылезает.
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
        ];
    }

    /**
     * Каналы в устойчивом порядке: витрина первой, дальше площадки
     * по своему порядку сортировки. Порядок важен — от него зависит
     * цвет, а цвет не должен прыгать между обновлениями.
     *
     * @return array<int, array{label: string, scope: callable}>
     */
    private function channels(): array
    {
        $channels = [[
            'label' => 'Витрина CoolPay',
            'scope' => fn ($query) => $query->where(
                'source',
                Order::SOURCE_STOREFRONT,
            ),
        ]];

        $types = IntegrationType::query()
            ->whereHas('accounts')
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        foreach ($types as $type) {
            $channels[] = [
                'label' => $type->name,
                'scope' => fn ($query) => $query->whereIn(
                    'marketplace_account_id',
                    DB::table('marketplace_accounts')
                        ->where(
                            'integration_type_id',
                            $type->getKey(),
                        )
                        ->pluck('id'),
                ),
            ];
        }

        return $channels;
    }
}
