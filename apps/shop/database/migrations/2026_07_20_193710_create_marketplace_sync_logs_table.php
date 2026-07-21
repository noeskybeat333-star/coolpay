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
        Schema::create('marketplace_sync_logs', function (Blueprint $table) {
    $table->id();

    $table->foreignId('marketplace_account_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->string('operation');
    $table->string('status');

    $table->unsignedInteger('received_count')->default(0);
    $table->unsignedInteger('created_count')->default(0);
    $table->unsignedInteger('updated_count')->default(0);
    $table->unsignedInteger('failed_count')->default(0);

    $table->text('message')->nullable();
    $table->json('details')->nullable();

    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();

    $table->timestamps();
	});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_sync_logs');
    }
};
