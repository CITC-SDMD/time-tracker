<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// id = UUID v7 made on the employee's PC — the idempotency key for sync (§10.2).
// docs/DEVELOPMENT_PLAN.md §8.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('device_id');
            $table->enum('type', ['APPLICATION', 'IDLE']);
            $table->string('app_name', 128)->nullable();
            $table->string('app_key', 128)->nullable();
            $table->string('process_name', 128)->nullable();
            $table->string('window_title', 512)->nullable();
            $table->string('idle_app_name', 128)->nullable();
            $table->timestamp('started_at', 3);
            $table->timestamp('ended_at', 3);
            $table->integer('duration_seconds');
            $table->date('day'); // office-timezone day of started_at
            $table->boolean('clock_changed')->default(false);
            $table->timestamp('received_at');

            $table->index(['user_id', 'started_at'], 'idx_sessions_user_started');
            $table->index('day', 'idx_sessions_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
