@extends('layouts.store')

@section('title', 'Корзина — CoolPay')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12">
        <nav class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
            <a
                href="{{ route('store.home') }}"
                class="transition hover:text-violet-600 dark:hover:text-red-500"
            >
                Главная
            </a>

            <span>/</span>
            <span>Корзина</span>
        </nav>

        <div class="mt-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-bold uppercase tracking-widest text-violet-600 dark:text-red-500">
                    Ваш заказ
                </p>

                <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">
                    Корзина
                </h1>
            </div>

            @if ($items->isNotEmpty())
                <div class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $items->sum('quantity') }} шт.
                </div>
            @endif
        </div>

        @if (session('success'))
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @if ($items->isEmpty())
            <section class="mt-8 rounded-3xl border border-slate-200 bg-white px-6 py-16 text-center dark:border-white/10 dark:bg-[#0d1324]">
                <div class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400">
                    <svg
                        class="size-8"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21 7H6"></path>
                        <circle cx="10" cy="20" r="1"></circle>
                        <circle cx="18" cy="20" r="1"></circle>
                    </svg>
                </div>

                <h2 class="mt-5 text-2xl font-black">
                    Корзина пока пустая
                </h2>

                <p class="mx-auto mt-3 max-w-md text-slate-500 dark:text-slate-400">
                    Выберите товар в каталоге, и он появится здесь.
                </p>

                <a
                    href="{{ route('store.home') }}#catalog"
                    class="mt-7 inline-flex rounded-xl bg-violet-600 px-6 py-3 font-bold text-white transition hover:bg-violet-500 dark:bg-red-600 dark:hover:bg-red-500"
                >
                    Перейти в каталог
                </a>
            </section>
        @else
            <div class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <section class="grid gap-4">
                    @foreach ($items as $item)
                        @php
                            $product = $item['product'];
                        @endphp

                        <article class="rounded-3xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-[#0d1324] sm:p-5">
                            <div class="flex flex-col gap-5 sm:flex-row">
                                <a
                                    href="{{ route('store.products.show', $product) }}"
                                    class="flex size-32 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-white"
                                >
                                    @if ($product->primary_image_url)
                                        <img
                                            src="{{ $product->primary_image_url }}"
                                            alt="{{ $product->name }}"
                                            class="h-full w-full object-contain"
                                            loading="lazy"
                                        >
                                    @else
                                        <span class="text-xs text-slate-400">
                                            Нет фото
                                        </span>
                                    @endif
                                </a>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-col justify-between gap-4 sm:flex-row">
                                        <div>
                                            <a
                                                href="{{ route('store.products.show', $product) }}"
                                                class="text-lg font-black transition hover:text-violet-600 dark:hover:text-red-500"
                                            >
                                                {{ $product->name }}
                                            </a>

                                            @if ($product->sku)
                                                <div class="mt-2 text-xs text-slate-400">
                                                    Артикул: {{ $product->sku }}
                                                </div>
                                            @endif
                                        </div>

                                        <div class="shrink-0 sm:text-right">
                                            <div class="text-xl font-black">
                                                {{ number_format($item['total'], 0, ',', ' ') }} ₽
                                            </div>

                                            @if ($item['quantity'] > 1)
                                                <div class="mt-1 text-xs text-slate-400">
                                                    {{ number_format($item['unit_price'], 0, ',', ' ') }} ₽ за шт.
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    @unless ($item['available'])
                                        <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                            Товар недоступен в выбранном количестве. Проверьте остаток.
                                        </div>
                                    @endunless

                                    <div class="mt-5 flex flex-wrap items-end gap-3">
                                        <form
                                            action="{{ route('store.cart.update', $product) }}"
                                            method="POST"
                                            class="flex items-end gap-2"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <label class="grid gap-1 text-xs font-semibold text-slate-500 dark:text-slate-400">
                                                Количество

                                                <input
                                                    type="number"
                                                    name="quantity"
                                                    value="{{ $item['quantity'] }}"
                                                    min="1"
                                                    max="{{ max(1, $product->stock_quantity) }}"
                                                    class="h-10 w-20 rounded-xl border border-slate-200 bg-white px-3 text-slate-950 outline-none focus:border-violet-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-red-500"
                                                >
                                            </label>

                                            <button
                                                type="submit"
                                                class="h-10 rounded-xl bg-slate-100 px-4 text-sm font-bold transition hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10"
                                            >
                                                Обновить
                                            </button>
                                        </form>

                                        <form
                                            action="{{ route('store.cart.destroy', $product) }}"
                                            method="POST"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <button
                                                type="submit"
                                                class="h-10 rounded-xl px-3 text-sm font-bold text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                                            >
                                                Удалить
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </section>

                <aside class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#0d1324] lg:sticky lg:top-28">
                    <h2 class="text-xl font-black">
                        Итого
                    </h2>

                    <dl class="mt-5 grid gap-4 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500 dark:text-slate-400">
                                Товары
                            </dt>

                            <dd class="font-bold">
                                {{ number_format($subtotal, 0, ',', ' ') }} ₽
                            </dd>
                        </div>

                        <div class="flex justify-between gap-4 border-b border-slate-100 pb-4 dark:border-white/10">
                            <dt class="text-slate-500 dark:text-slate-400">
                                Доставка
                            </dt>

                            <dd class="text-right font-semibold">
                                Рассчитаем при оформлении
                            </dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="font-bold">К оплате</dt>

                            <dd class="text-2xl font-black">
                                {{ number_format($subtotal, 0, ',', ' ') }} ₽
                            </dd>
                        </div>
                    </dl>

                    @if ($canCheckout)
                        <a
                            href="{{ route('store.checkout.create') }}"
                            class="mt-6 block w-full rounded-xl bg-violet-600 px-6 py-4 text-center font-bold text-white transition hover:bg-violet-500 dark:bg-red-600 dark:hover:bg-red-500"
                        >
                            Перейти к оформлению
                        </a>
                    @else
                        <button
                            type="button"
                            disabled
                            class="mt-6 w-full cursor-not-allowed rounded-xl bg-slate-300 px-6 py-4 font-bold text-white dark:bg-slate-700"
                        >
                            Оформление недоступно
                        </button>
                    @endif

                    @unless ($canCheckout)
                        <p class="mt-3 text-xs leading-5 text-amber-700 dark:text-amber-300">
                            Перед оформлением исправьте недоступные позиции.
                        </p>
                    @endunless
                </aside>
            </div>
        @endif
    </div>
@endsection
