<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'products',
            function (Blueprint $table): void {
                $table->decimal(
                    'base_price',
                    12,
                    2
                )->nullable();

                $table->unsignedTinyInteger(
                    'discount_percent'
                )->default(0);
            }
        );

        DB::table('products')
            ->update([
                'base_price' => DB::raw(
                    'sale_price'
                ),
                'discount_percent' => 0,
            ]);
    }

    public function down(): void
    {
        Schema::table(
            'products',
            function (Blueprint $table): void {
                $table->dropColumn([
                    'base_price',
                    'discount_percent',
                ]);
            }
        );
    }
};
