<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE VIEW channel_prices AS

            SELECT
                ('storefront-' || products.id::text) AS id,
                'storefront'::text AS record_type,
                products.id AS source_id,
                products.id AS product_id,
                NULL::bigint AS marketplace_account_id,
                'storefront'::text AS channel_key,
                'storefront'::text AS marketplace_slug,
                'Витрина CoolPay'::text AS marketplace_name,
                'CoolPay'::text AS connection_name,
                products.name,
                products.sku,
                (
                    SELECT product_images.path
                    FROM product_images
                    WHERE product_images.product_id = products.id
                    ORDER BY
                        product_images.sort_order,
                        product_images.id
                    LIMIT 1
                ) AS local_image_path,
                (
                    SELECT marketplace_listings.images
                    FROM marketplace_listings
                    WHERE marketplace_listings.product_id = products.id
                    ORDER BY
                        marketplace_listings.synced_at DESC NULLS LAST,
                        marketplace_listings.id DESC
                    LIMIT 1
                ) AS images,
                products.base_price,
                products.discount_percent,
                products.sale_price,
                products.stock_quantity,
                CASE
                    WHEN products.is_active THEN 'active'
                    ELSE 'inactive'
                END::text AS status,
                products.created_at,
                products.updated_at
            FROM products

            UNION ALL

            SELECT
                ('marketplace-' || marketplace_listings.id::text) AS id,
                'marketplace'::text AS record_type,
                marketplace_listings.id AS source_id,
                marketplace_listings.product_id,
                marketplace_listings.marketplace_account_id,
                (
                    'account:'
                    || marketplace_listings.marketplace_account_id::text
                ) AS channel_key,
                integration_types.slug::text AS marketplace_slug,
                integration_types.name::text AS marketplace_name,
                marketplace_accounts.name::text AS connection_name,
                marketplace_listings.name,
                marketplace_listings.seller_sku AS sku,
                NULL::varchar AS local_image_path,
                marketplace_listings.images,
                marketplace_listings.base_price,
                marketplace_listings.discount_percent,
                marketplace_listings.price AS sale_price,
                marketplace_listings.stock_quantity,
                marketplace_listings.status::text,
                marketplace_listings.created_at,
                marketplace_listings.updated_at
            FROM marketplace_listings
            INNER JOIN marketplace_accounts
                ON marketplace_accounts.id =
                    marketplace_listings.marketplace_account_id
            INNER JOIN integration_types
                ON integration_types.id =
                    marketplace_accounts.integration_type_id
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'DROP VIEW IF EXISTS channel_prices'
        );
    }
};
