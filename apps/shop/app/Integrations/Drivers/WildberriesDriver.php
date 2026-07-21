<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\MarketplaceDriver;
use App\Integrations\Results\CatalogImportResult;
use App\Integrations\Results\ConnectionTestResult;
use App\Models\MarketplaceAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class WildberriesDriver implements MarketplaceDriver
{
    private const API_URL =
        'https://common-api.wildberries.ru';

    public function testConnection(
        MarketplaceAccount $account,
    ): ConnectionTestResult {
        $token = data_get(
            $account->credentials,
            'api_token',
        );

        if (blank($token)) {
            return ConnectionTestResult::failure(
                'API-токен Wildberries не указан.',
            );
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(5)
                ->timeout(10)
                ->get(self::API_URL.'/ping');

            if ($response->successful()) {
                return ConnectionTestResult::success(
                    'Подключение к Wildberries работает.',
                    [
                        'http_status' => $response->status(),
                        'checked_at' => now()->toIso8601String(),
                    ],
                );
            }

            $message = match ($response->status()) {
                401 => 'Токен Wildberries недействителен или истёк.',
                403 => 'У токена недостаточно разрешений.',
                429 => 'Превышен лимит проверок. Повторите позднее.',
                default => 'Wildberries вернул ошибку HTTP '
                    .$response->status().'.',
            };

            return ConnectionTestResult::failure(
                $message,
                [
                    'http_status' => $response->status(),
                ],
            );
        } catch (ConnectionException) {
            return ConnectionTestResult::failure(
                'Не удалось соединиться с API Wildberries.',
            );
        } catch (Throwable $exception) {
            report($exception);

            return ConnectionTestResult::failure(
                'Во время проверки произошла внутренняя ошибка.',
            );
        }
    }

    public function importCatalog(
        MarketplaceAccount $account,
    ): CatalogImportResult {
        return CatalogImportResult::empty();
    }

    public function capabilities(): array
    {
        return [
            'connection_test' => true,
            'catalog_read' => false,
            'prices_read' => false,
            'stocks_read' => false,
            'orders_read' => false,
            'prices_write' => false,
            'stocks_write' => false,
        ];
    }
}
