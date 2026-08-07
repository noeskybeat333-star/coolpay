@extends('layouts.store')

@section('title', 'Заказ оформлен — CoolPay')

@section('content')
    @php
        $deliveryLabel = match ($order->delivery_method) {
            'pickup' => 'Самовывоз',
            default => 'Доставка',
        };

        $paymentLabel = match ($order->payment_method) {
            'cash' => 'Наличными',
            default => 'Перевод после подтверждения',
        };
    @endphp

    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 sm:py-16">
        <section class="rounded-3xl border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-white/10 dark:bg-[#0d1324] sm:p-10">
            <div class="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-100 text-3xl text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
                ✓
            </div>

            <p class="mt-6 text-sm font-bold uppercase tracking-widest text-violet-600 dark:text-red-500">
                Заказ принят
            </p>

            <h1 class="mt-2 text-3xl font-black tracking-tight">
                Спасибо за заказ!
            </h1>

            <p class="mt-3 text-slate-500 dark:text-slate-400">
                Номер заказа:
                <span class="font-bold text-slate-950 dark:text-white">
                    {{ $order->number }}
                </span>
            </p>

            <p class="mx-auto mt-4 max-w-xl leading-7 text-slate-600 dark:text-slate-300">
                Мы проверим наличие и свяжемся с вами по номеру
                <span class="font-bold">{{ $order->customer_phone }}</span>
                для подтверждения доставки и оплаты.
            </p>

            <div class="mt-8 rounded-2xl bg-slate-50 p-5 text-left dark:bg-white/5">
                <div class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <div class="text-slate-400">Получатель</div>
                        <div class="mt-1 font-bold">
                            {{ $order->customer_name }}
                        </div>
                    </div>

                    <div>
                        <div class="text-slate-400">Способ получения</div>
                        <div class="mt-1 font-bold">
                            {{ $deliveryLabel }}
                        </div>
                    </div>

                    <div>
                        <div class="text-slate-400">Способ оплаты</div>
                        <div class="mt-1 font-bold">
                            {{ $paymentLabel }}
                        </div>
                    </div>

                    <div>
                        <div class="text-slate-400">Сумма без доставки</div>
                        <div class="mt-1 font-bold">
                            {{ number_format((float) $order->total, 0, ',', ' ') }} ₽
                        </div>
                    </div>
                </div>

                @if ($order->delivery_address)
                    <div class="mt-4 border-t border-slate-200 pt-4 text-sm dark:border-white/10">
                        <div class="text-slate-400">Адрес доставки</div>
                        <div class="mt-1 font-bold">
                            {{ $order->delivery_address }}
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a
                    href="{{ route('store.home') }}#catalog"
                    class="rounded-xl bg-violet-600 px-6 py-3 font-bold text-white transition hover:bg-violet-500 dark:bg-red-600 dark:hover:bg-red-500"
                >
                    Вернуться в каталог
                </a>

                <a
                    href="{{ route('store.home') }}"
                    class="rounded-xl bg-slate-100 px-6 py-3 font-bold transition hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10"
                >
                    На главную
                </a>
            </div>
        </section>
    </div>
@endsection

