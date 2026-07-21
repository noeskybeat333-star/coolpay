<?php

namespace App\Filament\Resources\MarketplaceListings\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketplaceListingInfolist
{
    public static function configure(
        Schema $schema,
    ): Schema {
        return $schema
            ->components([
                Section::make('Изображение')
                    ->schema([
                        ImageEntry::make(
                            'primary_image_url'
                        )
                            ->label('Основное фото')
                            ->imageHeight(320)
                            ->square()
                            ->checkFileExistence(false)
                            ->extraImgAttributes([
                                'loading' => 'lazy',
                                'alt' => 'Фото товара',
                            ]),
                    ])
                    ->columnSpan(1),

                Section::make(
                    'Информация о карточке'
                )
                    ->schema([
                        TextEntry::make('name')
                            ->label('Название')
                            ->columnSpanFull(),

                        TextEntry::make(
                            'account.integrationType.name'
                        )
                            ->label('Маркетплейс')
                            ->badge(),

                        TextEntry::make('account.name')
                            ->label('Подключение'),

                        TextEntry::make('external_id')
                            ->label('nmID')
                            ->copyable(),

                        TextEntry::make('seller_sku')
                            ->label('Артикул продавца')
                            ->copyable()
                            ->placeholder('—'),

                        TextEntry::make('barcode')
                            ->label('Штрихкод')
                            ->copyable()
                            ->placeholder('—'),

                        TextEntry::make('brand')
                            ->label('Бренд')
                            ->placeholder('—'),

                        TextEntry::make('category')
                            ->label('Категория')
                            ->placeholder('—'),

                        TextEntry::make('product.name')
                            ->label('Связанный товар CRM')
                            ->placeholder(
                                'Пока не сопоставлен'
                            ),

                        TextEntry::make('synced_at')
                            ->label(
                                'Последняя синхронизация'
                            )
                            ->dateTime(
                                'd.m.Y H:i:s'
                            ),
                    ])
                    ->columns(2)
                    ->columnSpan(2),

                Section::make('Описание')
                    ->schema([
                        TextEntry::make('description')
                            ->label('Описание товара')
                            ->placeholder(
                                'Описание отсутствует'
                            )
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(3);
    }
}
