<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Solo el equipo de la plataforma. 404 (no 403) para no revelar que el panel existe. */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_superadmin, 404);

        return $next($request);
    }
}
