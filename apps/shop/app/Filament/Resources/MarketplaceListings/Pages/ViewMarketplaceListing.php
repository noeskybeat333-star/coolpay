<?php

namespace App\Filament\Resources\MarketplaceListings\Pages;

use App\Filament\Resources\MarketplaceListings\MarketplaceListingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMarketplaceListing extends ViewRecord
{
    protected static string $resource =
        MarketplaceListingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
