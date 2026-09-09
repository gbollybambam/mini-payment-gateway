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
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();

            $table->string('provider');
            $table->string('customer_email')->nullable();
            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable()->unique();

            $table->decimal('amount', 19, 4);
            $table->string('currency', 3);
            $table->decimal('fee', 19, 4)->default(0);
            $table->decimal('net_amount', 19, 4)->default(0);

            $table->string('status')->default('INITIALIZED');
            $table->string('payment_method')->nullable();

            $table->json('metadata')->nullable();
            $table->string('checkout_url')->nullable();
            $table->string('idempotency_key')->nullable()->unique();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
