<?php

namespace App\Filament\Resources\MarketplaceAccounts\Pages;

use App\Filament\Resources\MarketplaceAccounts\MarketplaceAccountResource;
use App\Integrations\MarketplaceDriverManager;
use App\Models\IntegrationType;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMarketplaceAccount extends EditRecord
{
    protected static string $resource = MarketplaceAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Проверить подключение')
                ->icon('heroicon-o-signal')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Проверить подключение?')
                ->modalDescription(
                    'Будет выполнен один безопасный запрос к API маркетплейса. '
                    . 'Товары, цены и остатки изменены не будут.'
                )
                ->modalSubmitActionLabel('Проверить')
                ->action(function (): void {
                    abort_unless(
                        static::getResource()::canEdit($this->record),
                        403,
                    );

                    $integrationType = $this->record->integrationType;

                    if (! $integrationType) {
                        Notification::make()
                            ->danger()
                            ->title('Не выбрана площадка')
                            ->body('Сначала выберите тип интеграции.')
                            ->send();

                        return;
                    }

                    $driver = app(MarketplaceDriverManager::class)
                        ->for($integrationType);

                    $result = $driver->testConnection($this->record);

                    $this->record->update([
                        'connection_status' => $result->successful
                            ? 'connected'
                            : 'error',
                        'status_message' => $result->message,
                        'tested_at' => now(),
                    ]);

                    $this->record->refresh();

                    $this->refreshFormData([
                        'connection_status',
                        'status_message',
                        'tested_at',
                        'last_synced_at',
                    ]);

                    $notification = Notification::make()
                        ->title(
                            $result->successful
                                ? 'Подключение работает'
                                : 'Ошибка подключения'
                        )
                        ->body($result->message);

                    if ($result->successful) {
                        $notification->success();
                    } else {
                        $notification->danger();
                    }

                    $notification->send();
                }),

            DeleteAction::make()
                ->label('Удалить'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $integration = IntegrationType::findOrFail(
            $data['integration_type_id']
        );

        $data['marketplace'] = $integration->slug;

        $data['credentials'] = array_replace(
            $this->record->credentials ?? [],
            $data['credentials'] ?? [],
        );

        return $data;
    }
}
