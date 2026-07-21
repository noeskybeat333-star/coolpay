<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MarketplaceAccounts\MarketplaceAccountResource;
use App\Models\IntegrationType;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class Integrations extends Page
{
    protected static string | BackedEnum | null $navigationIcon =
        Heroicon::OutlinedRectangleGroup;

    protected static ?string $navigationLabel = 'Интеграции';

    protected static ?string $title = 'Интеграции';

    protected static string | UnitEnum | null $navigationGroup =
        'Маркетплейсы';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.integrations';

    public function getIntegrations(): Collection
    {
        return IntegrationType::query()
            ->where('is_active', true)
            ->withCount('accounts')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function getConnectUrl(IntegrationType $integration): string
    {
        return MarketplaceAccountResource::getUrl(
            'create',
            [
                'integration_type_id' => $integration->id,
            ],
        );
    }
}
