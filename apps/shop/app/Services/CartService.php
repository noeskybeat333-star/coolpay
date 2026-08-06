<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CartService
{
    private const SESSION_KEY = 'store.cart';

    public function add(
        Product $product,
        int $quantity = 1,
    ): void {
        $this->assertPurchasable($product);

        $quantities = $this->quantities();
        $productId = (string) $product->getKey();

        $newQuantity =
            ($quantities[$productId] ?? 0)
            + max(1, $quantity);

        $this->assertStockAvailable(
            $product,
            $newQuantity,
        );

        $quantities[$productId] = $newQuantity;

        $this->store($quantities);
    }

    public function update(
        Product $product,
        int $quantity,
    ): void {
        if ($quantity <= 0) {
            $this->remove($product);

            return;
        }

        $this->assertPurchasable($product);
        $this->assertStockAvailable(
            $product,
            $quantity,
        );

        $quantities = $this->quantities();
        $quantities[(string) $product->getKey()] = $quantity;

        $this->store($quantities);
    }

    public function remove(Product $product): void
    {
        $quantities = $this->quantities();

        unset($quantities[(string) $product->getKey()]);

        $this->store($quantities);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    public function count(): int
    {
        return array_sum($this->quantities());
    }

    /**
     * @return Collection<int, array{
     *     product: Product,
     *     quantity: int,
     *     unit_price: float,
     *     total: float,
     *     available: bool
     * }>
     */
    public function items(): Collection
    {
        $quantities = $this->quantities();

        if ($quantities === []) {
            return collect();
        }

        $products = Product::query()
            ->with([
                'primaryProductImage',
                'primaryMarketplaceListing',
            ])
            ->whereKey(array_keys($quantities))
            ->get()
            ->keyBy(
                fn (Product $product): string =>
                    (string) $product->getKey()
            );

        return collect($quantities)
            ->map(function (
                int $quantity,
                string $productId,
            ) use ($products): ?array {
                $product = $products->get($productId);

                if (! $product) {
                    return null;
                }

                $unitPrice = $product->store_price;

                return [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => round(
                        $unitPrice * $quantity,
                        2,
                    ),
                    'available' =>
                        $product->is_active
                        && $unitPrice > 0
                        && $product->stock_quantity >= $quantity,
                ];
            })
            ->filter()
            ->values();
    }

    public function subtotal(): float
    {
        return round(
            (float) $this->items()->sum('total'),
            2,
        );
    }

    /**
     * @return array<string, int>
     */
    public function quantities(): array
    {
        return collect(
            session()->get(self::SESSION_KEY, [])
        )
            ->mapWithKeys(function (
                mixed $quantity,
                mixed $productId,
            ): array {
                $productId = (int) $productId;
                $quantity = (int) $quantity;

                if (
                    $productId < 1
                    || $quantity < 1
                ) {
                    return [];
                }

                return [
                    (string) $productId => $quantity,
                ];
            })
            ->all();
    }

    private function store(array $quantities): void
    {
        if ($quantities === []) {
            $this->clear();

            return;
        }

        session()->put(
            self::SESSION_KEY,
            $quantities,
        );
    }

    private function assertPurchasable(
        Product $product,
    ): void {
        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'quantity' => 'Этот товар сейчас недоступен.',
            ]);
        }

        if ($product->store_price <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Для товара пока не установлена цена.',
            ]);
        }

        if ($product->stock_quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Товар закончился.',
            ]);
        }
    }

    private function assertStockAvailable(
        Product $product,
        int $quantity,
    ): void {
        if ($quantity > $product->stock_quantity) {
            throw ValidationException::withMessages([
                'quantity' => sprintf(
                    'Доступно только %d шт.',
                    $product->stock_quantity,
                ),
            ]);
        }
    }
}