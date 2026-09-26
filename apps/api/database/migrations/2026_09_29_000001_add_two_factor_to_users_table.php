<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// optional second sign-in step with an authenticator app. the secret is stored encrypted, the recovery codes only as
// hashes (they are shown once), and the last accepted time step stops one code from being used twice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('invite_failed_at');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_confirmed_at');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_last_step']);
        });
    }
};
