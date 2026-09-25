<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Deleting an account must not delete what the audit log says that person did. The actor link now
// clears instead of cascading, and each entry keeps the names as they were when it was written.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_user_id')->nullable()->change();
            $table->string('actor_name')->nullable()->after('actor_user_id');
            $table->string('target_name')->nullable()->after('target_user_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // Names for the entries written before this change.
        foreach (DB::table('audit_logs')->select('id', 'actor_user_id', 'target_user_id')->get() as $row) {
            DB::table('audit_logs')->where('id', $row->id)->update([
                'actor_name' => DB::table('users')->where('id', $row->actor_user_id)->value('name'),
                'target_name' => $row->target_user_id ? DB::table('users')->where('id', $row->target_user_id)->value('name') : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['actor_name', 'target_name']);
        });
    }
};
