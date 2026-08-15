<?php

namespace App\Integrations\Contracts;

use App\Integrations\Results\CatalogImportResult;
use App\Models\MarketplaceAccount;

/**
 * Обновление цен и остатков по уже сохранённым карточкам.
 *
 * Вынесено из импорта каталога, потому что цены и остатки живут в
 * отдельных API со своими лимитерами. Пока это было одной операцией,
 * отказ по ценам отменял и карточки, а повтор заново выкачивал их
 * целиком — и тратил квоту на данные, которые уже дошли.
 */
interface ImportsMarketplacePrices
{
    public function importPrices(
        MarketplaceAccount $account,
    ): CatalogImportResult;
}
