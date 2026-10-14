<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chemin legacy (Sanctum) : la société courante est celle de l'utilisateur.
 */
class SetTenantFromUser
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->company) {
            return response()->json(['error' => 'NO_COMPANY', 'message' => 'Aucune société associée.'], 403);
        }

        $this->tenant->clear();
        $this->tenant->set($user->company);

        return $next($request);
    }
}
