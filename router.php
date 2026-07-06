<?php
/**
 * router.php — Router para el servidor embebido de PHP (php -S).
 *
 * El servidor embebido NO lee `.htaccess` (eso es exclusivo de Apache), así
 * que los headers de seguridad y el bloqueo de carpetas sensibles definidos
 * ahí nunca se aplicaban en el uso real de esta herramienta (Iniciar.bat usa
 * `php -S`, no Apache). Este router hace lo mismo pero de forma que el
 * servidor embebido sí lo respeta.
 */

// ── Headers de seguridad (antes solo vivían en .htaccess) ─────────
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// ── Bloquear acceso directo a storage/ (chardumps, cachés, sesiones) ──
if (preg_match('#^/storage(/|$)#', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── Bloquear extensiones sensibles en cualquier ruta ───────────────
if (preg_match('#\.(sql|log|bak|env|ini)$#i', $uri)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── Proxies (antes reescritos por mod_rewrite en .htaccess) ────────
if (preg_match('#^/api/model/(.+)$#', $uri, $m)) {
    $_GET['_path'] = $m[1];
    require __DIR__ . '/api/model_proxy.php';
    return true;
}
if (preg_match('#^/api/wotlk_display/(.+)$#', $uri, $m)) {
    $_GET['_path'] = $m[1];
    require __DIR__ . '/api/wotlk_display.php';
    return true;
}

// Servir archivos estáticos existentes tal cual (CSS/JS/imágenes);
// cualquier otra cosa cae al script PHP pedido normalmente.
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file) && !str_ends_with($uri, '.php')) {
    return false;
}

return false;
