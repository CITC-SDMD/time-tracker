<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// PUT /me/email (docs/DEVELOPMENT_PLAN.md §10): a person changes the email they sign in with. It needs
// the current password ("password" for factory users), tells the old address, and ends old reset links.
class MeEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oic = User::factory()->oic()->create(['name' => 'Olive', 'email' => 'olive@example.com']);
        $this->dev = User::factory()->individualContributor($this->oic)->create(['name' => 'Dev', 'email' => 'dev@example.com']);
    }

    private function change(User $who, array $body)
    {
        return $this->actingAs($who, 'sanctum')->putJson('/api/v1/me/email', $body);
    }

    public function test_a_person_can_change_their_own_email_with_their_password(): void
    {
        Notification::fake();

        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])
            ->assertOk()
            ->assertJsonPath('email', 'new.dev@example.com')
            ->assertJsonPath('role.name', 'Developer');

        $this->assertSame('new.dev@example.com', $this->dev->fresh()->email);
    }

    public function test_every_role_can_do_it_including_the_oic(): void
    {
        Notification::fake();

        $this->change($this->oic, ['email' => 'boss@example.com', 'currentPassword' => 'password'])->assertOk();

        $this->assertSame('boss@example.com', $this->oic->fresh()->email);
    }

    public function test_the_old_address_is_told_and_the_notice_names_the_new_one(): void
    {
        Notification::fake();

        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])->assertOk();

        Notification::assertSentOnDemand(EmailChangedNotification::class, function ($notification, $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'dev@example.com'
                && str_contains(implode(' ', $mail->introLines), 'new.dev@example.com');
        });
    }

    public function test_the_change_is_written_to_the_audit_log(): void
    {
        Notification::fake();

        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])->assertOk();

        $entry = AuditLog::where('action', 'profile.email_changed')->firstOrFail();
        $this->assertSame($this->dev->id, $entry->actor_user_id);
        $this->assertSame(['from' => 'dev@example.com', 'to' => 'new.dev@example.com'], $entry->details);
    }

    public function test_a_wrong_password_changes_nothing(): void
    {
        Notification::fake();

        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WRONG_PASSWORD');

        $this->assertSame('dev@example.com', $this->dev->fresh()->email);
        Notification::assertNothingSent();
    }

    public function test_an_email_someone_else_uses_is_refused_in_any_case(): void
    {
        Notification::fake();

        $this->change($this->dev, ['email' => 'olive@example.com', 'currentPassword' => 'password'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EMAIL_TAKEN');
        $this->change($this->dev, ['email' => 'OLIVE@Example.com', 'currentPassword' => 'password'])
            ->assertStatus(409);

        $this->assertSame('dev@example.com', $this->dev->fresh()->email);
    }

    public function test_the_same_email_is_refused(): void
    {
        $this->change($this->dev, ['email' => 'DEV@example.com', 'currentPassword' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SAME_EMAIL');
    }

    public function test_an_invalid_or_missing_email_is_refused(): void
    {
        foreach (['', 'not-an-email', str_repeat('a', 250).'@example.com'] as $bad) {
            $this->change($this->dev, ['email' => $bad, 'currentPassword' => 'password'])->assertStatus(422);
        }
        $this->change($this->dev, ['currentPassword' => 'password'])->assertStatus(422);
        $this->change($this->dev, ['email' => 'new.dev@example.com'])->assertStatus(422);

        $this->assertSame('dev@example.com', $this->dev->fresh()->email);
    }

    public function test_signed_out_is_refused(): void
    {
        $this->putJson('/api/v1/me/email', ['email' => 'x@example.com', 'currentPassword' => 'password'])->assertStatus(401);
    }

    public function test_a_deactivated_person_cannot_use_it(): void
    {
        $gone = User::factory()->individualContributor($this->oic)->deactivated()->create();

        $this->change($gone, ['email' => 'x@example.com', 'currentPassword' => 'password'])->assertStatus(403);
    }

    public function test_it_is_limited_to_five_tries_a_minute(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'wrong'])->assertStatus(422);
        }
        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])->assertStatus(429);
    }

    public function test_reset_and_invite_links_for_the_old_address_stop_working(): void
    {
        Notification::fake();
        DB::table('password_reset_tokens')->insert(['email' => 'dev@example.com', 'token' => 'x', 'created_at' => now()]);
        DB::table('password_invite_tokens')->insert(['email' => 'dev@example.com', 'token' => 'y', 'created_at' => now()]);

        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])->assertOk();

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'dev@example.com')->count());
        $this->assertSame(0, DB::table('password_invite_tokens')->where('email', 'dev@example.com')->count());
    }

    public function test_the_desktop_app_stays_signed_in(): void
    {
        Notification::fake();
        $token = $this->dev->createToken('agent-test');

        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])->assertOk();

        $this->assertSame(1, $this->dev->tokens()->count());
        $this->assertNotNull($token);
    }

    public function test_login_works_with_the_new_email_and_not_the_old_one(): void
    {
        Notification::fake();
        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/login', ['email' => 'new.dev@example.com', 'password' => 'password', 'deviceId' => 'd1', 'deviceName' => 'PC'])
            ->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'dev@example.com', 'password' => 'password', 'deviceId' => 'd1', 'deviceName' => 'PC'])
            ->assertStatus(401);
    }

    public function test_a_failing_mail_server_does_not_undo_the_change(): void
    {
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('smtp down'));

        $this->change($this->dev, ['email' => 'new.dev@example.com', 'currentPassword' => 'password'])->assertOk();

        $this->assertSame('new.dev@example.com', $this->dev->fresh()->email);
    }
}
