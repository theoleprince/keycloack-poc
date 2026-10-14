<?php

namespace App\Auth\Keycloak;

use App\Auth\AuthException;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clés publiques de signature Keycloak (JWKS), mises en cache.
 *
 * - Cache de `jwks_cache_ttl` secondes (10 min par défaut).
 * - `kid` inconnu -> un rafraîchissement forcé, au plus une fois toutes les
 *   `jwks_min_refresh_interval` secondes (rotation de clés côté Keycloak).
 * - Keycloak injoignable ET aucun JWKS en cache -> AuthException 503
 *   (fail-closed : jamais de mode dégradé).
 */
class JwksProvider
{
    private const CACHE_KEY = 'keycloak:jwks';

    private const REFRESH_LOCK_KEY = 'keycloak:jwks:last-forced-refresh';

    public function __construct(private Cache $cache) {}

    /**
     * @return array<string, Key> clés RS256 de signature, indexées par kid
     */
    public function keys(): array
    {
        $jwks = $this->cache->get(self::CACHE_KEY);

        if (! is_array($jwks)) {
            $jwks = $this->fetchAndStore();
        }

        return $this->parse($jwks);
    }

    /**
     * Rafraîchit le JWKS si le `kid` est inconnu (rotation). Retourne null si
     * un rafraîchissement a déjà eu lieu trop récemment.
     *
     * @return array<string, Key>|null
     */
    public function refreshForUnknownKid(): ?array
    {
        if (! $this->cache->add(self::REFRESH_LOCK_KEY, time(), config('keycloak.jwks_min_refresh_interval'))) {
            return null;
        }

        return $this->parse($this->fetchAndStore());
    }

    private function fetchAndStore(): array
    {
        try {
            $response = Http::timeout(config('keycloak.http_timeout'))
                ->connectTimeout(config('keycloak.http_timeout'))
                ->acceptJson()
                ->get(config('keycloak.jwks_url'));
        } catch (Throwable $e) {
            Log::warning('JWKS Keycloak injoignable', ['exception' => $e::class]);

            throw AuthException::providerUnavailable();
        }

        $jwks = $response->json();

        if (! $response->successful() || ! is_array($jwks) || ! isset($jwks['keys']) || ! is_array($jwks['keys'])) {
            Log::warning('JWKS Keycloak invalide', ['status' => $response->status()]);

            throw AuthException::providerUnavailable();
        }

        $this->cache->put(self::CACHE_KEY, $jwks, config('keycloak.jwks_cache_ttl'));

        return $jwks;
    }

    /**
     * Ne garde que les clés RSA de signature RS256 (Keycloak publie aussi une
     * clé de chiffrement RSA-OAEP, à ignorer).
     *
     * @return array<string, Key>
     */
    private function parse(array $jwks): array
    {
        $signing = array_values(array_filter($jwks['keys'], fn ($k) => is_array($k)
            && ($k['kty'] ?? null) === 'RSA'
            && ($k['use'] ?? 'sig') === 'sig'
            && ($k['alg'] ?? 'RS256') === 'RS256'
            && isset($k['kid'])));

        if ($signing === []) {
            return [];
        }

        return JWK::parseKeySet(['keys' => $signing], 'RS256');
    }
}
