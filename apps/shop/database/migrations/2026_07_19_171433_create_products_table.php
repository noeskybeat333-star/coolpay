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
         Schema::create('products', function (Blueprint $table) {
   		 $table->id();

   		 $table->string('name');
   		 $table->string('slug')->unique();
   		 $table->string('sku')->nullable()->unique();

   		 $table->string('brand')->nullable();
   		 $table->string('category')->nullable();

   		 $table->text('description')->nullable();

   		 $table->decimal('purchase_price', 12, 2)->nullable();
   		 $table->decimal('sale_price', 12, 2);
   		 $table->unsignedInteger('stock_quantity')->default(0);

   		 $table->boolean('is_active')->default(true);
   		 $table->boolean('is_featured')->default(false);

   		 $table->timestamps();
		});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
