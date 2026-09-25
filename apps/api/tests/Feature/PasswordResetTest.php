<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.2: the emailed welcome link, "Forgot password" and the reset
// endpoint the dashboard's pages call.
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OfficeSetting::create([
            'id' => 1,
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'full',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
        ]);
        config(['app.dashboard_url' => 'https://tracker.example.com']);
    }

    public function test_forgot_password_mails_an_active_account_a_link_to_the_dashboard(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $user->passwordSetUrl($n->token);

            return str_starts_with($url, 'https://tracker.example.com/reset-password?link=')
                && $this->linkFromUrl($url) === ['token' => $n->token, 'email' => $user->email];
        });
    }

    public function test_forgot_password_gives_the_same_answer_for_unknown_and_deactivated_emails(): void
    {
        Notification::fake();
        $deactivated = User::factory()->deactivated()->create();
        $active = User::factory()->create();

        $known = $this->postJson('/auth/forgot-password', ['email' => $active->email]);
        $unknown = $this->postJson('/auth/forgot-password', ['email' => 'nobody@example.com']);
        $off = $this->postJson('/auth/forgot-password', ['email' => $deactivated->email]);

        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame($known->getContent(), $off->getContent());
        Notification::assertSentToTimes($active, ResetPassword::class, 1);
        Notification::assertNotSentTo($deactivated, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->postJson('/auth/forgot-password', ['email' => "x$i@example.com"])->assertOk();
        }
        $this->postJson('/auth/forgot-password', ['email' => 'x6@example.com'])->assertStatus(429);
    }

    public function test_a_valid_link_sets_a_new_password_and_the_old_one_stops_working(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-password-123')]);
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-password', $user->password));
        $this->assertFalse(Hash::check('old-password-123', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password.reset', 'target_user_id' => $user->id]);
        $this->assertSame(1, AuditLog::where('action', 'password.reset')->count());
    }

    public function test_a_link_works_only_once(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $body = ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'];

        $this->postJson('/auth/reset-password', $body)->assertOk();
        $this->postJson('/auth/reset-password', $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_wrong_token_short_password_and_bad_confirmation_are_refused(): void
    {
        $user = User::factory()->create();
        $token = Password::broker('users')->createToken($user);
        $good = ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'];

        $this->postJson('/auth/reset-password', [...$good, 'token' => 'garbage'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_TOKEN');
        $this->postJson('/auth/reset-password', [...$good, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->postJson('/auth/reset-password', [...$good, 'password_confirmation' => 'different-password'])
            ->assertStatus(422);

        // Nothing changed, and the untouched link still works.
        $this->postJson('/auth/reset-password', $good)->assertOk();
    }

    public function test_a_reset_link_expires_after_an_hour_but_a_welcome_link_lasts_three_days(): void
    {
        $user = User::factory()->create();
        $reset = Password::broker('users')->createToken($user);
        $body = fn (string $token) => ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'];

        $this->travel(61)->minutes();
        $this->postJson('/auth/reset-password', $body($reset))->assertStatus(422);

        $this->travelBack();
        $welcome = Password::broker('invites')->createToken($user);
        $this->travel(2)->days();
        $this->postJson('/auth/reset-password', $body($welcome))->assertOk();

        $other = User::factory()->create();
        $welcome2 = Password::broker('invites')->createToken($other);
        $this->travel(4)->days();
        $this->postJson('/auth/reset-password', [...$body($welcome2), 'email' => $other->email])->assertStatus(422);
    }

    public function test_a_deactivated_account_cannot_use_a_link(): void
    {
        $user = User::factory()->deactivated()->create();
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_a_reset_signs_out_every_token_including_the_desktop_app(): void
    {
        $user = User::factory()->create();
        $agent = $user->createToken('agent-pc1', ['agent'])->plainTextToken;
        $this->withToken($agent)->getJson('/api/v1/me')->assertOk();
        $token = Password::broker('users')->createToken($user);

        $this->postJson('/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($agent)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_adding_someone_emails_them_a_set_password_link_and_no_password(): void
    {
        Notification::fake();
        $oic = User::factory()->oic()->create(['name' => 'Olive Boss']);

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New PM', 'email' => 'pm@example.com', 'role' => 'project_manager',
        ])->assertCreated()->assertJsonPath('emailSent', true);

        $this->assertArrayNotHasKey('temporaryPassword', $response->json());
        $this->assertArrayNotHasKey('setPasswordUrl', $response->json());
        $pm = User::where('email', 'pm@example.com')->firstOrFail();
        Notification::assertSentTo($pm, WelcomeNotification::class);
    }

    public function test_the_welcome_link_lets_the_new_person_choose_a_password_and_log_in(): void
    {
        Notification::fake();
        $oic = User::factory()->oic()->create();
        $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New PM', 'email' => 'pm@example.com', 'role' => 'project_manager',
        ])->assertCreated();
        $pm = User::where('email', 'pm@example.com')->firstOrFail();

        $token = null;
        Notification::assertSentTo($pm, WelcomeNotification::class, function (WelcomeNotification $n) use (&$token, $pm) {
            $mail = $n->toMail($pm);
            $token = $this->tokenFromUrl($mail->actionUrl);

            return $mail->actionText === 'Set your password';
        });

        $this->postJson('/auth/reset-password', [
            'token' => $token, 'email' => $pm->email,
            'password' => 'chosen-by-them-1', 'password_confirmation' => 'chosen-by-them-1',
        ])->assertOk();

        $this->postJson('/auth/login', ['email' => $pm->email, 'password' => 'chosen-by-them-1'])->assertOk();
    }

    public function test_a_broken_mail_server_does_not_undo_the_account_and_the_manager_gets_the_link(): void
    {
        config(['mail.default' => 'failing']);
        config(['mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New PM', 'email' => 'pm@example.com', 'role' => 'project_manager',
        ])->assertCreated()->assertJsonPath('emailSent', false);

        $this->assertDatabaseHas('users', ['email' => 'pm@example.com']);
        $this->assertStringStartsWith('https://tracker.example.com/reset-password?link=', $response->json('setPasswordUrl'));
        $this->assertDoesNotMatchRegularExpression('/[&%@]/', (string) parse_url($response->json('setPasswordUrl'), PHP_URL_QUERY));

        $this->postJson('/auth/reset-password', [
            'token' => $this->tokenFromUrl($response->json('setPasswordUrl')), 'email' => 'pm@example.com',
            'password' => 'chosen-by-them-1', 'password_confirmation' => 'chosen-by-them-1',
        ])->assertOk();
    }

    /** @return array{token: string, email: string} */
    private function linkFromUrl(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return json_decode(base64_decode(strtr($query['link'], '-_', '+/')), true);
    }

    private function tokenFromUrl(string $url): string
    {
        return $this->linkFromUrl($url)['token'];
    }
}
