<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// the oic chooses how often desktop apps take a screenshot (docs/development_plan.md phase 10).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            // minutes between screenshots: 0 = off (the default), otherwise 5, 10, 15 or 30
            $table->unsignedTinyInteger('screenshot_interval_minutes')->default(0);
            // true = one shot at a random moment inside each block instead of on a fixed rhythm
            $table->boolean('screenshot_random')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('office_settings', function (Blueprint $table) {
            $table->dropColumn(['screenshot_interval_minutes', 'screenshot_random']);
        });
    }
};
