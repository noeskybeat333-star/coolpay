<?php

namespace App\Filament\Resources\CatalogCards;

use App\Filament\Resources\CatalogCards\Pages\ListCatalogCards;
use App\Filament\Resources\CatalogCards\Tables\CatalogCardsTable;
use App\Models\ChannelPrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Все карточки CRM в одном месте.
 *
 * CRM — общее хранилище карточек всех каналов: витрины CoolPay,
 * Wildberries, Яндекс Маркета и всего, что появится дальше. Экран
 * построен на представлении `channel_prices`, которое объединяет
 * товары витрины и карточки маркетплейсов в один список, поэтому
 * канал здесь — просто ещё одна вкладка, а не отдельный раздел.
 */
class CatalogCardResource extends Resource
{
    protected static ?string $model = ChannelPrice::class;

    protected static string|BackedEnum|null $navigationIcon =
        'heroicon-o-square-3-stack-3d';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'карточка';

    protected static ?string $pluralModelLabel = 'карточки';

    protected static ?string $navigationLabel = 'Карточки';

    protected static string|UnitEnum|null $navigationGroup =
        'Каталог';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'catalog-cards';

    public static function table(Table $table): Table
    {
        return CatalogCardsTable::configure($table);
    }

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
            'index' => ListCatalogCards::route('/'),
        ];
    }
}
