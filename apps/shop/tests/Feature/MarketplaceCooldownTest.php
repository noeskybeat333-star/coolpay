<?php

namespace Tests\Feature;

use App\Models\IntegrationType;
use App\Models\MarketplaceAccount;
use App\Services\MarketplaceCooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketplaceCooldownTest extends TestCase
{
    use RefreshDatabase;

    private function account(): MarketplaceAccount
    {
        $type = IntegrationType::create([
            'slug' => 'wildberries',
            'name' => 'Wildberries',
            'description' => 'Тестовая интеграция',
            'credential_schema' => [],
            'capabilities' => [],
            'is_active' => true,
            'sort_order' => 10,
        ]);

        return MarketplaceAccount::create([
            'integration_type_id' => $type->getKey(),
            'marketplace' => 'wildberries',
            'name' => 'Тестовый кабинет',
            'credentials' => ['api_token' => 'test-token'],
            'is_active' => true,
        ]);
    }

    public function test_пауза_записывается_и_читается(): void
    {
        $account = $this->account();

        $cooldown = app(MarketplaceCooldown::class);

        $this->assertSame(0, $cooldown->secondsLeft($account));
        $this->assertFalse($cooldown->isActive($account));

        $cooldown->start($account, 900);

        $this->assertTrue($cooldown->isActive($account));
        $this->assertEqualsWithDelta(
            900,
            $cooldown->secondsLeft($account),
            2,
        );

        $cooldown->clear($account);

        $this->assertSame(0, $cooldown->secondsLeft($account));
    }

    /**
     * Настройка cache.serializable_classes = false запрещает
     * восстанавливать объекты из кэша: сохранённый CarbonImmutable
     * вернулся бы как __PHP_Incomplete_Class и молча обнулил паузу.
     * Поэтому в кэш обязан уходить скаляр.
     */
    public function test_в_кэш_уходит_скаляр_а_не_объект(): void
    {
        $account = $this->account();

        app(MarketplaceCooldown::class)->start($account, 900);

        $stored = Cache::get(
            'marketplace-cooldown:'.$account->getKey()
        );

        $this->assertIsNotObject($stored);
        $this->assertIsNumeric($stored);
    }

    /**
     * Драйвер тестов — array, он хранит значения в памяти как есть
     * и потому не воспроизводит проблему. Настоящий сценарий —
     * запись в базу и чтение обратно уже из сериализованного вида.
     */
    public function test_пауза_переживает_запись_в_базу(): void
    {
        config()->set('cache.default', 'database');

        Cache::purge('database');

        $account = $this->account();

        $cooldown = app(MarketplaceCooldown::class);

        $cooldown->start($account, 900);

        // Сбрасываем всё, что могло остаться в памяти процесса,
        // чтобы значение поднялось из таблицы cache заново.
        Cache::purge('database');

        $this->assertEqualsWithDelta(
            900,
            $cooldown->secondsLeft($account),
            2,
        );
    }
}
