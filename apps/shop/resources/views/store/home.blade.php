@extends('layouts.store')

@section('title', 'CoolPay — оригинальная электроника')

@section('content')
    @php
        $featuredProduct = $products->firstWhere(
            'is_featured',
            true
        ) ?? $products->first();
    @endphp

    <section class="relative overflow-hidden px-3 pt-5 sm:px-6 sm:pt-10">
        <div class="pointer-events-none absolute left-1/2 top-0 h-[650px] w-[1100px] -translate-x-1/2 rounded-full bg-violet-400/10 blur-[140px] dark:bg-red-600/10"></div>

        <div class="pointer-events-none absolute right-0 top-20 size-[420px] rounded-full bg-blue-400/10 blur-[130px] dark:bg-violet-600/10"></div>

        <div class="relative mx-auto max-w-[1420px]">
            <div class="overflow-hidden rounded-[24px] bg-white shadow-2xl shadow-slate-300/60 sm:rounded-[34px] dark:border dark:border-white/10 dark:bg-[#070b18] dark:shadow-black/40">
                <picture>
                    <source
                        media="(prefers-color-scheme: dark)"
                        srcset="{{ asset('images/coolpay-storefront-hero-dark.png') }}"
                    >

                    <img
                        src="{{ asset('images/coolpay-storefront-hero-light.png') }}"
                        alt="Добро пожаловать в магазин CoolPay"
                        fetchpriority="high"
                        class="block h-auto w-full"
                    >
                </picture>
            </div>

        </div>
    </section>

    <section
        id="catalog"
        class="scroll-mt-32 mx-auto max-w-7xl px-4 pb-10 pt-20 sm:px-6 sm:pt-24"
    >
        <div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-bold uppercase tracking-[0.2em] text-violet-600 dark:text-red-400">
                    Каталог CoolPay
                </p>

                <h2 class="mt-3 text-3xl font-black tracking-tight text-slate-950 sm:text-5xl dark:text-white">
                    Популярные товары
                </h2>

                <p class="mt-3 max-w-xl text-sm leading-6 text-slate-500 sm:text-base dark:text-slate-400">
                    Оригинальная электроника с проверкой, гарантией и доставкой по России.
                </p>
            </div>

            <div class="inline-flex w-fit rounded-full border border-slate-200 bg-white px-4 py-2 text-sm text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                Найдено товаров:

                <span class="ml-1 font-bold text-slate-950 dark:text-white">
                    {{ $products->count() }}
                </span>
            </div>
        </div>

        @if ($categories->isNotEmpty())
            <div class="mt-8 flex gap-2 overflow-x-auto pb-3">
                <a
                    href="{{ route('store.home') }}#catalog"
                    class="shrink-0 rounded-full px-5 py-2.5 text-sm font-semibold transition {{ $category === '' ? 'bg-violet-600 text-white shadow-lg shadow-violet-200 dark:bg-red-500 dark:shadow-red-950/40' : 'border border-slate-200 bg-white text-slate-600 hover:border-violet-300 hover:text-violet-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-red-400/50 dark:hover:text-white' }}"
                >
                    Все товары
                </a>

                @foreach ($categories as $item)
                    <a
                        href="{{ route('store.home', ['category' => $item]) }}#catalog"
                        class="shrink-0 rounded-full px-5 py-2.5 text-sm font-semibold transition {{ $category === $item ? 'bg-violet-600 text-white shadow-lg shadow-violet-200 dark:bg-red-500 dark:shadow-red-950/40' : 'border border-slate-200 bg-white text-slate-600 hover:border-violet-300 hover:text-violet-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-red-400/50 dark:hover:text-white' }}"
                    >
                        {{ $item }}
                    </a>
                @endforeach
            </div>
        @endif

        @if ($search !== '')
            <div class="mt-6 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/5">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Результаты поиска:

                    <strong class="text-slate-950 dark:text-white">
                        «{{ $search }}»
                    </strong>
                </p>

                <a
                    href="{{ route('store.home') }}#catalog"
                    class="text-sm font-semibold text-violet-600 transition hover:text-violet-500 dark:text-red-400 dark:hover:text-red-300"
                >
                    Сбросить
                </a>
            </div>
        @endif

        @if ($products->isEmpty())
            <div class="mt-8 rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-20 text-center dark:border-white/15 dark:bg-white/[0.03]">
                <h3 class="text-xl font-bold text-slate-950 dark:text-white">
                    Товары не найдены
                </h3>

                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Попробуйте изменить запрос или выбрать другую категорию.
                </p>
            </div>
        @else
            <div class="mt-8 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    @php
                        $storePrice = (float) $product->sale_price;
                        $basePrice = (float) $product->base_price;
                        $discountPercent = (int) $product->discount_percent;

                        $hasDiscount = $discountPercent > 0
                            && $basePrice > $storePrice
                            && $storePrice > 0;
                    @endphp

                    <article class="group flex min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white transition duration-300 hover:-translate-y-1 hover:border-violet-300 hover:shadow-2xl hover:shadow-violet-100 sm:rounded-3xl dark:border-white/10 dark:bg-[#0d1324] dark:hover:border-red-400/40 dark:hover:shadow-red-950/20">
                        <a
                            href="{{ route('store.products.show', $product) }}"
                           class="relative flex aspect-square items-center justify-center overflow-hidden bg-white"
                        >
                            @if ($product->is_featured)
                                <span class="absolute left-3 top-3 z-10 rounded-full bg-violet-600 px-2.5 py-1 text-[9px] font-extrabold uppercase tracking-wide text-white shadow-lg sm:text-xs dark:bg-red-500">
                                    Рекомендуем
                                </span>
                            @elseif ($hasDiscount)
                                <span class="absolute left-3 top-3 z-10 rounded-full bg-violet-600 px-2.5 py-1 text-[10px] font-extrabold text-white shadow-lg sm:text-xs dark:bg-red-500">
                                    −{{ $discountPercent }}%
                                </span>
                            @endif

                            @if ($product->primary_image_url)
                                <img
                                    src="{{ $product->primary_image_url }}"
                                    alt="{{ $product->name }}"
                                    loading="lazy"
                                    class="size-full scale-[1.28] object-contain transition duration-500 group-hover:scale-[1.32]"
                                >
                            @else
                                <div class="flex size-full items-center justify-center rounded-xl bg-slate-100 text-center text-xs text-slate-400">
                                    Нет изображения
                                </div>
                            @endif
                        </a>

                        <div class="flex flex-1 flex-col p-3 sm:p-5">
                            <div class="text-[10px] font-bold uppercase tracking-[0.15em] text-violet-500 sm:text-xs dark:text-red-400/80">
                                {{ $product->brand ?: $product->category }}
                            </div>

                            <h3 class="mt-2 line-clamp-2 text-sm font-bold leading-5 text-slate-950 sm:text-base sm:leading-6 dark:text-white">
                                <a
                                    href="{{ route('store.products.show', $product) }}"
                                    class="transition group-hover:text-violet-600 dark:group-hover:text-red-300"
                                >
                                    {{ $product->name }}
                                </a>
                            </h3>

                            <div class="mt-auto pt-5">
                                @if ($storePrice > 0)
                                    <div class="flex flex-wrap items-baseline gap-2">
                                        <span class="text-lg font-black text-slate-950 sm:text-2xl dark:text-white">
                                            {{ number_format($storePrice, 0, ',', ' ') }} ₽
                                        </span>

                                        @if ($hasDiscount)
                                            <span class="text-xs text-slate-400 line-through sm:text-sm dark:text-slate-500">
                                                {{ number_format($basePrice, 0, ',', ' ') }} ₽
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    <div class="text-sm font-bold text-slate-950 dark:text-white">
                                        Цена по запросу
                                    </div>
                                @endif

                                <div class="mt-4 flex items-center justify-between gap-2">
                                    <span class="inline-flex items-center gap-1.5 text-[11px] sm:text-xs {{ $product->stock_quantity > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }}">
                                        <span class="size-1.5 rounded-full bg-current"></span>

                                        {{ $product->stock_quantity > 0 ? 'В наличии' : 'Под заказ' }}
                                    </span>

                                    <a
                                        href="{{ route('store.products.show', $product) }}"
                                        class="inline-flex items-center justify-center rounded-lg bg-slate-950 px-3 py-2 text-[11px] font-bold text-white transition hover:bg-violet-600 sm:rounded-xl sm:px-4 sm:text-xs dark:border dark:border-white/10 dark:bg-white/5 dark:hover:border-red-400/50 dark:hover:bg-red-500"
                                    >
                                        Подробнее
                                    </a>
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection