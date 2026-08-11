<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_name', 150)
                ->nullable()
                ->change();

            $table->string('customer_phone', 50)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('orders')
            ->whereNull('customer_name')
            ->update([
                'customer_name' =>
                    'Покупатель маркетплейса',
            ]);

        DB::table('orders')
            ->whereNull('customer_phone')
            ->update([
                'customer_phone' => 'Не указан',
            ]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_name', 150)
                ->nullable(false)
                ->change();

            $table->string('customer_phone', 50)
                ->nullable(false)
                ->change();
        });
    }
};
