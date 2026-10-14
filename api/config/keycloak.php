<?php

/*
 * Configuration Keycloak — identique pour tous les projets : seule la valeur
 * des variables d'environnement change (KEYCLOAK_CLIENT_ID=projet-1|projet-2…).
 */

$issuer = rtrim(env('KEYCLOAK_ISSUER', 'http://10.0.2.2:8080/realms/plateforme'), '/');

return [
    // Valeur EXACTE attendue dans le claim `iss` (URL "frontend" de Keycloak).
    'issuer' => $issuer,

    // Client (projet) pour lequel le jeton doit avoir été émis : `aud` et `azp`.
    'client_id' => env('KEYCLOAK_CLIENT_ID', 'projet-1'),

    // URL "backchannel" du JWKS : peut différer de l'issuer (ex. Laravel
    // joint Keycloak via localhost alors que `iss` = http://10.0.2.2:8080/...).
    'jwks_url' => env('KEYCLOAK_JWKS_URL', $issuer.'/protocol/openid-connect/certs'),

    'jwks_cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 600),

    // Délai minimal entre deux rafraîchissements forcés (kid inconnu) :
    // empêche de marteler Keycloak avec des `kid` fantaisistes.
    'jwks_min_refresh_interval' => (int) env('KEYCLOAK_JWKS_MIN_REFRESH', 30),

    'http_timeout' => (float) env('KEYCLOAK_HTTP_TIMEOUT', 3),

    // Tolérance d'horloge (secondes) pour exp/nbf/iat. Garder petit + NTP.
    'leeway' => (int) env('KEYCLOAK_LEEWAY', 30),

    // Admin API (commande keycloak:import-users).
    'admin' => [
        'base_url' => rtrim(env('KEYCLOAK_ADMIN_BASE_URL', 'http://localhost:8080'), '/'),
        'realm' => env('KEYCLOAK_REALM', 'plateforme'),
        'client_id' => env('KEYCLOAK_IMPORTER_CLIENT_ID', 'laravel-importer'),
        'client_secret' => env('KEYCLOAK_IMPORTER_CLIENT_SECRET'),
        // Mot de passe TEMPORAIRE posé sur les comptes importés (UPDATE_PASSWORD
        // forcé au premier login). Vide = aucun mot de passe (envoi d'email
        // "execute-actions" nécessaire, non disponible dans ce POC).
        'import_temp_password' => env('KEYCLOAK_IMPORT_TEMP_PASSWORD'),
        // Valeur de l'attribut utilisateur `api_url` posée à l'import.
        'default_api_url' => env('KEYCLOAK_DEFAULT_API_URL', 'http://10.0.2.2:8000'),
    ],
];
