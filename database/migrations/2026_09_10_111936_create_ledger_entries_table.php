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
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('wallet_id')->constrained('wallets')->cascadeOnDelete();

            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            $table->string('type');
            $table->string('direction');

            $table->decimal('amount', 19, 4);
            $table->string('currency', 3);

            $table->decimal('balance_before', 19, 4);
            $table->decimal('balance_after', 19, 4);

            $table->string('description')->nullable();
            $table->string('reference')->unique();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
