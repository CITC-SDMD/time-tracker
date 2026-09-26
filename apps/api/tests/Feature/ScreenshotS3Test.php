<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

// Checks the REAL storage server (docs/VPS_S3_STORAGE_PLAN.pdf, section 9). Skipped unless you ask:
//   SCREENSHOT_S3_TEST=1 SCREENSHOT_DISK=s3 AWS_...=... php artisan test --filter=ScreenshotS3Test
// It writes, reads and deletes one small file under a random name and touches nothing else.
#[Group('s3')]
class ScreenshotS3Test extends TestCase
{
    public function test_the_screenshots_disk_can_write_read_and_delete_on_the_real_s3_server(): void
    {
        if (getenv('SCREENSHOT_S3_TEST') !== '1') {
            $this->markTestSkipped('Set SCREENSHOT_S3_TEST=1 (and the AWS_* variables) to test the real S3 server.');
        }

        $this->assertSame('s3', config('filesystems.disks.screenshots.driver'), 'set SCREENSHOT_DISK=s3');
        $disk = Storage::disk('screenshots');
        $path = 'smoke-test/'.Str::uuid().'.txt';

        $disk->put($path, 'ok');
        $this->assertSame('ok', $disk->get($path));
        $this->assertTrue($disk->exists($path));
        $disk->delete($path);
        $this->assertFalse($disk->exists($path));
    }
}
