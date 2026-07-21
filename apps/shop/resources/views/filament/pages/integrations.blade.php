<x-filament-panels::page>
    <div
        style="
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
        "
    >
        @foreach ($this->getIntegrations() as $integration)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $integration->name }}
                </x-slot>

                <x-slot name="description">
                    {{ $integration->description
                        ?: 'Подключение внешней торговой площадки.' }}
                </x-slot>

                <div style="display: grid; gap: 1rem;">
                    <div>
                        @if ($integration->accounts_count > 0)
                            <x-filament::badge color="success">
                                Подключений: {{ $integration->accounts_count }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="gray">
                                Не подключено
                            </x-filament::badge>
                        @endif
                    </div>

                    <div>
                        <x-filament::button
                            tag="a"
                            :href="$this->getConnectUrl($integration)"
                            icon="heroicon-o-plus"
                        >
                            Подключить
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
