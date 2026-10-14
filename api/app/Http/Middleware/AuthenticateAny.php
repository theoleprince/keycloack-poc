<?php

namespace App\Http\Middleware;

use App\Auth\AuthException;
use App\Auth\Keycloak\KeycloakTokenValidator;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `auth.any` — cohabitation pendant la migration.
 *
 * - Bearer = JWT dont `iss` (lu sans vérification, pour aiguiller seulement)
 *   est l'issuer Keycloak configuré -> chemin Keycloak (validation complète).
 * - Sinon -> chemin Sanctum (jeton opaque "id|secret").
 *
 * Dans les deux cas : même modèle User local, TenantContext initialisé
 * (depuis le jeton côté Keycloak, depuis users.company_id côté Sanctum).
 * Les contrôleurs métier ne voient aucune différence.
 *
 * À retirer après migration : remplacer par EnsureValidKeycloakToken.
 */
class AuthenticateAny
{
    public function __construct(
        private EnsureValidKeycloakToken $keycloak,
        private TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->clear();

        $token = $request->bearerToken();
        if (! $token) {
            throw new AuthException(401, 'UNAUTHENTICATED', 'Authentification requise.');
        }

        if (KeycloakTokenValidator::peekIssuer($token) === config('keycloak.issuer')) {
            $this->keycloak->authenticate($request, $token);

            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            throw new AuthException(401, 'UNAUTHENTICATED', 'Authentification requise.');
        }

        if (! $user->company) {
            throw AuthException::forbidden('NO_COMPANY', 'Aucune société associée à ce compte.');
        }

        $this->tenant->set($user->company);
        $request->attributes->set('auth_method', 'sanctum');
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        return $next($request);
    }
}
