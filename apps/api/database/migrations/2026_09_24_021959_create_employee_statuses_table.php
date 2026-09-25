<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One live-status row per person. docs/DEVELOPMENT_PLAN.md §8.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_statuses', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->enum('state', ['active', 'idle', 'paused', 'away', 'not_tracking']);
            $table->string('current_app', 128)->nullable();
            $table->string('idle_app_name', 128)->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->timestamp('since'); // when this state began
            $table->timestamp('last_seen_at'); // server time of last sync
            $table->integer('clock_skew_seconds')->default(0); // PC clock minus server clock
            $table->timestamp('tracking_device_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_statuses');
    }
};
