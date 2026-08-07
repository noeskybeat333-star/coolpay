<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(
        Schema $schema,
    ): Schema {
        return $schema
            ->components([
                Section::make('Заказ')
                    ->schema([
                        TextEntry::make('number')
                            ->label('Номер заказа')
                            ->copyable()
                            ->weight('bold'),

                        TextEntry::make('placed_at')
                            ->label('Дата оформления')
                            ->dateTime('d.m.Y H:i:s'),

                        TextEntry::make('status')
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
                            ),

                        TextEntry::make('payment_status')
                            ->label('Статус оплаты')
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
                            ),

                        TextEntry::make('delivery_method')
                            ->label('Способ получения')
                            ->formatStateUsing(
                                fn (?string $state): string =>
                                    Order::deliveryMethodOptions()[$state]
                                    ?? $state
                                    ?? '—'
                            ),

                        TextEntry::make('payment_method')
                            ->label('Способ оплаты')
                            ->formatStateUsing(
                                fn (?string $state): string =>
                                    Order::paymentMethodOptions()[$state]
                                    ?? $state
                                    ?? '—'
                            ),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Покупатель')
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label('Имя'),

                        TextEntry::make('customer_phone')
                            ->label('Телефон')
                            ->copyable(),

                        TextEntry::make('customer_email')
                            ->label('Email')
                            ->copyable()
                            ->placeholder('Не указан'),

                        TextEntry::make('delivery_address')
                            ->label('Адрес доставки')
                            ->placeholder('Самовывоз')
                            ->columnSpanFull(),

                        TextEntry::make('customer_comment')
                            ->label('Комментарий')
                            ->placeholder('Комментарий отсутствует')
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Товары')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product_name')
                                    ->label('Товар')
                                    ->weight('bold')
                                    ->columnSpan(2),

                                TextEntry::make('product_sku')
                                    ->label('Артикул')
                                    ->placeholder('—'),

                                TextEntry::make('quantity')
                                    ->label('Количество')
                                    ->suffix(' шт.'),

                                TextEntry::make('unit_price')
                                    ->label('Цена')
                                    ->money('RUB'),

                                TextEntry::make('total')
                                    ->label('Сумма')
                                    ->money('RUB'),
                            ])
                            ->columns(6)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Суммы')
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('Товары')
                            ->money('RUB'),

                        TextEntry::make('delivery_price')
                            ->label('Доставка')
                            ->money('RUB'),

                        TextEntry::make('total')
                            ->label('Итого')
                            ->money('RUB')
                            ->weight('bold'),

                        TextEntry::make('stock_restored_at')
                            ->label('Остаток возвращён')
                            ->dateTime('d.m.Y H:i:s')
                            ->placeholder('Нет'),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),
            ]);
    }
}
