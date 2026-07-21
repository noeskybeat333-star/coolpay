<?php

namespace App\Integrations;

use App\Integrations\Contracts\MarketplaceDriver;
use App\Integrations\Drivers\UnsupportedDriver;
use App\Models\IntegrationType;
use InvalidArgumentException;

class MarketplaceDriverManager
{
    public function for(
        IntegrationType|string $integration,
    ): MarketplaceDriver {
        $slug = $integration instanceof IntegrationType
            ? $integration->slug
            : $integration;

        $driverClass = config(
            "marketplaces.drivers.{$slug}",
            UnsupportedDriver::class,
        );

        if (! class_exists($driverClass)) {
            throw new InvalidArgumentException(
                "Драйвер интеграции [{$slug}] не найден.",
            );
        }

        $driver = app($driverClass);

        if (! $driver instanceof MarketplaceDriver) {
            throw new InvalidArgumentException(
                "Класс [{$driverClass}] не реализует MarketplaceDriver.",
            );
        }

        return $driver;
    }
}
