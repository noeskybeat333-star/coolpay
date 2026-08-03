<?php

namespace App\Filament\Resources\Products\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make(
                    'primaryMarketplaceListing.primary_image_url'
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
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable()
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

                TextColumn::make('purchase_price')
                    ->label('Закупка')
                    ->money('RUB')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('sale_price')
                    ->label('Цена продажи')
                    ->money('RUB')
                    ->sortable(),

                TextColumn::make('primaryMarketplaceListing.price')
                    ->label('Цена WB')
                    ->money('RUB')
                    ->placeholder('—'),

                TextColumn::make('stock_quantity')
                    ->label('Остаток')
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Опубликован')
                    ->boolean(),

                IconColumn::make('is_featured')
                    ->label('Рекомендуемый')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label('Изменить'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Удалить выбранные'),
                ]),
            ]);
    }
}
