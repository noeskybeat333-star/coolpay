<?php

namespace App\Filament\Resources\MarketplaceAccounts;

use App\Filament\Resources\MarketplaceAccounts\Pages\CreateMarketplaceAccount;
use App\Filament\Resources\MarketplaceAccounts\Pages\EditMarketplaceAccount;
use App\Filament\Resources\MarketplaceAccounts\Pages\ListMarketplaceAccounts;
use App\Filament\Resources\MarketplaceAccounts\Schemas\MarketplaceAccountForm;
use App\Filament\Resources\MarketplaceAccounts\Tables\MarketplaceAccountsTable;
use App\Models\MarketplaceAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MarketplaceAccountResource extends Resource
{
    protected static ?string $model = MarketplaceAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'подключение';

    protected static ?string $pluralModelLabel = 'подключения';

    protected static ?string $navigationLabel = 'Подключения';

    protected static string | UnitEnum | null $navigationGroup = 'Маркетплейсы';

    public static function form(Schema $schema): Schema
    {
        return MarketplaceAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarketplaceAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceAccounts::route('/'),
            'create' => CreateMarketplaceAccount::route('/create'),
            'edit' => EditMarketplaceAccount::route('/{record}/edit'),
        ];
    }
}
