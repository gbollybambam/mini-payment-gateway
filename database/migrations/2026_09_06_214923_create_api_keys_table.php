<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained('merchants')->onDelete('cascade');
            $table->string('name');
            $table->string('environment');
            $table->string('key_prefix');
            $table->string('secret_key')->unique();

            // NEW COLUMNS FOR GRANULAR CONTROL
            $table->json('scopes')->nullable(); // Stores ['payments:write', 'wallets:read'] or ['*'] for full access
            $table->timestamp('expires_at')->nullable(); // Null means it never expires naturally

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
