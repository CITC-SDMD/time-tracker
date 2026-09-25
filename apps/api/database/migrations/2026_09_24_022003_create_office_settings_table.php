<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Single row, id = 1. OIC-only to read/write (docs/DEVELOPMENT_PLAN.md §9.1, §10).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_settings', function (Blueprint $table) {
            $table->tinyInteger('id')->primary()->default(1);
            $table->string('timezone', 64); // e.g. "Asia/Manila" — defines what "a day" is
            $table->integer('idle_threshold_seconds')->default(300);
            $table->enum('window_title_mode', ['full', 'app_only'])->default('full');
            $table->string('min_agent_version', 32);
            $table->integer('consent_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_settings');
    }
};
