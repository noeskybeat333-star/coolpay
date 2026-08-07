<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('source', 32)
                ->default('storefront')
                ->index();

            $table->foreignId('marketplace_account_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('external_id')
                ->nullable();

            $table->string('external_number')
                ->nullable();

            $table->string('external_status', 100)
                ->nullable();

            $table->string('fulfillment_type', 32)
                ->nullable();

            $table->timestamp('external_created_at')
                ->nullable();

            $table->timestamp('synced_at')
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->unique(
                [
                    'marketplace_account_id',
                    'external_id',
                ],
                'orders_marketplace_external_unique',
            );

            $table->index(
                [
                    'source',
                    'placed_at',
                ],
                'orders_source_placed_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(
                'orders_marketplace_external_unique'
            );

            $table->dropIndex(
                'orders_source_placed_at_index'
            );

            $table->dropConstrainedForeignId(
                'marketplace_account_id'
            );

            $table->dropIndex([
                'source',
            ]);

            $table->dropColumn([
                'source',
                'external_id',
                'external_number',
                'external_status',
                'fulfillment_type',
                'external_created_at',
                'synced_at',
                'metadata',
            ]);
        });
    }
};
