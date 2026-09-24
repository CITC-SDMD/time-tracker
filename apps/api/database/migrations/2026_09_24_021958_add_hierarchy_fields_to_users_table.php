<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The hierarchy: OIC -> PROJECT_MANAGER -> TEAM_LEADER -> (LEAD_DEVELOPER, DEVELOPER,
// CLIENT_SUPPORT, QA, SYSTEM_ANALYST). See docs/DEVELOPMENT_PLAN.md §8, §9.1.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'OIC',
                'PROJECT_MANAGER',
                'TEAM_LEADER',
                'LEAD_DEVELOPER',
                'DEVELOPER',
                'CLIENT_SUPPORT',
                'QA',
                'SYSTEM_ANALYST',
            ])->after('password');

            // Self-referencing: NULL only for the OIC. Enforcing "exactly one tier up"
            // is application logic (AdminEmployeeController@store), not a DB constraint.
            $table->foreignId('manager_id')->nullable()->after('role')
                ->constrained('users')->nullOnDelete();

            $table->enum('status', ['ACTIVE', 'DEACTIVATED'])->default('ACTIVE')->after('manager_id');
            $table->timestamp('deactivated_at')->nullable()->after('status');
            $table->unsignedInteger('consent_version')->nullable()->after('deactivated_at');
            $table->timestamp('consent_accepted_at')->nullable()->after('consent_version');

            $table->foreignId('created_by')->nullable()->after('consent_accepted_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn(['role', 'status', 'deactivated_at', 'consent_version', 'consent_accepted_at']);
        });
    }
};
