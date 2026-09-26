<?php

namespace Tests\Feature;

use App\Jobs\SendInviteEmail;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

// POST /api/v1/admin/employees/import: many people at once, all or nothing (AdminEmployeeController@import).
class ImportPeopleTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $tl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oic = User::factory()->oic()->create(['name' => 'Olive']);
        $this->tl = User::factory()->teamLeader($this->oic)->create(['name' => 'Tina']);
    }

    private function import(User $caller, array $rows)
    {
        return $this->actingAs($caller, 'sanctum')->postJson('/api/v1/admin/employees/import', ['rows' => $rows]);
    }

    private function row(string $email, array $more = []): array
    {
        return ['name' => 'New '.$email, 'email' => $email, 'roleId' => $this->tl->role_id, ...$more];
    }

    public function test_every_valid_row_becomes_a_person_who_is_invited_and_audited(): void
    {
        Notification::fake();

        $this->import($this->oic, [
            $this->row('a@example.com'),
            $this->row('b@example.com', ['managerEmail' => $this->tl->email]),
        ])->assertCreated()->assertJsonPath('created', 2)->assertJsonPath('invitesQueued', 2);

        $a = User::where('email', 'a@example.com')->firstOrFail();
        $b = User::where('email', 'b@example.com')->firstOrFail();
        $this->assertSame($this->oic->id, $a->manager_id);
        $this->assertSame($this->tl->id, $b->manager_id);
        $this->assertSame($this->oic->organization_id, $a->organization_id);
        Notification::assertSentTo($a, WelcomeNotification::class);
        $this->assertSame(2, AuditLog::where('action', 'employee.created')->count());
    }

    public function test_one_bad_row_creates_nobody_and_each_problem_names_its_row(): void
    {
        $this->import($this->oic, [
            $this->row('ok@example.com'),
            $this->row('not-an-email'),
            $this->row($this->tl->email),
            $this->row('ok@example.com'),
            $this->row('x@example.com', ['roleId' => 999999]),
            $this->row('y@example.com', ['managerEmail' => 'nobody@example.com']),
        ])->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_INVALID');

        $rows = collect($this->import($this->oic, [$this->row('not-an-email'), $this->row($this->tl->email)])->json('error.rows'));
        $this->assertSame([1, 2], $rows->pluck('row')->all());
        $this->assertFalse(User::where('email', 'ok@example.com')->exists());
    }

    public function test_it_needs_the_permission_to_add_people_and_is_capped(): void
    {
        $dev = User::factory()->individualContributor($this->tl)->create();

        $this->import($dev, [$this->row('a@example.com')])->assertForbidden();
        $this->import($this->oic, [])->assertUnprocessable();
        $this->import($this->oic, array_map(fn ($i) => $this->row("p{$i}@example.com"), range(1, 201)))->assertUnprocessable();
    }

    public function test_nobody_imports_a_role_above_their_own(): void
    {
        $adminRoleId = $this->oic->role_id;

        $this->import($this->tl, [$this->row('a@example.com', ['roleId' => $adminRoleId])])
            ->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_INVALID');
        $this->assertFalse(User::where('email', 'a@example.com')->exists());
    }

    public function test_invitations_are_queued_not_sent_during_the_request(): void
    {
        Bus::fake();

        $this->import($this->oic, [$this->row('a@example.com'), $this->row('b@example.com')])->assertCreated();

        Bus::assertDispatched(SendInviteEmail::class, 2);
        $this->assertSame(2, User::whereIn('email', ['a@example.com', 'b@example.com'])->count());
    }

    public function test_a_failure_part_way_leaves_nobody_behind(): void
    {
        // the second account fails to save: the first one, already made, must be rolled back with it
        $calls = 0;
        User::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('disk full');
            }
        });

        try {
            $this->withoutExceptionHandling()->import($this->oic, [$this->row('a@example.com'), $this->row('b@example.com')]);
            $this->fail('the import should have failed');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(User::where('email', 'a@example.com')->exists());
        $this->assertSame(0, AuditLog::where('action', 'employee.created')->count());
        $this->assertSame(0, DB::table('password_invite_tokens')->count());
    }

    public function test_a_manager_may_come_later_in_the_same_file(): void
    {
        Notification::fake();

        $this->import($this->oic, [
            $this->row('junior@example.com', ['managerEmail' => 'senior@example.com']),
            $this->row('senior@example.com'),
        ])->assertCreated();

        $senior = User::where('email', 'senior@example.com')->firstOrFail();
        $this->assertSame($senior->id, User::where('email', 'junior@example.com')->value('manager_id'));
        $this->assertSame($this->oic->id, $senior->manager_id);
    }

    public function test_people_of_one_file_cannot_report_to_each_other_in_a_circle_or_to_themselves(): void
    {
        $this->import($this->oic, [
            $this->row('a@example.com', ['managerEmail' => 'b@example.com']),
            $this->row('b@example.com', ['managerEmail' => 'a@example.com']),
            $this->row('c@example.com', ['managerEmail' => 'c@example.com']),
        ])->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_INVALID');

        $rows = collect($this->import($this->oic, [
            $this->row('a@example.com', ['managerEmail' => 'b@example.com']),
            $this->row('b@example.com', ['managerEmail' => 'a@example.com']),
        ])->json('error.rows'));
        $this->assertStringContainsString('in a circle', $rows->first()['message']);
        $this->assertFalse(User::where('email', 'a@example.com')->exists());
    }
}
