<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md phase 10: uploading, listing and viewing screenshots, and who may see them.
// The `screenshots` disk is faked, so these tests never touch a real folder or a real S3 server.
class ScreenshotTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-26 06:00:00';

    private User $oic;

    private User $pm;

    private User $tl;

    private User $dev;

    private User $otherDev;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        Storage::fake('screenshots');
        OfficeSetting::create([
            'id' => 1,
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'full',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
            'screenshot_interval_minutes' => 10,
        ]);
        $this->oic = User::factory()->oic()->create(['name' => 'Olive']);
        $this->pm = User::factory()->projectManager($this->oic)->create(['name' => 'Pat']);
        $this->tl = User::factory()->teamLeader($this->pm)->create(['name' => 'Tina']);
        $this->dev = User::factory()->individualContributor($this->tl)->create(['name' => 'Dan']);
        $otherPm = User::factory()->projectManager($this->oic)->create(['name' => 'Pedro']);
        $otherTl = User::factory()->teamLeader($otherPm)->create(['name' => 'Tess']);
        $this->otherDev = User::factory()->individualContributor($otherTl)->create(['name' => 'Cara']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function jpeg(int $width = 1280, int $height = 720): UploadedFile
    {
        return UploadedFile::fake()->image('screen.jpg', $width, $height);
    }

    /** @param array<string, mixed> $overrides */
    private function upload(User $user, array $overrides = [])
    {
        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Agent-Version' => '0.1.0', 'Accept' => 'application/json'])
            ->post('/api/v1/agent/screenshots', array_merge([
                'id' => (string) Str::uuid(),
                'takenAt' => Carbon::now('UTC')->subMinute()->toIso8601ZuluString(),
                'width' => 1280,
                'height' => 720,
                'image' => $this->jpeg(),
            ], $overrides));
    }

    private function shot(User $user, ?Carbon $takenAt = null): Screenshot
    {
        $shot = Screenshot::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'taken_at' => $takenAt ?? Carbon::now('UTC')->subHour(),
            'width' => 1280,
            'height' => 720,
        ]);
        $shot->addMedia($this->jpeg())->usingFileName($shot->id.'.jpg')->toMediaCollection(Screenshot::COLLECTION);

        return $shot;
    }

    // ---- upload -----------------------------------------------------------------------------

    public function test_a_valid_upload_is_stored_with_a_thumbnail_in_the_persons_own_folder(): void
    {
        $id = (string) Str::uuid();

        $this->upload($this->dev, ['id' => $id])->assertCreated()->assertJsonPath('status', 'stored');

        $shot = Screenshot::findOrFail($id);
        $this->assertSame($this->dev->id, $shot->user_id);
        $media = $shot->getFirstMedia(Screenshot::COLLECTION);
        $this->assertSame('screenshots', $media->disk);
        $this->assertTrue($media->hasGeneratedConversion('thumb'));
        $this->assertSame("{$this->dev->id}/2026/09/26/{$id}/{$id}.jpg", $media->getPathRelativeToRoot());
        Storage::disk('screenshots')->assertExists($media->getPathRelativeToRoot());
        Storage::disk('screenshots')->assertExists($media->getPathRelativeToRoot('thumb'));
    }

    public function test_the_person_comes_from_the_token_never_from_the_body(): void
    {
        $this->upload($this->dev, ['user_id' => $this->pm->id, 'uid' => $this->pm->id, 'userId' => $this->pm->id])->assertCreated();

        $this->assertSame(0, Screenshot::where('user_id', $this->pm->id)->count());
        $this->assertSame(1, Screenshot::where('user_id', $this->dev->id)->count());
    }

    public function test_the_same_id_twice_is_stored_once_and_answered_as_a_duplicate(): void
    {
        $id = (string) Str::uuid();
        $this->upload($this->dev, ['id' => $id])->assertCreated();

        $this->upload($this->dev, ['id' => $id])->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, Screenshot::count());
        $this->assertSame(1, Media::count());
    }

    public function test_an_id_that_belongs_to_someone_else_is_refused(): void
    {
        $id = (string) Str::uuid();
        $this->upload($this->otherDev, ['id' => $id])->assertCreated();

        $this->upload($this->dev, ['id' => $id])->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_it_is_refused_while_screenshots_are_switched_off(): void
    {
        OfficeSetting::current()->update(['screenshot_interval_minutes' => 0]);

        $this->upload($this->dev)->assertStatus(409)->assertJsonPath('error.code', 'SCREENSHOTS_DISABLED');

        $this->assertSame(0, Screenshot::count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badUploads(): array
    {
        return [
            'not a uuid' => [['id' => 'not-a-uuid']],
            'time in the future' => [['takenAt' => '2026-09-26T08:00:00Z']],
            'older than 30 days' => [['takenAt' => '2026-08-01T00:00:00Z']],
            'not a date' => [['takenAt' => 'yesterday-ish']],
            'zero width' => [['width' => 0]],
            'too many pixels wide' => [['width' => 5000]],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('badUploads')]
    public function test_bad_fields_are_refused_and_nothing_is_stored(array $overrides): void
    {
        $this->upload($this->dev, $overrides)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, Screenshot::count());
    }

    public function test_the_file_must_really_be_a_jpeg_whatever_it_is_called(): void
    {
        $png = UploadedFile::fake()->image('screen.jpg', 100, 100)->mimeType('image/jpeg');
        // a real PNG saved under a .jpg name
        $path = tempnam(sys_get_temp_dir(), 'png');
        imagepng(imagecreatetruecolor(50, 50), $path);
        $disguised = new UploadedFile($path, 'screen.jpg', 'image/jpeg', null, true);

        $this->upload($this->dev, ['image' => $disguised])->assertStatus(422);
        $this->upload($this->dev, ['image' => UploadedFile::fake()->create('screen.jpg', 20, 'image/jpeg')])->assertStatus(422);
        $this->upload($this->dev, ['image' => null])->assertStatus(422);
        unset($png);
        $this->assertSame(0, Screenshot::count());
    }

    public function test_a_picture_over_the_size_limit_or_the_pixel_limit_is_refused(): void
    {
        $this->upload($this->dev, ['image' => UploadedFile::fake()->create('screen.jpg', 2000, 'image/jpeg')])->assertStatus(422);
        $this->upload($this->dev, ['image' => $this->jpeg(4000, 100), 'width' => 3840])->assertStatus(422);

        $this->assertSame(0, Screenshot::count());
    }

    public function test_when_the_storage_is_down_nothing_is_kept_and_the_app_is_told_to_retry(): void
    {
        Storage::forgetDisk('screenshots');
        config(['filesystems.disks.screenshots' => ['driver' => 'local', 'root' => __FILE__.'/cannot-be-a-folder', 'throw' => true]]);

        $this->upload($this->dev)->assertStatus(503)->assertJsonPath('error.code', 'STORAGE_UNAVAILABLE');

        $this->assertSame(0, Screenshot::count());
        $this->assertSame(0, Media::count());
    }

    public function test_a_signed_out_caller_and_an_old_app_are_refused(): void
    {
        $this->postJson('/api/v1/agent/screenshots', [])->assertUnauthorized();
        $this->actingAs($this->dev, 'sanctum')->withHeaders(['X-Agent-Version' => '0.0.1'])
            ->postJson('/api/v1/agent/screenshots', [])->assertStatus(426);
    }

    public function test_a_deactivated_account_cannot_upload(): void
    {
        $this->dev->forceFill(['status' => 'inactive'])->save();

        $this->upload($this->dev)->assertForbidden();
    }

    // ---- listing ----------------------------------------------------------------------------

    public function test_the_list_holds_the_office_day_oldest_first_and_only_that_day(): void
    {
        // 06:00 UTC on 26 Sep is 14:00 in Manila. The Manila day of the 26th is 25 Sep 16:00 UTC to 26 Sep 16:00 UTC.
        $late = $this->shot($this->dev, Carbon::parse('2026-09-26 05:30:00', 'UTC'));
        $early = $this->shot($this->dev, Carbon::parse('2026-09-25 17:00:00', 'UTC'));
        $this->shot($this->dev, Carbon::parse('2026-09-25 15:00:00', 'UTC')); // the 25th in Manila
        $this->shot($this->dev, Carbon::parse('2026-09-26 16:30:00', 'UTC')); // the 27th in Manila
        $this->shot($this->otherDev, Carbon::parse('2026-09-26 03:00:00', 'UTC'));

        $list = $this->actingAs($this->oic, 'sanctum')->getJson("/api/v1/employees/{$this->dev->id}/screenshots?day=2026-09-26")
            ->assertOk()->json();

        $this->assertSame([$early->id, $late->id], array_column($list, 'id'));
        $this->assertSame(['id', 'takenAt', 'width', 'height'], array_keys($list[0]));
        $this->assertSame('2026-09-25T17:00:00Z', $list[0]['takenAt']);
    }

    public function test_the_list_needs_a_valid_day(): void
    {
        $this->actingAs($this->oic, 'sanctum')->getJson("/api/v1/employees/{$this->dev->id}/screenshots")->assertStatus(422);
        $this->actingAs($this->oic, 'sanctum')->getJson("/api/v1/employees/{$this->dev->id}/screenshots?day=26-09-2026")->assertStatus(422);
    }

    public function test_who_may_list_whose_screenshots(): void
    {
        $url = "/api/v1/employees/{$this->dev->id}/screenshots?day=2026-09-26";

        foreach ([$this->dev, $this->tl, $this->pm, $this->oic] as $allowed) {
            $this->actingAs($allowed, 'sanctum')->getJson($url)->assertOk();
        }
        // another branch, and someone below cannot look upwards or sideways
        $this->actingAs($this->otherDev, 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($this->tl, 'sanctum')->getJson("/api/v1/employees/{$this->otherDev->id}/screenshots?day=2026-09-26")->assertForbidden();
        $this->actingAs($this->dev, 'sanctum')->getJson("/api/v1/employees/{$this->tl->id}/screenshots?day=2026-09-26")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_looking_at_someone_elses_day_is_logged_once_and_looking_at_your_own_is_not(): void
    {
        $url = "/api/v1/employees/{$this->dev->id}/screenshots?day=2026-09-26";

        $this->actingAs($this->tl, 'sanctum')->getJson($url)->assertOk();
        $this->actingAs($this->tl, 'sanctum')->getJson($url)->assertOk();
        $this->actingAs($this->dev, 'sanctum')->getJson($url)->assertOk();
        $this->actingAs($this->tl, 'sanctum')->getJson("/api/v1/employees/{$this->dev->id}/screenshots?day=2026-09-25")->assertOk();

        $entries = AuditLog::where('action', 'screenshots.viewed')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame($this->tl->id, $entries[0]->actor_user_id);
        $this->assertSame($this->dev->id, $entries[0]->target_user_id);
        $this->assertSame(['day' => '2026-09-26'], $entries[0]->details);
        $this->assertSame(['day' => '2026-09-25'], $entries[1]->details);
    }

    // ---- viewing the pictures ---------------------------------------------------------------

    public function test_the_picture_and_the_thumbnail_are_sent_as_private_jpegs(): void
    {
        $shot = $this->shot($this->dev);

        foreach (['image', 'thumb'] as $kind) {
            $response = $this->actingAs($this->tl, 'sanctum')->get("/api/v1/screenshots/{$shot->id}/{$kind}");
            $response->assertOk();
            $response->assertHeader('Content-Type', 'image/jpeg');
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $body = $response->streamedContent();
            $this->assertSame("\xFF\xD8\xFF", substr($body, 0, 3), "{$kind} is a JPEG");
        }
    }

    public function test_the_thumbnail_is_smaller_than_the_picture(): void
    {
        $shot = $this->shot($this->dev);

        $full = strlen($this->actingAs($this->oic, 'sanctum')->get("/api/v1/screenshots/{$shot->id}/image")->streamedContent());
        $thumb = strlen($this->actingAs($this->oic, 'sanctum')->get("/api/v1/screenshots/{$shot->id}/thumb")->streamedContent());

        $this->assertLessThan($full, $thumb);
    }

    public function test_the_thumbnail_falls_back_to_the_picture_while_it_is_missing(): void
    {
        $shot = $this->shot($this->dev);
        $media = $shot->getFirstMedia(Screenshot::COLLECTION);
        $media->generated_conversions = [];
        $media->save();

        $full = $this->actingAs($this->oic, 'sanctum')->get("/api/v1/screenshots/{$shot->id}/image")->streamedContent();
        $thumb = $this->actingAs($this->oic, 'sanctum')->get("/api/v1/screenshots/{$shot->id}/thumb")->streamedContent();

        $this->assertSame($full, $thumb);
    }

    public function test_only_people_who_may_see_the_person_can_open_a_picture(): void
    {
        $shot = $this->shot($this->dev);
        $url = "/api/v1/screenshots/{$shot->id}/image";

        foreach ([$this->dev, $this->tl, $this->pm, $this->oic] as $allowed) {
            $this->actingAs($allowed, 'sanctum')->get($url)->assertOk();
        }
        $this->actingAs($this->otherDev, 'sanctum')->get($url)->assertForbidden();
        $this->actingAs($this->otherDev, 'sanctum')->get("/api/v1/screenshots/{$shot->id}/thumb")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_an_unknown_picture_or_kind_is_not_found(): void
    {
        $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/screenshots/'.Str::uuid().'/image')->assertNotFound();
        $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/screenshots/not-a-uuid/image')->assertNotFound();
        $shot = $this->shot($this->dev);
        $this->actingAs($this->oic, 'sanctum')->getJson("/api/v1/screenshots/{$shot->id}/original")->assertNotFound();
    }

    public function test_no_answer_ever_carries_a_path_a_bucket_or_a_link(): void
    {
        $shot = $this->shot($this->dev);
        $bodies = [
            $this->actingAs($this->oic, 'sanctum')->getJson("/api/v1/employees/{$this->dev->id}/screenshots?day=2026-09-26")->getContent(),
            $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/admin/settings')->getContent(),
            $this->upload($this->dev)->getContent(),
        ];

        foreach ($bodies as $body) {
            $this->assertStringNotContainsString($shot->id.'.jpg', $body);
            $this->assertStringNotContainsString('conversions', $body);
            $this->assertStringNotContainsString('http', $body);
            $this->assertStringNotContainsString('private', $body);
        }
    }

    // ---- settings ---------------------------------------------------------------------------

    public function test_screenshots_are_off_by_default_and_the_settings_show_the_storage_used(): void
    {
        OfficeSetting::current()->update(['screenshot_interval_minutes' => 0]);
        $this->shot($this->dev);

        $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/admin/settings')->assertOk()
            ->assertJsonPath('screenshotIntervalMinutes', 0)
            ->assertJsonPath('screenshotRandom', false)
            ->assertJson(fn ($json) => $json->where('screenshotStorageBytes', fn ($bytes) => $bytes > 0)->etc());
    }

    public function test_turning_screenshots_on_needs_a_higher_consent_version_in_the_same_change(): void
    {
        OfficeSetting::current()->update(['screenshot_interval_minutes' => 0]);
        $put = fn (array $data) => $this->actingAs($this->oic, 'sanctum')->putJson('/api/v1/admin/settings', $data);

        $put(['screenshotIntervalMinutes' => 10])->assertStatus(422)->assertJsonPath('error.code', 'SCREENSHOTS_NEED_CONSENT');
        $put(['screenshotIntervalMinutes' => 10, 'consentVersion' => 1])->assertStatus(422)->assertJsonPath('error.code', 'SCREENSHOTS_NEED_CONSENT');
        $this->assertSame(0, OfficeSetting::current()->screenshot_interval_minutes);

        $put(['screenshotIntervalMinutes' => 10, 'consentVersion' => 2, 'screenshotRandom' => true])->assertOk()
            ->assertJsonPath('screenshotIntervalMinutes', 10)->assertJsonPath('screenshotRandom', true)->assertJsonPath('consentVersion', 2);
    }

    public function test_changing_the_interval_or_turning_off_needs_no_new_consent(): void
    {
        $put = fn (array $data) => $this->actingAs($this->oic, 'sanctum')->putJson('/api/v1/admin/settings', $data);

        $put(['screenshotIntervalMinutes' => 30])->assertOk();
        $put(['screenshotIntervalMinutes' => 0])->assertOk();
        $this->assertSame(0, OfficeSetting::current()->screenshot_interval_minutes);
    }

    public function test_only_the_allowed_intervals_are_accepted(): void
    {
        foreach ([1, 7, 20, 60, -5, 'often'] as $bad) {
            $this->actingAs($this->oic, 'sanctum')->putJson('/api/v1/admin/settings', ['screenshotIntervalMinutes' => $bad])->assertStatus(422);
        }
        // downwards, so that no step turns screenshots on from off (that needs a new consent version)
        foreach ([30, 15, 10, 5, 0] as $good) {
            $this->actingAs($this->oic, 'sanctum')->putJson('/api/v1/admin/settings', ['screenshotIntervalMinutes' => $good])->assertOk();
        }
    }

    public function test_the_settings_reach_the_desktop_app_in_me_and_in_the_sync_answer(): void
    {
        OfficeSetting::current()->update(['screenshot_interval_minutes' => 15, 'screenshot_random' => true]);

        $this->actingAs($this->dev, 'sanctum')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('officeSettings.screenshotIntervalMinutes', 15)
            ->assertJsonPath('officeSettings.screenshotRandom', true);
    }

    public function test_the_person_who_owns_screenshots_can_never_be_deleted(): void
    {
        $this->shot($this->dev);

        $this->expectException(QueryException::class);
        $this->dev->delete();
    }
}
