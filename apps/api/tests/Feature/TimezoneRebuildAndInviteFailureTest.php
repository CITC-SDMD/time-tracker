<?php

namespace Tests\Feature;

use App\Jobs\SendInviteEmail;
use App\Models\AuditLog;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Services\SummaryService;
use Database\Factories\OrganizationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

// A timezone change recalculates the stored days (SummaryService::rebuildForOrganization via RebuildDaysJob), and a
// queued invitation that finally fails is marked on the person so the admin can send it again.
class TimezoneRebuildAndInviteFailureTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oic = User::factory()->oic()->create(['name' => 'Olive']);
        $this->dev = User::factory()->individualContributor($this->oic)->create();
        OrganizationSetting::withoutGlobalScopes()->where('organization_id', $this->oic->organization_id)->update(['timezone' => 'Asia/Manila']);
    }

    /** a stored session, counted in the summaries the way a sync counts it */
    private function track(User $user, string $start, int $seconds, string $timezone): void
    {
        $started = Carbon::parse($start, 'UTC');
        $ended = $started->copy()->addSeconds($seconds);
        $summaries = app(SummaryService::class);
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'device_id' => (string) Str::uuid(),
            'type' => 'application',
            'app_name' => 'Code',
            'app_key' => 'code',
            'process_name' => 'code.exe',
            'started_at' => $started->format('Y-m-d H:i:s.v'),
            'ended_at' => $ended->format('Y-m-d H:i:s.v'),
            'duration_seconds' => $seconds,
            'day' => $summaries->dayOf($started, $timezone),
            'received_at' => now(),
        ]);
        $summaries->add($user->id, 'application', 'code', 'Code', $started, $ended, $seconds, $timezone);
    }

    /** @return array<string, int> day => tracked seconds */
    private function days(User $user): array
    {
        return DB::table('daily_summaries')->where('user_id', $user->id)->orderBy('day')->pluck('tracked_seconds', 'day')->map(fn ($v) => (int) $v)->all();
    }

    public function test_changing_the_timezone_moves_stored_time_to_the_right_days(): void
    {
        // 23:30 UTC on 1 May is 07:30 on 2 May in Manila and still 1 May in UTC; 15:30 UTC crosses midnight in Manila
        $this->track($this->dev, '2026-05-01 23:30:00', 1800, 'Asia/Manila');
        $this->track($this->dev, '2026-05-01 15:45:00', 1800, 'Asia/Manila');
        $this->assertSame(['2026-05-01' => 900, '2026-05-02' => 2700], $this->days($this->dev));

        $this->actingAs($this->oic, 'sanctum')->putJson('/api/v1/admin/settings', ['timezone' => 'UTC'])->assertOk();

        $this->assertSame(['2026-05-01' => 3600], $this->days($this->dev));
        $this->assertSame(['2026-05-01'], DB::table('sessions')->where('user_id', $this->dev->id)->pluck('day')->unique()->values()->all());
        $summary = DB::table('daily_summaries')->where('user_id', $this->dev->id)->first();
        $this->assertSame(3600, (int) json_decode($summary->apps, true)['code']);
        $this->assertStringStartsWith('2026-05-01 15:45:00', $summary->first_activity_at);
    }

    public function test_rebuilding_is_repeatable_and_leaves_other_organizations_alone(): void
    {
        $other = OrganizationFactory::made('Other Office');
        $stranger = User::factory()->adminOf($other)->create();
        OrganizationSetting::withoutGlobalScopes()->where('organization_id', $other->id)->update(['timezone' => 'Asia/Manila']);
        $this->track($this->dev, '2026-05-01 15:45:00', 1800, 'Asia/Manila');
        $this->track($stranger, '2026-05-01 15:45:00', 1800, 'Asia/Manila');
        $service = app(SummaryService::class);

        $service->rebuildForOrganization($this->oic->organization_id, 'UTC');
        $service->rebuildForOrganization($this->oic->organization_id, 'UTC');

        $this->assertSame(['2026-05-01' => 1800], $this->days($this->dev));
        $this->assertSame(['2026-05-01' => 900, '2026-05-02' => 900], $this->days($stranger));
    }

    public function test_the_platform_timezone_edit_recalculates_too(): void
    {
        $owner = User::factory()->superadmin()->create(['is_owner' => true]);
        $this->track($this->dev, '2026-05-01 15:45:00', 1800, 'Asia/Manila');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/platform/organizations/{$this->oic->organization_id}", ['timezone' => 'UTC'])->assertOk();

        $this->assertSame(['2026-05-01' => 1800], $this->days($this->dev));
    }

    public function test_a_queued_invitation_that_finally_fails_marks_the_person_and_is_audited(): void
    {
        (new SendInviteEmail($this->dev->id, 'token', 'Olive', $this->oic->id))->failed(new \RuntimeException('smtp down'));

        $this->assertNotNull($this->dev->fresh()->invite_failed_at);
        $entry = AuditLog::where('action', 'employee.invite_failed')->firstOrFail();
        $this->assertSame($this->dev->id, $entry->target_user_id);
        $this->assertSame($this->oic->organization_id, $entry->organization_id);

        $list = $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/employees?includeDeactivated=1')->assertOk()->json();
        $this->assertTrue(collect($list)->firstWhere('id', (string) $this->dev->id)['inviteFailed']);
    }

    public function test_a_send_that_works_and_a_resend_clear_the_mark(): void
    {
        Notification::fake();
        $this->dev->forceFill(['invite_failed_at' => now()])->save();

        (new SendInviteEmail($this->dev->id, 'token', 'Olive', $this->oic->id))->handle();
        $this->assertNull($this->dev->fresh()->invite_failed_at);
        Notification::assertSentTo($this->dev, WelcomeNotification::class);

        $this->dev->forceFill(['invite_failed_at' => now()])->save();
        $this->actingAs($this->oic, 'sanctum')->postJson("/api/v1/admin/employees/{$this->dev->id}/resend-invite")->assertOk();
        $this->assertNull($this->dev->fresh()->invite_failed_at);
    }
}
