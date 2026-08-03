@extends('layouts.store')

@section('title', $product->name.' — CoolPay')

@section(
    'description',
    $product->description
        ? \Illuminate\Support\Str::limit($product->description, 155)
        : $product->name
)

@section('content')
    @php
        $images = collect($product->image_urls)
            ->filter()
            ->values();

        $storePrice = (float) $product->sale_price > 0
            ? (float) $product->sale_price
            : (float) ($product->primaryMarketplaceListing?->price ?? 0);

        $basePrice = (float) $product->sale_price > 0
            ? null
            : (float) ($product->primaryMarketplaceListing?->base_price ?? 0);

        $characteristics = collect(
            $product->primaryMarketplaceListing?->characteristics ?? [])
            ->map(function ($characteristic): ?array {
                 if (! is_array($characteristic)) {
                    return null;
                }

                $name = trim(
                    (string) data_get(
                        $characteristic,
                        'name',
                        ''
                    )
                );

                $value = data_get(
                    $characteristic,
                    'value'
                );

                if (is_array($value)) {
                    $value = collect($value)
                        ->flatten()
                        ->filter(
                            fn ($item): bool =>
                            $item !== null
                            && $item !== ''
                        )
                        ->implode(', ');
                } elseif (is_bool($value)) {
                    $value = $value ? 'Да' : 'Нет';
                } elseif ($value !== null) {
                    $value = trim((string) $value);
                }

                if (
                    $name === ''
                    || $value === null
                    || $value === ''
                ) {
                    return null;
                }

                return [
                    'name' => $name,
                    'value' => $value,
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values();

    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-10">
        <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <a
                href="{{ route('store.home') }}"
                class="transition hover:text-red-600"
            >
                Главная
            </a>

            <span>/</span>

            @if ($product->category)
                <a
                    href="{{ route('store.home', ['category' => $product->category]) }}#catalog"
                    class="transition hover:text-red-600"
                >
                    {{ $product->category }}
                </a>

                <span>/</span>
            @endif

            <span class="max-w-xs truncate text-slate-900">
                {{ $product->name }}
            </span>
        </nav>

        <div class="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(380px,0.85fr)] lg:gap-12">
            <section class="min-w-0">
                <div class="grid gap-4 sm:grid-cols-[88px_minmax(0,1fr)]">
                    @if ($images->count() > 1)
                        <div class="order-2 flex gap-3 overflow-x-auto sm:order-1 sm:flex-col">
                            @foreach ($images as $index => $image)
                                <button
                                    type="button"
                                    data-gallery-thumbnail
                                    data-image="{{ $image }}"
                                    class="size-20 shrink-0 overflow-hidden rounded-xl border bg-white p-2 transition {{ $index === 0 ? 'border-red-500 ring-2 ring-red-100' : 'border-slate-200 hover:border-red-300' }}"
                                    aria-label="Открыть изображение {{ $index + 1 }}"
                                >
                                    <img
                                        src="{{ $image }}"
                                        alt="{{ $product->name }} — фото {{ $index + 1 }}"
                                        loading="lazy"
                                        class="size-full object-contain"
                                    >
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <div class="order-1 flex aspect-square min-w-0 items-center justify-center overflow-hidden rounded-3xl border border-slate-200 bg-white p-5 sm:order-2 sm:p-10">
                        @if ($images->isNotEmpty())
                            <img
                                data-gallery-main
                                src="{{ $images->first() }}"
                                alt="{{ $product->name }}"
                                class="size-full object-contain"
                            >
                        @else
                            <div class="text-sm text-slate-400">
                                Изображение отсутствует
                            </div>
                        @endif
                    </div>
                </div>

                @if ($images->count() > 1)
                    <p class="mt-3 text-xs text-slate-400 sm:pl-[104px]">
                        Доступно изображений: {{ $images->count() }}
                    </p>
                @endif
            </section>

            <section>
                <div class="lg:sticky lg:top-28">
                    <div class="text-sm font-semibold uppercase tracking-widest text-red-600">
                        {{ $product->brand ?: $product->category }}
                    </div>

                    <h1 class="mt-3 text-3xl font-black leading-tight tracking-tight sm:text-4xl">
                        {{ $product->name }}
                    </h1>

                    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm text-slate-500">
                        @if ($product->sku)
                            <span>
                                Артикул: {{ $product->sku }}
                            </span>
                        @endif

                        <span class="{{ $product->stock_quantity > 0 ? 'text-emerald-600' : 'text-amber-600' }}">
                            {{ $product->stock_quantity > 0 ? 'В наличии' : 'Под заказ' }}
                        </span>
                    </div>

                    <div class="mt-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        @if ($storePrice > 0)
                            <div class="flex flex-wrap items-baseline gap-3">
                                <span class="text-4xl font-black tracking-tight">
                                    {{ number_format($storePrice, 0, ',', ' ') }} ₽
                                </span>

                                @if ($basePrice && $basePrice > $storePrice)
                                    <span class="text-lg text-slate-400 line-through">
                                        {{ number_format($basePrice, 0, ',', ' ') }} ₽
                                    </span>
                                @endif
                            </div>
                        @else
                            <div class="text-2xl font-black">
                                Цена по запросу
                            </div>
                        @endif

                        <button
                            type="button"
                            disabled
                            class="mt-6 w-full cursor-not-allowed rounded-xl bg-slate-300 px-6 py-4 text-base font-bold text-white"
                            title="Корзину подключим на следующем этапе"
                        >
                            Корзина скоро появится
                        </button>

                        <div class="mt-6 grid gap-4 border-t border-slate-100 pt-6 text-sm">
                            <div class="flex gap-3">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
                                    ✓
                                </div>

                                <div>
                                    <div class="font-bold">Проверка перед отправкой</div>
                                    <div class="mt-1 text-slate-500">Проверяем комплектность и внешний вид товара</div>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
                                    ✓
                                </div>

                                <div>
                                    <div class="font-bold">Гарантия</div>
                                    <div class="mt-1 text-slate-500">Поможем с гарантийным обслуживанием</div>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-red-50 text-red-600">
                                    ✓
                                </div>

                                <div>
                                    <div class="font-bold">Доставка по России</div>
                                    <div class="mt-1 text-slate-500">Способ и срок доставки уточняются при оформлении</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section class="mt-14 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 sm:p-8">
                <h2 class="text-2xl font-black">
                    О товаре
                </h2>

                <div class="mt-5 whitespace-pre-line text-base leading-7 text-slate-600">
                    {{ $product->description ?: 'Описание товара пока не добавлено.' }}
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 sm:p-8">
                <h2 class="text-2xl font-black">
                    Основные данные
                </h2>

                <dl class="mt-5 grid gap-4 text-sm">
                    @if ($product->brand)
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-500">Бренд</dt>
                            <dd class="text-right font-semibold">{{ $product->brand }}</dd>
                        </div>
                    @endif

                    @if ($product->category)
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-500">Категория</dt>
                            <dd class="text-right font-semibold">{{ $product->category }}</dd>
                        </div>
                    @endif

                    @if ($product->sku)
                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-500">Артикул</dt>
                            <dd class="break-all text-right font-semibold">{{ $product->sku }}</dd>
                        </div>
                    @endif

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Остаток</dt>
                        <dd class="text-right font-semibold">
                            {{ $product->stock_quantity }} шт.
                        </dd>
                    </div>
                </dl>
            </div>
        </section>


        @include('store.partials.product-characteristics')

        <div class="mt-10">
            <a
                href="{{ route('store.home') }}#catalog"
                class="inline-flex items-center gap-2 text-sm font-bold text-red-600 transition hover:text-red-700"
            >
                ← Вернуться в каталог
            </a>
        </div>
    </div>

    @if ($images->count() > 1)
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const mainImage = document.querySelector('[data-gallery-main]');
                const thumbnails = document.querySelectorAll('[data-gallery-thumbnail]');

                thumbnails.forEach((thumbnail) => {
                    thumbnail.addEventListener('click', () => {
                        if (! mainImage) {
                            return;
                        }

                        mainImage.src = thumbnail.dataset.image;

                        thumbnails.forEach((item) => {
                            item.classList.remove(
                                'border-red-500',
                                'ring-2',
                                'ring-red-100'
                            );

                            item.classList.add('border-slate-200');
                        });

                        thumbnail.classList.remove('border-slate-200');
                        thumbnail.classList.add(
                            'border-red-500',
                            'ring-2',
                            'ring-red-100'
                        );
                    });
                });
            });
        </script>
    @endif
@endsection