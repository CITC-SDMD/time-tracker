<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScreenshotRequest;
use App\Models\AuditLog;
use App\Models\OrganizationSetting;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\AccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

// Screenshots (docs/DEVELOPMENT_PLAN.md phase 10). The desktop app uploads one JPEG; the files live
// on the private `screenshots` disk and reach a browser only through `show`, after the same
// reach check as a timeline. No path, bucket or link ever appears in an answer.
class ScreenshotController extends Controller
{
    /** the same person looking at the same day again within this time is not logged again */
    private const VIEW_AUDIT_WINDOW_MINUTES = 30;

    public function __construct(private AccessService $access) {}

    /** POST /api/v1/agent/screenshots */
    public function store(StoreScreenshotRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (OrganizationSetting::current()->screenshot_interval_minutes === 0) {
            return $this->error('SCREENSHOTS_DISABLED', 'Screenshots are switched off for your organization.', 409);
        }

        $existing = Screenshot::find($data['id']);
        if ($existing !== null) {
            return $existing->user_id === $user->id
                ? response()->json(['status' => 'duplicate'])
                : $this->error('CONFLICT', 'That id is already used.', 409);
        }

        try {
            DB::transaction(function () use ($request, $user, $data) {
                $screenshot = Screenshot::create([
                    'id' => $data['id'],
                    'user_id' => $user->id,
                    'device_id' => $data['deviceId'] ?? null,
                    'taken_at' => CarbonImmutable::parse($data['takenAt'])->utc(),
                    'width' => $data['width'],
                    'height' => $data['height'],
                ]);
                $screenshot->addMedia($request->file('image'))
                    ->usingFileName($data['id'].'.jpg')
                    ->toMediaCollection(Screenshot::COLLECTION);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']); // the same upload arrived twice at once
        } catch (Throwable $e) {
            report($e);

            // nothing was kept (the row was rolled back): the desktop app keeps its copy and tries again
            return $this->error('STORAGE_UNAVAILABLE', 'The screenshot could not be stored. Try again later.', 503);
        }

        return response()->json(['status' => 'stored'], 201);
    }

    /** GET /api/v1/employees/{id}/screenshots?day=YYYY-MM-DD (self, or a manager who can see the person) */
    public function index(Request $request, int $id): JsonResponse
    {
        $request->validate(['day' => ['required', 'date_format:Y-m-d']]);
        $day = $request->string('day')->toString();
        $timezone = OrganizationSetting::current()->timezone;
        $from = CarbonImmutable::createFromFormat('Y-m-d', $day, $timezone)->startOfDay()->utc();

        $items = Screenshot::where('user_id', $id)
            ->where('taken_at', '>=', $from)
            ->where('taken_at', '<', $from->addDay())
            ->orderBy('taken_at')
            ->get()
            ->map(fn (Screenshot $shot) => [
                'id' => $shot->id,
                'takenAt' => $shot->taken_at->utc()->toIso8601ZuluString(),
                'width' => $shot->width,
                'height' => $shot->height,
            ])
            ->values();

        $this->recordView($request->user(), $id, $day);

        return response()->json($items);
    }

    /** GET /api/v1/screenshots/{screenshot}/thumb and /image */
    public function show(Request $request, string $screenshot, string $kind): StreamedResponse|JsonResponse
    {
        $shot = Screenshot::find($screenshot);
        if ($shot === null) {
            return $this->error('NOT_FOUND', 'Not found.', 404);
        }
        if (! $this->access->canSee($request->user(), $shot->user_id, 'screenshots.view')) {
            return $this->error('FORBIDDEN', 'You cannot view this person\'s data.', 403);
        }

        $media = $shot->getFirstMedia(Screenshot::COLLECTION);
        if ($media === null) {
            return $this->error('NOT_FOUND', 'Not found.', 404);
        }

        // the thumbnail is made after the upload; until it exists (or if its job failed) the full picture stands in
        $conversion = $kind === 'thumb' && $media->hasGeneratedConversion('thumb') ? 'thumb' : '';
        $disk = $conversion !== '' ? ($media->conversions_disk ?? $media->disk) : $media->disk;
        $path = $media->getPathRelativeToRoot($conversion);

        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable $e) {
            report($e);

            return $this->error('STORAGE_UNAVAILABLE', 'The picture could not be read. Try again later.', 503);
        }
        if (! is_resource($stream)) {
            return $this->error('NOT_FOUND', 'Not found.', 404);
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** looking at someone else's day is written to the audit log, once per viewer, person and day within a window */
    private function recordView(User $viewer, int $targetId, string $day): void
    {
        // looking at your own pictures, or a superadmin looking inside an organization, is not logged
        if ($viewer->id === $targetId || $viewer->isSuperadmin()) {
            return;
        }

        $recent = AuditLog::where('actor_user_id', $viewer->id)
            ->where('target_user_id', $targetId)
            ->where('action', 'screenshots.viewed')
            ->where('created_at', '>=', now()->subMinutes(self::VIEW_AUDIT_WINDOW_MINUTES))
            ->get()
            ->contains(fn (AuditLog $entry) => ($entry->details['day'] ?? null) === $day);

        if (! $recent) {
            AuditLog::record($viewer, 'screenshots.viewed', User::find($targetId), ['day' => $day]);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
