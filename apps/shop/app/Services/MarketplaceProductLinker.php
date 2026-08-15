<?php

namespace App\Services;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Связь карточек маркетплейсов с товарами CRM.
 *
 * Здесь два разных по смыслу действия, и раньше они были одним:
 *
 * - `linkToProduct` — сказать «эта карточка и есть вот этот товар».
 *   Ничего не создаёт. Так карточки WB и Яндекса сходятся на одном
 *   товаре, и CRM становится концентратором каналов.
 *
 * - `createDraft` — завести в CoolPay новый товар по карточке.
 *   Это перенос в собственную витрину, а не сопоставление, и делать
 *   его молча, «заодно», неправильно: у пользователя может не быть
 *   намерения торговать этим товаром у себя.
 *
 * Товар создаётся выключенным и с нулевой ценой: витрина показывает
 * только `is_active`, а цены маркетплейсов включают комиссию площадки
 * и в рознице неприменимы.
 */
class MarketplaceProductLinker
{
    /**
     * Привязать карточку к существующему товару CRM.
     */
    public function linkToProduct(
        MarketplaceListing $listing,
        Product $product,
    ): void {
        $listing->update([
            'product_id' => $product->getKey(),
        ]);
    }

    /**
     * Отвязать карточку, не трогая сам товар.
     */
    public function unlink(
        MarketplaceListing $listing,
    ): void {
        $listing->update(['product_id' => null]);
    }

    /**
     * Найти товар CRM с таким же артикулом.
     *
     * У Яндекс Маркета `offerId` — это артикул продавца, поэтому
     * совпадение здесь надёжное. У Wildberries артикул тоже есть,
     * но заполнен не всегда.
     */
    public function findMatchingProduct(
        MarketplaceListing $listing,
    ): ?Product {
        $sku = trim(
            (string) ($listing->seller_sku ?: $listing->offer_id)
        );

        if ($sku === '') {
            return null;
        }

        return Product::query()
            ->whereRaw(
                'lower(trim(sku)) = ?',
                [Str::lower($sku)],
            )
            ->first();
    }

    /**
     * Связать по совпадению артикула все карточки подключения.
     *
     * Вызывается после каждого импорта каталога. Связывать карточки
     * руками человек не должен: если артикул на площадке и в CRM
     * совпадает, это и есть один товар, и подтверждать тут нечего.
     * Ручное связывание остаётся только для случаев, когда артикулы
     * разошлись.
     *
     * @return int сколько карточек связалось
     */
    public function autoLinkBySku(
        MarketplaceAccount $account,
    ): int {
        $listings = MarketplaceListing::query()
            ->where('marketplace_account_id', $account->getKey())
            ->whereNull('product_id')
            ->get(['id', 'seller_sku', 'offer_id']);

        if ($listings->isEmpty()) {
            return 0;
        }

        // Собираем артикулы товаров CRM одним запросом: по карточке
        // на запрос было бы сотни обращений к базе на каждый импорт.
        $productIdBySku = Product::query()
            ->whereNotNull('sku')
            ->pluck('id', 'sku')
            ->mapWithKeys(fn (int $id, string $sku): array => [
                Str::lower(trim($sku)) => $id,
            ]);

        $linked = 0;

        foreach ($listings as $listing) {
            $sku = Str::lower(trim(
                (string) ($listing->seller_sku ?: $listing->offer_id)
            ));

            if ($sku === '' || ! $productIdBySku->has($sku)) {
                continue;
            }

            $listing->update([
                'product_id' => $productIdBySku->get($sku),
            ]);

            $linked++;
        }

        return $linked;
    }

    /**
     * @return array{product: Product, created: bool}
     */
    public function createDraft(
        MarketplaceListing $listing,
    ): array {
        return DB::transaction(function () use (
            $listing,
        ): array {
            $listing = MarketplaceListing::query()
                ->with([
                    'product',
                    'account.integrationType',
                ])
                ->lockForUpdate()
                ->findOrFail($listing->getKey());

            if ($listing->product !== null) {
                return [
                    'product' => $listing->product,
                    'created' => false,
                ];
            }

            $sku = $this->resolveSku($listing);

            $existingProduct = Product::query()
                ->whereRaw(
                    'lower(trim(sku)) = ?',
                    [
                        Str::lower(trim($sku)),
                    ],
                )
                ->first();

            if ($existingProduct !== null) {
                $listing->update([
                    'product_id' => $existingProduct->getKey(),
                ]);

                return [
                    'product' => $existingProduct,
                    'created' => false,
                ];
            }

            $product = Product::query()->create([
                'name' => $listing->name,
                'slug' => $this->makeUniqueSlug(
                    $listing
                ),
                'sku' => $sku,
                'brand' => $listing->brand,
                'category' => $listing->category,
                'description' => $listing->description,
                'purchase_price' => null,
                'sale_price' => 0,
                'stock_quantity' => 0,
                'is_active' => false,
                'is_featured' => false,
            ]);

            $listing->update([
                'product_id' => $product->getKey(),
            ]);

            return [
                'product' => $product,
                'created' => true,
            ];
        });
    }

    private function resolveSku(
        MarketplaceListing $listing,
    ): string {
        $sku = trim(
            (string) (
                $listing->seller_sku
                ?: $listing->offer_id
            )
        );

        if ($sku !== '') {
            return $sku;
        }

        $marketplace = $listing
            ->account
            ?->integrationType
            ?->slug
            ?? 'marketplace';

        return $marketplace
            .'-'
            .$listing->external_id;
    }

    private function makeUniqueSlug(
        MarketplaceListing $listing,
    ): string {
        $base = Str::slug($listing->name);

        if ($base === '') {
            $base = 'product';
        }

        $base = Str::substr($base, 0, 180);

        if (
            ! Product::query()
                ->where('slug', $base)
                ->exists()
        ) {
            return $base;
        }

        $marketplace = $listing
            ->account
            ?->integrationType
            ?->slug
            ?? 'marketplace';

        $suffix = Str::slug(
            $marketplace
                .'-'
                .$listing->external_id
        );

        $candidate = $base.'-'.$suffix;
        $counter = 2;

        while (
            Product::query()
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base
                .'-'
                .$suffix
                .'-'
                .$counter;

            $counter++;
        }

        return $candidate;
    }
}
