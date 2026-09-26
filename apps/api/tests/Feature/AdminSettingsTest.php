<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
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
    }

    public function test_oic_can_read_settings(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/settings');

        $response->assertOk()->assertJson([
            'timezone' => 'Asia/Manila',
            'idleThresholdSeconds' => 300,
            'windowTitleMode' => 'full',
            'consentVersion' => 1,
        ])->assertJsonMissingPath('minAgentVersion'); // the oldest allowed app version is the platform's, not the organization's
    }

    public function test_oic_can_partially_update_settings(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['idleThresholdSeconds' => 600]);

        $response->assertOk()->assertJsonPath('idleThresholdSeconds', 600);
        // Untouched fields are unchanged.
        $response->assertJsonPath('timezone', 'Asia/Manila');
        $this->assertSame(600, $this->settings()->idle_threshold_seconds);
    }

    public function test_bumping_consent_version_is_allowed(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['consentVersion' => 2]);

        $response->assertOk()->assertJsonPath('consentVersion', 2);
        $this->assertSame(2, $this->settings()->consent_version);
    }

    public function test_the_consent_version_can_never_go_down(): void
    {
        // found by the browser tests: the form refused it but the API accepted it
        $oic = User::factory()->oic()->create();
        $this->actingAs($oic, 'sanctum')->putJson('/api/v1/admin/settings', ['consentVersion' => 3])->assertOk();

        $this->actingAs($oic, 'sanctum')->putJson('/api/v1/admin/settings', ['consentVersion' => 2])->assertStatus(422);
        $this->actingAs($oic, 'sanctum')->putJson('/api/v1/admin/settings', ['consentVersion' => 3])->assertOk();

        $this->assertSame(3, $this->settings()->consent_version);
    }

    public function test_the_oldest_allowed_app_version_is_not_an_organization_setting(): void
    {
        // it belongs to the platform now (PlatformSettingsTest): sending it here changes nothing
        $oic = User::factory()->oic()->create();

        $this->actingAs($oic, 'sanctum')->putJson('/api/v1/admin/settings', ['minAgentVersion' => '9.9.9'])->assertOk();

        $this->assertSame('0.1.0', PlatformSetting::current()->min_agent_version);
    }

    public function test_invalid_window_title_mode_is_rejected(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['windowTitleMode' => 'NOT_A_MODE']);

        $response->assertStatus(422);
        $this->assertSame('full', $this->settings()->window_title_mode);
    }

    public function test_a_project_manager_cannot_read_or_change_settings(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();

        $this->actingAs($pm, 'sanctum')->getJson('/api/v1/admin/settings')->assertStatus(403);
        $this->actingAs($pm, 'sanctum')
            ->putJson('/api/v1/admin/settings', ['idleThresholdSeconds' => 600])
            ->assertStatus(403);
        $this->assertSame(300, $this->settings()->idle_threshold_seconds);
    }
}
