@php
    $characteristics = collect(
        $product->primaryMarketplaceListing?->characteristics ?? []
    )
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

@if ($characteristics->isNotEmpty())
    <section class="mt-8 rounded-3xl border border-slate-200 bg-white p-6 sm:p-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-bold uppercase tracking-widest text-red-600">
                    Подробная информация
                </p>

                <h2 class="mt-2 text-2xl font-black sm:text-3xl">
                    Характеристики
                </h2>
            </div>

            <div class="text-sm text-slate-400">
                {{ $characteristics->count() }} параметров
            </div>
        </div>

        <details
            open
            class="group mt-6"
>
            <summary class="flex cursor-pointer list-none items-center justify-between rounded-xl bg-slate-100 px-5 py-4 text-sm font-bold transition hover:bg-slate-200">
                <span>
                    <span class="group-open:hidden">
                        Показать характеристики
                    </span>

                    <span class="hidden group-open:inline">
                        Свернуть характеристики
                    </span>
                </span>

                <svg
                    class="size-5 transition-transform group-open:rotate-180"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <path d="m6 9 6 6 6-6"></path>
                </svg>
            </summary>

            <div class="mt-4 grid gap-x-12 lg:grid-cols-2">
                @foreach ($characteristics as $characteristic)
                    <dl class="grid grid-cols-2 gap-4 border-b border-slate-100 py-4 text-sm">
                        <dt class="leading-6 text-slate-500">
                            {{ $characteristic['name'] }}
                        </dt>

                        <dd class="break-words text-right font-semibold leading-6 text-slate-900">
                            {{ $characteristic['value'] }}
                        </dd>
                    </dl>
                @endforeach
            </div>
        </details>

        <p class="mt-6 text-xs leading-5 text-slate-400">
            Характеристики загружены из карточки товара Wildberries. Комплектация отдельных поставок может отличаться.
        </p>
    </section>
@endif