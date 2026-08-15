<?php

namespace App\Filament\Resources\CatalogCards\Pages;

use App\Filament\Resources\CatalogCards\CatalogCardResource;
use App\Models\ChannelPrice;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCatalogCards extends ListRecords
{
    protected static string $resource = CatalogCardResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Вкладка на канал: витрина CoolPay, Wildberries, Яндекс Маркет
     * и всё, что появится дальше. Список каналов не зашит — берётся
     * из самих данных, поэтому новая площадка появляется здесь сама,
     * как только по ней пришла первая карточка.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Все каналы')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query
                        ->where('status', '!=', 'archived'),
                )
                ->badge(fn (): int => ChannelPrice::query()
                    ->where('status', '!=', 'archived')
                    ->count()),
        ];

        $channels = ChannelPrice::query()
            ->where('status', '!=', 'archived')
            ->distinct()
            ->orderBy('marketplace_name')
            ->pluck('marketplace_name', 'marketplace_slug');

        foreach ($channels as $slug => $name) {
            $tabs[$slug] = Tab::make($name)
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query
                        ->where('marketplace_slug', $slug)
                        ->where('status', '!=', 'archived'),
                )
                ->badge(fn (): int => ChannelPrice::query()
                    ->where('marketplace_slug', $slug)
                    ->where('status', '!=', 'archived')
                    ->count());
        }

        // Карточки, у которых нет пары в CRM: канал их знает, а
        // остальные каналы про этот товар не в курсе. Это не список
        // дел, а список расхождений в артикулах.
        $tabs['unmatched'] = Tab::make('Без пары')
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->whereNull('product_id')
                    ->where('status', '!=', 'archived'),
            )
            ->badge(fn (): int => ChannelPrice::query()
                ->whereNull('product_id')
                ->where('status', '!=', 'archived')
                ->count());

        $tabs['archived'] = Tab::make('Архив')
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->where('status', 'archived'),
            )
            ->badge(fn (): int => ChannelPrice::query()
                ->where('status', 'archived')
                ->count());

        return $tabs;
    }
}
