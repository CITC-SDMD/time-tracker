<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// completion is per person, not per task: a task assigned to three people can have each finish
// independently. `tasks.status` (active/archived) is unrelated and stays a manager-only, task-wide switch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
