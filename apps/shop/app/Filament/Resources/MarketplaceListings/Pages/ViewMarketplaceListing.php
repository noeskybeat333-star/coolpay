<?php

namespace App\Filament\Resources\MarketplaceListings\Pages;

use App\Filament\Resources\MarketplaceListings\MarketplaceListingResource;
use App\Filament\Resources\Products\ProductResource;
use App\Services\MarketplaceProductLinker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewMarketplaceListing extends ViewRecord
{
    protected static string $resource =
        MarketplaceListingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createProduct')
                ->label('Создать товар')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->visible(
                    fn (): bool => $this->record->product_id === null
                )
                ->requiresConfirmation()
                ->modalHeading(
                    'Создать товар из карточки?'
                )
                ->modalDescription(
                    'Будет создан неактивный черновик. '
                    .'Название, артикул, бренд, категория '
                    .'и описание будут перенесены из '
                    .'карточки маркетплейса. Цена и остаток '
                    .'останутся нулевыми до проверки.'
                )
                ->modalSubmitActionLabel(
                    'Создать черновик'
                )
                ->action(function (): void {
                    abort_unless(
                        MarketplaceListingResource::canView(
                            $this->record
                        ),
                        403,
                    );

                    try {
                        $result = app(
                            MarketplaceProductLinker::class
                        )->createOrLinkDraft(
                            $this->record
                        );

                        $product = $result['product'];

                        Notification::make()
                            ->success()
                            ->title(
                                $result['created']
                                    ? 'Черновик товара создан'
                                    : 'Карточка связана с товаром'
                            )
                            ->body(
                                'Проверьте цену, остаток '
                                .'и публикацию товара.'
                            )
                            ->send();

                        $this->redirect(
                            ProductResource::getUrl(
                                'edit',
                                [
                                    'record' => $product->getKey(),
                                ],
                            )
                        );
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->danger()
                            ->title(
                                'Не удалось создать товар'
                            )
                            ->body(
                                'Подробности записаны '
                                .'в журнал Laravel.'
                            )
                            ->send();
                    }
                }),

            Action::make('openProduct')
                ->label('Открыть связанный товар')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('info')
                ->visible(
                    fn (): bool => $this->record->product_id !== null
                )
                ->url(
                    fn (): string => ProductResource::getUrl(
                        'edit',
                        [
                            'record' => $this->record->product_id,
                        ],
                    )
                ),
        ];
    }
}
