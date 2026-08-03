<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    public function index(
        Request $request,
    ): View {
        $search = trim(
            (string) $request->query('q', '')
        );

        $category = trim(
            (string) $request->query('category', '')
        );

        $products = Product::query()
            ->with([
                'primaryProductImage',
                'primaryMarketplaceListing',
            ])
            ->where('is_active', true)
            ->when(
                $search !== '',
                function ($query) use ($search): void {
                    $value = '%'.mb_strtolower($search).'%';

                    $query->where(
                        function ($query) use ($value): void {
                            $query
                                ->whereRaw(
                                    'lower(name) like ?',
                                    [$value]
                                )
                                ->orWhereRaw(
                                    'lower(coalesce(sku, \'\')) like ?',
                                    [$value]
                                )
                                ->orWhereRaw(
                                    'lower(coalesce(brand, \'\')) like ?',
                                    [$value]
                                )
                                ->orWhereRaw(
                                    'lower(coalesce(category, \'\')) like ?',
                                    [$value]
                                );
                        }
                    );
                }
            )
            ->when(
                $category !== '',
                fn ($query) => $query->where(
                    'category',
                    $category
                )
            )
            ->orderByDesc('is_featured')
            ->latest()
            ->take(24)
            ->get();

        $categories = Product::query()
            ->where('is_active', true)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view(
            'store.home',
            compact(
                'products',
                'categories',
                'search',
                'category',
            )
        );
    }

    public function show(Product $product): View
    {
        abort_unless(
            $product->is_active,
            404
        );

        $product->load([
            'productImages',
            'primaryMarketplaceListing',
        ]);

        return view(
            'store.product',
            compact('product')
        );
    }
}