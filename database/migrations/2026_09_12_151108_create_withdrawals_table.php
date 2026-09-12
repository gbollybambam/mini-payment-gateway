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
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->cascadeOnDelete();

            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable()->unique();

            $table->decimal('amount', 19, 4);
            $table->decimal('fee', 19, 4)->default(0);
            $table->string('currency', 3);

            $table->string('status')->default('PENDING');

            $table->json('bank_account');

            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
