<?php

namespace App\Filament\Resources\MarketplaceListings\Tables;

use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Services\MarketplaceProductLinker;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

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

                // Раньше подписывалось «nmID», но идентификатор
                // площадки у каждой свой: у WB это число, у Яндекса —
                // артикул продавца.
                TextColumn::make('external_id')
                    ->label('ID на площадке')
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

                TextColumn::make('base_price')
                    ->label('До скидки')
                    ->money('RUB')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('discount_percent')
                    ->label('Скидка')
                    ->suffix('%')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('price')
                    ->label('Цена на площадке')
                    ->money('RUB')
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

                // Сопоставление и перенос — разные намерения, поэтому
                // и действия разные. Связать карточку с товаром CRM
                // не значит завести этот товар у себя на витрине.
                Action::make('linkProduct')
                    ->label('Связать с товаром')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->visible(
                        fn (MarketplaceListing $record): bool =>
                            $record->product_id === null
                    )
                    ->modalHeading('Связать карточку с товаром CRM')
                    ->modalDescription(
                        'Карточка будет отмечена как тот же товар. '
                        .'На витрине CoolPay ничего не появится, цены '
                        .'и остатки не изменятся.'
                    )
                    ->modalSubmitActionLabel('Связать')
                    ->schema([
                        Select::make('product_id')
                            ->label('Товар в CRM')
                            ->required()
                            ->searchable()
                            ->options(
                                fn (
                                    MarketplaceListing $record,
                                ): array => static::productOptions(
                                    $record
                                ),
                            )
                            ->default(
                                fn (
                                    MarketplaceListing $record,
                                ): ?int => app(
                                    MarketplaceProductLinker::class
                                )
                                    ->findMatchingProduct($record)
                                    ?->getKey(),
                            )
                            ->helperText(
                                'Если артикул совпал, нужный товар '
                                .'уже подставлен.'
                            ),
                    ])
                    ->action(function (
                        MarketplaceListing $record,
                        array $data,
                    ): void {
                        $product = Product::query()
                            ->find($data['product_id']);

                        if ($product === null) {
                            Notification::make()
                                ->danger()
                                ->title('Товар не найден')
                                ->send();

                            return;
                        }

                        app(MarketplaceProductLinker::class)
                            ->linkToProduct($record, $product);

                        Notification::make()
                            ->success()
                            ->title('Карточка связана с товаром')
                            ->body($product->name)
                            ->send();
                    }),

                Action::make('moveToStorefront')
                    ->label('Перенести в CoolPay')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('primary')
                    ->visible(
                        fn (MarketplaceListing $record): bool =>
                            $record->product_id === null
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Перенести карточку в CoolPay?')
                    ->modalDescription(
                        'В CoolPay появится новый товар с названием, '
                        .'описанием и категорией из карточки. Он будет '
                        .'выключен и с нулевой ценой: цены '
                        .'маркетплейсов включают комиссию площадки, '
                        .'поэтому розничную нужно назначить самому. '
                        .'На маркетплейсе ничего не изменится.'
                    )
                    ->modalSubmitActionLabel('Перенести')
                    ->action(function (
                        MarketplaceListing $record,
                    ): void {
                        try {
                            $result = app(
                                MarketplaceProductLinker::class
                            )->createDraft($record);

                            Notification::make()
                                ->success()
                                ->title(
                                    $result['created']
                                        ? 'Товар создан в CoolPay'
                                        : 'Карточка связана с товаром'
                                )
                                ->body(
                                    $result['product']->name
                                    .' — выключен, назначьте цену.'
                                )
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->danger()
                                ->title('Не удалось перенести карточку')
                                ->body(
                                    'Подробности записаны в журнал Laravel.'
                                )
                                ->send();
                        }
                    }),

                Action::make('unlinkProduct')
                    ->label('Отвязать')
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->visible(
                        fn (MarketplaceListing $record): bool =>
                            $record->product_id !== null
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Отвязать карточку от товара?')
                    ->modalDescription(
                        'Сам товар останется в CRM, удалена будет '
                        .'только связь.'
                    )
                    ->action(function (
                        MarketplaceListing $record,
                    ): void {
                        app(MarketplaceProductLinker::class)
                            ->unlink($record);

                        Notification::make()
                            ->success()
                            ->title('Связь удалена')
                            ->send();
                    }),
            ])
            ->paginationPageOptions([
                25,
                50,
                100,
            ]);
    }

    /**
     * Список товаров CRM для выбора вручную.
     *
     * Совпадение по артикулу показывается первым, остальные — по
     * алфавиту. Артикул в подписи нужен, потому что названия у
     * одинаковых моделей различаются одним словом.
     *
     * @return array<int, string>
     */
    private static function productOptions(
        MarketplaceListing $listing,
    ): array {
        return Product::query()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'sku'])
            ->mapWithKeys(fn (Product $product): array => [
                $product->getKey() => $product->name
                    .($product->sku ? '  ['.$product->sku.']' : ''),
            ])
            ->all();
    }
}
