<?php

namespace App\Filament\Resources\MarketplaceAccounts\Pages;

use App\Filament\Resources\MarketplaceAccounts\MarketplaceAccountResource;
use App\Integrations\MarketplaceDriverManager;
use App\Jobs\SyncMarketplaceCatalog;
use App\Jobs\SyncMarketplaceOrders;
use App\Jobs\SyncMarketplacePrices;
use App\Models\IntegrationType;
use App\Services\MarketplaceCatalogSyncRunner;
use App\Services\MarketplaceCooldown;
use App\Services\MarketplaceOrderSyncRunner;
use App\Services\MarketplacePriceSyncRunner;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

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

                    // Во время паузы даже проверка связи вредна: это
                    // лишний запрос в тот же счётчик. Показываем остаток
                    // из кэша, никуда не ходя.
                    $left = app(MarketplaceCooldown::class)
                        ->secondsLeft($this->record);

                    if ($left > 0) {
                        Notification::make()
                            ->warning()
                            ->title('Кабинет на паузе')
                            ->body(
                                'Маркетплейс ограничил частоту запросов. '
                                .'Осталось '.$left.' с. Проверка не '
                                .'выполнялась, чтобы не продлить паузу.'
                            )
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

            // Импорты больше не выполняются внутри HTTP-запроса: они
            // ставятся в очередь. Так сбой на середине не теряет работу,
            // лимит частоты API приводит к автоповтору, а долгий импорт
            // не упирается в таймаут nginx.
            Action::make('importCatalog')
                ->label('Импортировать карточки')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(
                    fn (): bool => app(
                        MarketplaceCatalogSyncRunner::class
                    )->supportsAccount($this->record)
                )
                ->requiresConfirmation()
                ->modalHeading('Импортировать карточки?')
                ->modalDescription(
                    'Задача уйдёт в очередь и выполнится в фоне. CRM '
                    .'прочитает карточки маркетплейса и обновит локальную '
                    .'базу. Карточки, цены и остатки на маркетплейсе '
                    .'изменены не будут.'
                )
                ->modalSubmitActionLabel('Поставить в очередь')
                ->action(function (): void {
                    abort_unless(
                        static::getResource()::canEdit(
                            $this->record
                        ),
                        403,
                    );

                    SyncMarketplaceCatalog::dispatch(
                        $this->record->getKey()
                    );

                    Notification::make()
                        ->info()
                        ->title('Импорт каталога поставлен в очередь')
                        ->body(
                            'Результат появится в разделе «Журнал '
                            .'синхронизаций». Если импорт уже выполняется, '
                            .'повторная задача не создаётся.'
                        )
                        ->send();
                }),

            Action::make('importPrices')
                ->label('Обновить цены и остатки')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->visible(
                    fn (): bool => app(
                        MarketplacePriceSyncRunner::class
                    )->supportsAccount($this->record)
                )
                ->action(function (): void {
                    abort_unless(
                        static::getResource()::canEdit(
                            $this->record
                        ),
                        403,
                    );

                    SyncMarketplacePrices::dispatch(
                        $this->record->getKey()
                    );

                    Notification::make()
                        ->info()
                        ->title('Обновление цен поставлено в очередь')
                        ->body(
                            'CRM пройдёт по уже сохранённым карточкам и '
                            .'обновит цены и остатки. На маркетплейсе '
                            .'ничего изменено не будет.'
                        )
                        ->send();
                }),

            Action::make('importOrders')
                ->label('Синхронизировать заказы')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('primary')
                ->visible(
                    fn (): bool => app(
                        MarketplaceOrderSyncRunner::class
                    )->supportsAccount($this->record)
                )
                ->requiresConfirmation()
                ->modalHeading('Синхронизировать заказы?')
                ->modalDescription(
                    'Задача уйдёт в очередь и выполнится в фоне. CRM '
                    .'прочитает заказы за последние 90 дней. На '
                    .'маркетплейсе ничего изменено не будет, повторный '
                    .'запуск не создаёт дублей.'
                )
                ->modalSubmitActionLabel('Поставить в очередь')
                ->action(function (): void {
                    abort_unless(
                        static::getResource()::canEdit(
                            $this->record
                        ),
                        403,
                    );

                    SyncMarketplaceOrders::dispatch(
                        $this->record->getKey(),
                        90,
                    );

                    Notification::make()
                        ->info()
                        ->title('Синхронизация заказов поставлена в очередь')
                        ->body(
                            'Результат появится в разделе «Журнал '
                            .'синхронизаций».'
                        )
                        ->send();
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
