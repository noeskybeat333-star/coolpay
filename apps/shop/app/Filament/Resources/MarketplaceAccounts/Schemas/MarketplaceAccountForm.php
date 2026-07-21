<?php

namespace App\Filament\Resources\MarketplaceAccounts\Schemas;

use App\Models\IntegrationType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MarketplaceAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Подключение')
                    ->schema([
                        Select::make('integration_type_id')
                            ->label('Площадка')
                            ->options(
                                IntegrationType::query()
                                    ->where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'id')
                                    ->all(),
                            )
                            ->default(
                                fn (): ?int =>
                                    request()->integer(
                                        'integration_type_id',
                                    ) ?: null,
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(
                                fn (Set $set) =>
                                    $set('credentials', []),
                            ),

                        TextInput::make('name')
                            ->label('Название подключения')
                            ->placeholder(
                                'Например: WB — основной кабинет',
                            )
                            ->required()
                            ->maxLength(255),

                        TextInput::make('external_account_id')
                            ->label('ID кабинета или продавца')
                            ->maxLength(255)
                            ->helperText(
                                'Можно оставить пустым, если площадка определит ID через API.',
                            ),

                        Toggle::make('is_active')
                            ->label('Подключение активно')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Данные авторизации')
                    ->description(
                        'Данные сохраняются в базе в зашифрованном виде.',
                    )
                    ->schema(
                        fn (Get $get): array =>
                            self::credentialFields(
                                $get('integration_type_id'),
                            ),
                    )
                    ->visible(
                        fn (Get $get): bool =>
                            filled($get('integration_type_id')),
                    )
                    ->columns(2),

                Section::make('Состояние')
                    ->schema([
                        TextInput::make('connection_status')
                            ->label('Статус подключения')
                            ->disabled()
                            ->dehydrated(false),

                        Textarea::make('status_message')
                            ->label('Сообщение')
                            ->disabled()
                            ->dehydrated(false),

                        DateTimePicker::make('tested_at')
                            ->label('Последняя проверка')
                            ->disabled()
                            ->dehydrated(false),

                        DateTimePicker::make('last_synced_at')
                            ->label('Последняя синхронизация')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->visible(
                        fn (string $operation): bool =>
                            $operation === 'edit',
                    )
                    ->columns(2),
            ]);
    }

    private static function credentialFields(
        mixed $integrationTypeId,
    ): array {
        if (blank($integrationTypeId)) {
            return [];
        }

        $integration = IntegrationType::find(
            $integrationTypeId,
        );

        if (! $integration) {
            return [];
        }

        return collect(
            $integration->credential_schema ?? [],
        )
            ->map(function (array $definition) {
                $name = $definition['name'] ?? null;

                if (blank($name)) {
                    return null;
                }

                $type = $definition['type'] ?? 'text';
                $label = $definition['label'] ?? $name;
                $required = (bool) (
                    $definition['required'] ?? false
                );

                if ($type === 'textarea') {
                    return Textarea::make(
                        "credentials.{$name}",
                    )
                        ->label($label)
                        ->required($required)
                        ->rows(4)
                        ->columnSpanFull();
                }

                $input = TextInput::make(
                    "credentials.{$name}",
                )
                    ->label($label)
                    ->required(
                        fn (string $operation): bool =>
                            $operation === 'create'
                            && $required,
                    )
                    ->maxLength(2048);

                if ($type === 'password') {
                    $input
                        ->password()
                        ->revealable()
                        ->afterStateHydrated(
                            fn (TextInput $component) =>
                                $component->state(null),
                        )
                        ->dehydrated(
                            fn (?string $state): bool =>
                                filled($state),
                        );
                }

                return $input;
            })
            ->filter()
            ->values()
            ->all();
    }
}
