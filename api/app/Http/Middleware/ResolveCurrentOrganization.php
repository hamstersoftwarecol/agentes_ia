<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentOrganization
{
    public function __construct(private readonly CurrentOrganization $current) {}

    /**
     * Uso: `organization` (opcional) u `organization:required` (404 si la cuenta
     * todavía no tiene empresa). Debe ir después de la autenticación.
     */
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $organization = $request->user()?->resolveCurrentOrganization();

        $this->current->set($organization);

        if ($organization === null && $mode === 'required') {
            return response()->json(['message' => 'Aún no has creado tu empresa.'], 404);
        }

        return $next($request);
    }
}
