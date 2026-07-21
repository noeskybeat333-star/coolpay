<?php

namespace App\Filament\Resources\MarketplaceListings\Tables;

use App\Models\MarketplaceListing;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MarketplaceListingsTable
{
    public static function configure(
        Table $table,
    ): Table {
        return $table
            ->columns([
                ImageColumn::make(
                    'primary_image_url'
                )
                    ->label('Фото')
                    ->imageSize(64)
                    ->square()
                    ->checkFileExistence(false)
                    ->extraImgAttributes([
                        'loading' => 'lazy',
                        'alt' => 'Фото товара',
                    ]),

                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(70),

                TextColumn::make(
                    'account.integrationType.name'
                )
                    ->label('Площадка')
                    ->badge(),

                TextColumn::make('account.name')
                    ->label('Подключение')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('external_id')
                    ->label('nmID')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('seller_sku')
                    ->label('Артикул продавца')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('brand')
                    ->label('Бренд')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('category')
                    ->label('Категория')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('barcode')
                    ->label('Штрихкод')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(
                        isToggledHiddenByDefault: true
                    ),

                TextColumn::make('product.name')
                    ->label('Наш товар')
                    ->placeholder('Не сопоставлен')
                    ->toggleable(),

                TextColumn::make('synced_at')
                    ->label('Синхронизировано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make(
                    'marketplace_account_id'
                )
                    ->label('Подключение')
                    ->relationship(
                        'account',
                        'name'
                    )
                    ->searchable()
                    ->preload(),

                SelectFilter::make('brand')
                    ->label('Бренд')
                    ->options(
                        fn (): array => MarketplaceListing::query()
                            ->whereNotNull('brand')
                            ->where('brand', '!=', '')
                            ->distinct()
                            ->orderBy('brand')
                            ->pluck('brand', 'brand')
                            ->all()
                    ),

                SelectFilter::make('category')
                    ->label('Категория')
                    ->options(
                        fn (): array => MarketplaceListing::query()
                            ->whereNotNull('category')
                            ->where(
                                'category',
                                '!=',
                                ''
                            )
                            ->distinct()
                            ->orderBy('category')
                            ->pluck(
                                'category',
                                'category'
                            )
                            ->all()
                    ),
            ])
            ->defaultSort('synced_at', 'desc')
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
