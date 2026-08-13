<?php

namespace App\Filament\Resources\MarketplaceSyncLogs;

use App\Filament\Resources\MarketplaceSyncLogs\Pages\ListMarketplaceSyncLogs;
use App\Filament\Resources\MarketplaceSyncLogs\Tables\MarketplaceSyncLogsTable;
use App\Models\MarketplaceSyncLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MarketplaceSyncLogResource extends Resource
{
    protected static ?string $model =
        MarketplaceSyncLog::class;

    protected static string|BackedEnum|null $navigationIcon =
        'heroicon-o-clipboard-document-list';

    protected static ?string $modelLabel =
        'запись журнала';

    protected static ?string $pluralModelLabel =
        'журнал синхронизаций';

    protected static ?string $navigationLabel =
        'Журнал синхронизаций';

    protected static string|UnitEnum|null $navigationGroup =
        'Маркетплейсы';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug =
        'marketplace-sync-logs';

    public static function table(Table $table): Table
    {
        return MarketplaceSyncLogsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['account.integrationType']);
    }

    // Журнал пишется только кодом синхронизации: руками записи
    // не создаются, не правятся и не удаляются.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplaceSyncLogs::route('/'),
        ];
    }
}
