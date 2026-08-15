<?php

namespace Tests\Feature;

use App\Integrations\Drivers\WildberriesDriver;
use App\Models\IntegrationType;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WildberriesCatalogImportTest extends TestCase
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
     * @return array<string, mixed>
     */
    private function card(): array
    {
        return [
            'nmID' => 111,
            'vendorCode' => 'SKU-1',
            'title' => 'Наушники',
            'brand' => 'Acme',
            'subjectName' => 'Наушники',
            'description' => 'Описание',
            'photos' => [],
            'characteristics' => [],
            'sizes' => [
                [
                    'chrtID' => 555,
                    'skus' => ['2000000000015'],
                ],
            ],
        ];
    }

    /**
     * Цены и остатки живут в других API со своими лимитерами. Их отказ
     * не должен отменять уже полученные карточки: иначе каждый повтор
     * заново выкачивает содержимое, которое и так дошло.
     */
    public function test_отказ_по_ценам_не_отменяет_карточки(): void
    {
        $account = $this->account();

        Http::fake([
            'marketplace-api.wildberries.ru/api/v3/warehouses' =>
                Http::response([
                    ['id' => 1, 'name' => 'Склад'],
                ]),

            'content-api.wildberries.ru/*' => Http::response([
                'cards' => [$this->card()],
                'cursor' => ['total' => 1],
            ]),

            'discounts-prices-api.wildberries.ru/*' => Http::response(
                ['detail' => 'too many requests'],
                429,
                ['X-Ratelimit-Retry' => '380'],
            ),
        ]);

        $result = app(WildberriesDriver::class)
            ->importCatalog($account);

        $this->assertSame(0, $result->failed);
        $this->assertSame(1, $result->received);
        $this->assertSame(1, $result->created);

        $this->assertTrue($result->pricesDeferred);
        $this->assertSame(400, $result->pricesRetryAfterSeconds);

        $listing = MarketplaceListing::query()
            ->where('external_id', '111')
            ->sole();

        $this->assertSame('Наушники', $listing->name);
        $this->assertSame('SKU-1', $listing->offer_id);

        // Главное: несостоявшиеся цены не подменяются нулями.
        // Иначе CRM показала бы «нет цены» и нулевой остаток
        // из-за чужого лимита частоты.
        $this->assertNull($listing->status);
        $this->assertNull($listing->stock_quantity);
        $this->assertNull($listing->price);

        // За остатками ходить уже незачем: лимитер у WB общий на
        // кабинет, лишний запрос только продлил бы блокировку.
        Http::assertNotSent(
            fn ($request): bool => str_contains(
                $request->url(),
                '/api/v3/stocks/',
            ),
        );
    }

    public function test_успешный_импорт_заполняет_цены_и_остатки(): void
    {
        $account = $this->account();

        Http::fake([
            'marketplace-api.wildberries.ru/api/v3/warehouses' =>
                Http::response([
                    ['id' => 1, 'name' => 'Склад'],
                ]),

            'marketplace-api.wildberries.ru/api/v3/stocks/*' =>
                Http::response([
                    'stocks' => [
                        ['chrtId' => 555, 'amount' => 7],
                    ],
                ]),

            'content-api.wildberries.ru/*' => Http::response([
                'cards' => [$this->card()],
                'cursor' => ['total' => 1],
            ]),

            'discounts-prices-api.wildberries.ru/*' => Http::response([
                'data' => [
                    'listGoods' => [
                        [
                            'nmID' => 111,
                            'discount' => 20,
                            'sizes' => [
                                [
                                    'price' => 5000,
                                    'discountedPrice' => 4000,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(WildberriesDriver::class)
            ->importCatalog($account);

        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->pricesDeferred);

        $listing = MarketplaceListing::query()
            ->where('external_id', '111')
            ->sole();

        $this->assertEquals(4000, $listing->price);
        $this->assertEquals(5000, $listing->base_price);
        $this->assertSame(20, $listing->discount_percent);
    }
}
