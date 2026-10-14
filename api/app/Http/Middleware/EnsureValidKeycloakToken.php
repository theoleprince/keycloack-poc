<?php

namespace App\Http\Middleware;

use App\Auth\AuthException;
use App\Auth\Keycloak\KeycloakTokenValidator;
use App\Auth\Keycloak\KeycloakUserResolver;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chemin Keycloak seul (routes qui n'acceptent PAS Sanctum).
 * Valide le JWT, résout société + User local, initialise le TenantContext.
 *
 * Attributs de requête exposés : `auth_method` = keycloak, `keycloak_claims`.
 */
class EnsureValidKeycloakToken
{
    public function __construct(
        private KeycloakTokenValidator $validator,
        private KeycloakUserResolver $resolver,
        private TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            throw new AuthException(401, 'UNAUTHENTICATED', 'Authentification requise.');
        }

        $this->authenticate($request, $token);

        return $next($request);
    }

    public function authenticate(Request $request, string $token): void
    {
        $this->tenant->clear();

        $claims = $this->validator->validate($token);
        $user = $this->resolver->resolve($claims);

        $request->attributes->set('auth_method', 'keycloak');
        $request->attributes->set('keycloak_claims', $claims);
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);
    }
}
