<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="color-scheme"
        content="light dark"
    >

    <title>
        @yield('title', 'CoolPay — магазин электроники')
    </title>

    <meta
        name="description"
        content="@yield('description', 'Оригинальная электроника с гарантией и быстрой доставкой.')"
    >

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-[#f5f6f8] text-slate-950 antialiased transition-colors duration-300 dark:bg-[#050816] dark:text-white">
    <div class="border-b border-slate-200 bg-white text-slate-600 transition-colors duration-300 dark:border-white/10 dark:bg-[#050816] dark:text-slate-300">
        <div class="mx-auto flex max-w-7xl items-center justify-center px-4 py-2 text-center text-xs font-medium sm:px-6 sm:text-sm">
            Оригинальная техника · Гарантия качества · Доставка по России
        </div>
    </div>

    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 text-slate-950 shadow-sm backdrop-blur-xl transition-colors duration-300 dark:border-white/10 dark:bg-[#070b18]/95 dark:text-white dark:shadow-xl dark:shadow-black/10">
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
            <div class="flex h-18 items-center gap-4">
                <a
                    href="{{ route('store.home') }}"
                    class="shrink-0 text-2xl font-black tracking-tight text-slate-950 dark:text-white"
                >
                    Cool<span class="text-violet-600 dark:text-red-500">Pay</span>
                </a>

                <a
                    href="{{ route('store.home') }}#catalog"
                    class="hidden rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-violet-600 dark:border dark:border-white/10 dark:bg-white/5 dark:hover:border-red-500/50 dark:hover:bg-red-500/10 md:inline-flex"
                >
                    Каталог
                </a>

                <form
                    action="{{ route('store.home') }}"
                    method="GET"
                    class="hidden min-w-0 flex-1 lg:block"
                >
                    <label class="relative block">
                        <span class="sr-only">
                            Поиск товаров
                        </span>

                        <svg
                            class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400 dark:text-slate-500"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="m20 20-3.5-3.5"></path>
                        </svg>

                        <input
                            type="search"
                            name="q"
                            value="{{ request('q') }}"
                            placeholder="Найти смартфон, наушники или аксессуар"
                            class="h-12 w-full rounded-xl border border-slate-200 bg-slate-100 pl-12 pr-4 text-sm text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:bg-white focus:ring-4 focus:ring-violet-100 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-red-500/70 dark:focus:bg-white/10 dark:focus:ring-red-500/10"
                        >
                    </label>
                </form>

                <nav class="ml-auto flex items-center gap-2">
                    <a
                        href="{{ route('store.home') }}#catalog"
                        class="inline-flex size-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 transition hover:border-violet-300 hover:bg-violet-50 hover:text-violet-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300 dark:hover:border-red-500/50 dark:hover:bg-red-500/10 dark:hover:text-white lg:hidden"
                        aria-label="Поиск и каталог"
                    >
                        <svg
                            class="size-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="m20 20-3.5-3.5"></path>
                        </svg>
                    </a>

                    <a
                        href="{{ route('store.cart.index') }}"
                        class="relative inline-flex size-11 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-violet-700 text-white shadow-lg shadow-violet-200 transition hover:from-violet-400 hover:to-violet-600 dark:from-red-500 dark:to-red-700 dark:shadow-red-950/30 dark:hover:from-red-400 dark:hover:to-red-600"
                        aria-label="Корзина"
                    >
                        <svg
                            class="size-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 2-1.6L21 7H6"></path>
                            <circle cx="10" cy="20" r="1"></circle>
                            <circle cx="18" cy="20" r="1"></circle>
                        </svg>

                        <span class="absolute -right-1 -top-1 flex size-5 items-center justify-center rounded-full bg-slate-950 text-[10px] font-bold text-white dark:border dark:border-white/20 dark:bg-[#050816]">
                            {{ app(\App\Services\CartService::class)->count() }}
                        </span>
                    </a>
                </nav>
            </div>

            <form
                action="{{ route('store.home') }}"
                method="GET"
                class="pb-3 lg:hidden"
            >
                <input
                    type="search"
                    name="q"
                    value="{{ request('q') }}"
                    placeholder="Поиск по каталогу"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-slate-100 px-4 text-sm text-slate-950 outline-none placeholder:text-slate-400 focus:border-violet-500 focus:bg-white dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-500 dark:focus:border-red-500/70 dark:focus:bg-white/10"
                >
            </form>
        </div>
    </header>

    <main class="min-h-[60vh]">
        @yield('content')
    </main>

    <footer class="mt-20 border-t border-slate-200 bg-white text-slate-950 transition-colors duration-300 dark:border-white/10 dark:bg-[#070b18] dark:text-white">
        <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-3">
            <div>
                <div class="text-2xl font-black tracking-tight">
                    Cool<span class="text-violet-600 dark:text-red-500">Pay</span>
                </div>

                <p class="mt-4 max-w-sm text-sm leading-6 text-slate-500 dark:text-slate-400">
                    Магазин оригинальной электроники. Помогаем выбрать технику и доставляем по России.
                </p>
            </div>

            <div>
                <h2 class="font-bold">
                    Покупателям
                </h2>

                <div class="mt-4 grid gap-3 text-sm text-slate-500 dark:text-slate-400">
                    <span>Доставка и оплата</span>
                    <span>Гарантия и возврат</span>
                    <span>Контакты</span>
                </div>
            </div>

            <div>
                <h2 class="font-bold">
                    Связаться с нами
                </h2>

                <p class="mt-4 text-sm leading-6 text-slate-500 dark:text-slate-400">
                    Контактные данные добавим перед публикацией магазина.
                </p>
            </div>
        </div>

        <div class="border-t border-slate-100 dark:border-white/10">
            <div class="mx-auto max-w-7xl px-4 py-5 text-xs text-slate-400 dark:text-slate-500 sm:px-6">
                © {{ now()->year }} CoolPay
            </div>
        </div>
    </footer>
</body>
</html>