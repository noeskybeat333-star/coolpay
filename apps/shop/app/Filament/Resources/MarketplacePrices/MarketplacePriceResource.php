<?php

namespace App\Filament\Resources\MarketplacePrices;

use App\Filament\Resources\MarketplacePrices\Pages\ListMarketplacePrices;
use App\Filament\Resources\MarketplacePrices\Tables\MarketplacePricesTable;
use App\Models\ChannelPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MarketplacePriceResource extends Resource
{
    protected static ?string $model =
        ChannelPrice::class;

    protected static string|BackedEnum|null $navigationIcon =
        'heroicon-o-currency-dollar';

    protected static ?string $recordTitleAttribute =
        'name';

    protected static ?string $modelLabel =
        'цена';

    protected static ?string $pluralModelLabel =
        'цены';

    protected static ?string $navigationLabel =
        'Цены';

    protected static string|UnitEnum|null $navigationGroup =
        'Маркетплейсы';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug =
        'marketplace-prices';

    public static function table(
        Table $table,
    ): Table {
        return MarketplacePricesTable::configure(
            $table
        );
    }

    public static function canCreate(): bool
    {
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

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplacePrices::route('/'),
        ];
    }
}
