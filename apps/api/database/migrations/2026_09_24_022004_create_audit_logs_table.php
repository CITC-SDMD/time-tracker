<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Readable only by the OIC (docs/DEVELOPMENT_PLAN.md §9.1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->index('created_at', 'idx_audit_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
