<?php

namespace Tests\Feature;

use App\Models\IntegrationType;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\Product;
use App\Services\WildberriesOrderImporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesOrderImportTest extends TestCase
{
    use RefreshDatabase;

    private function account(): MarketplaceAccount
    {
        $type = IntegrationType::create([
            'slug' => 'wildberries',
            'name' => 'Wildberries',
            'description' => 'Тестовая интеграция',
            'credential_schema' => [],
            'capabilities' => [],
            'is_active' => true,
            'sort_order' => 10,
        ]);

        return MarketplaceAccount::create([
            'integration_type_id' => $type->getKey(),
            'marketplace' => 'wildberries',
            'name' => 'Тестовый кабинет',
            'credentials' => ['api_token' => 'test-token'],
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function task(
        int $id,
        string $orderUid,
        int $nmId,
        string $article,
        int $priceKopecks,
        array $overrides = [],
    ): array {
        return array_replace([
            'id' => $id,
            'rid' => 'ebS.'.$orderUid.'.1.0',
            'orderUid' => $orderUid,
            'article' => $article,
            'nmId' => $nmId,
            'chrtId' => $nmId * 10,
            'skus' => ['200000000'.$nmId],
            'price' => $priceKopecks,
            'convertedPrice' => $priceKopecks,
            'currencyCode' => 643,
            'deliveryType' => 'fbs',
            'supplyId' => 'WB-GI-1',
            'warehouseId' => 1,
            'officeId' => 2,
            'offices' => ['Москва'],
            'address' => null,
            'comment' => '',
            'cargoType' => 1,
            'isZeroOrder' => false,
            'options' => ['isB2B' => false],
            'createdAt' => '2026-06-01T10:00:00Z',
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @param  array<int, array<string, mixed>>  $statuses
     */
    private function fakeWildberries(
        array $orders,
        array $statuses,
    ): void {
        Http::fake([
            '*/api/v3/orders/status' => Http::response([
                'orders' => $statuses,
            ]),

            '*/api/v3/orders*' => Http::response([
                'orders' => $orders,
                'next' => 0,
            ]),

            // Импортёр обходит ещё DBS и DBW. Без явного ответа они
            // считаются незаявленными запросами и падают с ошибкой,
            // подмешивая failed в результат про FBS.
            '*' => Http::response(['orders' => [], 'next' => 0]),
        ]);
    }

    private function import(
        MarketplaceAccount $account,
    ): \App\Integrations\Results\OrderImportResult {
        return app(WildberriesOrderImporter::class)->import(
            $account,
            CarbonImmutable::now()->subDays(30),
        );
    }

    public function test_groups_assembly_tasks_by_order_uid(): void
    {
        $account = $this->account();

        // Одна покупка возвращается несколькими сборочными заданиями:
        // два задания на один товар и одно на другой.
        $this->fakeWildberries(
            [
                $this->task(101, 'uid-multi', 111, 'ART-A', 7531900),
                $this->task(102, 'uid-multi', 111, 'ART-A', 7531900),
                $this->task(103, 'uid-multi', 222, 'ART-B', 1000000),
                $this->task(104, 'uid-single', 222, 'ART-B', 1000000),
            ],
            [
                ['id' => 101, 'supplierStatus' => 'complete', 'wbStatus' => 'sold'],
                ['id' => 102, 'supplierStatus' => 'complete', 'wbStatus' => 'sold'],
                ['id' => 103, 'supplierStatus' => 'complete', 'wbStatus' => 'sold'],
                ['id' => 104, 'supplierStatus' => 'complete', 'wbStatus' => 'sold'],
            ],
        );

        $result = $this->import($account);

        $this->assertSame(4, $result->received);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->failed);

        $this->assertSame(2, Order::query()->count());

        $multi = Order::query()
            ->where('external_id', 'uid-multi')
            ->with('items')
            ->firstOrFail();

        // Два задания на один товар схлопываются в одну позицию с кол-вом 2,
        // задание на другой товар остаётся отдельной позицией.
        $this->assertCount(2, $multi->items);

        $merged = $multi->items
            ->firstWhere('product_sku', 'ART-A');

        $this->assertSame(2, $merged->quantity);
        $this->assertSame('75319.00', (string) $merged->unit_price);
        $this->assertSame('150638.00', (string) $merged->total);

        // ID отдельных сборочных заданий не теряются.
        $this->assertEqualsCanonicalizing(
            ['101', '102'],
            $merged->product_snapshot['external_line_ids'],
        );

        // Внешний номер — минимальный id группы, он же виден в кабинете WB.
        $this->assertSame('101', $multi->external_number);
        $this->assertSame('fbs', $multi->fulfillment_type);
        $this->assertSame(
            [101, 102, 103],
            $multi->metadata['assembly_task_ids'],
        );

        $this->assertSame('160638.00', (string) $multi->total);
    }

    public function test_maps_statuses_and_converts_kopecks(): void
    {
        $account = $this->account();

        $this->fakeWildberries(
            [
                $this->task(201, 'uid-sold', 111, 'ART-A', 4911400),
                $this->task(202, 'uid-cancelled', 222, 'ART-B', 8815900),
                $this->task(203, 'uid-new', 333, 'ART-C', 1000),
            ],
            [
                ['id' => 201, 'supplierStatus' => 'complete', 'wbStatus' => 'sold'],
                ['id' => 202, 'supplierStatus' => 'complete', 'wbStatus' => 'canceled_by_client'],
                ['id' => 203, 'supplierStatus' => 'new', 'wbStatus' => 'waiting'],
            ],
        );

        $this->import($account);

        $sold = Order::query()
            ->where('external_id', 'uid-sold')
            ->firstOrFail();

        $this->assertSame(Order::STATUS_COMPLETED, $sold->status);
        $this->assertSame(Order::PAYMENT_PAID, $sold->payment_status);
        $this->assertSame('complete/sold', $sold->external_status);
        $this->assertSame('49114.00', (string) $sold->total);

        $cancelled = Order::query()
            ->where('external_id', 'uid-cancelled')
            ->firstOrFail();

        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(Order::PAYMENT_REFUNDED, $cancelled->payment_status);
        $this->assertSame(
            'complete/canceled_by_client',
            $cancelled->external_status,
        );

        $new = Order::query()
            ->where('external_id', 'uid-new')
            ->firstOrFail();

        $this->assertSame(Order::STATUS_NEW, $new->status);
        $this->assertSame(Order::PAYMENT_PENDING, $new->payment_status);

        // 1000 копеек = 10 рублей: копейки не должны утекать в рубли.
        $this->assertSame('10.00', (string) $new->total);
    }

    public function test_links_items_to_local_product_through_listing(): void
    {
        $account = $this->account();

        $product = Product::create([
            'name' => 'Локальный товар',
            'slug' => 'lokalnyi-tovar',
            'sku' => 'ART-A',
            'sale_price' => 100,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        MarketplaceListing::create([
            'product_id' => $product->getKey(),
            'marketplace_account_id' => $account->getKey(),
            'external_id' => '111',
            'seller_sku' => 'ART-A',
            'name' => 'Название из карточки WB',
            'characteristics' => [],
            'images' => [],
            'raw_data' => [],
        ]);

        $this->fakeWildberries(
            [
                $this->task(301, 'uid-linked', 111, 'ART-A', 1000000),
                $this->task(302, 'uid-orphan', 999, 'ART-Z', 2000000),
            ],
            [
                ['id' => 301, 'supplierStatus' => 'complete', 'wbStatus' => 'sold'],
                ['id' => 302, 'supplierStatus' => 'complete', 'wbStatus' => 'sold'],
            ],
        );

        $this->import($account);

        $linked = Order::query()
            ->where('external_id', 'uid-linked')
            ->with('items')
            ->firstOrFail();

        $this->assertSame(
            $product->getKey(),
            $linked->items->first()->product_id,
        );

        // Название берётся из карточки маркетплейса, а не из артикула.
        $this->assertSame(
            'Название из карточки WB',
            $linked->items->first()->product_name,
        );

        $orphan = Order::query()
            ->where('external_id', 'uid-orphan')
            ->with('items')
            ->firstOrFail();

        // Без карточки заказ всё равно импортируется, но товар не связан.
        $this->assertNull($orphan->items->first()->product_id);
        $this->assertSame(
            'ART-Z',
            $orphan->items->first()->product_name,
        );
    }

    public function test_reimport_updates_orders_without_duplicating(): void
    {
        $account = $this->account();

        $payload = [
            $this->task(401, 'uid-repeat', 111, 'ART-A', 1000000),
        ];

        $supplierStatus = 'new';
        $wbStatus = 'waiting';

        // Одна заглушка на весь тест: повторный Http::fake() не заменяет
        // предыдущие правила, а дописывает их, и срабатывает первое
        // подошедшее — поэтому статус меняем через ссылку.
        Http::fake(function ($request) use (
            $payload,
            &$supplierStatus,
            &$wbStatus,
        ) {
            // Импортёр обходит три модели работы. Отвечаем заказом только
            // за FBS: иначе один и тот же orderUid приедет трижды и
            // счётчики created/updated перестанут значить то, что проверяем.
            if (
                str_contains($request->url(), '/dbs/')
                || str_contains($request->url(), '/dbw/')
            ) {
                return Http::response(['orders' => [], 'next' => 0]);
            }

            if (str_contains($request->url(), '/orders/status')) {
                return Http::response([
                    'orders' => [
                        [
                            'id' => 401,
                            'supplierStatus' => $supplierStatus,
                            'wbStatus' => $wbStatus,
                        ],
                    ],
                ]);
            }

            return Http::response([
                'orders' => $payload,
                'next' => 0,
            ]);
        });

        $first = $this->import($account);

        $this->assertSame(1, $first->created);
        $this->assertSame(0, $first->updated);

        $orderId = Order::query()
            ->where('external_id', 'uid-repeat')
            ->value('id');

        // Второй прогон: статус на маркетплейсе изменился.
        $supplierStatus = 'complete';
        $wbStatus = 'sold';

        $second = $this->import($account);

        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->updated);

        $this->assertSame(1, Order::query()->count());

        $order = Order::query()->findOrFail($orderId);

        $this->assertSame(Order::STATUS_COMPLETED, $order->status);
        $this->assertCount(1, $order->items);
    }

    /**
     * Витрина (DBS) отвечает по другим правилам, чем FBS: свой путь
     * статусов, поле ordersIds вместо orders и идентификатор orderId
     * вместо id. Форма ответа взята с живого кабинета.
     */
    public function test_imports_dbs_orders_with_their_own_status_endpoint(): void
    {
        $account = $this->account();

        Http::fake([
            '*/api/marketplace/v3/dbs/orders/status/info' => Http::response([
                'orders' => [
                    [
                        'supplierStatus' => 'receive',
                        'wbStatus' => 'sold',
                        'errors' => null,
                        'orderId' => 5457801056,
                    ],
                    [
                        'supplierStatus' => 'new',
                        'wbStatus' => 'declined_by_client',
                        'errors' => null,
                        'orderId' => 5386942008,
                    ],
                ],
            ]),

            '*/api/v3/dbs/orders*' => Http::response([
                'next' => 0,
                'orders' => [
                    $this->task(
                        5457801056,
                        'uid-dbs-sold',
                        818195263,
                        '17promax_256_blue_2esim',
                        11772000,
                        [
                            'deliveryType' => 'edbs',
                            'finalPrice' => 11772000,
                            'convertedFinalPrice' => 11772000,
                            'groupId' => 'group-1',
                            'address' => [
                                'fullAddress' => 'Москва, Кусковская улица, д. 1Ас4',
                            ],
                        ],
                    ),
                    $this->task(
                        5386942008,
                        'uid-dbs-declined',
                        818143002,
                        '17Air_256_Black',
                        8840000,
                        [
                            'deliveryType' => 'edbs',
                            'finalPrice' => 8840000,
                            'convertedFinalPrice' => 8840000,
                        ],
                    ),
                ],
            ]),

            '*' => Http::response(['orders' => [], 'next' => 0]),
        ]);

        $result = $this->import($account);

        $this->assertSame(2, $result->received);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->failed);

        $sold = Order::query()
            ->where('external_id', 'uid-dbs-sold')
            ->firstOrFail();

        $this->assertSame('dbs', $sold->fulfillment_type);
        $this->assertSame(Order::STATUS_COMPLETED, $sold->status);
        $this->assertSame(Order::PAYMENT_PAID, $sold->payment_status);

        // 11772000 копеек = 117 720 ₽ — столько же в кабинете WB.
        $this->assertSame('117720.00', (string) $sold->total);

        $this->assertSame(
            'Москва, Кусковская улица, д. 1Ас4',
            $sold->delivery_address,
        );

        $declined = Order::query()
            ->where('external_id', 'uid-dbs-declined')
            ->firstOrFail();

        // Отказ покупателя в первый час — отменённый заказ, а не новый,
        // хотя supplierStatus так и остался new.
        $this->assertSame(Order::STATUS_CANCELLED, $declined->status);
        $this->assertSame('88400.00', (string) $declined->total);
    }

    /**
     * API отдаёт максимум 30 календарных дней за запрос, поэтому глубина
     * режется на окна. Без этого DBS отвечает IncorrectParameter.
     */
    public function test_splits_long_period_into_windows(): void
    {
        $account = $this->account();

        Http::fake(['*' => Http::response(['orders' => [], 'next' => 0])]);

        app(WildberriesOrderImporter::class)->import(
            $account,
            CarbonImmutable::now()->subDays(90),
        );

        $widest = 0;

        Http::assertSent(function ($request) use (&$widest): bool {
            if ($request->method() !== 'GET') {
                return true;
            }

            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query,
            );

            $this->assertArrayHasKey('dateTo', $query);
            $this->assertArrayHasKey('dateFrom', $query);

            $widest = max(
                $widest,
                (int) $query['dateTo'] - (int) $query['dateFrom'],
            );

            return true;
        });

        $this->assertGreaterThan(0, $widest);
        $this->assertLessThanOrEqual(30 * 86400, $widest);
    }
}
