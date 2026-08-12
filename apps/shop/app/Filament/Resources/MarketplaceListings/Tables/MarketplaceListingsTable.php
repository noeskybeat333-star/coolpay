<?php

namespace App\Filament\Resources\MarketplaceListings\Tables;

use App\Models\MarketplaceListing;
use App\Services\MarketplaceProductLinker;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
                    ->label('Цена WB')
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

                Action::make('linkProduct')
                    ->label('Связать с товаром')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->visible(
                        fn (MarketplaceListing $record): bool =>
                            $record->product_id === null
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Связать карточку с товаром?')
                    ->modalDescription(
                        'Если товар с таким артикулом уже есть в CRM — '
                        .'карточка будет связана с ним. Если нет — '
                        .'будет создан неактивный черновик с нулевой '
                        .'ценой и остатком.'
                    )
                    ->modalSubmitActionLabel('Связать')
                    ->action(function (
                        MarketplaceListing $record,
                    ): void {
                        try {
                            $result = app(
                                MarketplaceProductLinker::class
                            )->createOrLinkDraft($record);

                            Notification::make()
                                ->success()
                                ->title(
                                    $result['created']
                                        ? 'Черновик товара создан'
                                        : 'Карточка связана с товаром'
                                )
                                ->body($result['product']->name)
                                ->send();
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->danger()
                                ->title('Не удалось связать карточку')
                                ->body(
                                    'Подробности записаны в журнал Laravel.'
                                )
                                ->send();
                        }
                    }),
            ])
            ->paginationPageOptions([
                25,
                50,
                100,
            ]);
    }
}
