<?php

namespace App\Filament\Resources\MarketplaceAccounts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MarketplaceAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('marketplace')
                    ->label('Маркетплейс')
                    ->options([
                        'wildberries' => 'Wildberries',
                        'ozon' => 'Ozon',
                        'yandex_market' => 'Яндекс Маркет',
                    ])
                    ->required()
                    ->native(false),

                TextInput::make('name')
                    ->label('Название подключения')
                    ->placeholder('Например: WB — основной кабинет')
                    ->required()
                    ->maxLength(255),

                TextInput::make('api_token')
                    ->label('API-токен')
                    ->password()
                    ->revealable()
                    ->helperText(
                        'Токен хранится в базе в зашифрованном виде.'
                    )
                    ->required(fn (string $operation): bool =>
                        $operation === 'create'
                    )
                    ->dehydrated(fn (?string $state): bool =>
                        filled($state)
                    ),

                TextInput::make('external_account_id')
                    ->label('ID кабинета или продавца')
                    ->maxLength(255)
                    ->helperText(
                        'Можно оставить пустым, если маркетплейс определит его через API.'
                    ),

                Toggle::make('is_active')
                    ->label('Подключение активно')
                    ->default(true)
                    ->required(),

                DateTimePicker::make('last_synced_at')
                    ->label('Последняя синхронизация')
                    ->disabled()
                    ->dehydrated(false),

            ])
            ->columns(2);
    }
}
