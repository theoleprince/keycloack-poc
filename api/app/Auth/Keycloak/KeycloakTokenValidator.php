<?php

namespace App\Auth\Keycloak;

use App\Auth\AuthException;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Validation stricte d'un access token Keycloak :
 * - signature RS256 UNIQUEMENT (alg none / HS256 / autres rejetés) ;
 * - exp obligatoire, nbf/iat contrôlés (tolérance `keycloak.leeway`) ;
 * - iss EXACT = keycloak.issuer ;
 * - aud contient keycloak.client_id ET azp = keycloak.client_id ;
 * - typ = Bearer (refus des ID tokens / refresh tokens).
 */
class KeycloakTokenValidator
{
    public function __construct(private JwksProvider $jwks) {}

    /**
     * Lecture NON vérifiée de `iss`, uniquement pour aiguiller entre
     * Keycloak et Sanctum. Ne jamais l'utiliser pour autoriser.
     */
    public static function peekIssuer(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(self::b64($parts[1]) ?: '', true);

        return is_array($payload) && is_string($payload['iss'] ?? null) ? $payload['iss'] : null;
    }

    public function validate(string $token): KeycloakClaims
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw AuthException::invalidToken();
        }

        $header = json_decode(self::b64($parts[0]) ?: '', true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw AuthException::invalidToken();
        }

        $keys = $this->jwks->keys();
        if (! isset($keys[$header['kid']])) {
            // Rotation de clés possible : un seul rafraîchissement, borné dans le temps.
            $keys = $this->jwks->refreshForUnknownKid() ?? $keys;
            if (! isset($keys[$header['kid']])) {
                throw AuthException::invalidToken();
            }
        }

        try {
            JWT::$leeway = config('keycloak.leeway');
            $payload = JWT::decode($token, [$header['kid'] => $keys[$header['kid']]]);
        } catch (Throwable) {
            throw AuthException::invalidToken();
        }

        $this->assertClaims($payload);

        return KeycloakClaims::fromPayload($payload);
    }

    private function assertClaims(object $p): void
    {
        $clientId = config('keycloak.client_id');
        $aud = isset($p->aud) ? (array) $p->aud : [];

        $ok = isset($p->exp) && is_numeric($p->exp)
            && isset($p->sub) && is_string($p->sub) && $p->sub !== ''
            && ($p->iss ?? null) === config('keycloak.issuer')
            && in_array($clientId, $aud, true)
            && ($p->azp ?? null) === $clientId
            && ($p->typ ?? 'Bearer') === 'Bearer';

        if (! $ok) {
            throw AuthException::invalidToken();
        }
    }

    private static function b64(string $segment): string|false
    {
        return base64_decode(strtr($segment, '-_', '+/'), true);
    }
}
