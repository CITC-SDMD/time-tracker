<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgentSyncRequest;
use App\Models\Device;
use App\Models\EmployeeStatus;
use App\Models\OfficeSetting;
use App\Models\Session;
use App\Services\SummaryService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// POST /api/v1/agent/sync (docs/DEVELOPMENT_PLAN.md §10.1, §10.2). The route's middleware
// has already checked the token, the agent version and the rate limit; the Form Request
// has validated the envelope.
class AgentController extends Controller
{
    private const TRACKING_STATES = ['active', 'idle'];

    private const MAX_AGE_DAYS = 30;

    private const LIVE_WINDOW_MINUTES = 5;

    public function __construct(private SummaryService $summaries) {}

    public function sync(AgentSyncRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $deviceId = $data['deviceId'];
        $agentVersion = (string) $request->header('X-Agent-Version');
        $incoming = $data['status'];
        $office = OfficeSetting::current();
        $now = Carbon::now('UTC');
        $deactivated = $user->status !== 'active';

        $result = DB::transaction(function () use ($user, $data, $deviceId, $agentVersion, $incoming, $office, $now, $deactivated) {
            $accepted = [];
            $duplicates = [];
            $rejected = [];
            $commands = ['stopTracking' => false, 'stopReason' => null, 'signOut' => $deactivated];

            // Step 3 of §10.1: validate each session on its own merits.
            $candidates = [];
            foreach ($data['sessions'] as $raw) {
                [$parsed, $reason] = $this->parseSession($raw, $now);
                if ($reason !== null) {
                    $rejected[] = ['id' => (string) ($raw['id'] ?? ''), 'reason' => $reason];

                    continue;
                }
                if (isset($candidates[$parsed['id']])) {
                    $duplicates[] = $parsed['id']; // same id twice in one request

                    continue;
                }
                $candidates[$parsed['id']] = $parsed;
            }

            // 4.1: ids we already have are duplicates — report them, don't re-count them.
            $existing = $candidates === [] ? [] : Session::whereIn('id', array_keys($candidates))
                ->lockForUpdate()->pluck('id')->all();
            foreach ($existing as $id) {
                $duplicates[] = $id;
                unset($candidates[$id]);
            }

            // 4.2: the one-PC rule. Newer tracking start wins; the older PC is told to stop.
            $current = EmployeeStatus::where('user_id', $user->id)->lockForUpdate()->first();
            $incomingTracking = in_array($incoming['state'], self::TRACKING_STATES, true);
            $trackingStartedAt = $this->parseTime($incoming['trackingStartedAt'] ?? null, $now) ?? $now;
            $otherPcLive = $current !== null
                && $current->device_id !== null
                && $current->device_id !== $deviceId
                && in_array($current->state, self::TRACKING_STATES, true)
                && $current->last_seen_at !== null
                && $current->last_seen_at->gte($now->copy()->subMinutes(self::LIVE_WINDOW_MINUTES));

            $updateStatus = true;
            $blockedSince = null; // sessions starting after this on this PC are rejected
            if ($otherPcLive) {
                $holderSince = $current->tracking_device_since ?? $current->since ?? $now;
                if ($incomingTracking && $trackingStartedAt->gt($holderSince)) {
                    // This PC started tracking later: it takes over.
                } else {
                    $updateStatus = false;
                    $blockedSince = $holderSince;
                    if ($incomingTracking) {
                        $commands['stopTracking'] = true;
                        $commands['stopReason'] = 'STARTED_ON_OTHER_PC';
                    }
                }
            }

            // 4.3 and the one-PC rule decide what may be stored.
            $toInsert = [];
            foreach ($candidates as $id => $s) {
                if ($deactivated && $s['startedAt']->gte($user->deactivated_at ?? $now)) {
                    $rejected[] = ['id' => $id, 'reason' => 'ACCOUNT_DEACTIVATED'];
                } elseif ($blockedSince !== null && $s['startedAt']->gt($blockedSince)) {
                    $rejected[] = ['id' => $id, 'reason' => 'OTHER_DEVICE_ACTIVE'];
                } else {
                    $toInsert[$id] = $s;
                }
            }

            // 4.4 / 4.5: insert; a duplicate-key error means another request won the race.
            foreach ($toInsert as $id => $s) {
                if ($office->window_title_mode === 'app_only') {
                    $s['windowTitle'] = null;
                }
                $appKey = $s['type'] === 'application' ? $this->summaries->appKey($s['processName'], $s['appName']) : null;
                try {
                    Session::create([
                        'id' => $id,
                        'user_id' => $user->id,
                        'device_id' => $deviceId,
                        'type' => $s['type'],
                        'app_name' => $s['appName'],
                        'app_key' => $appKey,
                        'process_name' => $s['processName'],
                        'window_title' => $s['windowTitle'],
                        'idle_app_name' => $s['idleAppName'],
                        'started_at' => $s['startedAt'],
                        'ended_at' => $s['endedAt'],
                        'duration_seconds' => $s['durationSeconds'],
                        'day' => $this->summaries->dayOf($s['startedAt'], $office->timezone),
                        'clock_changed' => $s['clockChanged'],
                        'received_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $duplicates[] = $id;

                    continue;
                }
                $accepted[] = $id;
                $this->summaries->add(
                    $user->id, $s['type'], $appKey, $s['appName'],
                    $s['startedAt'], $s['endedAt'], $s['durationSeconds'], $office->timezone,
                );
            }

            if ($updateStatus && ! $deactivated) {
                $status = $current ?? new EmployeeStatus;
                $status->user_id = $user->id;
                $status->fill([
                    'state' => $incoming['state'],
                    'current_app' => $incoming['currentApp'] ?? null,
                    'idle_app_name' => $incoming['idleAppName'] ?? null,
                    'device_id' => $deviceId,
                    'agent_version' => substr($agentVersion, 0, 32),
                    'since' => $this->parseTime($incoming['since'] ?? null, $now) ?? $now,
                    'last_seen_at' => $now,
                    'clock_skew_seconds' => ($this->parseTime($data['clientTime'], null)?->getTimestamp() ?? $now->getTimestamp()) - $now->getTimestamp(),
                ]);
                if ($incomingTracking) {
                    $status->tracking_device_since = $trackingStartedAt;
                }
                $status->save();
            }

            $device = Device::find($deviceId) ?? new Device(['id' => $deviceId, 'first_seen_at' => $now]);
            $device->fill([
                'user_id' => $user->id,
                'computer_name' => isset($data['computerName']) ? substr($data['computerName'], 0, 128) : null,
                'agent_version' => substr($agentVersion, 0, 32),
                'last_seen_at' => $now,
            ]);
            $device->save();

            return compact('accepted', 'duplicates', 'rejected', 'commands');
        });

        return response()->json([
            'accepted' => array_values($result['accepted']),
            'duplicates' => array_values(array_unique($result['duplicates'])),
            'rejected' => $result['rejected'],
            'serverTime' => $now->toIso8601ZuluString('millisecond'),
            'commands' => $result['commands'],
            'settings' => [
                'idleThresholdSeconds' => $office->idle_threshold_seconds,
                'windowTitleMode' => $office->window_title_mode,
                'screenshotIntervalMinutes' => $office->screenshot_interval_minutes,
                'screenshotRandom' => $office->screenshot_random,
            ],
        ]);
    }

    /** @return array{0: ?array, 1: ?string} [parsed session, rejection reason] */
    private function parseSession(array $raw, Carbon $now): array
    {
        $id = $raw['id'] ?? null;
        $type = $raw['type'] ?? null;
        if (! is_string($id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)
            || ! in_array($type, ['application', 'idle'], true)) {
            return [null, 'INVALID'];
        }

        foreach (['appName' => 128, 'processName' => 128, 'idleAppName' => 128, 'windowTitle' => 512] as $field => $max) {
            $value = $raw[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                return [null, 'INVALID'];
            }
            if (is_string($value) && mb_strlen($value) > $max) {
                return [null, 'FIELD_TOO_LONG'];
            }
        }

        $startedAt = $this->parseTime($raw['startedAt'] ?? null, null);
        $endedAt = $this->parseTime($raw['endedAt'] ?? null, null);
        $duration = $raw['durationSeconds'] ?? null;
        if ($startedAt === null || $endedAt === null || ! is_int($duration)) {
            return [null, 'INVALID'];
        }
        if ($endedAt->lte($startedAt)) {
            return [null, 'BAD_TIMES'];
        }
        $span = (float) $endedAt->format('U.u') - (float) $startedAt->format('U.u');
        if ($duration < 1 || $duration > 660 || abs($span - $duration) > 5) {
            return [null, 'BAD_DURATION'];
        }
        if ($endedAt->gt($now->copy()->addMinutes(10))) {
            return [null, 'FUTURE'];
        }
        if ($startedAt->lt($now->copy()->subDays(self::MAX_AGE_DAYS))) {
            return [null, 'TOO_OLD'];
        }

        return [[
            'id' => strtolower($id),
            'type' => $type,
            'appName' => $raw['appName'] ?? null,
            'processName' => $raw['processName'] ?? null,
            'windowTitle' => $raw['windowTitle'] ?? null,
            'idleAppName' => $raw['idleAppName'] ?? null,
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'durationSeconds' => $duration,
            'clockChanged' => (bool) ($raw['clockChanged'] ?? false),
        ], null];
    }

    /** Parses an ISO timestamp as UTC; a value in the future beyond $clampTo is pulled back to it. */
    private function parseTime(mixed $value, ?Carbon $clampTo): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $time = Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }

        return $clampTo !== null && $time->gt($clampTo) ? $clampTo->copy() : $time;
    }
}
