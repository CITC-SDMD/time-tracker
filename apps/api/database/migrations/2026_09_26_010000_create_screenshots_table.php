<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// one row per screenshot (docs/development_plan.md phase 10). the picture itself and its thumbnail
// are files on the screenshots disk, listed in the media table of the media library.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshots', function (Blueprint $table) {
            // the uuid v7 the desktop app made, so a repeated upload is recognised (never auto-generated here)
            $table->uuid('id')->primary();
            // restrict: an account that has screenshots can never be deleted, only deactivated
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('device_id')->nullable();
            // utc, like every stored time
            $table->timestamp('taken_at');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'taken_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshots');
    }
};
