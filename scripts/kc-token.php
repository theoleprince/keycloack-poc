<?php
/**
 * Outil de DEV : obtient un vrai jeton Keycloak en jouant le flux navigateur
 * (Authorization Code + PKCE S256 + mot de passe + TOTP), sans émulateur.
 *
 * Usage :
 *   php scripts/kc-token.php <username> <password> [client_id=projet-1] [scope_extra="organization"]
 *
 * - Au premier login (action requise CONFIGURE_TOTP), le script enregistre
 *   l'OTP et stocke le secret TOTP dans scripts/.totp/<username>.json
 *   (fichier ignoré par git, DEV uniquement).
 * - Ensuite il génère le code TOTP à partir de ce secret.
 * - Si l'action UPDATE_PASSWORD est demandée, le nouveau mot de passe est lu
 *   dans la variable d'environnement KC_NEW_PASSWORD.
 *
 * Variables : KC_BASE (défaut http://localhost:8080), KC_REALM (plateforme).
 * Sortie : JSON de la réponse token sur stdout. Aucun mot de passe affiché.
 */

$username = $argv[1] ?? exit("usage: php kc-token.php <username> <password> [client_id] [scope_extra]\n");
$password = $argv[2] ?? exit("password manquant\n");
$clientId = $argv[3] ?? 'projet-1';
$scopeExtra = $argv[4] ?? 'organization';

$base = rtrim(getenv('KC_BASE') ?: 'http://localhost:8080', '/');
$realm = getenv('KC_REALM') ?: 'plateforme';
$redirectUri = 'com.example.pocapp://login-callback';
$totpDir = __DIR__.'/.totp';
@mkdir($totpDir, 0700, true);
$totpFile = "$totpDir/$username.json";

$cookieJar = tempnam(sys_get_temp_dir(), 'kc');

function b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Réécrit l'URL "frontend" (ex. http://10.0.2.2:8080) vers l'URL locale. */
function local(string $url, string $base): string
{
    return preg_replace('#^https?://[^/]+#', $base, html_entity_decode($url));
}

function http(string $method, string $url, string $jar, array $form = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        fwrite(STDERR, 'HTTP error: '.curl_error($ch)."\n");
        exit(2);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $hsize);
    $body = substr($resp, $hsize);
    $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : null;

    return [$status, $body, $location];
}

function formAction(string $html, string $formId): ?string
{
    if (preg_match('/<form[^>]*id="'.preg_quote($formId, '/').'"[^>]*action="([^"]+)"/s', $html, $m)
        || preg_match('/<form[^>]*action="([^"]+)"[^>]*id="'.preg_quote($formId, '/').'"/s', $html, $m)) {
        return $m[1];
    }

    return null;
}

function totp(string $key, int $window, int $digits = 6): string
{
    $hmac = hash_hmac('sha1', pack('N*', 0, $window), $key, true);
    $offset = ord($hmac[19]) & 0xF;
    $code = ((ord($hmac[$offset]) & 0x7F) << 24) | (ord($hmac[$offset + 1]) << 16) | (ord($hmac[$offset + 2]) << 8) | ord($hmac[$offset + 3]);

    return str_pad((string) ($code % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Évite la réutilisation d'un code dans la même fenêtre (refusée par Keycloak). */
function nextCode(string $key, string $totpFile): string
{
    $state = is_file($totpFile) ? json_decode(file_get_contents($totpFile), true) : [];
    $window = intdiv(time(), 30);
    if (($state['last_window'] ?? null) >= $window) {
        $wait = 30 - (time() % 30) + 1;
        fwrite(STDERR, "Attente de la prochaine fenêtre TOTP ({$wait}s)...\n");
        sleep($wait);
        $window = intdiv(time(), 30);
    }
    $state['last_window'] = $window;
    file_put_contents($totpFile, json_encode($state));

    return totp($key, $window);
}

// 1. Requête d'autorisation (PKCE S256)
$verifier = b64url(random_bytes(32));
$challenge = b64url(hash('sha256', $verifier, true));
$authUrl = "$base/realms/$realm/protocol/openid-connect/auth?".http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => trim("openid profile email offline_access $scopeExtra"),
    'state' => b64url(random_bytes(16)),
    'nonce' => b64url(random_bytes(16)),
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
]);

[$status, $html, $location] = http('GET', $authUrl, $cookieJar);
$code = null;

for ($step = 0; $step < 10 && $code === null; $step++) {
    if ($location !== null) {
        if (str_starts_with($location, $redirectUri)) {
            parse_str(parse_url($location, PHP_URL_QUERY) ?? '', $q);
            if (isset($q['error'])) {
                fwrite(STDERR, "Erreur OIDC: {$q['error']} ".($q['error_description'] ?? '')."\n");
                exit(3);
            }
            $code = $q['code'];
            break;
        }
        [$status, $html, $location] = http('GET', local($location, $base), $cookieJar);
        continue;
    }

    if ($action = formAction($html, 'kc-form-login')) {
        fwrite(STDERR, "-> formulaire mot de passe\n");
        [$status, $html, $location] = http('POST', local($action, $base), $cookieJar, [
            'username' => $username, 'password' => $password, 'credentialId' => '',
        ]);
        continue;
    }

    if ($action = formAction($html, 'kc-totp-settings-form')) {
        fwrite(STDERR, "-> configuration TOTP (première connexion)\n");
        preg_match('/name="totpSecret"[^>]*value="([^"]+)"/', $html, $m) || preg_match('/value="([^"]+)"[^>]*name="totpSecret"/', $html, $m);
        $rawSecret = html_entity_decode($m[1] ?? '');
        if ($rawSecret === '') {
            fwrite(STDERR, "Secret TOTP introuvable dans la page.\n");
            exit(4);
        }
        // Le secret affiché à l'utilisateur est base32(rawSecret) ; la clé HMAC est rawSecret.
        file_put_contents($totpFile, json_encode(['secret_raw_b64' => base64_encode($rawSecret)]));
        [$status, $html, $location] = http('POST', local($action, $base), $cookieJar, [
            'totp' => nextCode($rawSecret, $totpFile),
            'totpSecret' => $rawSecret,
            'userLabel' => 'kc-token.php',
            'mode' => '',
        ]);
        continue;
    }

    if ($action = formAction($html, 'kc-otp-login-form')) {
        fwrite(STDERR, "-> saisie du code TOTP\n");
        $state = is_file($totpFile) ? json_decode(file_get_contents($totpFile), true) : [];
        if (empty($state['secret_raw_b64'])) {
            fwrite(STDERR, "OTP déjà configuré hors de ce script : secret inconnu.\n");
            exit(5);
        }
        $key = base64_decode($state['secret_raw_b64']);
        [$status, $html, $location] = http('POST', local($action, $base), $cookieJar, ['otp' => nextCode($key, $totpFile)]);
        continue;
    }

    if ($action = formAction($html, 'kc-passwd-update-form')) {
        $new = getenv('KC_NEW_PASSWORD') ?: exit("Action UPDATE_PASSWORD : définir KC_NEW_PASSWORD\n");
        fwrite(STDERR, "-> mise à jour du mot de passe (action requise)\n");
        [$status, $html, $location] = http('POST', local($action, $base), $cookieJar, [
            'password-new' => $new, 'password-confirm' => $new,
        ]);
        continue;
    }

    if (preg_match('/<span[^>]*class="[^"]*(kc-feedback-text|pf-v5-c-alert__title)[^"]*"[^>]*>(.*?)<\/span>/s', $html, $m)
        || preg_match('/id="input-error[^"]*"[^>]*>(.*?)</s', $html, $m)) {
        fwrite(STDERR, 'Message Keycloak : '.trim(strip_tags(end($m)))."\n");
    }
    fwrite(STDERR, "Page inattendue (HTTP $status).\n");
    file_put_contents(sys_get_temp_dir().'/kc-last-page.html', $html);
    exit(6);
}

if ($code === null) {
    fwrite(STDERR, "Pas de code d'autorisation obtenu.\n");
    exit(7);
}

// 2. Échange du code (backchannel, localhost)
$ch = curl_init("$base/realms/$realm/protocol/openid-connect/token");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
    ]),
]);
$tokens = curl_exec($ch);
@unlink($cookieJar);
echo $tokens, "\n";
