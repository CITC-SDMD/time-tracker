<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// The security pass over docs/DEVELOPMENT_PLAN.md §15 (results in docs/SECURITY_REVIEW.md): guessing passwords, the
// token the desktop app holds, and tokens that outlive their use.
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function agentToken(User $user, string $password = 'correct-password'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->json('token');
    }

    public function test_guessing_a_password_on_the_desktop_login_stops_after_a_few_tries(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => "guess-{$i}"])->assertStatus(401);
        }

        // the ninth try is refused, and so is the right password: the account is not open to a guessing run
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'guess-9'])->assertStatus(429);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->assertStatus(429);
    }

    public function test_guessing_a_password_on_the_dashboard_login_stops_after_a_few_tries(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/auth/login', ['email' => $user->email, 'password' => "guess-{$i}"])->assertStatus(401);
        }

        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'guess-9'])->assertStatus(429);
    }

    public function test_one_persons_failed_tries_do_not_lock_out_a_colleague_on_the_same_address(): void
    {
        $one = User::factory()->create(['password' => bcrypt('correct-password')]);
        $two = User::factory()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $one->email, 'password' => "guess-{$i}"]);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $two->email, 'password' => 'correct-password'])->assertOk();
    }

    public function test_the_desktop_token_reaches_only_what_the_desktop_app_calls(): void
    {
        $admin = User::factory()->oic()->create(['password' => bcrypt('correct-password')]);
        $token = $this->agentToken($admin);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        // an administrator's token from the desktop app must not administer anything
        $this->withToken($token)->getJson('/api/v1/admin/settings')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->withToken($token)->getJson('/api/v1/employees')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/roles')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/reports/daily')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/admin/audit')->assertStatus(403);
        $this->withToken($token)->patchJson('/api/v1/me', ['name' => 'Someone Else'])->assertStatus(403);
        $this->withToken($token)->putJson('/api/v1/me/password', ['password' => 'a-new-long-password', 'password_confirmation' => 'a-new-long-password'])->assertStatus(403);
        $this->withToken($token)->postJson('/api/v1/admin/employees', [])->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/platform/organizations')->assertStatus(403);
    }

    public function test_the_desktop_token_still_reaches_sync_consent_and_the_persons_own_pictures(): void
    {
        $user = User::factory()->oic()->create(['password' => bcrypt('correct-password')]);
        $token = $this->agentToken($user);

        $this->assertNotSame(403, $this->withToken($token)->postJson('/api/v1/me/consent', ['version' => 1])->status());
        $this->withToken($token)->getJson("/api/v1/employees/{$user->id}/screenshots?day=2026-09-20")->assertOk();
        $this->assertNotContains($this->withToken($token)->postJson('/api/v1/agent/sync', [])->status(), [401, 403]);
    }

    public function test_signing_in_again_on_the_same_pc_replaces_its_token_and_a_huge_device_id_does_not_break_it(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $login = fn (string $device) => $this->withHeader('X-Device-Id', $device)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password']);

        $first = $login('pc-one')->assertOk()->json('token');
        $login('pc-one')->assertOk();
        $login('pc-two')->assertOk();
        $login(str_repeat('x', 400))->assertOk();

        $this->assertSame(3, $user->tokens()->count()); // one per PC, not one per login
        $this->app['auth']->forgetGuards();
        $this->withToken($first)->getJson('/api/v1/me')->assertStatus(401); // the replaced token no longer works
    }

    public function test_a_token_older_than_its_lifetime_is_refused(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $token = $this->agentToken($user);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->travel(config('sanctum.agent_token_days') + 1)->days();
        $this->app['auth']->forgetGuards(); // the test app keeps the person it resolved before

        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_a_deactivated_persons_token_stops_working_at_once(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $token = $this->agentToken($user);

        $user->forceFill(['status' => 'deactivated'])->save();

        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_an_error_never_shows_a_stack_trace_or_a_query_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $body = $this->getJson('/api/v1/employees/not-a-number/summary')->getContent();

        $this->assertStringNotContainsString('vendor/', $body);
        $this->assertStringNotContainsString('select ', strtolower($body));
    }

    public function test_the_settings_check_fails_on_development_settings_and_says_what_to_fix(): void
    {
        $this->artisan('tracker:security-check')
            ->expectsOutputToContain('APP_DEBUG is on')
            ->expectsOutputToContain('SESSION_SECURE_COOKIE')
            ->assertFailed();
    }

    public function test_the_settings_check_passes_on_production_settings(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://tracker.example.gov',
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'queue.default' => 'database', 'mail.default' => 'smtp', 'sanctum.stateful' => ['tracker.example.gov'],
            'database.connections.'.config('database.default').'.username' => 'tracker',
        ]);

        $this->artisan('tracker:security-check')->assertSuccessful();
    }
}
