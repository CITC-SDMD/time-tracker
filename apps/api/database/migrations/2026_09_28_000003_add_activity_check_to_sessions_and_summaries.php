<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// the activity check (docs/DEVELOPMENT_PLAN.md §16): each chunk of tracked time carries counts about the input that
// happened in it (never which keys), each day keeps the level and the reasons worked out from them, and the names of
// known macro programs seen running that day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->json('input_stats')->nullable()->after('clock_changed');
        });
        Schema::table('daily_summaries', function (Blueprint $table) {
            $table->string('integrity_level', 10)->nullable()->after('environment');
            $table->json('integrity_reasons')->nullable()->after('integrity_level');
            $table->json('macro_tools')->nullable()->after('integrity_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('daily_summaries', fn (Blueprint $table) => $table->dropColumn(['integrity_level', 'integrity_reasons', 'macro_tools']));
        Schema::table('sessions', fn (Blueprint $table) => $table->dropColumn('input_stats'));
    }
};
