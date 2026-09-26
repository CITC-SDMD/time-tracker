<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Controller;
use App\Jobs\RebuildDaysJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Screenshot;
use App\Models\User;
use App\Services\OrganizationService;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// /api/v1/platform/organizations (docs/DEVELOPMENT_PLAN.md §9.4, §10): superadmins create, look at, rename and
// suspend organizations. Numbers only here (people, admins, storage): what is inside an organization is read
// through the "open office" routes, which need their own permission.
class OrganizationController extends Controller
{
    public function __construct(
        private OrganizationService $organizations,
        private OrganizationContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $people = User::withoutGlobalScopes()->whereNotNull('organization_id')
            ->selectRaw('organization_id, count(*) as total')->groupBy('organization_id')->pluck('total', 'organization_id');
        $storage = DB::table('media')
            ->join('screenshots', 'screenshots.id', '=', 'media.model_id')
            ->where('media.model_type', Screenshot::class)
            ->selectRaw('screenshots.organization_id as organization_id, sum(media.size) as bytes')
            ->groupBy('screenshots.organization_id')->pluck('bytes', 'organization_id');

        return response()->json(
            Organization::orderBy('name')->get()->map(fn (Organization $organization) => $this->payload(
                $organization, (int) ($people[$organization->id] ?? 0), (int) ($storage[$organization->id] ?? 0),
            ))->values(),
        );
    }

    /** GET /platform/organizations/{organization}: the middleware has already put the request inside it */
    public function show(): JsonResponse
    {
        return response()->json($this->detail($this->current()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
        ]);
        if (Organization::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->exists()) {
            return response()->json(['error' => ['code' => 'ORGANIZATION_NAME_TAKEN', 'message' => 'An organization with that name already exists.']], 409);
        }

        $organization = $this->organizations->create($data['name'], $data['timezone'] ?? 'Asia/Manila');
        AuditLog::recordPlatform($request->user(), 'organization.created', null, ['organization' => $organization->name]);

        return response()->json($this->detail($organization), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
        ]);
        $organization = $this->current();
        $caller = $request->user();

        if (isset($data['timezone'])) {
            $settings = OrganizationSetting::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
            if ($settings !== null && $settings->timezone !== $data['timezone']) {
                $from = $settings->timezone;
                $settings->timezone = $data['timezone'];
                $settings->save();
                RebuildDaysJob::dispatch($organization->id);
                AuditLog::recordPlatform($caller, 'organization.timezone_changed', null, ['from' => $from, 'to' => $data['timezone']]);
            }
        }

        if (isset($data['name']) && trim($data['name']) !== $organization->name) {
            if (Organization::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->whereKeyNot($organization->id)->exists()) {
                return response()->json(['error' => ['code' => 'ORGANIZATION_NAME_TAKEN', 'message' => 'An organization with that name already exists.']], 409);
            }
            $from = $organization->name;
            $organization->name = trim($data['name']);
            AuditLog::recordPlatform($caller, 'organization.renamed', null, ['from' => $from, 'to' => $organization->name]);
        }
        if (isset($data['status']) && $data['status'] !== $organization->status) {
            $organization->status = $data['status'];
            AuditLog::recordPlatform($caller, $data['status'] === 'suspended' ? 'organization.suspended' : 'organization.reactivated', null, ['organization' => $organization->name]);
        }
        $organization->save();

        return response()->json($this->detail($organization));
    }

    private function current(): Organization
    {
        return Organization::findOrFail($this->context->id());
    }

    /** @return array<string, mixed> */
    private function detail(Organization $organization): array
    {
        $people = User::withoutGlobalScopes()->where('organization_id', $organization->id)->count();
        $storage = AdminSettingsController::storageBytes($organization->id);

        $since = now()->subDays(7);
        $recent = DB::table('sessions')->where('organization_id', $organization->id)->where('received_at', '>=', $since);

        return [
            ...$this->payload($organization, $people, $storage),
            'activePeopleLast7Days' => (int) (clone $recent)->distinct()->count('user_id'),
            'lastActivityAt' => DB::table('sessions')->where('organization_id', $organization->id)->max('received_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Organization $organization, int $people, int $storageBytes): array
    {
        return [
            'id' => (string) $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'timezone' => OrganizationSetting::withoutGlobalScopes()->where('organization_id', $organization->id)->value('timezone'),
            'peopleCount' => $people,
            'adminCount' => User::withoutGlobalScopes()->where('organization_id', $organization->id)
                ->whereIn('role_id', fn ($q) => $q->select('id')->from('roles')->where('is_system', true))->count(),
            'storageBytes' => $storageBytes,
            'createdAt' => $organization->created_at?->toIso8601String(),
        ];
    }
}
