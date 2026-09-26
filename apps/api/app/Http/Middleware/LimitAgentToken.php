<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

// The token the desktop app holds is made for the app only. Without this, whoever got hold of it (a copied
// credential store, a stolen laptop) could call every dashboard route with the rights of that person, and an
// administrator who signs in to the desktop app would hand over their whole administration. So a token that carries
// only the `agent` ability may reach just what the app itself calls; the dashboard signs in with a cookie, which is
// not affected.
class LimitAgentToken
{
    /** what the desktop app calls, as route URIs (docs/DEVELOPMENT_PLAN.md §10) */
    private const ALLOWED = [
        'GET' => ['api/v1/me', 'api/v1/employees/{id}/screenshots', 'api/v1/screenshots/{screenshot}/{kind}'],
        'POST' => ['api/v1/me/consent', 'api/v1/agent/sync', 'api/v1/agent/screenshots'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $this->isAgentOnly($token)) {
            $uri = $request->route()?->uri();
            if (! in_array($uri, self::ALLOWED[$request->method()] ?? [], true)) {
                return response()->json([
                    'error' => ['code' => 'FORBIDDEN', 'message' => 'This sign-in is for the desktop app only.'],
                ], 403);
            }
        }

        $this->keepAlive($token);

        return $next($request);
    }

    // A desktop token lasts `agent_token_days` from its last use, not from the sign-in: an app used every day never
    // asks for the password again, a PC nobody touches for that long does. Written at most once a day per PC.
    private function keepAlive(mixed $token): void
    {
        if (! $token instanceof PersonalAccessToken || ! $this->isAgentOnly($token)) {
            return;
        }

        $days = (int) config('sanctum.agent_token_days');
        if ($days > 0 && ($token->expires_at === null || $token->expires_at->lt(now()->addDays($days)->subDay()))) {
            $token->forceFill(['expires_at' => now()->addDays($days)])->save();
        }
    }

    private function isAgentOnly(PersonalAccessToken $token): bool
    {
        $abilities = $token->abilities ?? [];

        return in_array('agent', $abilities, true) && ! in_array('*', $abilities, true);
    }
}
