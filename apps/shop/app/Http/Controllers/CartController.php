<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(CartService $cart): View
    {
        $items = $cart->items();

        return view('store.cart', [
            'items' => $items,
            'subtotal' => round(
                (float) $items->sum('total'),
                2,
            ),
            'canCheckout' =>
                $items->isNotEmpty()
                && $items->every(
                    fn (array $item): bool =>
                        $item['available']
                ),
        ]);
    }

    public function store(
        Request $request,
        Product $product,
        CartService $cart,
    ): RedirectResponse {
        $validated = $request->validate([
            'quantity' => [
                'nullable',
                'integer',
                'min:1',
                'max:99',
            ],
        ]);

        $cart->add(
            $product,
            (int) ($validated['quantity'] ?? 1),
        );

        return to_route('store.cart.index')
            ->with(
                'success',
                'Товар добавлен в корзину.'
            );
    }

    public function update(
        Request $request,
        Product $product,
        CartService $cart,
    ): RedirectResponse {
        $validated = $request->validate([
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:99',
            ],
        ]);

        $cart->update(
            $product,
            (int) $validated['quantity'],
        );

        return to_route('store.cart.index')
            ->with(
                'success',
                'Количество обновлено.'
            );
    }

    public function destroy(
        Product $product,
        CartService $cart,
    ): RedirectResponse {
        $cart->remove($product);

        return to_route('store.cart.index')
            ->with(
                'success',
                'Товар удалён из корзины.'
            );
    }
}
