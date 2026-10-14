<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mock du "serveur X" : clé société -> URL du backend + infos Keycloak.
 * Pas d'email, pas d'OTP. Rate limit 10/min (voir routes).
 *
 * Anti-énumération : comparaison en temps constant sur TOUTES les clés et
 * réponse 404 strictement identique à n'importe quelle autre 404 de l'API.
 */
class DiscoveryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) $request->query('key', '');
        $found = null;

        foreach (config('discovery.keys', []) as $candidate => $entry) {
            // Pas de "break" : même nombre de comparaisons quelle que soit la clé.
            if (hash_equals((string) $candidate, $key) && $found === null) {
                $found = $entry;
            }
        }

        if ($found === null) {
            return response()->json(['error' => 'NOT_FOUND', 'message' => 'Ressource introuvable.'], 404);
        }

        return response()->json([
            'company_slug' => $found['company_slug'],
            'api_url' => $found['api_url'],
            'keycloak_issuer' => $found['keycloak_issuer'],
            'client_id' => $found['client_id'],
            'org_alias' => $found['org_alias'],
        ]);
    }
}
