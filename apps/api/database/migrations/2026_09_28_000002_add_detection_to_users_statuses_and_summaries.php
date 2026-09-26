<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// virtual machine detection (docs/DEVELOPMENT_PLAN.md §16): a superadmin can switch it off for a person, the agent
// reports where it runs (physical, virtual_machine, remote_session), and each day keeps the strongest value seen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('detection_enabled')->default(true)->after('invite_failed_at');
        });
        Schema::table('employee_statuses', function (Blueprint $table) {
            $table->string('environment', 20)->nullable()->after('agent_version');
        });
        Schema::table('daily_summaries', function (Blueprint $table) {
            $table->string('environment', 20)->nullable()->after('app_names');
        });
    }

    public function down(): void
    {
        Schema::table('daily_summaries', fn (Blueprint $table) => $table->dropColumn('environment'));
        Schema::table('employee_statuses', fn (Blueprint $table) => $table->dropColumn('environment'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('detection_enabled'));
    }
};
