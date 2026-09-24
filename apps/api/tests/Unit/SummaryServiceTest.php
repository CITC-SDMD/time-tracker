<?php

namespace Tests\Unit;

use App\Services\SummaryService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SummaryServiceTest extends TestCase
{
    public function test_a_session_inside_one_day_is_not_split(): void
    {
        $parts = (new SummaryService)->splitByDay(
            Carbon::parse('2026-09-25 02:00:00', 'UTC'), Carbon::parse('2026-09-25 02:10:00', 'UTC'), 600, 'Asia/Manila',
        );

        $this->assertSame(['2026-09-25' => 600], $parts);
    }

    public function test_a_session_crossing_midnight_splits_at_the_office_timezone_midnight(): void
    {
        // 15:57Z = 23:57 in Manila; midnight there is 16:00Z.
        $parts = (new SummaryService)->splitByDay(
            Carbon::parse('2026-09-25 15:57:00', 'UTC'), Carbon::parse('2026-09-25 16:04:00', 'UTC'), 420, 'Asia/Manila',
        );

        $this->assertSame(['2026-09-25' => 180, '2026-09-26' => 240], $parts);
    }

    public function test_the_same_moments_fall_on_different_days_in_different_timezones(): void
    {
        $start = Carbon::parse('2026-09-25 15:57:00', 'UTC');
        $end = Carbon::parse('2026-09-25 16:04:00', 'UTC');

        $this->assertCount(1, (new SummaryService)->splitByDay($start, $end, 420, 'UTC'));
        $this->assertCount(2, (new SummaryService)->splitByDay($start, $end, 420, 'Asia/Manila'));
    }

    public function test_the_split_always_adds_up_to_the_duration(): void
    {
        $parts = (new SummaryService)->splitByDay(
            Carbon::parse('2026-09-25 15:59:59.600', 'UTC'), Carbon::parse('2026-09-25 16:00:04.700', 'UTC'), 5, 'Asia/Manila',
        );

        $this->assertSame(5, array_sum($parts));
    }

    public function test_app_keys_are_lowercase_without_exe_and_safe(): void
    {
        $service = new SummaryService;

        $this->assertSame('code', $service->appKey('Code.exe', 'Visual Studio Code'));
        $this->assertSame('google_chrome', $service->appKey('Google Chrome.EXE', null));
        $this->assertSame('visual_studio_code', $service->appKey(null, 'Visual Studio Code'));
        $this->assertSame('unknown', $service->appKey(null, null));
    }
}
