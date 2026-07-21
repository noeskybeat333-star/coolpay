<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(
                        fn (?string $state, callable $set) =>
                            $set('slug', Str::slug($state ?? ''))
                    ),

                TextInput::make('slug')
                    ->label('Адрес страницы')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                TextInput::make('sku')
                    ->label('Артикул')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                TextInput::make('brand')
                    ->label('Бренд')
                    ->maxLength(255),

                TextInput::make('category')
                    ->label('Категория')
                    ->maxLength(255),

                Textarea::make('description')
                    ->label('Описание')
                    ->rows(5)
                    ->columnSpanFull(),

                TextInput::make('purchase_price')
                    ->label('Закупочная цена')
                    ->numeric()
                    ->prefix('₽')
                    ->minValue(0),

                TextInput::make('sale_price')
                    ->label('Цена продажи')
                    ->required()
                    ->numeric()
                    ->prefix('₽')
                    ->minValue(0),

                TextInput::make('stock_quantity')
                    ->label('Остаток')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->minValue(0),

                Toggle::make('is_active')
                    ->label('Опубликован')
                    ->default(true),

                Toggle::make('is_featured')
                    ->label('Рекомендуемый товар')
                    ->default(false),
            ])
            ->columns(2);
    }
}
