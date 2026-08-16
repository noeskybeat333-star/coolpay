<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\MarketplaceAccount;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(
        Table $table,
    ): Table {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold')
                    // Свой номер нужен CRM: он сквозной для всех каналов
                    // и не зависит от площадки. Но сверять заказ с
                    // кабинетом по нему нельзя, поэтому рядом всегда
                    // виден номер площадки.
                    ->description(
                        fn (Order $record): ?string =>
                            $record->external_number
                    ),

                TextColumn::make('source')
                    ->label('Канал')
                    ->badge()
                    ->formatStateUsing(
                        function (Order $record): string {
                            if (
                                $record->source
                                === Order::SOURCE_STOREFRONT
                            ) {
                                return 'CoolPay';
                            }

                            return $record
                                ->marketplaceAccount
                                ?->integrationType
                                ?->name
                                ?? 'Маркетплейс';
                        }
                    )
                    ->description(
                        fn (Order $record): ?string =>
                            $record->source
                                === Order::SOURCE_MARKETPLACE
                                ? $record
                                    ->marketplaceAccount
                                    ?->name
                                : null
                    )
                    ->color(
                        fn (?string $state): string =>
                            $state
                                === Order::SOURCE_STOREFRONT
                                ? 'primary'
                                : 'warning'
                    ),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state): string =>
                            Order::statusOptions()[$state]
                            ?? $state
                            ?? '—'
                    )
                    ->color(
                        fn (?string $state): string => match ($state) {
                            Order::STATUS_NEW => 'info',
                            Order::STATUS_CONFIRMED => 'primary',
                            Order::STATUS_PROCESSING => 'warning',
                            Order::STATUS_SHIPPED => 'gray',
                            Order::STATUS_COMPLETED => 'success',
                            Order::STATUS_CANCELLED => 'danger',
                            default => 'gray',
                        }
                    )
                    ->sortable(),

                TextColumn::make('payment_status')
                    ->label('Оплата')
                    ->badge()
                    ->formatStateUsing(
                        fn (?string $state): string =>
                            Order::paymentStatusOptions()[$state]
                            ?? $state
                            ?? '—'
                    )
                    ->color(
                        fn (?string $state): string => match ($state) {
                            Order::PAYMENT_PAID => 'success',
                            Order::PAYMENT_FAILED => 'danger',
                            Order::PAYMENT_REFUNDED => 'warning',
                            default => 'gray',
                        }
                    )
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Покупатель')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('customer_phone')
                    ->label('Телефон')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('delivery_method')
                    ->label('Получение')
                    ->formatStateUsing(
                        fn (?string $state): string =>
                            Order::deliveryMethodOptions()[$state]
                            ?? $state
                            ?? '—'
                    ),

                TextColumn::make('fulfillment_type')
                    ->label('Схема')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('items_count')
                    ->label('Позиций')
                    ->counts('items')
                    ->numeric(),

                TextColumn::make('total')
                    ->label('Сумма')
                    ->money('RUB')
                    ->sortable(),

                TextColumn::make('external_number')
                    ->label('Номер площадки')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make('placed_at')
                    ->label('Оформлен')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Источник')
                    ->options(Order::sourceOptions()),

                SelectFilter::make(
                    'marketplace_account_id'
                )
                    ->label('Кабинеты маркетплейсов')
                    ->options(
                        fn (): array =>
                            MarketplaceAccount::query()
                                ->with('integrationType')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(
                                    function (
                                        MarketplaceAccount $account,
                                    ): array {
                                        $platform =
                                            $account
                                                ->integrationType
                                                ?->name
                                            ?? 'Маркетплейс';

                                        return [
                                            $account->getKey() =>
                                                $platform
                                                .' — '
                                                .$account->name,
                                        ];
                                    }
                                )
                                ->all()
                    )
                    ->multiple()
                    ->searchable()
                    ->preload(),

                SelectFilter::make('status')
                    ->label('Статус заказа')
                    ->options(Order::statusOptions()),

                SelectFilter::make('payment_status')
                    ->label('Статус оплаты')
                    ->options(
                        Order::paymentStatusOptions()
                    ),

                SelectFilter::make('delivery_method')
                    ->label('Способ получения')
                    ->options(
                        Order::deliveryMethodOptions()
                    ),
            ])
            ->defaultSort('placed_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->label('Открыть'),
            ])
            ->paginationPageOptions([
                25,
                50,
                100,
            ]);
    }
}
