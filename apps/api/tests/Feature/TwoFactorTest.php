<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Factories\OrganizationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

// Optional two-factor sign-in for the dashboard (docs/SECURITY_REVIEW.md): an authenticator app code, single-use
// recovery codes, no guessing, and a way back for someone who lost their phone.
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new Google2FA;
        RateLimiter::clear('login');
    }

    /** turns two-factor on for $user through the API; returns the secret and the recovery codes */
    private function enable(User $user): array
    {
        $secret = $this->as($user)->postJson('/api/v1/me/two-factor', ['currentPassword' => 'correct-password'])
            ->assertOk()->json('secret');
        $codes = $this->as($user)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->totp->getCurrentOtp($secret)])
            ->assertOk()->json('recoveryCodes');

        return [$secret, $codes];
    }

    /** signs $user in for the next call; the test app keeps the person it resolved before, so that is forgotten first */
    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function person(): User
    {
        return User::factory()->oic()->create(['password' => bcrypt('correct-password')]);
    }

    /** a code for the next 30-second step: the one just used cannot be used again, this one is still in the accepted window */
    private function nextCode(string $secret): string
    {
        return $this->totp->oathTotp($secret, $this->totp->getTimestamp() + 1);
    }

    public function test_setting_it_up_needs_the_password_and_a_right_first_code(): void
    {
        $user = $this->person();

        $this->as($user)->postJson('/api/v1/me/two-factor', ['currentPassword' => 'nope'])->assertStatus(422)->assertJsonPath('error.code', 'WRONG_PASSWORD');

        $secret = $this->as($user)->postJson('/api/v1/me/two-factor', ['currentPassword' => 'correct-password'])
            ->assertOk()->assertJsonStructure(['secret', 'uri'])->json('secret');
        $this->assertFalse($user->fresh()->two_factor_confirmed_at !== null);

        $this->as($user)->postJson('/api/v1/me/two-factor/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonPath('error.code', 'WRONG_CODE');
        $this->as($user)->getJson('/api/v1/me/two-factor')->assertJsonPath('enabled', false);

        $response = $this->as($user)->postJson('/api/v1/me/two-factor/confirm', ['code' => $this->totp->getCurrentOtp($secret)])->assertOk();
        $this->assertCount(8, $response->json('recoveryCodes'));
        $this->as($user)->getJson('/api/v1/me/two-factor')->assertJsonPath('enabled', true)->assertJsonPath('recoveryCodesLeft', 8);
        $this->assertTrue(AuditLog::where('action', 'two_factor.enabled')->exists());
    }

    public function test_the_secret_and_the_codes_are_never_in_an_answer_or_stored_readable(): void
    {
        $user = $this->person();
        [$secret, $codes] = $this->enable($user);

        $me = $this->as($user)->getJson('/api/v1/me')->assertOk();
        $this->assertTrue($me->json('twoFactorEnabled'));
        $this->assertStringNotContainsString($secret, $me->getContent());

        $raw = (array) \DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString($secret, json_encode($raw));
        $this->assertStringNotContainsString($codes[0], json_encode($raw));
    }

    public function test_signing_in_with_a_password_alone_is_not_enough_once_it_is_on(): void
    {
        $user = $this->person();
        [$secret] = $this->enable($user);
        $this->app['auth']->forgetGuards();

        $answer = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertOk()->assertJsonPath('twoFactorRequired', true)->assertJsonStructure(['challenge']);
        $this->assertArrayNotHasKey('id', $answer->json());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertStatus(401);

        // the challenge plus the next code signs in (the first code was used to turn it on and cannot be used again)
        $this->postJson('/auth/two-factor', ['challenge' => $answer->json('challenge'), 'code' => $this->nextCode($secret)])
            ->assertOk()->assertJsonPath('email', $user->email);
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_a_wrong_code_and_a_reused_code_are_refused(): void
    {
        $user = $this->person();
        [$secret] = $this->enable($user);

        $challenge = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('challenge');
        $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => '123456'])->assertStatus(422)->assertJsonPath('error.code', 'WRONG_CODE');
        // the code that turned it on is already used
        $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => $this->totp->getCurrentOtp($secret)])->assertStatus(422);

        $code = $this->nextCode($secret);
        $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertOk();

        // and once used it works no second time, for a new sign-in
        $this->app['auth']->forgetGuards();
        $again = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('challenge');
        $this->postJson('/auth/two-factor', ['challenge' => $again, 'code' => $code])->assertStatus(422);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->person();
        [, $codes] = $this->enable($user);

        $challenge = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('challenge');
        $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => strtoupper($codes[0])])->assertOk();
        $this->assertSame(7, app(TwoFactorService::class)->recoveryCodesLeft($user->fresh()));

        $this->app['auth']->forgetGuards();
        $again = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('challenge');
        $this->postJson('/auth/two-factor', ['challenge' => $again, 'code' => $codes[0]])->assertStatus(422);
        $this->postJson('/auth/two-factor', ['challenge' => $again, 'code' => $codes[1]])->assertOk();
    }

    public function test_five_wrong_codes_stop_the_guessing_even_for_the_right_code(): void
    {
        $user = $this->person();
        [$secret] = $this->enable($user);

        $challenge = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => '00000'.$i])->assertStatus(422);
        }

        $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => $this->nextCode($secret)])->assertStatus(429);
    }

    public function test_an_unknown_or_used_challenge_is_refused(): void
    {
        $user = $this->person();
        [$secret] = $this->enable($user);

        $this->postJson('/auth/two-factor', ['challenge' => 'made-up', 'code' => '123456'])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CHALLENGE');

        $challenge = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('challenge');
        $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => $this->nextCode($secret)])->assertOk();
        $this->postJson('/auth/two-factor', ['challenge' => $challenge, 'code' => $this->nextCode($secret)])->assertStatus(422);
    }

    public function test_people_without_it_sign_in_as_before_and_the_desktop_login_is_not_affected(): void
    {
        $plain = $this->person();
        $this->postJson('/auth/login', ['email' => $plain->email, 'password' => 'correct-password'])->assertOk()->assertJsonPath('email', $plain->email);

        $withIt = $this->person();
        $this->enable($withIt);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $withIt->email, 'password' => 'correct-password'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_turning_it_off_needs_the_password_and_a_code(): void
    {
        $user = $this->person();
        [$secret, $codes] = $this->enable($user);

        $this->as($user)->deleteJson('/api/v1/me/two-factor', ['currentPassword' => 'nope', 'code' => $codes[0]])->assertStatus(422);
        $this->as($user)->deleteJson('/api/v1/me/two-factor', ['currentPassword' => 'correct-password', 'code' => '111111'])->assertStatus(422);
        $this->assertTrue(app(TwoFactorService::class)->enabled($user->fresh()));

        $this->as($user)->deleteJson('/api/v1/me/two-factor', ['currentPassword' => 'correct-password', 'code' => $codes[0]])->assertOk();
        $this->assertFalse(app(TwoFactorService::class)->enabled($user->fresh()));
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertTrue(AuditLog::where('action', 'two_factor.disabled')->exists());
    }

    public function test_new_recovery_codes_replace_the_old_ones(): void
    {
        $user = $this->person();
        [$secret, $codes] = $this->enable($user);

        $new = $this->as($user)->postJson('/api/v1/me/two-factor/recovery-codes', ['currentPassword' => 'correct-password', 'code' => $this->nextCode($secret)])
            ->assertOk()->json('recoveryCodes');

        $this->assertCount(8, $new);
        $this->assertFalse(app(TwoFactorService::class)->verify($user->fresh(), $codes[0]));
        $this->assertTrue(app(TwoFactorService::class)->verify($user->fresh(), $new[0]));
    }

    public function test_the_desktop_token_cannot_touch_it(): void
    {
        $user = $this->person();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/me/two-factor', ['currentPassword' => 'correct-password'])->assertStatus(403);
    }

    public function test_a_manager_resets_it_for_someone_in_reach_and_it_is_audited(): void
    {
        $admin = $this->person();
        $member = User::factory()->individualContributor($admin)->create(['password' => bcrypt('correct-password')]);
        $this->enable($member);

        $this->as($admin)->deleteJson("/api/v1/admin/employees/{$member->id}/two-factor")->assertOk();

        $this->assertFalse(app(TwoFactorService::class)->enabled($member->fresh()));
        $this->assertTrue(AuditLog::where('action', 'two_factor.reset')->where('target_user_id', $member->id)->where('actor_user_id', $admin->id)->exists());
        // they can sign in with the password alone again
        $this->app['auth']->forgetGuards();
        $this->postJson('/auth/login', ['email' => $member->email, 'password' => 'correct-password'])->assertOk()->assertJsonPath('email', $member->email);
    }

    public function test_the_reset_respects_reach_office_permission_and_self(): void
    {
        $boss = $this->person();
        $a = User::factory()->individualContributor($boss)->create(['password' => bcrypt('correct-password')]);
        $b = User::factory()->individualContributor($boss)->create(['password' => bcrypt('correct-password')]);
        $foreign = User::factory()->adminOf(OrganizationFactory::made('Office B'))->create(['password' => bcrypt('correct-password')]);
        $this->enable($b);
        $this->enable($foreign);

        $this->as($a)->deleteJson("/api/v1/admin/employees/{$b->id}/two-factor")->assertStatus(403); // no permission
        $this->as($boss)->deleteJson("/api/v1/admin/employees/{$foreign->id}/two-factor")->assertStatus(404); // another office
        $this->as($boss)->deleteJson("/api/v1/admin/employees/{$boss->id}/two-factor")->assertStatus(400); // not yourself

        $this->assertTrue(app(TwoFactorService::class)->enabled($b->fresh()));
        $this->assertTrue(app(TwoFactorService::class)->enabled($foreign->fresh()));
    }

    public function test_a_superadmin_resets_a_colleague_but_not_the_owner_and_the_server_command_resets_anyone(): void
    {
        $owner = User::factory()->superadmin(null, true)->create(['password' => bcrypt('correct-password')]);
        $staff = User::factory()->superadmin()->create(['password' => bcrypt('correct-password')]);
        $other = User::factory()->superadmin()->create(['password' => bcrypt('correct-password')]);
        $this->enable($owner);
        $this->enable($other);

        $this->as($staff)->deleteJson("/api/v1/platform/superadmins/{$other->id}/two-factor")->assertOk();
        $this->assertFalse(app(TwoFactorService::class)->enabled($other->fresh()));

        $this->as($staff)->deleteJson("/api/v1/platform/superadmins/{$owner->id}/two-factor")->assertStatus(403);
        $this->assertTrue(app(TwoFactorService::class)->enabled($owner->fresh()));

        $this->artisan('tracker:reset-two-factor', ['email' => strtoupper($owner->email)])->assertSuccessful();
        $this->assertFalse(app(TwoFactorService::class)->enabled($owner->fresh()));
        $this->artisan('tracker:reset-two-factor', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
