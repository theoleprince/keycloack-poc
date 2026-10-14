<?php

namespace App\Http\Middleware;

use App\Auth\AuthException;
use App\Auth\Keycloak\KeycloakClaims;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:admin` — rôle issu de :
 *  - realm_access.roles pour un jeton Keycloak ;
 *  - users.role pour un jeton Sanctum.
 *
 * Le rôle n'élargit JAMAIS le périmètre société : un admin reste cloisonné
 * à sa société par le TenantContext (pas de super-admin dans ce POC).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $claims = $request->attributes->get('keycloak_claims');

        $granted = $claims instanceof KeycloakClaims
            ? array_intersect($roles, $claims->roles) !== []
            : in_array($request->user()?->role, $roles, true);

        if (! $granted) {
            throw AuthException::forbidden('FORBIDDEN_ROLE', 'Droits insuffisants.');
        }

        return $next($request);
    }
}
