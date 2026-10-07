<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->dropColumn('cray_subaccount_token');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settings', function (Blueprint $table) {
            $table->string('cray_subaccount_token')->nullable()->after('webhook_secret');
        });
    }
};
