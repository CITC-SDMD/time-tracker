<?php

namespace Tests\Feature;

use App\Models\OrganizationSetting;
use App\Models\User;
use App\Services\IntegrityService;
use App\Services\SummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// The activity check (docs/DEVELOPMENT_PLAN.md §16): rules, ingest, visibility, clearing and tuning.
class ActivityCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lead;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->oic()->create();
        $this->lead = User::factory()->teamLeader($this->admin)->create();
        $this->dev = User::factory()->individualContributor($this->lead)->create();
    }

    // ---- the rules, on plain arrays ---------------------------------------------------------------

    /** a 10 minute chunk of $type with these counts */
    private function chunk(array $stats, string $title = 'main.rs', string $type = 'application', int $seconds = 600): array
    {
        return ['type' => $type, 'duration' => $seconds, 'app_key' => 'code', 'window_title' => $title, 'stats' => $stats];
    }

    /** @return list<array> */
    private function chunks(int $count, array $stats, string $title = 'main.rs'): array
    {
        return array_map(fn () => $this->chunk($stats, $title), range(1, $count));
    }

    private function level(array $chunks, ?string $environment = null, array $tools = []): ?string
    {
        return app(IntegrityService::class)->evaluate($chunks, $environment, $tools)['level'] ?? null;
    }

    private function codes(array $chunks, ?string $environment = null, array $tools = []): array
    {
        return array_column(app(IntegrityService::class)->evaluate($chunks, $environment, $tools)['reasons'] ?? [], 'code');
    }

    private const NORMAL = ['hwKeys' => 400, 'hwMouse' => 900, 'hwClicks' => 40, 'moveIntervalCv' => 180, 'tinyMoveShare' => 30];

    private const SOFTWARE = ['swMouse' => 500, 'swClicks' => 100, 'hwMouse' => 5];

    public function test_nothing_to_judge_gives_no_result_and_a_normal_day_gives_none(): void
    {
        $this->assertNull($this->level([]));
        $this->assertNull($this->level([['type' => 'application', 'duration' => 600, 'app_key' => 'x', 'window_title' => null, 'stats' => null]]));
        $this->assertSame('none', $this->level($this->chunks(30, self::NORMAL)));
    }

    public function test_software_input_needs_enough_events_share_and_minutes(): void
    {
        // two chunks = 20 minutes: strong; one chunk = 10 minutes: not enough
        $this->assertSame('strong', $this->level($this->chunks(2, self::SOFTWARE)));
        $this->assertSame('none', $this->level($this->chunks(1, self::SOFTWARE)));

        // a chunk with few events is not judged, and a 79% share is below the line
        $this->assertSame('none', $this->level($this->chunks(3, ['swMouse' => 20, 'hwMouse' => 5])));
        $this->assertSame('none', $this->level($this->chunks(3, ['swMouse' => 79, 'hwMouse' => 21])));
        $this->assertSame('strong', $this->level($this->chunks(3, ['swMouse' => 80, 'hwMouse' => 20])));
        $this->assertSame(['software_input'], $this->codes($this->chunks(2, self::SOFTWARE)));
    }

    public function test_activity_without_any_hardware_input_is_strong_from_15_minutes(): void
    {
        $this->assertSame('none', $this->level([$this->chunk(['swOnlySeconds' => 600]), $this->chunk(['swOnlySeconds' => 299])]));
        $this->assertSame('strong', $this->level([$this->chunk(['swOnlySeconds' => 600]), $this->chunk(['swOnlySeconds' => 300])]));
    }

    public function test_a_macro_program_alone_is_a_review_and_with_a_robotic_pattern_it_is_strong(): void
    {
        $this->assertSame('review', $this->level($this->chunks(3, self::NORMAL), null, ['autohotkey']));

        $robotic = ['hwMouse' => 60, 'moveIntervalCv' => 4, 'tinyMoveShare' => 100, 'hwKeys' => 0, 'hwClicks' => 0];
        $this->assertSame('review', $this->level($this->chunks(6, $robotic)));
        $this->assertSame('none', $this->level($this->chunks(5, $robotic)));
        $this->assertSame('strong', $this->level($this->chunks(6, $robotic), null, ['tinytask']));

        // one step less regular, or bigger movements, or some typing: not a jiggler
        $this->assertSame('none', $this->level($this->chunks(6, [...$robotic, 'moveIntervalCv' => 11])));
        $this->assertSame('none', $this->level($this->chunks(6, [...$robotic, 'tinyMoveShare' => 89])));
        $this->assertSame('none', $this->level($this->chunks(6, [...$robotic, 'hwKeys' => 3])));
    }

    public function test_hours_of_mouse_only_in_one_window_is_weak_and_needs_a_second_weak_sign_to_be_a_review(): void
    {
        $mouseOnly = ['hwMouse' => 300, 'hwClicks' => 1, 'moveIntervalCv' => 150, 'tinyMoveShare' => 20];

        $this->assertSame('none', $this->level($this->chunks(9, $mouseOnly)));
        $this->assertSame('review', $this->level($this->chunks(9, $mouseOnly), 'remote_session'));
        // a second window is somebody working
        $mixed = [...$this->chunks(5, $mouseOnly, 'a.rs'), ...$this->chunks(4, $mouseOnly, 'b.rs')];
        $this->assertSame('none', $this->level($mixed));
        $this->assertNotContains('mouse_only_hours', $this->codes($mixed));
    }

    public function test_software_input_inside_a_virtual_machine_or_remote_session_is_only_a_review_with_a_caveat(): void
    {
        $result = app(IntegrityService::class)->evaluate($this->chunks(3, self::SOFTWARE), 'remote_session', []);

        $this->assertSame('review', $result['level']);
        $this->assertStringContainsString('remote-control or accessibility', $result['reasons'][0]['message']);
        // two medium signs still count in full
        $robotic = ['hwMouse' => 60, 'moveIntervalCv' => 4, 'tinyMoveShare' => 100];
        $this->assertSame('strong', $this->level($this->chunks(6, $robotic), 'virtual_machine', ['autohotkey']));
    }

    public function test_only_known_counts_inside_their_limits_are_kept(): void
    {
        $clean = app(IntegrityService::class)->cleanStats(['hwKeys' => 5, 'swKeys' => -1, 'hwMouse' => 'lots', 'tinyMoveShare' => 101, 'swOnlySeconds' => 660, 'password' => 'x', 'hwClicks' => 1.5]);

        $this->assertSame(['hwKeys' => 5, 'swOnlySeconds' => 660], $clean);
        $this->assertNull(app(IntegrityService::class)->cleanStats('nope'));
        $this->assertNull(app(IntegrityService::class)->cleanStats(['whatever' => 1]));
    }

    public function test_only_names_of_the_known_list_survive(): void
    {
        $tools = app(IntegrityService::class)->knownTools(['AutoHotkey64', 'chrome', ' TinyTask ', 'notepad', 42, 'autohotkey']);

        $this->assertEqualsCanonicalizing(['autohotkey', 'autohotkey64', 'tinytask'], $tools);
        $this->assertSame([], app(IntegrityService::class)->knownTools('autohotkey'));
    }

    // ---- through the sync --------------------------------------------------------------------------

    private function syncBody(array $stats, array $status = [], int $chunks = 3): array
    {
        $now = Carbon::now('UTC');
        $sessions = [];
        for ($i = 0; $i < $chunks; $i++) {
            $end = $now->copy()->subMinutes(10 * $i + 1);
            $sessions[] = [
                'id' => (string) Str::uuid(),
                'type' => 'application',
                'appName' => 'Code',
                'processName' => 'Code.exe',
                'windowTitle' => 'main.rs',
                'idleAppName' => null,
                'startedAt' => $end->copy()->subMinutes(10)->toIso8601ZuluString('millisecond'),
                'endedAt' => $end->toIso8601ZuluString('millisecond'),
                'durationSeconds' => 600,
                'clockChanged' => false,
                'inputStats' => $stats,
            ];
        }

        return [
            'clientTime' => $now->toIso8601ZuluString('millisecond'),
            'status' => [
                'state' => 'active', 'currentApp' => 'Code',
                'since' => $now->copy()->subMinutes(40)->toIso8601ZuluString('millisecond'),
                'trackingStartedAt' => $now->copy()->subMinutes(40)->toIso8601ZuluString('millisecond'),
                ...$status,
            ],
            'sessions' => $sessions,
        ];
    }

    private function sync(User $user, array $body)
    {
        auth()->forgetGuards();

        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Agent-Version' => '0.1.0', 'X-Device-Id' => '11111111-1111-4111-8111-111111111111'])
            ->postJson('/api/v1/agent/sync', $body);
    }

    private function today(): string
    {
        return app(SummaryService::class)->dayOf(Carbon::now('UTC'), OrganizationSetting::withoutGlobalScopes()->where('organization_id', $this->dev->organization_id)->value('timezone'));
    }

    public function test_a_synced_day_of_software_input_becomes_a_strong_flag_with_reasons(): void
    {
        $this->sync($this->dev, $this->syncBody(self::SOFTWARE))->assertOk();

        $row = DB::table('daily_summaries')->where('user_id', $this->dev->id)->first();
        $this->assertSame('strong', $row->integrity_level);
        $this->assertSame('software_input', json_decode($row->integrity_reasons, true)[0]['code']);
        $this->assertSame(3, DB::table('sessions')->where('user_id', $this->dev->id)->whereNotNull('input_stats')->count());
    }

    public function test_known_macro_programs_are_kept_and_unknown_names_never_are(): void
    {
        $body = $this->syncBody(self::NORMAL, ['macroTools' => ['autohotkey64', 'my-secret-editor', 'chrome']]);

        $response = $this->sync($this->dev, $body)->assertOk();

        $this->assertContains('autohotkey', $response->json('settings.macroTools'));
        $row = DB::table('daily_summaries')->where('user_id', $this->dev->id)->first();
        $this->assertEqualsCanonicalizing(['autohotkey', 'autohotkey64'], json_decode($row->macro_tools, true));
        $this->assertStringNotContainsString('secret', $row->macro_tools);
        $this->assertSame('review', $row->integrity_level);
    }

    public function test_nothing_is_stored_while_detection_is_off_for_the_person(): void
    {
        $this->dev->forceFill(['detection_enabled' => false])->save();

        $this->sync($this->dev, $this->syncBody(self::SOFTWARE, ['macroTools' => ['autohotkey']]))->assertOk();

        $row = DB::table('daily_summaries')->where('user_id', $this->dev->id)->first();
        $this->assertNull($row->integrity_level);
        $this->assertNull($row->macro_tools);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->dev->id)->whereNotNull('input_stats')->count());
    }

    public function test_oversized_stats_do_not_reject_the_session(): void
    {
        $response = $this->sync($this->dev, $this->syncBody(['hwKeys' => 99_999_999_999, 'evil' => 'x'], [], 1))->assertOk();

        $this->assertCount(1, $response->json('accepted'));
        $this->assertNull(DB::table('sessions')->where('user_id', $this->dev->id)->value('input_stats'));
    }

    public function test_only_admins_and_superadmins_see_the_level_and_the_reasons(): void
    {
        $this->sync($this->dev, $this->syncBody(self::SOFTWARE, ['macroTools' => ['tinytask']]))->assertOk();
        $day = $this->today();

        auth()->forgetGuards();
        $this->actingAs($this->admin, 'sanctum');
        $row = collect($this->getJson('/api/v1/employees')->assertOk()->json())->firstWhere('id', (string) $this->dev->id);
        $this->assertSame('strong', $row['integrityLevel']);
        $summary = $this->getJson("/api/v1/employees/{$this->dev->id}/summary?from={$day}&to={$day}")->assertOk()->json()[0];
        $this->assertSame('strong', $summary['integrityLevel']);
        $this->assertSame('software_input', $summary['integrityReasons'][0]['code']);
        $this->assertSame(['tinytask'], $summary['macroTools']);

        auth()->forgetGuards();
        $this->actingAs($this->lead, 'sanctum');
        $row = collect($this->getJson('/api/v1/employees')->assertOk()->json())->firstWhere('id', (string) $this->dev->id);
        $this->assertArrayNotHasKey('integrityLevel', $row);
        $summary = $this->getJson("/api/v1/employees/{$this->dev->id}/summary?from={$day}&to={$day}")->assertOk()->json()[0];
        foreach (['integrityLevel', 'integrityReasons', 'macroTools'] as $key) {
            $this->assertArrayNotHasKey($key, $summary);
        }
    }

    public function test_switching_detection_off_clears_everything_that_was_stored(): void
    {
        $this->sync($this->dev, $this->syncBody(self::SOFTWARE, ['macroTools' => ['tinytask'], 'environment' => 'virtual_machine']))->assertOk();
        $staff = User::factory()->superadmin(['organizations.detection.manage'])->create();

        auth()->forgetGuards();
        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/v1/platform/organizations/{$this->dev->organization_id}/people/{$this->dev->id}/detection", ['enabled' => false])->assertOk();

        $row = DB::table('daily_summaries')->where('user_id', $this->dev->id)->first();
        $this->assertNull($row->integrity_level);
        $this->assertNull($row->integrity_reasons);
        $this->assertNull($row->macro_tools);
        $this->assertNull($row->environment);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->dev->id)->whereNotNull('input_stats')->count());
    }

    public function test_a_changed_threshold_is_applied_to_stored_days_by_the_command(): void
    {
        $this->sync($this->dev, $this->syncBody(self::SOFTWARE))->assertOk();
        $this->assertSame('strong', DB::table('daily_summaries')->where('user_id', $this->dev->id)->value('integrity_level'));

        config(['integrity.software_input.min_minutes' => 60]);
        Artisan::call('tracker:reevaluate-days', ['organization' => $this->dev->organization_id]);

        $this->assertSame('none', DB::table('daily_summaries')->where('user_id', $this->dev->id)->value('integrity_level'));
    }

    public function test_a_timezone_rebuild_keeps_what_the_desktop_app_reported_and_the_level(): void
    {
        $this->sync($this->dev, $this->syncBody(self::SOFTWARE, ['macroTools' => ['tinytask'], 'environment' => 'remote_session']))->assertOk();
        $day = $this->today();
        $timezone = OrganizationSetting::withoutGlobalScopes()->where('organization_id', $this->dev->organization_id)->value('timezone');

        app(SummaryService::class)->rebuildForOrganization($this->dev->organization_id, $timezone);

        $row = DB::table('daily_summaries')->where('user_id', $this->dev->id)->where('day', $day)->first();
        $this->assertSame('remote_session', $row->environment);
        $this->assertSame(['tinytask'], json_decode($row->macro_tools, true));
        $this->assertNotNull($row->integrity_level);
    }
}
