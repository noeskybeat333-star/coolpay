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
        Schema::create('marketplace_accounts', function (Blueprint $table) {
		 $table->id();

   		 $table->string('marketplace', 30);
   		 $table->string('name');
   		 $table->text('api_token')->nullable();
   		 $table->string('external_account_id')->nullable();

   		 $table->boolean('is_active')->default(true);
   		 $table->timestamp('last_synced_at')->nullable();

   		 $table->json('settings')->nullable();

   		 $table->timestamps();

   		 $table->index(['marketplace', 'is_active']);
		});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_accounts');
    }
};
