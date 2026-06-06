<?php
/**
 * Migrador de Personajes — AzerothCore WotLK 3.3.5a
 * config.php — Configuración principal (edita solo esta sección)
 *
 * PHP 8.0+ | MySQL 8 / MariaDB 10.5+ | AzerothCore última revisión
 */

// ═══════════════════════════════════════════════════════════════
//  BASE DE DATOS AUTH
//  AzerothCore moderno usa "acore_auth" por defecto
// ═══════════════════════════════════════════════════════════════
define('DB_AUTH_HOST', '127.0.0.1');
define('DB_AUTH_PORT', 3306);
define('DB_AUTH_USER', 'acore');
define('DB_AUTH_PASS', 'acore');
define('DB_AUTH_NAME', 'acore_auth');

// ═══════════════════════════════════════════════════════════════
//  BASE DE DATOS WORLD (para lookup de items, displayId, etc.)
// ═══════════════════════════════════════════════════════════════
define('DB_WORLD_HOST', '127.0.0.1');
define('DB_WORLD_PORT', 3306);
define('DB_WORLD_USER', 'acore');
define('DB_WORLD_PASS', 'acore');
define('DB_WORLD_NAME', 'acore_world');

// ═══════════════════════════════════════════════════════════════
//  REALMS  (añade tantos como necesites, índice empieza en 1)
//  soap_uri: AzerothCore usa 'urn:AC'  (TrinityCore usaba 'urn:TC')
// ═══════════════════════════════════════════════════════════════
define('REALMS', [
    1 => [
        'name'      => 'AzerothCore',
        'db_host'   => '127.0.0.1',
        'db_port'   => 3306,
        'db_user'   => 'acore',
        'db_pass'   => 'acore',
        'db_name'   => 'acore_characters',
        'soap_host' => '127.0.0.1',
        'soap_port' => 7878,
        'soap_user' => 'admin',
        'soap_pass' => 'admin',
        'soap_uri'  => 'urn:AC',
    ],
    // 2 => [
    //     'name'      => 'Realm 2',
    //     'db_host'   => '127.0.0.1',
    //     'db_port'   => 3306,
    //     'db_user'   => 'acore',
    //     'db_pass'   => 'acore',
    //     'db_name'   => 'acore_characters_2',
    //     'soap_host' => '127.0.0.1',
    //     'soap_port' => 7879,
    //     'soap_user' => 'admin',
    //     'soap_pass' => 'admin',
    //     'soap_uri'  => 'urn:AC',
    // ],
]);

// ═══════════════════════════════════════════════════════════════
//  REGLAS DE TRANSFERENCIA
// ═══════════════════════════════════════════════════════════════
define('MAX_LEVEL',           80);          // Nivel máximo permitido
define('MAX_COPPER',  200_000_000);         // 200.000g en cobres
define('MAX_HONOR',       75_000);          // Honor máximo transferible
define('MAX_ARENA_POINTS',  5_000);         // Arena points máximo
define('MIN_ACHIEVEMENTS',     50);         // Mínimo de logros (0 = desactivado)
define('CHECK_ACHIEVEMENTS', true);         // Activar chequeo de logros

// ═══════════════════════════════════════════════════════════════
//  SEGURIDAD
// ═══════════════════════════════════════════════════════════════
define('CAPTCHA_ENABLED',  false);          // Captcha en login
define('TOKEN_NAME',   'csrf_token');       // Nombre del token CSRF en sesión
define('SESSION_LIFETIME', 604_800);        // 7 días en segundos

// ═══════════════════════════════════════════════════════════════
//  GM — niveles con permiso para aprobar/denegar transferencias
//  AzerothCore: 0=Jugador 1=Moderator 2=GM 3=Admin 4=Console
// ═══════════════════════════════════════════════════════════════
define('GM_MIN_LEVEL', 3);                  // Nivel mínimo para panel GM

// ═══════════════════════════════════════════════════════════════
//  IDIOMA POR DEFECTO
// ═══════════════════════════════════════════════════════════════
define('DEFAULT_LANG', 'es');               // es | en | fr | de | ru

// ═══════════════════════════════════════════════════════════════
//  ITEMS BLOQUEADOS EN TRANSFERENCIA
// ═══════════════════════════════════════════════════════════════
define('BLOCKED_ITEMS', [
    19019,  // Thunderfury, Blessed Blade of the Windseeker
    17182,  // Sulfuras, Hand of Ragnaros
    32837,  // Warglaive of Azzinoth (MH)
    32838,  // Warglaive of Azzinoth (OH)
    30480,  // Talisman of Binding Shard
    31088,  // Alex's Ring of Audacity
    36976,  // Martin Fury
    49895,  // Frostmourne
]);

// ═══════════════════════════════════════════════════════════════
//  CONVERSIÓN DE ITEMS (entry_origen => entry_destino)
// ═══════════════════════════════════════════════════════════════
define('ITEM_CONVERSIONS', [
    49623 => 49888,  // Shadowmourne → Shadow's Edge
]);

// ═══════════════════════════════════════════════════════════════
//  PATHS INTERNOS (no cambiar salvo que muevas carpetas)
// ═══════════════════════════════════════════════════════════════
define('ROOT_PATH',     __DIR__);
define('CLASSES_PATH',  ROOT_PATH . '/classes');
define('STORAGE_PATH',  ROOT_PATH . '/storage');
define('TRANSFER_PATH', ROOT_PATH . '/transfer');

// Autoloader PSR-style para la carpeta classes/
spl_autoload_register(function (string $class): void {
    $file = CLASSES_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Arrancar sesión una sola vez con opciones seguras
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isHttps,          // false en localhost HTTP (no bloquear cookie)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Nombre de cookie único para evitar conflictos con otras apps en localhost
    session_name('migrador_sess');
    session_start();
}
