<?php
/**
 * User — Autenticación compatible con AzerothCore WotLK 3.3.5a (SRP6)
 *
 * AzerothCore moderno (post-2021) usa SRP6:
 *   salt     = 32 bytes aleatorios (binary(32))
 *   verifier = g^x mod N  donde:
 *       x = SHA1(salt || SHA1(UPPER(user) || ':' || UPPER(pass)))
 *       N = 894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7
 *       g = 7
 *
 * La tabla `account_access` gestiona niveles GM:
 *   id=account_id, gmlevel, RealmID (-1 = todos los realms)
 */
class User
{
    // SRP6 — constantes de AzerothCore (igual que en AuthSession.cpp)
    private const SRP6_N = '894B645E89E1535BBDAD5B8B290650530801B18EBFBF5E8FAB3C82872A3E9BB7';
    private const SRP6_g = '7';

    private bool    $loggedIn = false;
    private ?object $data     = null;

    public function __construct(?int $userId = null)
    {
        if ($userId !== null) {
            $this->findById($userId);
        } elseif (isset($_SESSION['user_id'])) {
            $this->findById((int) $_SESSION['user_id']);
        }
    }

    /**
     * Intenta hacer login con usuario y contraseña.
     * Usa SRP6 para verificar — compatible con AzerothCore última revisión.
     */
    public function login(string $username, string $password): bool
    {
        try {
            // HEX() para leer columnas binary(32) sin problemas de encoding en PDO
            $row = DB::auth()->row(
                'SELECT `id`, `username`, `email`, `locked`,
                        HEX(`salt`)     AS `salt_hex`,
                        HEX(`verifier`) AS `verifier_hex`
                   FROM `account`
                  WHERE `username` = ?
                  LIMIT 1',
                [strtoupper($username)]
            );
        } catch (Throwable) {
            return false;
        }

        if (!$row) return false;
        if ((int) $row->locked === 1) return false;

        // Convertir hex→bin para pasar a SRP6
        $saltBin  = hex2bin(strtolower($row->salt_hex));
        $verifBin = hex2bin(strtolower($row->verifier_hex));

        // Verificar contraseña con SRP6
        if (!$this->verifySRP6($row->username, $password, $saltBin, $verifBin)) {
            return false;
        }

        $this->data     = $row;
        $this->loggedIn = true;

        $_SESSION['user_id']   = (int) $row->id;
        $_SESSION['user_name'] = $row->username;

        session_regenerate_id(true);
        return true;
    }

    /**
     * Verifica la contraseña usando SRP6.
     *
     * @param string $username  Nombre de cuenta (mayúsculas)
     * @param string $password  Contraseña en texto plano
     * @param string $saltBin   Campo `salt`     de la DB (binary(32) → string raw)
     * @param string $verifBin  Campo `verifier` de la DB (binary(32) → string raw)
     */
    private function verifySRP6(
        string $username,
        string $password,
        string $saltBin,
        string $verifBin
    ): bool {
        // h1 = SHA1(UPPER(username) || ':' || UPPER(password))  — raw bytes
        $h1 = sha1(strtoupper($username) . ':' . strtoupper($password), true);

        // x = SHA1(salt_LE_bytes || h1)  — raw SHA1 output bytes
        $xRaw = sha1($saltBin . $h1, true);

        // AzerothCore usa BN_lebin2bn: los bytes del SHA1 se interpretan
        // como little-endian al convertir a BigNumber → invertir antes de gmp_init
        $x = gmp_init(bin2hex(strrev($xRaw)), 16);

        // v = g^x mod N
        $N = gmp_init(self::SRP6_N, 16);
        $g = gmp_init(self::SRP6_g, 10);
        $vComputed = gmp_powm($g, $x, $N);

        // ToByteArray<32>() devuelve little-endian → strrev del hex big-endian de gmp
        $vHex   = str_pad(gmp_strval($vComputed, 16), 64, '0', STR_PAD_LEFT);
        $vBytes = strrev(hex2bin($vHex));

        // Comparar en tiempo constante
        return hash_equals($vBytes, $verifBin);
    }

    /** Cierra la sesión completamente */
    public function logout(): void
    {
        $this->loggedIn = false;
        $this->data     = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
    }

    /** Devuelve el nivel GM del usuario. 0 = jugador, 1+ = GM/Admin */
    public function gmLevel(): int
    {
        if (!$this->loggedIn || !$this->data) return 0;
        try {
            $row = DB::auth()->row(
                'SELECT MAX(`gmlevel`) AS `gmlevel`
                   FROM `account_access`
                  WHERE `id` = ? AND `RealmID` IN (-1, 1)',
                [(int) $this->data->id]
            );
            return $row ? (int) $row->gmlevel : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    public function isGM(): bool      { return $this->gmLevel() >= GM_MIN_LEVEL; }
    public function isLoggedIn(): bool { return $this->loggedIn; }
    public function data(): ?object   { return $this->data; }
    public function id(): int         { return (int) ($this->data?->id ?? 0); }
    public function name(): string    { return $this->data?->username ?? ''; }

    // ── Privado ──────────────────────────────────────────────────

    private function findById(int $id): void
    {
        try {
            $row = DB::auth()->row(
                'SELECT `id`, `username`, `email`, `locked`
                   FROM `account`
                  WHERE `id` = ? LIMIT 1',
                [$id]
            );
            if ($row && (int) $row->locked === 0) {
                $this->data     = $row;
                $this->loggedIn = true;
            }
        } catch (Throwable) {
            // DB no disponible: sesión inválida
        }
    }
}
