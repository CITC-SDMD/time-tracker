<?php

namespace Tests\Feature;

use App\Models\DailySummary;
use App\Models\Device;
use App\Models\EmployeeStatus;
use App\Models\OfficeSetting;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md Â§10.1, Â§10.2, Phase 4 tests 4.2-4.4, 4.11-4.16, 4.18.
class AgentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-25 10:00:00';

    private string $deviceA;

    private string $deviceB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        OfficeSetting::create([
            'id' => 1,
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'full',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
        ]);
        $this->deviceA = (string) Str::uuid();
        $this->deviceB = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A valid 5-minute APPLICATION session ending $endedMinutesAgo minutes before "now". */
    private function makeSession(array $overrides = [], int $endedMinutesAgo = 1): array
    {
        $ended = Carbon::now('UTC')->subMinutes($endedMinutesAgo);

        return array_merge([
            'id' => (string) Str::uuid(),
            'type' => 'application',
            'appName' => 'Visual Studio Code',
            'processName' => 'Code.exe',
            'windowTitle' => 'main.rs',
            'idleAppName' => null,
            'startedAt' => $ended->copy()->subMinutes(5)->toIso8601ZuluString('millisecond'),
            'endedAt' => $ended->toIso8601ZuluString('millisecond'),
            'durationSeconds' => 300,
            'clockChanged' => false,
        ], $overrides);
    }

    private function body(array $sessions, array $status = [], array $extra = []): array
    {
        return array_merge([
            'clientTime' => Carbon::now('UTC')->toIso8601ZuluString('millisecond'),
            'computerName' => 'DESK-01',
            'dbReset' => false,
            'status' => array_merge([
                'state' => 'active',
                'currentApp' => 'Visual Studio Code',
                'idleAppName' => null,
                'since' => Carbon::now('UTC')->subMinutes(30)->toIso8601ZuluString('millisecond'),
                'trackingStartedAt' => Carbon::now('UTC')->subMinutes(30)->toIso8601ZuluString('millisecond'),
            ], $status),
            'sessions' => $sessions,
        ], $extra);
    }

    private function sync(User $user, array $body, ?string $device = null, string $version = '0.1.0')
    {
        auth()->forgetGuards();

        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Agent-Version' => $version, 'X-Device-Id' => $device ?? $this->deviceA])
            ->postJson('/api/v1/agent/sync', $body);
    }

    public function test_a_valid_batch_is_accepted_and_totals_are_correct(): void
    {
        $user = User::factory()->create();
        $a = $this->makeSession(['processName' => 'Code.exe'], 10);
        $b = $this->makeSession(['appName' => 'Chrome', 'processName' => 'chrome.exe'], 5);
        $idle = $this->makeSession(['type' => 'idle', 'appName' => null, 'processName' => null, 'idleAppName' => 'Zoom', 'durationSeconds' => 300], 1);

        $response = $this->sync($user, $this->body([$a, $b, $idle]));

        $response->assertOk()
            ->assertJsonPath('rejected', [])
            ->assertJsonPath('duplicates', [])
            ->assertJsonPath('commands.stopTracking', false)
            ->assertJsonPath('commands.signOut', false)
            ->assertJsonPath('settings.idleThresholdSeconds', 300);
        $this->assertEqualsCanonicalizing([$a['id'], $b['id'], $idle['id']], $response->json('accepted'));
        $this->assertSame(3, Session::count());
        $this->assertSame('code', Session::find($a['id'])->app_key);
        $this->assertNull(Session::find($idle['id'])->app_key);

        $summary = DailySummary::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(900, $summary->tracked_seconds);
        $this->assertSame(600, $summary->active_seconds);
        $this->assertSame(300, $summary->idle_seconds);
        $this->assertSame(['code' => 300, 'chrome' => 300], $summary->apps);
        $this->assertSame('Chrome', $summary->app_names['chrome']);
        $this->assertNotNull($summary->first_activity_at);
        $this->assertNotNull($summary->last_activity_at);
        $this->assertNotNull(Device::find($this->deviceA));
    }

    public function test_the_same_batch_twice_reports_duplicates_and_leaves_totals_alone(): void
    {
        $user = User::factory()->create();
        $sessions = [$this->makeSession([], 10), $this->makeSession([], 5)];
        $ids = array_column($sessions, 'id');

        $this->sync($user, $this->body($sessions))->assertOk();
        $second = $this->sync($user, $this->body($sessions));

        $second->assertOk()->assertJsonPath('accepted', []);
        $this->assertEqualsCanonicalizing($ids, $second->json('duplicates'));
        $this->assertSame(2, Session::count());
        $this->assertSame(600, DailySummary::where('user_id', $user->id)->firstOrFail()->tracked_seconds);
    }

    public function test_status_follows_the_reported_state(): void
    {
        $user = User::factory()->create();

        foreach (['active', 'idle', 'paused', 'not_tracking'] as $state) {
            $this->sync($user, $this->body([], ['state' => $state]))->assertOk();
            $this->assertSame($state, EmployeeStatus::findOrFail($user->id)->state);
        }
    }

    public function test_bad_sessions_are_rejected_with_reasons_and_nothing_bad_is_saved(): void
    {
        $user = User::factory()->create();
        $endBeforeStart = $this->makeSession([
            'startedAt' => Carbon::now('UTC')->subMinutes(2)->toIso8601ZuluString('millisecond'),
            'endedAt' => Carbon::now('UTC')->subMinutes(5)->toIso8601ZuluString('millisecond'),
        ]);
        $badDuration = $this->makeSession(['durationSeconds' => 99999]);
        $tooOld = $this->makeSession([
            'startedAt' => Carbon::now('UTC')->subDays(60)->toIso8601ZuluString('millisecond'),
            'endedAt' => Carbon::now('UTC')->subDays(60)->addMinutes(5)->toIso8601ZuluString('millisecond'),
        ]);
        $longTitle = $this->makeSession(['windowTitle' => str_repeat('x', 5000)]);
        $notUuid = $this->makeSession(['id' => 'not-a-uuid']);
        $future = $this->makeSession([
            'startedAt' => Carbon::now('UTC')->addMinutes(20)->toIso8601ZuluString('millisecond'),
            'endedAt' => Carbon::now('UTC')->addMinutes(25)->toIso8601ZuluString('millisecond'),
        ]);

        $response = $this->sync($user, $this->body([$endBeforeStart, $badDuration, $tooOld, $longTitle, $notUuid, $future]));

        $response->assertOk()->assertJsonPath('accepted', []);
        $reasons = collect($response->json('rejected'))->pluck('reason', 'id')->all();
        $this->assertSame('BAD_TIMES', $reasons[$endBeforeStart['id']]);
        $this->assertSame('BAD_DURATION', $reasons[$badDuration['id']]);
        $this->assertSame('TOO_OLD', $reasons[$tooOld['id']]);
        $this->assertSame('FIELD_TOO_LONG', $reasons[$longTitle['id']]);
        $this->assertSame('INVALID', $reasons['not-a-uuid']);
        $this->assertSame('FUTURE', $reasons[$future['id']]);
        $this->assertSame(0, Session::count());
    }

    public function test_more_than_100_sessions_is_a_400_and_nothing_is_saved(): void
    {
        $user = User::factory()->create();
        $sessions = array_map(fn () => $this->makeSession(), range(1, 101));

        $this->sync($user, $this->body($sessions))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'TOO_MANY_SESSIONS');
        $this->assertSame(0, Session::count());
    }

    public function test_exactly_100_sessions_is_fine(): void
    {
        $user = User::factory()->create();
        $sessions = array_map(fn () => $this->makeSession(), range(1, 100));

        $this->sync($user, $this->body($sessions))->assertOk()->assertJsonCount(100, 'accepted');
    }

    public function test_a_user_id_in_the_body_is_ignored(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $session = $this->makeSession(['uid' => $victim->id, 'userId' => $victim->id]);

        $this->sync($user, $this->body([$session], [], ['uid' => $victim->id, 'userId' => $victim->id]))->assertOk();

        $this->assertSame($user->id, Session::findOrFail($session['id'])->user_id);
        $this->assertSame(0, Session::where('user_id', $victim->id)->count());
        $this->assertNull(EmployeeStatus::find($victim->id));
        $this->assertSame(0, DailySummary::where('user_id', $victim->id)->count());
    }

    public function test_window_titles_are_dropped_when_the_office_only_wants_app_names(): void
    {
        OfficeSetting::current()->update(['window_title_mode' => 'app_only']);
        $user = User::factory()->create();
        $session = $this->makeSession(['windowTitle' => 'Secret plans.docx']);

        $this->sync($user, $this->body([$session]))->assertOk();

        $this->assertNull(Session::findOrFail($session['id'])->window_title);
    }

    public function test_a_session_crossing_office_midnight_is_split_across_two_days(): void
    {
        // Office timezone is Asia/Manila (UTC+8): 15:55Z-16:05Z is 23:55-00:05 local.
        Carbon::setTestNow(Carbon::parse('2026-09-25 16:10:00', 'UTC'));
        $user = User::factory()->create();
        $session = $this->makeSession([
            'startedAt' => '2026-09-25T15:55:00.000Z',
            'endedAt' => '2026-09-25T16:05:00.000Z',
            'durationSeconds' => 600,
        ]);

        $this->sync($user, $this->body([$session]))->assertOk()->assertJsonPath('rejected', []);

        $days = DailySummary::where('user_id', $user->id)->orderBy('day')->get();
        $this->assertCount(2, $days);
        $this->assertSame('2026-09-25', $days[0]->day->format('Y-m-d'));
        $this->assertSame(300, $days[0]->tracked_seconds);
        $this->assertSame('2026-09-26', $days[1]->day->format('Y-m-d'));
        $this->assertSame(300, $days[1]->tracked_seconds);
        $this->assertSame(600, $days->sum('tracked_seconds'));
        $this->assertSame('2026-09-25', Session::findOrFail($session['id'])->day->format('Y-m-d'));
    }

    public function test_the_newer_pc_takes_over_and_the_older_pc_is_told_to_stop(): void
    {
        $user = User::factory()->create();
        $startA = Carbon::now('UTC')->subMinutes(60)->toIso8601ZuluString('millisecond');
        $startB = Carbon::now('UTC')->subMinutes(20)->toIso8601ZuluString('millisecond');

        $this->sync($user, $this->body([], ['trackingStartedAt' => $startA]), $this->deviceA)
            ->assertOk()->assertJsonPath('commands.stopTracking', false);

        // PC B starts later, so it takes over.
        $this->sync($user, $this->body([], ['trackingStartedAt' => $startB]), $this->deviceB)
            ->assertOk()->assertJsonPath('commands.stopTracking', false);
        $this->assertSame($this->deviceB, EmployeeStatus::findOrFail($user->id)->device_id);

        // PC A syncs again: told to stop, and its session from after B started is rejected.
        $overlap = $this->makeSession([], 5); // started 10 minutes ago, after B's start 20 minutes ago
        $response = $this->sync($user, $this->body([$overlap], ['trackingStartedAt' => $startA]), $this->deviceA);

        $response->assertOk()
            ->assertJsonPath('commands.stopTracking', true)
            ->assertJsonPath('commands.stopReason', 'STARTED_ON_OTHER_PC')
            ->assertJsonPath('accepted', [])
            ->assertJsonPath('rejected.0.reason', 'OTHER_DEVICE_ACTIVE');
        $this->assertSame($this->deviceB, EmployeeStatus::findOrFail($user->id)->device_id);
        $this->assertSame(0, Session::count());
    }

    public function test_the_old_pc_can_still_upload_sessions_from_before_the_takeover(): void
    {
        $user = User::factory()->create();
        $startB = Carbon::now('UTC')->subMinutes(10)->toIso8601ZuluString('millisecond');

        $this->sync($user, $this->body([], ['trackingStartedAt' => Carbon::now('UTC')->subMinutes(60)->toIso8601ZuluString('millisecond')]), $this->deviceA)->assertOk();
        $this->sync($user, $this->body([], ['trackingStartedAt' => $startB]), $this->deviceB)->assertOk();

        $early = $this->makeSession([], 30); // ran 35-30 minutes ago, well before B started
        $this->sync($user, $this->body([$early], ['state' => 'not_tracking']), $this->deviceA)
            ->assertOk()
            ->assertJsonPath('accepted', [$early['id']]);
    }

    public function test_a_stale_other_pc_does_not_block_this_one(): void
    {
        $user = User::factory()->create();
        $this->sync($user, $this->body([]), $this->deviceA)->assertOk();

        Carbon::setTestNow(Carbon::now('UTC')->addMinutes(10)); // A has not synced for 10 minutes
        $session = $this->makeSession([], 5);
        $this->sync($user, $this->body([$session], ['trackingStartedAt' => Carbon::now('UTC')->subMinutes(90)->toIso8601ZuluString('millisecond')]), $this->deviceB)
            ->assertOk()
            ->assertJsonPath('commands.stopTracking', false)
            ->assertJsonPath('accepted', [$session['id']]);
        $this->assertSame($this->deviceB, EmployeeStatus::findOrFail($user->id)->device_id);
    }

    public function test_a_deactivated_user_keeps_what_happened_before_deactivation_and_is_signed_out(): void
    {
        $user = User::factory()->create();
        $user->status = 'inactive';
        $user->deactivated_at = Carbon::now('UTC')->subMinutes(8);
        $user->save();

        $before = $this->makeSession([], 10); // ran 15-10 minutes ago: started before deactivation
        $after = $this->makeSession([], 1);   // ran 6-1 minutes ago: started after deactivation

        $response = $this->sync($user, $this->body([$before, $after]));

        $response->assertOk()
            ->assertJsonPath('accepted', [$before['id']])
            ->assertJsonPath('rejected.0.id', $after['id'])
            ->assertJsonPath('rejected.0.reason', 'ACCOUNT_DEACTIVATED')
            ->assertJsonPath('commands.signOut', true);
        $this->assertSame(1, Session::count());
    }

    public function test_an_agent_older_than_the_minimum_gets_426(): void
    {
        OfficeSetting::current()->update(['min_agent_version' => '1.2.0']);
        $user = User::factory()->create();

        $this->sync($user, $this->body([]), null, '1.1.9')
            ->assertStatus(426)->assertJsonPath('error.code', 'UPGRADE_REQUIRED');
        $this->sync($user, $this->body([]), null, '1.2.0')->assertOk();
    }

    public function test_a_missing_agent_version_header_gets_426(): void
    {
        $user = User::factory()->create();

        auth()->forgetGuards();
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Device-Id', $this->deviceA)
            ->postJson('/api/v1/agent/sync', $this->body([]))
            ->assertStatus(426);
    }

    public function test_sync_needs_a_token(): void
    {
        $this->withHeaders(['X-Agent-Version' => '0.1.0', 'X-Device-Id' => $this->deviceA])
            ->postJson('/api/v1/agent/sync', $this->body([]))
            ->assertStatus(401);
    }

    public function test_a_missing_device_id_is_a_validation_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Agent-Version', '0.1.0')
            ->postJson('/api/v1/agent/sync', $this->body([]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_the_31st_sync_in_a_minute_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 30; $i++) {
            $this->sync($user, $this->body([]))->assertOk();
        }

        $this->sync($user, $this->body([]))->assertStatus(429);
    }
}
