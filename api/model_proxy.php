<?php
/**
 * api/model_proxy.php — Proxy caché para activos de wow.zamimg.com
 *
 * Acceso vía rewrite: GET /api/model/{path}
 *   ej: /api/model/modelviewer/live/viewer/viewer.min.js
 *       → https://wow.zamimg.com/modelviewer/live/viewer/viewer.min.js
 *
 * Los archivos se cachean en storage/model_cache/ para evitar
 * re-descargas en cada visita.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Sub-path enviado por el RewriteRule (?_path=...) ─────────
$subPath = trim($_GET['_path'] ?? '', '/ ');

if ($subPath === '' || !str_starts_with($subPath, 'modelviewer/')) {
    http_response_code(400);
    echo 'Bad Request: path must start with modelviewer/';
    exit;
}

// Sanitizar: eliminar ".." y caracteres peligrosos
$subPath = preg_replace('#\.\.+#', '', $subPath);
$subPath = preg_replace('#[^a-zA-Z0-9/_.\-]#', '', $subPath);
$subPath = ltrim($subPath, '/');

// ── Cache local ───────────────────────────────────────────────
require_once dirname(__DIR__) . '/config.php';

$cacheFile = STORAGE_PATH . '/model_cache/' . $subPath;
$cacheDir  = dirname($cacheFile);

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Content-Type por extensión
$ext  = strtolower(pathinfo($subPath, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'js'    => 'application/javascript; charset=utf-8',
    'json'  => 'application/json; charset=utf-8',
    'css'   => 'text/css; charset=utf-8',
    'png'   => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif'   => 'image/gif',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    default => 'application/octet-stream',
};

// ── Cache hit ─────────────────────────────────────────────────
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=604800');
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}

// ── Fetch desde wow.zamimg.com ────────────────────────────────
$url = 'https://wow.zamimg.com/' . $subPath;

$ctx = stream_context_create([
    'http' => [
        'method'          => 'GET',
        'timeout'         => 45,
        'follow_location' => 1,
        'max_redirects'   => 3,
        'header'          => implode("\r\n", [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: */*',
            'Accept-Encoding: identity',
            'Referer: https://www.wowhead.com/',
        ]),
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
]);

$body    = @file_get_contents($url, false, $ctx);
$httpCode = 200;
foreach ($http_response_header ?? [] as $line) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
        $httpCode = (int) $m[1];
    }
}

if ($body === false || $httpCode >= 400 || strlen($body) === 0) {
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    error_log("[model_proxy] Fallo al obtener: {$url}  (HTTP {$httpCode})");
    exit;
}

// Guardar en caché
@file_put_contents($cacheFile, $body);

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=604800');
header('X-Cache: MISS');
echo $body;
