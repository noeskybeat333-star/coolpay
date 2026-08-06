<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('number')
                ->nullable()
                ->unique();

            $table->string('status', 32)
                ->default('new')
                ->index();

            $table->string('payment_status', 32)
                ->default('pending')
                ->index();

            $table->string('payment_method', 32)
                ->nullable();

            $table->string('delivery_method', 32)
                ->default('delivery');

            $table->string('customer_name', 150);
            $table->string('customer_phone', 50);
            $table->string('customer_email')->nullable();

            $table->text('delivery_address')->nullable();
            $table->text('customer_comment')->nullable();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('delivery_price', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->char('currency', 3)->default('RUB');
            $table->timestamp('placed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};