<?php

namespace App\Integrations\Contracts;

use App\Integrations\Results\OrderImportResult;
use App\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;

interface ImportsMarketplaceOrders
{
    public function importOrders(
        MarketplaceAccount $account,
        ?CarbonImmutable $since = null,
    ): OrderImportResult;
}
