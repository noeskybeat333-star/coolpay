<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ImageEntry::make('image_urls')
                    ->label('Изображения товара')
                    ->imageHeight(180)
                    ->square()
                    ->checkFileExistence(false)
                    ->extraImgAttributes([
                        'loading' => 'lazy',
                        'alt' => 'Изображение товара',
                        'style' => 'cursor: zoom-in;',
                        'x-on:click.prevent.stop' => <<<'JS'
                            const source = event.currentTarget.src;
                            const oldOverflow = document.body.style.overflow;

                            const overlay = document.createElement('div');
                            overlay.style.cssText = [
                                'position: fixed',
                                'inset: 0',
                                'z-index: 9999',
                                'display: flex',
                                'align-items: center',
                                'justify-content: center',
                                'padding: 32px',
                                'background: transparent',
                                'cursor: zoom-out'
                            ].join(';');

                            const image = document.createElement('img');
                            image.src = source;
                            image.alt = 'Изображение товара';
                            image.style.cssText = [
                                'max-width: 95vw',
                                'max-height: 92vh',
                                'object-fit: contain',
                                'border-radius: 10px',
                                'box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5)',
                                'cursor: default'
                             ].join(';');

                            const close = () => {
                                overlay.remove();
                                document.body.style.overflow = oldOverflow;
                                document.removeEventListener('keydown', handleKeydown);
                            };

                            const handleKeydown = (keyboardEvent) => {
                                if (keyboardEvent.key === 'Escape') {
                                    close();
                                }
                            };

                            image.addEventListener('click', (clickEvent) => {
                                clickEvent.stopPropagation();
                            });

                            overlay.addEventListener('click', close);
                            document.addEventListener('keydown', handleKeydown);

                            overlay.appendChild(image);
                            document.body.appendChild(overlay);
                            document.body.style.overflow = 'hidden';
                            JS,
                    ])
                    ->visible(
                        fn (?Product $record): bool => filled($record?->image_urls)
                    )
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(
                        fn (?string $state, callable $set) => $set('slug', Str::slug($state ?? ''))
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

                Repeater::make('productImages')
                    ->label('Изображения товара')
                    ->relationship()
                    ->schema([
                        FileUpload::make('path')
                            ->label('Изображение')
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes([
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->maxSize(10240)
                            ->imageEditor()
                            ->openable()
                            ->helperText(
                                'Поддерживаются JPG, PNG и WebP размером до 10 МБ. '
                                .'Фотографии HEIC необходимо предварительно преобразовать в JPG.'
                            )
                            ->validationMessages([
                                'accepted_file_types' => 'Формат изображения не поддерживается. Используйте JPG, PNG или WebP.',
                                'max_size' => 'Изображение слишком большое. Максимальный размер — 10 МБ.',
                                'required' => 'Выберите изображение.',
                            ])
                            ->required(),

                        TextInput::make('alt_text')
                            ->label('Подпись изображения')
                            ->placeholder('Например: вид спереди')
                            ->maxLength(255),
                    ])
                    ->columns(2)
                    ->orderColumn('sort_order')
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->collapsed()
                    ->defaultItems(0)
                    ->itemLabel(
                        fn (array $state): string => $state['alt_text'] ?? 'Изображение'
                    )
                    ->addActionLabel('Добавить изображение')
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
