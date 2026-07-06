<?php
/**
 * RateLimiter — Bloqueo temporal de intentos de login por IP.
 *
 * Independiente del `failed_logins` de `acore_auth.account` (ese lo usa el
 * propio cliente del juego) para no arriesgar efectos secundarios en el
 * login del juego solo por fallos en la web.
 */
class RateLimiter
{
    private const MAX_ATTEMPTS      = 5;
    private const WINDOW_MINUTES    = 15;
    private const LOCKOUT_MINUTES   = 15;

    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** ¿Esta IP está bloqueada ahora mismo? Si sí, devuelve minutos restantes; si no, null. */
    public static function isBlocked(): ?int
    {
        try {
            // El resto (NOW() vs. attempted_at + INTERVAL) se calcula por
            // completo dentro de MySQL: si se comparara con time()/strtotime()
            // de PHP y el reloj del sistema de MySQL no está en UTC (como
            // aquí, PHP fuerza UTC pero MySQL usa SYSTEM), el resultado queda
            // desfasado por la diferencia horaria y el bloqueo nunca dispara.
            $row = DB::auth()->row(
                'SELECT COUNT(*) AS `cnt`,
                        TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(`attempted_at`), INTERVAL ? MINUTE)) AS `remaining`
                   FROM `migrador_login_attempts`
                  WHERE `ip_address` = ? AND `attempted_at` > (NOW() - INTERVAL ? MINUTE)',
                [self::LOCKOUT_MINUTES, self::clientIp(), self::WINDOW_MINUTES]
            );
            if (!$row || (int) $row->cnt < self::MAX_ATTEMPTS) {
                return null;
            }
            $minutesLeft = (int) ceil(((int) $row->remaining) / 60);
            return $minutesLeft > 0 ? $minutesLeft : null;
        } catch (Throwable) {
            return null; // si la DB falla, no bloqueamos el login por esto
        }
    }

    /** Registra un intento fallido para esta IP. */
    public static function recordFailure(): void
    {
        try {
            DB::auth()->query(
                'INSERT INTO `migrador_login_attempts` (`ip_address`) VALUES (?)',
                [self::clientIp()]
            );
        } catch (Throwable) {
            // no crítico
        }
    }

    /** Limpia los intentos de esta IP (llamar tras un login exitoso). */
    public static function clear(): void
    {
        try {
            DB::auth()->query(
                'DELETE FROM `migrador_login_attempts` WHERE `ip_address` = ?',
                [self::clientIp()]
            );
        } catch (Throwable) {
            // no crítico
        }
    }
}
