<?php

namespace App\Filament\Resources\MarketplaceListings;

use App\Filament\Resources\MarketplaceListings\Pages\ListMarketplaceListings;
use App\Filament\Resources\MarketplaceListings\Pages\ViewMarketplaceListing;
use App\Filament\Resources\MarketplaceListings\Schemas\MarketplaceListingInfolist;
use App\Filament\Resources\MarketplaceListings\Tables\MarketplaceListingsTable;
use App\Models\MarketplaceListing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MarketplaceListingResource extends Resource
{
    protected static ?string $model =
        MarketplaceListing::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute =
        'name';

    protected static ?string $modelLabel =
        'карточка';

    protected static ?string $pluralModelLabel =
        'карточки';

    protected static ?string $navigationLabel =
        'Карточки';

    protected static string|UnitEnum|null $navigationGroup =
        'Маркетплейсы';

    protected static ?int $navigationSort = 3;

    public static function infolist(
        Schema $schema,
    ): Schema {
        return MarketplaceListingInfolist::configure(
            $schema
        );
    }

    public static function table(
        Table $table,
    ): Table {
        return MarketplaceListingsTable::configure(
            $table
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(
        Model $record,
    ): bool {
        return false;
    }

    public static function canDelete(
        Model $record,
    ): bool {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceListings::route('/'),

            'view' => ViewMarketplaceListing::route(
                '/{record}'
            ),
        ];
    }
}
