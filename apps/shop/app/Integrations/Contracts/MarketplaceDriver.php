<?php

namespace App\Integrations\Contracts;

use App\Integrations\Results\CatalogImportResult;
use App\Integrations\Results\ConnectionTestResult;
use App\Models\MarketplaceAccount;

interface MarketplaceDriver
{
    public function testConnection(
        MarketplaceAccount $account,
    ): ConnectionTestResult;

    public function importCatalog(
        MarketplaceAccount $account,
    ): CatalogImportResult;

    public function capabilities(): array;
}
