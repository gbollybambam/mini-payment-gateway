<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('known_devices', function (Blueprint $table): void {
            $table->foreignUuid('merchant_user_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('merchant_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('known_devices', function (Blueprint $table): void {
            $table->dropForeign(['merchant_user_id']);
            $table->dropColumn('merchant_user_id');
        });
    }
};
