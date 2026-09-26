<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// runs first on every request: whatever organization the previous request worked in is forgotten, so a
// request that has not signed in yet (login, password reset) never runs inside somebody's office.
class ResetOrganizationContext
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
