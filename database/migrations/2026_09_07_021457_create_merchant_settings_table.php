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
        Schema::create('merchant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();

            // Business settings
            $table->string('logo_url')->nullable();
            $table->string('website_url')->nullable();

            // Cheout settings
            $table->string('brand_name')->nullable();
            $table->string('checkout_logo_url')->nullable();
            $table->string('success_url')->nullable();
            $table->string('cancel_url')->nullable();

            // Developer settings
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_settings');
    }
};
