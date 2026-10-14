<?php

/*
 * Mock du "serveur X" de découverte : clé société -> backend + infos Keycloak.
 * Aucun email, aucun OTP. Les clés ci-dessous sont des valeurs de DEV.
 * En production : table dédiée / service séparé, clés longues et aléatoires.
 */

$apiUrl = env('DISCOVERY_API_URL', 'http://10.0.2.2:8000');
$issuer = rtrim(env('KEYCLOAK_ISSUER', 'http://10.0.2.2:8080/realms/plateforme'), '/');
$clientId = env('KEYCLOAK_CLIENT_ID', 'projet-1');

return [
    'keys' => [
        'ACME-7K2P-DEV' => [
            'company_slug' => 'acme',
            'api_url' => $apiUrl,
            'keycloak_issuer' => $issuer,
            'client_id' => $clientId,
            'org_alias' => 'acme',
        ],
        'GLOBEX-4M9Q-DEV' => [
            'company_slug' => 'globex',
            'api_url' => $apiUrl,
            'keycloak_issuer' => $issuer,
            'client_id' => $clientId,
            'org_alias' => 'globex',
        ],
        'INITECH-8R3T-DEV' => [
            'company_slug' => 'initech',
            'api_url' => $apiUrl,
            'keycloak_issuer' => $issuer,
            'client_id' => $clientId,
            'org_alias' => 'initech',
        ],
    ],
];
