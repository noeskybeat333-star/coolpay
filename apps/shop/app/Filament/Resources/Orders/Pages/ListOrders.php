<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\IntegrationType;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource =
        OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Вкладки по каналам продаж — та же логика, что на карточках.
     *
     * CRM собирает заказы отовсюду, и первый вопрос к списку обычно
     * «что пришло с этой площадки». Площадки берутся из данных, а не
     * зашиты: новая появится сама после первого импорта.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Все каналы')
                ->badge(fn (): int => Order::query()->count()),

            'storefront' => Tab::make('Витрина CoolPay')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query
                        ->where('source', Order::SOURCE_STOREFRONT),
                )
                ->badge(fn (): int => Order::query()
                    ->where('source', Order::SOURCE_STOREFRONT)
                    ->count()),
        ];

        $types = IntegrationType::query()
            ->whereHas('accounts.orders')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        foreach ($types as $type) {
            $scope = fn (Builder $query): Builder => $query
                ->whereHas(
                    'marketplaceAccount',
                    fn (Builder $accounts): Builder => $accounts
                        ->where(
                            'integration_type_id',
                            $type->getKey(),
                        ),
                );

            $tabs[$type->slug] = Tab::make($type->name)
                ->modifyQueryUsing($scope)
                ->badge(
                    fn (): int => $scope(Order::query())->count()
                );
        }

        // Требуют внимания: оформленные, но ещё не доведённые до
        // конца. Отменённые и выполненные сюда не попадают.
        $tabs['active'] = Tab::make('В работе')
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->whereNotIn(
                        'status',
                        ['completed', 'cancelled', 'returned'],
                    ),
            )
            ->badge(fn (): int => Order::query()
                ->whereNotIn(
                    'status',
                    ['completed', 'cancelled', 'returned'],
                )
                ->count())
            ->badgeColor('warning');

        return $tabs;
    }
}
