<?php

namespace App\Services;

use App\Models\MarketplaceListing;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceProductLinker
{
    /**
     * @return array{product: Product, created: bool}
     */
    public function createOrLinkDraft(
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
