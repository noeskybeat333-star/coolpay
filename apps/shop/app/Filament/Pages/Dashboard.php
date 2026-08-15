<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Concerns\HasConfigurableWidth;
use App\Filament\Widgets\WidgetWidth;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Сводка';

    /**
     * Свой шаблон вместо схемы Filament: нужно обернуть каждый
     * виджет в контейнер, который можно перетаскивать.
     */
    protected string $view = 'filament.pages.dashboard';

    /**
     * Режим настройки. Пока он выключен, виджеты работают как
     * обычно; включённый — накрывает их прозрачным слоем, чтобы
     * перетаскивание не попадало по кнопкам внутри виджета.
     */
    public bool $arranging = false;

    public function toggleArranging(): void
    {
        $this->arranging = ! $this->arranging;
    }

    /**
     * Виджеты в пользовательском порядке.
     *
     * @return array<int, class-string>
     */
    public function orderedWidgets(): array
    {
        $widgets = $this->filterVisibleWidgets($this->getWidgets());

        $order = data_get(
            Auth::user()?->dashboard_settings ?? [],
            'order',
            [],
        );

        usort(
            $widgets,
            function (string $a, string $b) use ($order): int {
                $positionA = array_search(
                    $this->widgetKey($a),
                    $order,
                    true,
                );

                $positionB = array_search(
                    $this->widgetKey($b),
                    $order,
                    true,
                );

                // Ненастроенные уходят в конец, сохраняя порядок
                // сортировки самих виджетов.
                $positionA = $positionA === false
                    ? PHP_INT_MAX
                    : $positionA;

                $positionB = $positionB === false
                    ? PHP_INT_MAX
                    : $positionB;

                return $positionA <=> $positionB;
            },
        );

        return $widgets;
    }

    /**
     * Ширина виджета в колонках сетки.
     */
    public function widgetSpan(string $widget): int
    {
        if (! in_array(
            HasConfigurableWidth::class,
            class_uses_recursive($widget),
            true,
        )) {
            return WidgetWidth::DEFAULT;
        }

        return $widget::configuredWidth()
            ?? WidgetWidth::DEFAULT;
    }

    public function widgetKey(string $widget): string
    {
        return str_replace('\\', '_', $widget);
    }

    /**
     * Ширина, выставленная перетаскиванием края карточки.
     *
     * Пишется в те же настройки, что и выбор в модальном окне, —
     * иначе два способа менять размер разошлись бы между собой.
     */
    public function saveWidgetWidth(
        string $key,
        int $span,
    ): void {
        $span = max(
            WidgetWidth::MIN,
            min(WidgetWidth::MAX, $span),
        );

        $user = Auth::user();

        $settings = $user->dashboard_settings ?? [];

        $settings['widths'][$key] = $span;

        $user->dashboard_settings = $settings;

        $user->save();
    }

    /**
     * Высота карточки в пикселях. Ноль — высота по содержимому.
     */
    public function saveWidgetHeight(
        string $key,
        int $height,
    ): void {
        $user = Auth::user();

        $settings = $user->dashboard_settings ?? [];

        $settings['heights'][$key] = max(0, min(1200, $height));

        $user->dashboard_settings = $settings;

        $user->save();
    }

    public function widgetHeight(string $widget): int
    {
        return (int) data_get(
            Auth::user()?->dashboard_settings ?? [],
            'heights.'.$this->widgetKey($widget),
            0,
        );
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function saveWidgetOrder(array $keys): void
    {
        $user = Auth::user();

        $user->dashboard_settings = array_replace(
            $user->dashboard_settings ?? [],
            ['order' => array_values($keys)],
        );

        $user->save();
    }

    /**
     * Шесть колонок вместо стандартных двух: так доступны и трети,
     * и половины. С двумя колонками выбор сводился бы к «половина
     * или вся ширина».
     *
     * @return int | array<string, int>
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'lg' => 6,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleArranging')
                ->label(
                    fn (): string => $this->arranging
                        ? 'Готово'
                        : 'Расставить виджеты'
                )
                ->icon(
                    fn (): string => $this->arranging
                        ? 'heroicon-o-check'
                        : 'heroicon-o-arrows-pointing-out'
                )
                ->color(
                    fn (): string => $this->arranging
                        ? 'success'
                        : 'gray'
                )
                ->action('toggleArranging'),

            Action::make('configureWidgets')
                ->label('Настроить виджеты')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->modalHeading('Размер виджетов')
                ->modalDescription(
                    'Настройки личные — у других пользователей '
                    .'дашборд не изменится.'
                )
                ->modalSubmitActionLabel('Сохранить')
                ->fillForm(
                    fn (): array => [
                        'widths' => static::currentWidths(),
                    ],
                )
                ->schema([
                    ...static::widthFields(),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();

                    $user->dashboard_settings = array_replace(
                        $user->dashboard_settings ?? [],
                        ['widths' => $data['widths'] ?? []],
                    );

                    $user->save();

                    Notification::make()
                        ->success()
                        ->title('Дашборд настроен')
                        ->send();
                }),
        ];
    }

    /**
     * Поле выбора ширины на каждый настраиваемый виджет.
     *
     * Список берётся из виджетов панели, а не из захардкоженного
     * перечня: добавленный виджет появляется в настройках сам, если
     * подключил трейт.
     *
     * @return array<int, Select>
     */
    protected static function widthFields(): array
    {
        $fields = [];

        foreach (static::configurableWidgets() as $widget) {
            $fields[] = Select::make(
                'widths.'.$widget::settingsKey()
            )
                ->label($widget::configurableLabel())
                ->options(WidgetWidth::OPTIONS)
                ->selectablePlaceholder(false)
                ->default(WidgetWidth::DEFAULT);
        }

        return $fields;
    }

    /**
     * @return array<string, int>
     */
    protected static function currentWidths(): array
    {
        $saved = data_get(
            Auth::user()?->dashboard_settings ?? [],
            'widths',
            [],
        );

        $widths = [];

        foreach (static::configurableWidgets() as $widget) {
            $key = $widget::settingsKey();

            $widths[$key] = (int) (
                $saved[$key] ?? WidgetWidth::DEFAULT
            );
        }

        return $widths;
    }

    /**
     * @return array<int, class-string>
     */
    protected static function configurableWidgets(): array
    {
        return array_values(array_filter(
            filament()->getWidgets(),
            fn (string $widget): bool => in_array(
                HasConfigurableWidth::class,
                class_uses_recursive($widget),
                true,
            ),
        ));
    }
}
