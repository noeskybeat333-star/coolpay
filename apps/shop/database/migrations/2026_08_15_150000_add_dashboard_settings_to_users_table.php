<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Настройки дашборда персональные: у разных людей разные задачи,
     * и общий набор виджетов на всех означал бы, что кто-то смотрит
     * на чужой экран.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('dashboard_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('dashboard_settings');
        });
    }
};
