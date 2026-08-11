<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute =
        'number';

    protected static ?string $modelLabel =
        'заказ';

    protected static ?string $pluralModelLabel =
        'заказы';

    protected static ?string $navigationLabel =
        'Заказы';

    protected static string|UnitEnum|null $navigationGroup =
        'Продажи';

    protected static ?int $navigationSort = 1;

    public static function infolist(
        Schema $schema,
    ): Schema {
        return OrderInfolist::configure($schema);
    }

    public static function table(
        Table $table,
    ): Table {
        return OrdersTable::configure($table);
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
        $count = Order::query()
            ->where(
                'status',
                Order::STATUS_NEW,
            )
            ->count();

        return $count > 0
            ? (string) $count
            : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'marketplaceAccount.integrationType',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
