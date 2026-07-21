<?php

namespace Database\Seeders;

use App\Models\IntegrationType;
use Illuminate\Database\Seeder;

class IntegrationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $integrations = [
            [
                'slug' => 'wildberries',
                'name' => 'Wildberries',
                'description' => 'Карточки, цены, остатки и заказы Wildberries.',
                'credential_schema' => [
                    [
                        'name' => 'api_token',
                        'label' => 'API-токен',
                        'type' => 'password',
                        'required' => true,
                    ],
                ],
                'sort_order' => 10,
            ],
            [
                'slug' => 'ozon',
                'name' => 'Ozon',
                'description' => 'Интеграция с кабинетом продавца Ozon.',
                'credential_schema' => [
                    [
                        'name' => 'client_id',
                        'label' => 'Client ID',
                        'type' => 'text',
                        'required' => true,
                    ],
                    [
                        'name' => 'api_key',
                        'label' => 'API-ключ',
                        'type' => 'password',
                        'required' => true,
                    ],
                ],
                'sort_order' => 20,
            ],
            [
                'slug' => 'yandex-market',
                'name' => 'Яндекс Маркет',
                'description' => 'Интеграция с кабинетом Яндекс Маркета.',
                'credential_schema' => [
                    [
                        'name' => 'campaign_id',
                        'label' => 'Campaign ID',
                        'type' => 'text',
                        'required' => true,
                    ],
                    [
                        'name' => 'oauth_token',
                        'label' => 'OAuth-токен',
                        'type' => 'password',
                        'required' => true,
                    ],
                ],
                'sort_order' => 30,
            ],
            [
                'slug' => 'megamarket',
                'name' => 'Мегамаркет',
                'description' => 'Интеграция с кабинетом Мегамаркета.',
                'credential_schema' => [],
                'sort_order' => 40,
            ],
            [
                'slug' => 'mvideo',
                'name' => 'М.Видео',
                'description' => 'Интеграция с площадкой М.Видео.',
                'credential_schema' => [],
                'sort_order' => 50,
            ],
            [
                'slug' => 'magnit-market',
                'name' => 'Магнит Маркет',
                'description' => 'Интеграция с площадкой Магнит Маркет.',
                'credential_schema' => [],
                'sort_order' => 60,
            ],
            [
                'slug' => 'custom',
                'name' => 'Другая площадка',
                'description' => 'Ручное подключение или импорт из файла.',
                'credential_schema' => [
                    [
                        'name' => 'notes',
                        'label' => 'Описание подключения',
                        'type' => 'textarea',
                        'required' => false,
                    ],
                ],
                'sort_order' => 1000,
            ],
        ];

        $defaultCapabilities = [
            'catalog_read' => false,
            'prices_read' => false,
            'stocks_read' => false,
            'orders_read' => false,
            'prices_write' => false,
            'stocks_write' => false,
        ];

        foreach ($integrations as $integration) {
            IntegrationType::query()->updateOrCreate(
                ['slug' => $integration['slug']],
                array_merge(
                    [
                        'is_active' => true,
                        'capabilities' => $defaultCapabilities,
                        'settings' => [],
                    ],
                    $integration,
                ),
            );
        }
    }
}
