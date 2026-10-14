<?php
/**
 * Décode (SANS vérifier) le payload d'un JWT — outil de lecture/débogage.
 * Usage : php scripts/jwt-decode.php <jwt>   ou   ... | php scripts/jwt-decode.php
 * Accepte aussi la réponse JSON du endpoint token (prend access_token).
 */
$in = trim($argv[1] ?? stream_get_contents(STDIN));
if (str_starts_with($in, '{')) {
    $in = json_decode($in, true)['access_token'] ?? '';
}
[$h, $p] = array_pad(explode('.', $in), 2, '');
$dec = fn ($s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
echo json_encode(['header' => $dec($h), 'payload' => $dec($p)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
