<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// every tenant table gets the organization it belongs to (also copied onto rows that could reach it through
// user_id, so a query can never forget the filter and a bug cannot quietly cross two offices). The columns
// are nullable in the database because platform superadmins and platform audit entries belong to no
// organization; the models fill and check them.
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'users', 'audit_logs', 'employee_statuses', 'sessions', 'daily_summaries', 'devices', 'screenshots',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();
            // platform staff: no organization, no organization role, their own list of platform permissions
            $table->boolean('is_superadmin')->default(false);
            $table->boolean('is_owner')->default(false);
            $table->json('superadmin_permissions')->nullable();
        });

        Schema::table('sessions', fn (Blueprint $table) => $table->index(['organization_id', 'day']));
        Schema::table('daily_summaries', fn (Blueprint $table) => $table->index(['organization_id', 'day']));
        Schema::table('audit_logs', fn (Blueprint $table) => $table->index(['organization_id', 'created_at']));
        Schema::table('screenshots', fn (Blueprint $table) => $table->index(['organization_id', 'taken_at']));
    }

    public function down(): void
    {
        Schema::table('screenshots', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'taken_at']));
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'created_at']));
        Schema::table('daily_summaries', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'day']));
        Schema::table('sessions', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'day']));

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_superadmin', 'is_owner', 'superadmin_permissions']);
            $table->dropConstrainedForeignId('role_id');
        });

        foreach (array_reverse($this->tables) as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
        }
    }
};
