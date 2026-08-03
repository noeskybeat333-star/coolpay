<?php

namespace App\Filament\Resources\MarketplacePrices\Pages;

use App\Filament\Resources\MarketplacePrices\MarketplacePriceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListMarketplacePrices extends ListRecords
{
    protected static string $resource =
        MarketplacePriceResource::class;

    public bool $isEditingPrices = false;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('togglePriceEditing')
                ->label(
                    fn (): string => $this->isEditingPrices
                        ? 'Завершить редактирование'
                        : 'Редактировать цены'
                )
                ->icon(
                    fn (): string => $this->isEditingPrices
                        ? 'heroicon-o-check'
                        : 'heroicon-o-pencil-square'
                )
                ->color(
                    fn (): string => $this->isEditingPrices
                        ? 'success'
                        : 'primary'
                )
                ->action(function (): void {
                    $this->isEditingPrices =
                        ! $this->isEditingPrices;
                }),
        ];
    }
}
