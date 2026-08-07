@extends('layouts.store')

@section('title', 'Оформление заказа — CoolPay')

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

            <a
                href="{{ route('store.cart.index') }}"
                class="transition hover:text-violet-600 dark:hover:text-red-500"
            >
                Корзина
            </a>

            <span>/</span>
            <span>Оформление</span>
        </nav>

        <div class="mt-6">
            <p class="text-sm font-bold uppercase tracking-widest text-violet-600 dark:text-red-500">
                Последний шаг
            </p>

            <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">
                Оформление заказа
            </h1>
        </div>

        @if ($errors->any())
            <div class="mt-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form
            action="{{ route('store.checkout.store') }}"
            method="POST"
            class="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]"
        >
            @csrf

            <div class="grid gap-6">
                <section class="rounded-3xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-[#0d1324] sm:p-8">
                    <h2 class="text-xl font-black">
                        Контактные данные
                    </h2>

                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2 text-sm font-bold sm:col-span-2">
                            Имя получателя

                            <input
                                type="text"
                                name="customer_name"
                                value="{{ old('customer_name', auth()->user()?->name) }}"
                                required
                                maxlength="150"
                                autocomplete="name"
                                class="h-12 rounded-xl border border-slate-200 bg-white px-4 font-normal text-slate-950 outline-none focus:border-violet-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-red-500"
                            >
                        </label>

                        <label class="grid gap-2 text-sm font-bold">
                            Телефон

                            <input
                                type="tel"
                                name="customer_phone"
                                value="{{ old('customer_phone') }}"
                                required
                                maxlength="50"
                                autocomplete="tel"
                                placeholder="+7 999 000-00-00"
                                class="h-12 rounded-xl border border-slate-200 bg-white px-4 font-normal text-slate-950 outline-none placeholder:text-slate-400 focus:border-violet-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-red-500"
                            >
                        </label>

                        <label class="grid gap-2 text-sm font-bold">
                            Email
                            <span class="font-normal text-slate-400">необязательно</span>

                            <input
                                type="email"
                                name="customer_email"
                                value="{{ old('customer_email', auth()->user()?->email) }}"
                                maxlength="255"
                                autocomplete="email"
                                class="h-12 rounded-xl border border-slate-200 bg-white px-4 font-normal text-slate-950 outline-none focus:border-violet-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-red-500"
                            >
                        </label>
                    </div>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-[#0d1324] sm:p-8">
                    <h2 class="text-xl font-black">
                        Способ получения
                    </h2>

                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 transition has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 dark:border-white/10 dark:has-[:checked]:border-red-500 dark:has-[:checked]:bg-red-500/10">
                            <div class="flex gap-3">
                                <input
                                    type="radio"
                                    name="delivery_method"
                                    value="delivery"
                                    class="mt-1"
                                    @checked(old('delivery_method', 'delivery') === 'delivery')
                                >

                                <div>
                                    <div class="font-bold">Доставка</div>
                                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                        Стоимость и срок подтвердит менеджер
                                    </div>
                                </div>
                            </div>
                        </label>

                        <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 transition has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 dark:border-white/10 dark:has-[:checked]:border-red-500 dark:has-[:checked]:bg-red-500/10">
                            <div class="flex gap-3">
                                <input
                                    type="radio"
                                    name="delivery_method"
                                    value="pickup"
                                    class="mt-1"
                                    @checked(old('delivery_method') === 'pickup')
                                >

                                <div>
                                    <div class="font-bold">Самовывоз</div>
                                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                        Адрес и время подтвердит менеджер
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>

                    <label class="mt-5 grid gap-2 text-sm font-bold">
                        Адрес доставки

                        <textarea
                            name="delivery_address"
                            rows="3"
                            maxlength="1000"
                            placeholder="Город, улица, дом, квартира"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal text-slate-950 outline-none placeholder:text-slate-400 focus:border-violet-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-red-500"
                        >{{ old('delivery_address') }}</textarea>
                    </label>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-[#0d1324] sm:p-8">
                    <h2 class="text-xl font-black">
                        Оплата
                    </h2>

                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 transition has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 dark:border-white/10 dark:has-[:checked]:border-red-500 dark:has-[:checked]:bg-red-500/10">
                            <div class="flex gap-3">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="bank_transfer"
                                    class="mt-1"
                                    @checked(old('payment_method', 'bank_transfer') === 'bank_transfer')
                                >

                                <div>
                                    <div class="font-bold">Перевод</div>
                                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                        После подтверждения заказа
                                    </div>
                                </div>
                            </div>
                        </label>

                        <label class="cursor-pointer rounded-2xl border border-slate-200 p-4 transition has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 dark:border-white/10 dark:has-[:checked]:border-red-500 dark:has-[:checked]:bg-red-500/10">
                            <div class="flex gap-3">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="cash"
                                    class="mt-1"
                                    @checked(old('payment_method') === 'cash')
                                >

                                <div>
                                    <div class="font-bold">Наличными</div>
                                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                        При получении после согласования
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>

                    <label class="mt-5 grid gap-2 text-sm font-bold">
                        Комментарий
                        <span class="font-normal text-slate-400">необязательно</span>

                        <textarea
                            name="customer_comment"
                            rows="3"
                            maxlength="2000"
                            placeholder="Пожелания к заказу"
                            class="rounded-xl border border-slate-200 bg-white px-4 py-3 font-normal text-slate-950 outline-none placeholder:text-slate-400 focus:border-violet-500 dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-red-500"
                        >{{ old('customer_comment') }}</textarea>
                    </label>
                </section>
            </div>

            <aside class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-[#0d1324] lg:sticky lg:top-28">
                <h2 class="text-xl font-black">
                    Ваш заказ
                </h2>

                <div class="mt-5 grid gap-4">
                    @foreach ($items as $item)
                        @php
                            $product = $item['product'];
                        @endphp

                        <div class="flex gap-3">
                            <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white">
                                @if ($product->primary_image_url)
                                    <img
                                        src="{{ $product->primary_image_url }}"
                                        alt="{{ $product->name }}"
                                        class="h-full w-full object-contain"
                                    >
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="line-clamp-2 text-sm font-bold">
                                    {{ $product->name }}
                                </div>

                                <div class="mt-1 flex justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
                                    <span>{{ $item['quantity'] }} шт.</span>
                                    <span class="font-semibold">
                                        {{ number_format($item['total'], 0, ',', ' ') }} ₽
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <dl class="mt-6 grid gap-4 border-t border-slate-100 pt-5 text-sm dark:border-white/10">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">
                            Товары
                        </dt>

                        <dd class="font-bold">
                            {{ number_format($subtotal, 0, ',', ' ') }} ₽
                        </dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400">
                            Доставка
                        </dt>

                        <dd class="text-right font-semibold">
                            После согласования
                        </dd>
                    </div>

                    <div class="flex items-baseline justify-between gap-4 border-t border-slate-100 pt-4 dark:border-white/10">
                        <dt class="font-bold">Итого без доставки</dt>

                        <dd class="text-2xl font-black">
                            {{ number_format($subtotal, 0, ',', ' ') }} ₽
                        </dd>
                    </div>
                </dl>

                <button
                    type="submit"
                    class="mt-6 w-full rounded-xl bg-violet-600 px-6 py-4 font-bold text-white transition hover:bg-violet-500 dark:bg-red-600 dark:hover:bg-red-500"
                >
                    Оформить заказ
                </button>

                <p class="mt-4 text-xs leading-5 text-slate-400">
                    После оформления менеджер свяжется с вами для подтверждения наличия, доставки и оплаты.
                </p>
            </aside>
        </form>
    </div>
@endsection
