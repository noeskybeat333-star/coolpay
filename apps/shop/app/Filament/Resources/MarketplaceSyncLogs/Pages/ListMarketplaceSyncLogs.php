<?php

namespace App\Filament\Resources\MarketplaceSyncLogs\Pages;

use App\Filament\Resources\MarketplaceSyncLogs\MarketplaceSyncLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceSyncLogs extends ListRecords
{
    protected static string $resource =
        MarketplaceSyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
