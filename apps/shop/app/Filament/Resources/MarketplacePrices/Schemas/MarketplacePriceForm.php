<?php

namespace App\Filament\Resources\MarketplacePrices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketplacePriceForm
{
    public static function configure(
        Schema $schema,
    ): Schema {
        return $schema
            ->components([
                Section::make('Товар и магазин')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Название товара')
                            ->columnSpanFull(),

                        TextEntry::make(
                            'account.integrationType.name'
                        )
                            ->label('Маркетплейс')
                            ->badge(),

                        TextEntry::make('account.name')
                            ->label('Подключение'),

                        TextEntry::make('seller_sku')
                            ->label('Артикул продавца')
                            ->copyable()
                            ->placeholder('—'),

                        TextEntry::make('external_id')
                            ->label('ID карточки')
                            ->copyable(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Цена')
                    ->description(
                        'Цена продажи рассчитывается '
                        .'из цены до скидки и скидки продавца.'
                    )
                    ->schema([
                        TextInput::make('base_price')
                            ->label('Цена до скидки')
                            ->required()
                            ->numeric()
                            ->prefix('₽')
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(
                                fn (
                                    callable $get,
                                    callable $set,
                                ) => self::calculateSalePrice(
                                    $get,
                                    $set,
                                )
                            ),

                        TextInput::make(
                            'discount_percent'
                        )
                            ->label('Скидка продавца')
                            ->required()
                            ->numeric()
                            ->suffix('%')
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(
                                fn (
                                    callable $get,
                                    callable $set,
                                ) => self::calculateSalePrice(
                                    $get,
                                    $set,
                                )
                            ),

                        TextInput::make('price')
                            ->label('Цена после скидки')
                            ->required()
                            ->numeric()
                            ->prefix('₽')
                            ->readOnly()
                            ->helperText(
                                'Рассчитывается автоматически.'
                            ),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Синхронизация')
                    ->schema([
                        TextEntry::make('synced_at')
                            ->label(
                                'Последние данные магазина'
                            )
                            ->dateTime('d.m.Y H:i:s')
                            ->placeholder('Ещё не синхронизировано'),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function calculateSalePrice(
        callable $get,
        callable $set,
    ): void {
        $basePrice = $get('base_price');

        if (
            $basePrice === null
            || $basePrice === ''
        ) {
            $set('price', null);

            return;
        }

        $discount = (float) (
            $get('discount_percent') ?? 0
        );

        $discount = min(
            100,
            max(0, $discount)
        );

        $salePrice = (float) $basePrice
            * (100 - $discount)
            / 100;

        $set(
            'price',
            round($salePrice, 2)
        );
    }
}
