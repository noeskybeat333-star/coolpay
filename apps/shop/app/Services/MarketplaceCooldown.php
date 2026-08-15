<?php

namespace App\Services;

use App\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Общая пауза на кабинет маркетплейса.
 *
 * Лимитер Wildberries считает запросы глобально по кабинету, а не по
 * эндпоинту. Поэтому недостаточно, чтобы паузу выдерживал тот компонент,
 * который получил отказ: пока задача каталога ждёт свои 900 секунд,
 * планировщик заказов продолжает ходить в тот же API и продлевает
 * блокировку всем.
 *
 * Проверено на живом кабинете 13.08.2026: серия преждевременных запросов
 * подняла штраф с 380 до 3581 секунды.
 */
class MarketplaceCooldown
{
    public function secondsLeft(
        MarketplaceAccount $account,
    ): int {
        $until = Cache::get($this->key($account));

        if (! is_numeric($until)) {
            return 0;
        }

        $left = (int) $until - CarbonImmutable::now()->getTimestamp();

        return max(0, $left);
    }

    /**
     * Момент окончания паузы — для показа человеку.
     */
    public function until(
        MarketplaceAccount $account,
    ): ?CarbonImmutable {
        $left = $this->secondsLeft($account);

        return $left > 0
            ? CarbonImmutable::now()->addSeconds($left)
            : null;
    }

    public function isActive(
        MarketplaceAccount $account,
    ): bool {
        return $this->secondsLeft($account) > 0;
    }

    public function start(
        MarketplaceAccount $account,
        int $seconds,
    ): void {
        $seconds = max(1, $seconds);

        // В кэше храним unix-время окончания паузы обычным числом.
        // Объект сюда класть нельзя: cache.serializable_classes = false
        // запрещает восстанавливать классы из кэша, и CarbonImmutable
        // вернулся бы как __PHP_Incomplete_Class, молча обнуляя паузу.
        Cache::put(
            $this->key($account),
            CarbonImmutable::now()->addSeconds($seconds)->getTimestamp(),
            $seconds + 10,
        );
    }

    public function clear(
        MarketplaceAccount $account,
    ): void {
        Cache::forget($this->key($account));
    }

    private function key(
        MarketplaceAccount $account,
    ): string {
        return 'marketplace-cooldown:'.$account->getKey();
    }
}
