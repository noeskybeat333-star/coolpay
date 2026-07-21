<?php

namespace App\Filament\Resources\MarketplaceAccounts\Pages;

use App\Filament\Resources\MarketplaceAccounts\MarketplaceAccountResource;
use App\Models\IntegrationType;
use Filament\Resources\Pages\CreateRecord;

class CreateMarketplaceAccount extends CreateRecord
{
    protected static string $resource =
        MarketplaceAccountResource::class;

    protected function mutateFormDataBeforeCreate(
        array $data,
    ): array {
        $integration = IntegrationType::findOrFail(
            $data['integration_type_id'],
        );

        $data['marketplace'] = $integration->slug;
        $data['connection_status'] = 'not_tested';

        return $data;
    }
}
