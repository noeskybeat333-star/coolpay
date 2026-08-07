<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\OrderStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource =
        OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeStatus')
                ->label('Изменить статус')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(
                    fn (): bool =>
                        $this->record->status
                        !== Order::STATUS_CANCELLED
                )
                ->schema([
                    Select::make('status')
                        ->label('Новый статус')
                        ->options(
                            collect(
                                Order::statusOptions()
                            )
                                ->except(
                                    Order::STATUS_CANCELLED
                                )
                                ->all()
                        )
                        ->default(
                            fn (): string =>
                                $this->record->status
                        )
                        ->required(),
                ])
                ->modalSubmitActionLabel('Сохранить')
                ->action(function (array $data): void {
                    $this->record = app(
                        OrderStatusService::class
                    )->changeStatus(
                        $this->record,
                        $data['status'],
                    );

                    Notification::make()
                        ->success()
                        ->title('Статус заказа обновлён')
                        ->send();
                }),

            Action::make('changePaymentStatus')
                ->label('Изменить оплату')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->schema([
                    Select::make('payment_status')
                        ->label('Статус оплаты')
                        ->options(
                            Order::paymentStatusOptions()
                        )
                        ->default(
                            fn (): string =>
                                $this->record->payment_status
                        )
                        ->required(),
                ])
                ->modalSubmitActionLabel('Сохранить')
                ->action(function (array $data): void {
                    $this->record = app(
                        OrderStatusService::class
                    )->changePaymentStatus(
                        $this->record,
                        $data['payment_status'],
                    );

                    Notification::make()
                        ->success()
                        ->title('Статус оплаты обновлён')
                        ->send();
                }),

            Action::make('cancelOrder')
                ->label('Отменить заказ')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(
                    fn (): bool =>
                        $this->record->status
                        !== Order::STATUS_CANCELLED
                )
                ->requiresConfirmation()
                ->modalHeading('Отменить заказ?')
                ->modalDescription(
                    'Статус заказа изменится на «Отменён». '
                    .'Количество товаров будет возвращено '
                    .'в остаток. Повторный возврат исключён.'
                )
                ->modalSubmitActionLabel('Отменить заказ')
                ->action(function (): void {
                    $this->record = app(
                        OrderStatusService::class
                    )->changeStatus(
                        $this->record,
                        Order::STATUS_CANCELLED,
                    );

                    Notification::make()
                        ->success()
                        ->title('Заказ отменён')
                        ->body(
                            'Товары возвращены в остаток.'
                        )
                        ->send();
                }),
        ];
    }
}
