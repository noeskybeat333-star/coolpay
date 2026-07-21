<?php

namespace App\Integrations\Drivers;

use App\Integrations\Contracts\MarketplaceDriver;
use App\Integrations\Results\CatalogImportResult;
use App\Integrations\Results\ConnectionTestResult;
use App\Models\MarketplaceAccount;

class UnsupportedDriver implements MarketplaceDriver
{
    public function testConnection(
        MarketplaceAccount $account,
    ): ConnectionTestResult {
        return ConnectionTestResult::failure(
            'Автоматическое подключение для этой площадки пока не реализовано.',
        );
    }

    public function importCatalog(
        MarketplaceAccount $account,
    ): CatalogImportResult {
        return CatalogImportResult::empty();
    }

    public function capabilities(): array
    {
        return [
            'catalog_read' => false,
            'prices_read' => false,
            'stocks_read' => false,
            'orders_read' => false,
            'prices_write' => false,
            'stocks_write' => false,
        ];
    }
}
