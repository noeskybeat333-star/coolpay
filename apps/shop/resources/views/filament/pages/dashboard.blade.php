{{--
    Дашборд с перетаскиванием и изменением размера виджетов.

    Вся вёрстка — обычный CSS в этом же файле, без классов Tailwind.
    Причина: сборка ассетов в Docker не налажена (см. CLAUDE.md,
    «Известные проблемы»), поэтому новый класс Tailwind в готовый CSS
    не попадает и просто не работает. Инлайновый стиль и <style> в
    шаблоне применяются всегда.
--}}
<x-filament-panels::page>
    <style>
        .cp-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1.5rem;
            align-items: start;
        }

        @media (min-width: 640px) {
            .cp-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .cp-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }
        }

        .cp-cell {
            min-width: 0;
        }

        /* Высота только если её задал пользователь. С height: 100%
           на ячейке без собственной высоты Chart.js получал
           неограниченный холст и рисовался поверх соседей. */
        .cp-inner {
            position: relative;
        }

        .cp-cell[data-sized='1'] .cp-inner {
            height: 100%;
            overflow: hidden;
            border-radius: 0.75rem;
        }

        /* Растягиваем цепочку контейнеров до самой карточки, чтобы
           содержимое занимало заданную высоту целиком. Высоту шапки
           вычитает flexbox — считать её в пикселях значит промахнуться
           при любом изменении заголовка или фильтра. */
        .cp-cell[data-sized='1'] .cp-inner > .fi-wi,
        .cp-cell[data-sized='1'] .fi-wi-chart,
        .cp-cell[data-sized='1'] .fi-wi-chart > .fi-section,
        .cp-cell[data-sized='1'] .fi-ta,
        .cp-cell[data-sized='1'] .fi-ta > .fi-section {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .cp-cell[data-sized='1'] .fi-section-content-ctn {
            flex: 1 1 auto;
            min-height: 0;
            overflow: auto;
        }

        .cp-cell[data-sized='1'] .fi-wi-chart-canvas-ctn {
            height: 100%;
            /* Подстраховка: если разметка Filament изменится и
               цепочка растяжки разорвётся, график не схлопнется
               в ноль, а останется читаемым. */
            min-height: 180px;
        }

        .cp-cell[data-sized='1'] .fi-wi-chart canvas {
            height: 100% !important;
            max-height: none !important;
        }

        .cp-hint {
            border-radius: 0.75rem;
            background: #fef3c7;
            color: #92400e;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }

        @media (prefers-color-scheme: dark) {
            .cp-hint {
                background: rgba(245, 158, 11, 0.12);
                color: #fcd34d;
            }
        }

        .cp-arranging .cp-inner {
            cursor: move;
            border-radius: 0.75rem;
            outline: 2px dashed #6366f1;
            outline-offset: 3px;
        }

        .cp-overlay {
            position: absolute;
            inset: 0;
            z-index: 10;
            border-radius: 0.75rem;
        }

        .cp-handle {
            position: absolute;
            z-index: 20;
            background: rgba(99, 102, 241, 0.25);
        }

        .cp-handle:hover {
            background: rgba(99, 102, 241, 0.55);
        }

        .cp-handle-x {
            top: 0;
            bottom: 0;
            right: 0;
            width: 12px;
            cursor: ew-resize;
            border-radius: 0 0.75rem 0.75rem 0;
        }

        .cp-handle-y {
            left: 0;
            right: 0;
            bottom: 0;
            height: 12px;
            cursor: ns-resize;
            border-radius: 0 0 0.75rem 0.75rem;
        }

        .cp-handle-xy {
            right: 0;
            bottom: 0;
            width: 18px;
            height: 18px;
            cursor: nwse-resize;
            z-index: 21;
            border-radius: 0 0 0.75rem 0;
            background: rgba(99, 102, 241, 0.6);
        }
    </style>

    @if ($this->arranging)
        <div class="cp-hint">
            Режим настройки: тяните карточку, чтобы поменять порядок.
            Правый край меняет ширину, нижний — высоту, угол — сразу
            оба размера. Изменения сохраняются сразу.
        </div>
    @endif

    <div
        x-data="{
            dragged: null,
            resize: null,

            node(key) {
                return this.$refs.grid.querySelector(
                    `[data-widget='${key}']`
                );
            },

            /*
                Подгонка холста графика под фактическую высоту
                карточки.

                Считаем измерением, а не правилами CSS: растяжка
                через height: 100% требует, чтобы вся цепочка
                контейнеров Filament была растянута, и промах в
                одном звене ломает всё. Здесь же берём реальные
                координаты и вычитаем что есть.
            */
            fit(cell) {
                if (cell.dataset.sized !== '1') {
                    return;
                }

                const canvasBox = cell.querySelector(
                    '.fi-wi-chart-canvas-ctn'
                );

                if (! canvasBox) {
                    return;
                }

                canvasBox.style.height = 'auto';

                const bottom = cell.getBoundingClientRect().bottom;
                const top = canvasBox.getBoundingClientRect().top;

                // 24px — нижний внутренний отступ секции Filament.
                const available = Math.round(bottom - top - 24);

                canvasBox.style.height =
                    `${Math.max(160, available)}px`;
            },

            fitAll() {
                [...this.$refs.grid.children].forEach(
                    (cell) => this.fit(cell)
                );
            },

            start(event, key) {
                if (this.resize) {
                    event.preventDefault();

                    return;
                }

                this.dragged = key;
                event.dataTransfer.effectAllowed = 'move';
            },

            over(event, key) {
                if (this.dragged === null || this.dragged === key) {
                    return;
                }

                const from = this.node(this.dragged);
                const to = this.node(key);

                if (! from || ! to) {
                    return;
                }

                const after = to.compareDocumentPosition(from)
                    & Node.DOCUMENT_POSITION_PRECEDING;

                to.parentNode.insertBefore(
                    from,
                    after ? to.nextSibling : to,
                );
            },

            drop() {
                if (this.dragged === null) {
                    return;
                }

                this.dragged = null;

                $wire.saveWidgetOrder(
                    [...this.$refs.grid.children]
                        .map((node) => node.dataset.widget)
                        .filter(Boolean)
                );
            },

            startResize(event, key, span, height, axis) {
                const node = this.node(key);

                this.resize = {
                    key,
                    axis,
                    span,
                    startSpan: span,
                    height: height || node.offsetHeight,
                    startHeight: height,
                    startX: event.clientX,
                    startY: event.clientY,
                    column: this.$refs.grid.getBoundingClientRect()
                        .width / 6,
                };
            },

            onResize(event) {
                if (! this.resize) {
                    return;
                }

                const node = this.node(this.resize.key);

                if (! node) {
                    return;
                }

                if (this.resize.axis !== 'y') {
                    const moved = event.clientX - this.resize.startX;

                    this.resize.span = Math.min(6, Math.max(1,
                        this.resize.startSpan
                            + Math.round(moved / this.resize.column)
                    ));

                    node.style.gridColumn =
                        `span ${this.resize.span} / span ${this.resize.span}`;
                }

                if (this.resize.axis !== 'x') {
                    const moved = event.clientY - this.resize.startY;

                    this.resize.height = Math.max(
                        160,
                        Math.round(this.resize.height + moved)
                    );

                    this.resize.startY = event.clientY;

                    node.style.height = `${this.resize.height}px`;
                    node.dataset.sized = '1';
                }

                // Пересчитываем на каждом шаге, чтобы график тянулся
                // вместе с рамкой, а не прыгал после отпускания.
                this.fit(node);
            },

            endResize() {
                if (! this.resize) {
                    return;
                }

                const saved = this.resize;

                this.resize = null;

                if (saved.axis !== 'y'
                    && saved.span !== saved.startSpan) {
                    $wire.saveWidgetWidth(saved.key, saved.span);
                }

                if (saved.axis !== 'x'
                    && saved.height !== saved.startHeight) {
                    $wire.saveWidgetHeight(saved.key, saved.height);
                }
            },
        }"
        x-ref="grid"
        x-init="
            $nextTick(() => fitAll());
            setTimeout(() => fitAll(), 300);
        "
        x-on:pointermove.window="onResize($event)"
        x-on:pointerup.window="endResize()"
        x-on:resize.window.debounce.150ms="fitAll()"
        x-on:livewire:update.window="$nextTick(() => fitAll())"
        class="cp-grid {{ $this->arranging ? 'cp-arranging' : '' }}"
    >
        @foreach ($this->orderedWidgets() as $index => $widget)
            @php
                $key = $this->widgetKey($widget);
                $span = $this->widgetSpan($widget);
                $height = $this->widgetHeight($widget);
            @endphp

            <div
                class="cp-cell"
                data-widget="{{ $key }}"
                data-sized="{{ $height > 0 ? '1' : '0' }}"
                style="grid-column: span {{ $span }} / span {{ $span }};@if ($height > 0) height: {{ $height }}px;@endif"
                @if ($this->arranging)
                    draggable="true"
                    x-on:dragstart="start($event, '{{ $key }}')"
                    x-on:dragover.prevent="over($event, '{{ $key }}')"
                    x-on:drop.prevent="drop()"
                    x-on:dragend="drop()"
                @endif
            >
                <div class="cp-inner">
                    @if ($this->arranging)
                        {{-- Прозрачный слой поверх виджета: без него
                             перетаскивание попадало бы по кнопкам,
                             фильтрам и ссылкам внутри карточки. --}}
                        <div class="cp-overlay"></div>

                        <div
                            class="cp-handle cp-handle-x"
                            title="Ширина"
                            x-on:pointerdown.prevent.stop="
                                startResize($event, '{{ $key }}', {{ $span }}, {{ $height }}, 'x')
                            "
                        ></div>

                        <div
                            class="cp-handle cp-handle-y"
                            title="Высота"
                            x-on:pointerdown.prevent.stop="
                                startResize($event, '{{ $key }}', {{ $span }}, {{ $height }}, 'y')
                            "
                        ></div>

                        <div
                            class="cp-handle cp-handle-xy"
                            title="Ширина и высота"
                            x-on:pointerdown.prevent.stop="
                                startResize($event, '{{ $key }}', {{ $span }}, {{ $height }}, 'xy')
                            "
                        ></div>
                    @endif

                    @livewire(
                        $widget,
                        $widget::getDefaultProperties(),
                        key($key.'-'.$index),
                    )
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
