<?php

namespace App\Filament\Resources\MarketplaceSyncLogs\Tables;

use App\Models\MarketplaceSyncLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MarketplaceSyncLogsTable
{
    public static function configure(
        Table $table,
    ): Table {
        return $table
            ->columns([
                TextColumn::make('started_at')
                    ->label('Начало')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label('Подключение')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make(
                    'account.integrationType.name'
                )
                    ->label('Площадка')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('operation')
                    ->label('Операция')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            'catalog_import' => 'Каталог',
                            'prices_import' => 'Цены и остатки',
                            'orders_import' => 'Заказы',
                            default => (string) $state,
                        }
                    ),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(
                        fn (?string $state): string => match ($state) {
                            'success' => 'success',
                            'failed' => 'danger',
                            'running' => 'info',
                            'retrying' => 'warning',
                            default => 'gray',
                        }
                    )
                    ->formatStateUsing(
                        fn (?string $state): string => match ($state) {
                            'success' => 'Успешно',
                            'failed' => 'Ошибка',
                            'running' => 'Выполняется',
                            'retrying' => 'Повтор',
                            default => (string) $state,
                        }
                    ),

                TextColumn::make('received_count')
                    ->label('Получено')
                    ->numeric()
                    ->placeholder('—'),

                TextColumn::make('created_count')
                    ->label('Создано')
                    ->numeric()
                    ->placeholder('—'),

                TextColumn::make('updated_count')
                    ->label('Обновлено')
                    ->numeric()
                    ->placeholder('—'),

                TextColumn::make('failed_count')
                    ->label('Ошибок')
                    ->numeric()
                    ->placeholder('—'),

                TextColumn::make('message')
                    ->label('Сообщение')
                    ->wrap()
                    ->limit(140)
                    ->placeholder('—'),

                TextColumn::make('finished_at')
                    ->label('Длительность')
                    ->formatStateUsing(
                        function (
                            MarketplaceSyncLog $record,
                        ): string {
                            if (
                                $record->started_at === null
                                || $record->finished_at === null
                            ) {
                                return '—';
                            }

                            return (int) round(
                                $record->started_at->diffInSeconds(
                                    $record->finished_at
                                )
                            ).' с';
                        }
                    )
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('operation')
                    ->label('Операция')
                    ->options([
                        'catalog_import' => 'Каталог',
                        'prices_import' => 'Цены и остатки',
                        'orders_import' => 'Заказы',
                    ]),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'success' => 'Успешно',
                        'failed' => 'Ошибка',
                        'running' => 'Выполняется',
                        'retrying' => 'Повтор',
                    ]),

                SelectFilter::make('marketplace_account_id')
                    ->label('Подключение')
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            // Задачи выполняются в фоне — таблица обновляется сама,
            // чтобы не приходилось жать F5 в ожидании результата.
            ->poll('15s');
    }
}
