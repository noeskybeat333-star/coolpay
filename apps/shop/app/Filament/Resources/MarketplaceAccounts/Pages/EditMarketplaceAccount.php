<?php

namespace App\Filament\Resources\MarketplaceAccounts\Pages;

use App\Filament\Resources\MarketplaceAccounts\MarketplaceAccountResource;
use App\Integrations\MarketplaceDriverManager;
use App\Models\IntegrationType;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;
use Throwable;

class EditMarketplaceAccount extends EditRecord
{
    protected static string $resource =
        MarketplaceAccountResource::class;

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
                    'Будет выполнен один безопасный запрос к API. '
                    .'Данные маркетплейса изменены не будут.'
                )
                ->modalSubmitActionLabel('Проверить')
                ->action(function (): void {
                    abort_unless(
                        static::getResource()::canEdit(
                            $this->record
                        ),
                        403,
                    );

                    $integrationType =
                        $this->record->integrationType;

                    if (! $integrationType) {
                        Notification::make()
                            ->danger()
                            ->title('Не выбрана площадка')
                            ->send();

                        return;
                    }

                    $driver = app(
                        MarketplaceDriverManager::class
                    )->for($integrationType);

                    $result = $driver->testConnection(
                        $this->record
                    );

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

            Action::make('importCatalog')
                ->label('Импортировать карточки')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(function (): bool {
                    $integrationType =
                        $this->record->integrationType;

                    if (! $integrationType) {
                        return false;
                    }

                    try {
                        $driver = app(
                            MarketplaceDriverManager::class
                        )->for($integrationType);

                        return (bool) (
                            $driver->capabilities()[
                                'catalog_read'
                            ] ?? false
                        );
                    } catch (Throwable) {
                        return false;
                    }
                })
                ->requiresConfirmation()
                ->modalHeading('Импортировать карточки?')
                ->modalDescription(
                    'CRM прочитает карточки маркетплейса и '
                    .'обновит локальную базу. Карточки, цены и '
                    .'остатки на маркетплейсе изменены не будут.'
                )
                ->modalSubmitActionLabel('Начать импорт')
                ->action(function (): void {
                    abort_unless(
                        static::getResource()::canEdit(
                            $this->record
                        ),
                        403,
                    );

                    $lock = Cache::lock(
                        'marketplace-catalog-import:'
                            .$this->record->getKey(),
                        600,
                    );

                    if (! $lock->get()) {
                        Notification::make()
                            ->warning()
                            ->title('Импорт уже выполняется')
                            ->body(
                                'Дождитесь завершения текущего импорта.'
                            )
                            ->send();

                        return;
                    }

                    $log = null;

                    try {
                        $integrationType =
                            $this->record->integrationType;

                        if (! $integrationType) {
                            throw new \RuntimeException(
                                'Не выбрана площадка.'
                            );
                        }

                        $driver = app(
                            MarketplaceDriverManager::class
                        )->for($integrationType);

                        if (
                            ! (
                                $driver->capabilities()[
                                    'catalog_read'
                                ] ?? false
                            )
                        ) {
                            Notification::make()
                                ->warning()
                                ->title('Импорт не поддерживается')
                                ->body(
                                    'Для этой площадки ещё нет '
                                    .'драйвера чтения каталога.'
                                )
                                ->send();

                            return;
                        }

                        $log = $this->record
                            ->syncLogs()
                            ->create([
                                'operation' => 'catalog_import',

                                'status' => 'running',
                                'message' => 'Импорт запущен.',

                                'started_at' => now(),
                            ]);

                        $result = $driver->importCatalog(
                            $this->record
                        );

                        $successful =
                            $result->failed === 0;

                        $message = $successful
                            ? sprintf(
                                'Получено: %d, создано: %d, '
                                .'обновлено: %d.',
                                $result->received,
                                $result->created,
                                $result->updated,
                            )
                            : (
                                $result->errors[0]
                                ?? 'Импорт завершился с ошибками.'
                            );

                        $log->update([
                            'status' => $successful
                                ? 'success'
                                : 'failed',

                            'received_count' => $result->received,

                            'created_count' => $result->created,

                            'updated_count' => $result->updated,

                            'failed_count' => $result->failed,

                            'message' => $message,

                            'details' => [
                                'errors' => $result->errors,
                            ],

                            'finished_at' => now(),
                        ]);

                        $this->record->refresh();

                        $this->refreshFormData([
                            'last_synced_at',
                        ]);

                        $notification = Notification::make()
                            ->title(
                                $successful
                                    ? 'Импорт завершён'
                                    : 'Ошибка импорта'
                            )
                            ->body($message);

                        if ($successful) {
                            $notification->success();
                        } else {
                            $notification->danger();
                        }

                        $notification->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        if ($log) {
                            $log->update([
                                'status' => 'failed',
                                'failed_count' => 1,
                                'message' => 'Внутренняя ошибка импорта.',
                                'finished_at' => now(),
                            ]);
                        }

                        Notification::make()
                            ->danger()
                            ->title('Ошибка импорта')
                            ->body(
                                'Во время импорта произошла '
                                .'внутренняя ошибка.'
                            )
                            ->send();
                    } finally {
                        $lock->release();
                    }
                }),

            DeleteAction::make()
                ->label('Удалить'),
        ];
    }

    protected function mutateFormDataBeforeSave(
        array $data,
    ): array {
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
