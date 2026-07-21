<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('marketplace_accounts', function (Blueprint $table) {
        $table->foreignId('integration_type_id')
            ->nullable()
            ->after('id')
            ->constrained('integration_types')
            ->nullOnDelete();

        $table->text('credentials')
            ->nullable()
            ->after('api_token');

        $table->string('connection_status')
            ->default('not_tested')
            ->after('is_active');

        $table->text('status_message')
            ->nullable()
            ->after('connection_status');

        $table->timestamp('tested_at')
            ->nullable()
            ->after('last_synced_at');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
{
    Schema::table('marketplace_accounts', function (Blueprint $table) {
        $table->dropForeign(['integration_type_id']);

        $table->dropColumn([
            'integration_type_id',
            'credentials',
            'connection_status',
            'status_message',
            'tested_at',
        ]);
    });
}
};
