<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function create(
        CartService $cart,
    ): View|RedirectResponse {
        $items = $cart->items();

        if ($items->isEmpty()) {
            return to_route('store.cart.index')
                ->withErrors([
                    'cart' => 'Корзина пуста.',
                ]);
        }

        if (
            $items->contains(
                fn (array $item): bool =>
                    ! $item['available']
            )
        ) {
            return to_route('store.cart.index')
                ->withErrors([
                    'cart' =>
                        'Проверьте недоступные позиции в корзине.',
                ]);
        }

        return view('store.checkout', [
            'items' => $items,
            'subtotal' => round(
                (float) $items->sum('total'),
                2,
            ),
        ]);
    }

    public function store(
        StoreOrderRequest $request,
        CartService $cart,
    ): RedirectResponse {
        $quantities = $cart->quantities();

        if ($quantities === []) {
            throw ValidationException::withMessages([
                'cart' => 'Корзина пуста.',
            ]);
        }

        $validated = $request->validated();

        $order = DB::transaction(
            function () use (
                $request,
                $quantities,
                $validated,
            ): Order {
                $products = Product::query()
                    ->with([
                        'primaryProductImage',
                        'primaryMarketplaceListing',
                    ])
                    ->whereKey(array_keys($quantities))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(
                        fn (Product $product): string =>
                            (string) $product->getKey()
                    );

                if ($products->count() !== count($quantities)) {
                    throw ValidationException::withMessages([
                        'cart' =>
                            'Один из товаров больше недоступен.',
                    ]);
                }

                $lines = collect();
                $subtotal = 0.0;

                foreach (
                    $quantities as $productId => $quantity
                ) {
                    /** @var Product|null $product */
                    $product = $products->get(
                        (string) $productId
                    );

                    if (
                        ! $product
                        || ! $product->is_active
                    ) {
                        throw ValidationException::withMessages([
                            'cart' =>
                                'Один из товаров больше недоступен.',
                        ]);
                    }

                    $unitPrice = $product->store_price;

                    if ($unitPrice <= 0) {
                        throw ValidationException::withMessages([
                            'cart' =>
                                "Для товара «{$product->name}» не установлена цена.",
                        ]);
                    }

                    if (
                        $quantity < 1
                        || $quantity > $product->stock_quantity
                    ) {
                        throw ValidationException::withMessages([
                            'cart' =>
                                "Изменился остаток товара «{$product->name}».",
                        ]);
                    }

                    $lineTotal = round(
                        $unitPrice * $quantity,
                        2,
                    );

                    $subtotal = round(
                        $subtotal + $lineTotal,
                        2,
                    );

                    $lines->push([
                        'product' => $product,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total' => $lineTotal,
                    ]);
                }

                $order = Order::create([
                    'user_id' =>
                        $request->user()?->getKey(),
                    'status' => Order::STATUS_NEW,
                    'payment_status' =>
                        Order::PAYMENT_PENDING,
                    'payment_method' =>
                        $validated['payment_method'],
                    'delivery_method' =>
                        $validated['delivery_method'],
                    'customer_name' =>
                        $validated['customer_name'],
                    'customer_phone' =>
                        $validated['customer_phone'],
                    'customer_email' =>
                        $validated['customer_email'] ?: null,
                    'delivery_address' =>
                        $validated['delivery_method']
                            === 'delivery'
                            ? $validated['delivery_address']
                            : null,
                    'customer_comment' =>
                        $validated['customer_comment'] ?: null,
                    'subtotal' => $subtotal,
                    'delivery_price' => 0,
                    'total' => $subtotal,
                    'currency' => 'RUB',
                    'placed_at' => now(),
                ]);

                foreach ($lines as $line) {
                    /** @var Product $product */
                    $product = $line['product'];

                    $order->items()->create([
                        'product_id' => $product->getKey(),
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'unit_price' => $line['unit_price'],
                        'quantity' => $line['quantity'],
                        'total' => $line['total'],
                        'product_snapshot' => [
                            'slug' => $product->slug,
                            'brand' => $product->brand,
                            'category' => $product->category,
                            'image_url' =>
                                $product->primary_image_url,
                            'stock_before' =>
                                $product->stock_quantity,
                        ],
                    ]);

                    $product->decrement(
                        'stock_quantity',
                        $line['quantity'],
                    );
                }

                return $order->load('items');
            },
            3,
        );

        $cart->clear();

        $request->session()->put(
            'store.last_order_id',
            $order->getKey(),
        );

        return to_route('store.checkout.success');
    }

    public function success(Request $request): View
    {
        $orderId = (int) $request->session()->get(
            'store.last_order_id'
        );

        abort_if($orderId < 1, 404);

        $order = Order::query()
            ->with('items')
            ->findOrFail($orderId);

        return view(
            'store.order-success',
            compact('order')
        );
    }
}
