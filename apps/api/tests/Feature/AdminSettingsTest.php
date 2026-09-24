<?php

namespace Tests\Feature;

use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.1, §10: GET/PUT /admin/settings, OIC-only.
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OfficeSetting::create([
            'id' => 1,
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'FULL',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
        ]);
    }

    public function test_oic_can_read_settings(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/settings');

        $response->assertOk()->assertJson([
            'timezone' => 'Asia/Manila',
            'idleThresholdSeconds' => 300,
            'windowTitleMode' => 'FULL',
            'minAgentVersion' => '0.1.0',
            'consentVersion' => 1,
        ]);
    }

    public function test_oic_can_partially_update_settings(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['idleThresholdSeconds' => 600]);

        $response->assertOk()->assertJsonPath('idleThresholdSeconds', 600);
        // Untouched fields are unchanged.
        $response->assertJsonPath('timezone', 'Asia/Manila');
        $this->assertSame(600, OfficeSetting::current()->idle_threshold_seconds);
    }

    public function test_bumping_consent_version_is_allowed(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['consentVersion' => 2]);

        $response->assertOk()->assertJsonPath('consentVersion', 2);
        $this->assertSame(2, OfficeSetting::current()->consent_version);
    }

    public function test_invalid_window_title_mode_is_rejected(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['windowTitleMode' => 'NOT_A_MODE']);

        $response->assertStatus(422);
        $this->assertSame('FULL', OfficeSetting::current()->window_title_mode);
    }

    public function test_a_project_manager_cannot_read_or_change_settings(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();

        $this->actingAs($pm, 'sanctum')->getJson('/api/v1/admin/settings')->assertStatus(403);
        $this->actingAs($pm, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['idleThresholdSeconds' => 600])
            ->assertStatus(403);
        $this->assertSame(300, OfficeSetting::current()->idle_threshold_seconds);
    }
}
