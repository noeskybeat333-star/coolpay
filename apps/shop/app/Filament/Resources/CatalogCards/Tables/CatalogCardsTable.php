<?php

namespace App\Filament\Resources\CatalogCards\Tables;

use App\Models\ChannelPrice;
use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Services\MarketplaceProductLinker;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class CatalogCardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('primary_image_url')
                    ->label('Фото')
                    ->square()
                    ->size(48)
                    ->extraImgAttributes(['loading' => 'lazy']),

                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->wrap()
                    ->limit(70)
                    ->sortable(),

                TextColumn::make('sku')
                    ->label('Артикул')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('marketplace_name')
                    ->label('Канал')
                    ->badge()
                    ->color(fn (ChannelPrice $record): string => match (
                        $record->marketplace_slug
                    ) {
                        'storefront' => 'success',
                        'wildberries' => 'purple',
                        'yandex-market' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('connection_name')
                    ->label('Подключение')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('sale_price')
                    ->label('Цена')
                    ->money('RUB')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('stock_quantity')
                    ->label('Остаток')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string =>
                        match ($state) {
                            'active' => 'Активна',
                            'inactive' => 'Выключена',
                            'out_of_stock' => 'Нет остатка',
                            'no_price' => 'Нет цены',
                            'archived' => 'Архив',
                            default => (string) $state,
                        })
                    ->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'out_of_stock', 'no_price' => 'warning',
                        'archived' => 'gray',
                        default => 'gray',
                    }),

                // Ключевая колонка концентратора: она показывает,
                // сошлись ли карточки разных каналов на одном товаре.
                TextColumn::make('product_id')
                    ->label('Товар CRM')
                    ->formatStateUsing(
                        fn (?int $state): string => $state === null
                            ? 'нет пары'
                            : '#'.$state,
                    )
                    ->color(
                        fn (?int $state): string => $state === null
                            ? 'danger'
                            : 'gray',
                    )
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('marketplace_slug')
                    ->label('Канал')
                    ->options(fn (): array => ChannelPrice::query()
                        ->distinct()
                        ->pluck(
                            'marketplace_name',
                            'marketplace_slug',
                        )
                        ->all()),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('linkProduct')
                    ->label('Связать с товаром')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->visible(fn (ChannelPrice $record): bool =>
                        $record->record_type === 'marketplace'
                        && $record->product_id === null)
                    ->modalHeading('Связать карточку с товаром CRM')
                    ->modalDescription(
                        'Карточка будет отмечена как тот же товар. '
                        .'На витрине ничего не появится, цены и '
                        .'остатки не изменятся.'
                    )
                    ->modalSubmitActionLabel('Связать')
                    ->schema([
                        Select::make('product_id')
                            ->label('Товар в CRM')
                            ->required()
                            ->searchable()
                            ->options(
                                fn (): array => static::productOptions()
                            ),
                    ])
                    ->action(function (
                        ChannelPrice $record,
                        array $data,
                    ): void {
                        $listing = MarketplaceListing::query()
                            ->find($record->source_id);

                        $product = Product::query()
                            ->find($data['product_id']);

                        if ($listing === null || $product === null) {
                            Notification::make()
                                ->danger()
                                ->title('Запись не найдена')
                                ->send();

                            return;
                        }

                        app(MarketplaceProductLinker::class)
                            ->linkToProduct($listing, $product);

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
                    ->visible(fn (ChannelPrice $record): bool =>
                        $record->record_type === 'marketplace'
                        && $record->product_id === null)
                    ->requiresConfirmation()
                    ->modalHeading('Перенести карточку в CoolPay?')
                    ->modalDescription(
                        'В CoolPay появится новый товар с названием, '
                        .'описанием и категорией из карточки. Он будет '
                        .'выключен и с нулевой ценой: цены '
                        .'маркетплейсов включают комиссию площадки. '
                        .'На маркетплейсе ничего не изменится.'
                    )
                    ->modalSubmitActionLabel('Перенести')
                    ->action(function (ChannelPrice $record): void {
                        $listing = MarketplaceListing::query()
                            ->find($record->source_id);

                        if ($listing === null) {
                            Notification::make()
                                ->danger()
                                ->title('Карточка не найдена')
                                ->send();

                            return;
                        }

                        try {
                            $result = app(
                                MarketplaceProductLinker::class
                            )->createDraft($listing);

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
                                    'Подробности в журнале Laravel.'
                                )
                                ->send();
                        }
                    }),

                Action::make('unlinkProduct')
                    ->label('Отвязать')
                    ->icon('heroicon-o-link-slash')
                    ->color('gray')
                    ->visible(fn (ChannelPrice $record): bool =>
                        $record->record_type === 'marketplace'
                        && $record->product_id !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Отвязать карточку от товара?')
                    ->modalDescription(
                        'Сам товар останется в CRM, удалена будет '
                        .'только связь.'
                    )
                    ->action(function (ChannelPrice $record): void {
                        $listing = MarketplaceListing::query()
                            ->find($record->source_id);

                        if ($listing === null) {
                            return;
                        }

                        app(MarketplaceProductLinker::class)
                            ->unlink($listing);

                        Notification::make()
                            ->success()
                            ->title('Связь удалена')
                            ->send();
                    }),
            ])
            ->paginationPageOptions([25, 50, 100]);
    }

    /**
     * @return array<int, string>
     */
    private static function productOptions(): array
    {
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
