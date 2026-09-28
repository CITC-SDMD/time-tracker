<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// the task the employee had picked while a chunk of time was tracked (nullable: general, untagged time is
// allowed). A task that is later deleted leaves the sessions that named it untagged, not gone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('input_stats')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });
    }
};
