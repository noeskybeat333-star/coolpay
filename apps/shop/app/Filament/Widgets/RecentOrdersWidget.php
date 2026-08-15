<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasConfigurableWidth;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Последние заказы.
 *
 * Заодно закрывает требование доступности к графику выше: у цвета
 * аквы контраст к светлой подложке ниже 3:1, и рядом обязана быть
 * табличная подача тех же данных.
 */
class RecentOrdersWidget extends TableWidget
{
    use HasConfigurableWidth;

    public static function configurableLabel(): string
    {
        return 'Последние заказы';
    }

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Последние заказы')
            ->query(
                fn (): Builder => Order::query()
                    ->with(['marketplaceAccount.integrationType'])
                    ->latest('placed_at')
            )
            ->paginated([10, 25])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('placed_at')
                    ->label('Оформлен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable(),

                TextColumn::make('source')
                    ->label('Канал')
                    ->badge()
                    ->formatStateUsing(
                        fn (Order $record): string => $record->source
                            === Order::SOURCE_STOREFRONT
                                ? 'CoolPay'
                                : ($record->marketplaceAccount
                                    ?->integrationType
                                    ?->name ?? 'Маркетплейс'),
                    ),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state): string => Order::statusOptions(
                        )[$state] ?? (string) $state,
                    ),

                TextColumn::make('total')
                    ->label('Сумма')
                    ->money('RUB')
                    ->sortable(),
            ]);
    }
}
