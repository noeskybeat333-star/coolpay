<?php

namespace App\Filament\Resources\MarketplaceListings\Pages;

use App\Filament\Resources\MarketplaceListings\MarketplaceListingResource;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceListings extends ListRecords
{
    protected static string $resource =
        MarketplaceListingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
