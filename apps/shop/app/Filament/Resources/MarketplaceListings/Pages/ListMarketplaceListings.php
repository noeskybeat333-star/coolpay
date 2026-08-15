<?php

namespace App\Filament\Resources\MarketplaceListings\Pages;

use App\Filament\Resources\MarketplaceListings\MarketplaceListingResource;
use App\Models\IntegrationType;
use App\Models\MarketplaceListing;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMarketplaceListings extends ListRecords
{
    protected static string $resource =
        MarketplaceListingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Вкладки по источникам: CRM собирает карточки из разных каналов,
     * и первое, что нужно человеку, — смотреть их по площадкам.
     *
     * Список площадок берётся из тех, по которым карточки реально
     * есть: вкладка «Ozon» без единой карточки только мешает.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        // По умолчанию архив не показываем: это карточки, которых на
        // площадке уже нет, и в повседневной работе они только шумят.
        $tabs = [
            'all' => Tab::make('Активные')
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query
                        ->where('status', '!=', 'archived'),
                )
                ->badge(
                    fn (): int => MarketplaceListing::query()
                        ->where('status', '!=', 'archived')
                        ->count()
                ),
        ];

        $types = IntegrationType::query()
            ->whereHas('accounts.listings')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        foreach ($types as $type) {
            $tabs[$type->slug] = Tab::make($type->name)
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query
                        ->whereHas(
                            'account',
                            fn (Builder $accounts): Builder => $accounts
                                ->where(
                                    'integration_type_id',
                                    $type->getKey(),
                                ),
                        ),
                )
                ->badge(
                    fn (): int => MarketplaceListing::query()
                        ->whereHas(
                            'account',
                            fn (Builder $accounts): Builder => $accounts
                                ->where(
                                    'integration_type_id',
                                    $type->getKey(),
                                ),
                        )
                        ->count(),
                );
        }

        // Связывание происходит само, по совпадению артикула. Эта
        // вкладка — не список дел, а список расхождений: сюда попадает
        // то, что не сошлось, то есть повод поправить артикул.
        $tabs['unlinked'] = Tab::make('Артикул не сошёлся')
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->whereNull('product_id'),
            )
            ->badge(
                fn (): int => MarketplaceListing::query()
                    ->whereNull('product_id')
                    ->count(),
            );

        $tabs['archived'] = Tab::make('Архив')
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->where('status', 'archived'),
            )
            ->badge(
                fn (): int => MarketplaceListing::query()
                    ->where('status', 'archived')
                    ->count(),
            );

        $tabs['linked'] = Tab::make('Связанные')
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query
                    ->whereNotNull('product_id'),
            )
            ->badge(
                fn (): int => MarketplaceListing::query()
                    ->whereNotNull('product_id')
                    ->count(),
            );

        return $tabs;
    }
}
