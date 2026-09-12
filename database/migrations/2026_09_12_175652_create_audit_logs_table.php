<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('action'); // e.g., 'SUSPEND_MERCHANT', 'UPDATE_PROVIDER'
            $table->string('target_type')->nullable(); // e.g., 'App\Models\Merchant'
            $table->uuid('target_id')->nullable();
            $table->json('changes')->nullable(); // What was changed
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
