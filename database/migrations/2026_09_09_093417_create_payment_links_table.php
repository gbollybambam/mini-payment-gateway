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
        Schema::create('payment_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();

            // Core Link Details
            $table->string('reference')->unique();
            $table->string('checkout_token')->unique();

            // Financials
            $table->decimal('amount', 19, 4);
            $table->string('currency', 3);

            // Display & Customer Info
            $table->text('description');
            $table->json('customer_information')->nullable();

            // State & Configuration
            $table->string('status')->default('ACTIVE');
            $table->timestamp('expiry')->nullable();
            $table->string('redirect_url')->nullable();

            // Relation to the completed transaction (populated once paid)
            $table->foreignUuid('payment_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
