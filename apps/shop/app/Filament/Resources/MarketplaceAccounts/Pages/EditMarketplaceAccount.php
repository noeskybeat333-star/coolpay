<?php

namespace App\Filament\Resources\MarketplaceAccounts\Pages;

use App\Filament\Resources\MarketplaceAccounts\MarketplaceAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceAccount extends EditRecord
{
    protected static string $resource = MarketplaceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
