<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;

// GET /api/v1/permissions and /api/v1/platform/permissions: the fixed lists of permissions with their plain-words
// labels, so the dashboard's role forms never keep their own copy of them.
class PermissionCatalogController extends Controller
{
    public function organization(): JsonResponse
    {
        return response()->json([
            'permissions' => $this->rows(Permissions::ORGANIZATION),
            'organizationWide' => Permissions::ORGANIZATION_WIDE,
            'scopes' => [
                ['key' => 'self', 'label' => 'Only themselves', 'description' => 'Their own data only.'],
                ['key' => 'team', 'label' => 'Their team', 'description' => 'Themselves and everyone who reports to them, at any depth.'],
                ['key' => 'organization', 'label' => 'The whole organization', 'description' => 'Everyone in the organization.'],
            ],
        ]);
    }

    public function platform(): JsonResponse
    {
        return response()->json(['permissions' => $this->rows(Permissions::SUPERADMIN)]);
    }

    /**
     * @param  array<string, array{group: string, label: string, description: string}>  $catalog
     * @return list<array<string, string>>
     */
    private function rows(array $catalog): array
    {
        $rows = [];
        foreach ($catalog as $key => $info) {
            $rows[] = ['key' => $key, ...$info];
        }

        return $rows;
    }
}
