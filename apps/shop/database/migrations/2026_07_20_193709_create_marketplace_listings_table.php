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
        Schema::create('marketplace_listings', function (Blueprint $table) {
	 $table->id();

   	 $table->foreignId('product_id')
       	 ->nullable()
       	 ->constrained()
       	 ->nullOnDelete();

   	 $table->foreignId('marketplace_account_id')
       	 ->constrained()
       	 ->cascadeOnDelete();

   	 $table->string('external_id');
   	 $table->string('offer_id')->nullable();
   	 $table->string('seller_sku')->nullable();
   	 $table->string('barcode')->nullable();

   	 $table->string('name');
   	 $table->string('brand')->nullable();
   	 $table->string('category')->nullable();
   	 $table->text('description')->nullable();

   	 $table->decimal('price', 14, 2)->nullable();
   	 $table->decimal('old_price', 14, 2)->nullable();
   	 $table->unsignedInteger('stock_quantity')->nullable();

   	 $table->string('status')->nullable();
   	 $table->json('characteristics')->nullable();
   	 $table->json('images')->nullable();
   	 $table->json('raw_data');

   	 $table->timestamp('synced_at')->nullable();
   	 $table->timestamps();

   	 $table->unique(
       	 ['marketplace_account_id', 'external_id'],
       	 'marketplace_listing_external_unique'
   	 );

   	 $table->index('offer_id');
   	 $table->index('seller_sku');
   	 $table->index('barcode');
	});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_listings');
    }
};
