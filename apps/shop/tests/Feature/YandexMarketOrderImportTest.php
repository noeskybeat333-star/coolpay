<?php

namespace Tests\Feature;

use App\Models\IntegrationType;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Services\YandexMarketOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexMarketOrderImportTest extends TestCase
{
    use RefreshDatabase;

    private function account(): MarketplaceAccount
    {
        $type = IntegrationType::create([
            'slug' => 'yandex-market',
            'name' => 'Яндекс Маркет',
            'description' => 'Тестовая интеграция',
            'credential_schema' => [],
            'capabilities' => [],
            'is_active' => true,
            'sort_order' => 30,
        ]);

        return MarketplaceAccount::create([
            'integration_type_id' => $type->getKey(),
            'marketplace' => 'yandex-market',
            'name' => 'Тестовый кабинет',
            'credentials' => ['api_key' => 'test-key'],
            'is_active' => true,
            'settings' => [
                'business_id' => 210007193,
                'campaigns' => [
                    [
                        'id' => 148730310,
                        'domain' => 'Cool Pay FBS',
                        'placementType' => 'FBS',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Числа взяты с живого заказа 15.08.2026: покупатель заплатил
     * 127040, Маркет компенсировал 52960 баллами, а в кабинете
     * «Итого» показано 180000. CRM обязана показывать столько же.
     */
    public function test_субсидия_маркета_входит_в_выручку(): void
    {
        $account = $this->account();

        Http::fake([
            '*/campaigns/148730310/orders*' => Http::response([
                'orders' => [
                    [
                        'id' => 59659378817,
                        'status' => 'DELIVERED',
                        'substatus' => 'DELIVERY_SERVICE_DELIVERED',
                        'creationDate' => '29-07-2026 10:43:01',
                        'currency' => 'RUR',
                        'itemsTotal' => 127040,
                        'deliveryTotal' => 0,
                        'paymentType' => 'PREPAID',
                        'items' => [
                            [
                                'id' => 1168382345,
                                'offerId' => 'macboock_air13_16/1024_m5Starlight',
                                'offerName' => 'MacBook Air 13"',
                                'price' => 127040,
                                'buyerPrice' => 127040,
                                'count' => 1,
                                'subsidies' => [
                                    [
                                        'type' => 'SUBSIDY',
                                        'amount' => 52960,
                                    ],
                                ],
                            ],
                        ],
                        'delivery' => [
                            'address' => [
                                'country' => 'Россия',
                                'city' => 'Набережные Челны',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(YandexMarketOrderImporter::class)
            ->import($account);

        $this->assertSame(1, $result->received);
        $this->assertSame(1, $result->created);
        $this->assertSame(0, $result->failed);

        $order = Order::query()
            ->where('external_id', '59659378817')
            ->sole();

        $item = $order->items->sole();

        $this->assertEquals(180000, $item->unit_price);
        $this->assertEquals(180000, $order->total);

        // Раскладка сохраняется целиком: финансовому модулю нужно
        // знать, сколько пришло деньгами, а сколько баллами.
        $this->assertEquals(
            127040,
            data_get($item->product_snapshot, 'paid_by_buyer'),
        );

        $this->assertEquals(
            52960,
            data_get($item->product_snapshot, 'subsidy_total'),
        );

        // RUR — устаревший код рубля, в CRM всюду RUB.
        $this->assertSame('RUB', $order->currency);

        $this->assertSame('fbs', $order->fulfillment_type);
        $this->assertSame('completed', $order->status);
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_отмены_различают_оплату(): void
    {
        $account = $this->account();

        Http::fake([
            '*/campaigns/148730310/orders*' => Http::response([
                'orders' => [
                    [
                        'id' => 57345791875,
                        'status' => 'CANCELLED',
                        'substatus' => 'USER_NOT_PAID',
                        'creationDate' => '21-05-2026 21:50:42',
                        'currency' => 'RUR',
                        'paymentType' => 'PREPAID',
                        'items' => [[
                            'id' => 1,
                            'offerId' => 'sku-1',
                            'offerName' => 'Товар',
                            'price' => 60039,
                            'count' => 1,
                        ]],
                    ],
                    [
                        'id' => 59342937603,
                        'status' => 'CANCELLED',
                        'substatus' => 'USER_CHANGED_MIND',
                        'creationDate' => '20-07-2026 11:52:20',
                        'currency' => 'RUR',
                        'paymentType' => 'PREPAID',
                        'items' => [[
                            'id' => 2,
                            'offerId' => 'sku-2',
                            'offerName' => 'Товар',
                            'price' => 280000,
                            'count' => 1,
                        ]],
                    ],
                ],
            ]),
        ]);

        app(YandexMarketOrderImporter::class)->import($account);

        // Покупатель не заплатил — возвращать нечего.
        $this->assertSame(
            'unpaid',
            Order::query()
                ->where('external_id', '57345791875')
                ->value('payment_status'),
        );

        // Передумал уже после оплаты — деньги вернулись.
        $this->assertSame(
            'refunded',
            Order::query()
                ->where('external_id', '59342937603')
                ->value('payment_status'),
        );
    }

    public function test_повторный_импорт_не_плодит_заказы(): void
    {
        $account = $this->account();

        Http::fake([
            '*/campaigns/148730310/orders*' => Http::response([
                'orders' => [[
                    'id' => 60233849090,
                    'status' => 'DELIVERY',
                    'substatus' => 'DELIVERY_SERVICE_RECEIVED',
                    'creationDate' => '12-08-2026 12:02:35',
                    'currency' => 'RUR',
                    'paymentType' => 'PREPAID',
                    'items' => [[
                        'id' => 3,
                        'offerId' => 'sku-3',
                        'offerName' => 'Товар',
                        'price' => 134715,
                        'count' => 1,
                        'subsidies' => [
                            ['type' => 'SUBSIDY', 'amount' => 65285],
                        ],
                    ]],
                ]],
            ]),
        ]);

        $importer = app(YandexMarketOrderImporter::class);

        $first = $importer->import($account);
        $second = $importer->import($account);

        $this->assertSame(1, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->updated);

        $this->assertSame(1, Order::query()->count());

        $this->assertEquals(
            200000,
            Order::query()->sole()->total,
        );
    }
}
