<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Ширина виджета, задаваемая пользователем.
 *
 * Сетка дашборда разбита на 6 колонок, поэтому доступны трети и
 * половины. Значение 0 означает «скрыт»: так один и тот же список
 * настроек управляет и размером, и составом дашборда, без второго
 * экрана с галочками.
 */
trait HasConfigurableWidth
{
    public function getColumnSpan(): int|string|array
    {
        $width = static::configuredWidth();

        return $width > 0
            ? $width
            : parent::getColumnSpan();
    }

    public static function canView(): bool
    {
        return static::configuredWidth() !== 0;
    }

    /**
     * Ширина из настроек пользователя.
     *
     * `null` означает «не настраивал» — тогда берётся ширина по
     * умолчанию, зашитая в самом виджете.
     */
    public static function configuredWidth(): ?int
    {
        $settings = Auth::user()?->dashboard_settings ?? [];

        $width = data_get(
            $settings,
            'widths.'.static::settingsKey(),
        );

        return is_numeric($width) ? (int) $width : null;
    }

    /**
     * Высота карточки в пикселях, заданная пользователем.
     * Ноль означает «по содержимому».
     */
    public static function configuredHeight(): int
    {
        $settings = Auth::user()?->dashboard_settings ?? [];

        return (int) data_get(
            $settings,
            'heights.'.static::settingsKey(),
            0,
        );
    }

    /**
     * Ключ настройки.
     *
     * Полное имя класса содержит обратные слэши, а Filament строит
     * пути состояния формы через точки — брать имя как есть значит
     * нарваться на разбор пути. Заменяем разделитель.
     */
    public static function settingsKey(): string
    {
        return str_replace('\\', '_', static::class);
    }

    /**
     * Подпись виджета в настройках.
     *
     * Именно метод, а не свойство: свойство с разными значениями
     * по умолчанию в трейте и в классе PHP считает несовместимым,
     * а метод класса спокойно перекрывает метод трейта.
     */
    public static function configurableLabel(): string
    {
        return class_basename(static::class);
    }
}
