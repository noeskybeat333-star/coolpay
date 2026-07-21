<?php

namespace App\Filament\Resources\MarketplaceAccounts\Pages;

use App\Filament\Resources\MarketplaceAccounts\MarketplaceAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceAccounts extends ListRecords
{
    protected static string $resource = MarketplaceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
