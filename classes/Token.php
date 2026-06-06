<?php
/**
 * Token — Protección CSRF
 * Genera un token por sesión (no de un solo uso) para evitar problemas
 * con recargas de página, preloading del navegador y múltiples tabs.
 * El token se invalida solo al hacer logout o al llamar a invalidate().
 */
class Token
{
    /** Genera (o reutiliza) el token CSRF de la sesión. */
    public static function generate(): string
    {
        // Reutilizar el token existente si ya hay uno en la sesión
        if (!empty($_SESSION[TOKEN_NAME])) {
            return $_SESSION[TOKEN_NAME];
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION[TOKEN_NAME] = $token;
        return $token;
    }

    /**
     * Valida el token enviado contra el guardado en sesión.
     * Usa hash_equals para resistir timing attacks.
     * NO elimina el token al validar (permite múltiples intentos de login).
     */
    public static function check(string $token): bool
    {
        if (empty($token) || empty($_SESSION[TOKEN_NAME])) {
            return false;
        }
        return hash_equals($_SESSION[TOKEN_NAME], $token);
    }

    /** Invalida el token (llamar tras login exitoso o logout). */
    public static function invalidate(): void
    {
        unset($_SESSION[TOKEN_NAME]);
    }
}
