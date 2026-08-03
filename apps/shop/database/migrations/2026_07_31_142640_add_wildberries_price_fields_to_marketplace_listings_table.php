<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'marketplace_listings',
            function (Blueprint $table): void {
                $table->renameColumn(
                    'old_price',
                    'base_price'
                );

                $table->unsignedTinyInteger(
                    'discount_percent'
                )->nullable();
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'marketplace_listings',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'discount_percent'
                );

                $table->renameColumn(
                    'base_price',
                    'old_price'
                );
            }
        );
    }
};
