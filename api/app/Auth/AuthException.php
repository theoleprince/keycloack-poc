<?php

namespace App\Auth;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Erreur d'authentification/autorisation rendue en JSON :
 * { "error": "<CODE>", "message": "<texte>" }.
 * Le message ne contient jamais de détail exploitable (pas de jeton, pas de
 * raison cryptographique précise).
 */
class AuthException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidToken(): self
    {
        return new self(401, 'INVALID_TOKEN', 'Jeton invalide ou expiré.');
    }

    public static function providerUnavailable(): self
    {
        return new self(503, 'AUTH_PROVIDER_UNAVAILABLE', 'Service d\'authentification indisponible.');
    }

    public static function forbidden(string $code, string $message): self
    {
        return new self(403, $code, $message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
}
