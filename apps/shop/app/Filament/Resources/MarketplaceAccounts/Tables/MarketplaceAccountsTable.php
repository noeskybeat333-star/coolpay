<?php

namespace App\Filament\Resources\MarketplaceAccounts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MarketplaceAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('marketplace')
                    ->label('Маркетплейс')
                    ->formatStateUsing(fn (string $state): string =>
                        match ($state) {
                            'wildberries' => 'Wildberries',
                            'ozon' => 'Ozon',
                            'yandex_market' => 'Яндекс Маркет',
                            default => $state,
                        }
                    )
                    ->badge()
                    ->sortable(),

                TextColumn::make('external_account_id')
                    ->label('ID кабинета')
                    ->placeholder('—')
                    ->searchable(),

                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean(),

                TextColumn::make('last_synced_at')
                    ->label('Последняя синхронизация')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Ещё не выполнялась')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Изменено')
                    ->dateTime('d.m.Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()
                    ->label('Изменить'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Удалить выбранные'),
                ]),
            ]);
    }
}
