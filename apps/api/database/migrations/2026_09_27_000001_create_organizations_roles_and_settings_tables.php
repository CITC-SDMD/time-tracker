<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// the app serves many government offices (organizations) from one database: every office designs its
// own roles, and every tenant row carries the organization it belongs to.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // a suspended organization cannot sign in; nothing is deleted
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();
        });

        // roles are made by each organization from the fixed list of permissions in the code
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            // how far the permissions reach: only me, me and everyone below me, or the whole organization
            $table->enum('scope', ['self', 'team', 'organization'])->default('self');
            $table->json('permissions');
            // the built-in admin role of an organization: permissions and scope are locked
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // one row per organization (was the single office_settings row)
        Schema::create('organization_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 64); // e.g. "Asia/Manila", defines what "a day" is
            $table->integer('idle_threshold_seconds')->default(300);
            $table->enum('window_title_mode', ['full', 'app_only'])->default('full');
            $table->integer('consent_version')->default(1);
            // minutes between screenshots: 0 = off, otherwise 5, 10, 15 or 30
            $table->unsignedTinyInteger('screenshot_interval_minutes')->default(0);
            // true = one shot at a random moment inside each block instead of on a fixed rhythm
            $table->boolean('screenshot_random')->default(false);
        });

        // one row for the whole platform: what only the platform owner decides
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->tinyInteger('id')->primary()->default(1);
            $table->string('min_agent_version', 32);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('organization_settings');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('organizations');
    }
};
