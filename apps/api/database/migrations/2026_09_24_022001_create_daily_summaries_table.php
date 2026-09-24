<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// docs/DEVELOPMENT_PLAN.md §8, §10.1 (SummaryService).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->integer('tracked_seconds')->default(0);
            $table->integer('active_seconds')->default(0);
            $table->integer('idle_seconds')->default(0);
            $table->json('apps'); // { appKey: seconds } — active seconds per app
            $table->json('app_names'); // { appKey: "Visual Studio Code" }
            $table->timestamp('first_activity_at', 3)->nullable();
            $table->timestamp('last_activity_at', 3)->nullable();

            $table->primary(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
