<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// after sign-in: from here on the request works inside one organization (see OrganizationContext). An
// organization person works inside their own. A superadmin works inside none, unless the URL names an
// organization (/platform/organizations/{organization}/...), which is how they open an office; what they may
// do there is then decided by their platform permissions (AccessService). The `organization` route parameter is
// consumed here so controllers do not receive it as an extra argument.
class SetOrganizationContext
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->isSuperadmin()) {
            $named = $request->route('organization');
            if ($named !== null) {
                $organization = Organization::find((int) $named);
                if ($organization === null) {
                    return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']], 404);
                }
                $this->context->set($organization->id);
                $request->route()->forgetParameter('organization');
            }
        } else {
            $this->context->set($user->organization_id);
        }

        return $next($request);
    }
}
