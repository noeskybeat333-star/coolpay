<?php

namespace App\Filament\Resources\MarketplacePrices\Pages;

use App\Filament\Resources\MarketplacePrices\MarketplacePriceResource;
use Filament\Resources\Pages\EditRecord;

class EditMarketplacePrice extends EditRecord
{
    protected static string $resource =
        MarketplacePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(
        array $data,
    ): array {
        $basePrice = max(
            0,
            (float) ($data['base_price'] ?? 0)
        );

        $discount = min(
            100,
            max(
                0,
                (float) (
                    $data['discount_percent'] ?? 0
                )
            )
        );

        $data['base_price'] = $basePrice;
        $data['discount_percent'] = $discount;
        $data['price'] = round(
            $basePrice
                * (100 - $discount)
                / 100,
            2
        );

        return $data;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Цена сохранена в CRM';
    }
}
