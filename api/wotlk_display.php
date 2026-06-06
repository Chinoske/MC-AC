<?php
/**
 * api/wotlk_display.php — Proxy caché para wotlk.murlocvillage.com/api/items
 *
 * Convierte un displayId de WotLK a su equivalente Retail para el visor 3D.
 * La librería wow-model-viewer llama: {WOTLK_API}/{entry}/{displayId}
 * Nosotros cacheamos la respuesta en storage/model_cache/display_ids/
 *
 * Acceso vía rewrite: GET /api/wotlk_display/{entry}/{displayId}
 *
 * Respuesta JSON esperada por el cliente:
 *   { "newDisplayId": 123456 }
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Extraer entry y displayId ─────────────────────────────────
// Soporta tres formas de llamada:
//   1. PATH_INFO:  /api/wotlk_display.php/123/456   → $_SERVER['PATH_INFO']
//   2. RewriteRule: ?_path=123/456                  → $_GET['_path']
//   3. Query string: ?entry=123&displayid=456        → $_GET['entry'] / ['displayid']
$pathInfo = trim($_SERVER['PATH_INFO'] ?? $_GET['_path'] ?? '', '/');
$parts    = array_values(array_filter(explode('/', $pathInfo)));

$entry     = (int)($parts[0] ?? 0);
$displayId = (int)($parts[1] ?? 0);

if ($entry <= 0 || $displayId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'entry and displayId are required', 'newDisplayId' => 0]);
    exit;
}

require_once dirname(__DIR__) . '/config.php';

// ── Cache local ───────────────────────────────────────────────
$cacheDir  = STORAGE_PATH . '/model_cache/display_ids/';
$cacheFile = $cacheDir . $entry . '_' . $displayId . '.json';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

if (is_file($cacheFile)) {
    header('Cache-Control: public, max-age=2592000');
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}

// ── Fetch desde murlocvillage ─────────────────────────────────
$url = "https://wotlk.murlocvillage.com/api/items/{$entry}/{$displayId}";
$ctx = stream_context_create([
    'http' => [
        'method'        => 'GET',
        'timeout'       => 8,
        'header'        => 'User-Agent: Migrador-WotLK/1.0',
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer'      => false,
        'verify_peer_name' => false,
    ],
]);

$body    = @file_get_contents($url, false, $ctx);
$httpCode = 200;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m)) {
        $httpCode = (int) $m[1];
    }
}

// ── Normalizar respuesta ──────────────────────────────────────
// murlocvillage puede responder: { data: { newDisplayId: X } }
// o simplemente: { newDisplayId: X }
// Normalizamos a: { newDisplayId: X }
$newId = $displayId; // fallback: usar el original

if ($body !== false && $httpCode < 400) {
    $data = json_decode($body, true);
    if (isset($data['data']['newDisplayId'])) {
        $newId = (int)$data['data']['newDisplayId'];
    } elseif (isset($data['newDisplayId'])) {
        $newId = (int)$data['newDisplayId'];
    }
}

$response = json_encode(['newDisplayId' => $newId]);

// Guardar en caché solo si hay un ID válido
if ($newId > 0) {
    @file_put_contents($cacheFile, $response);
}

header('Cache-Control: public, max-age=2592000');
header('X-Cache: MISS');
echo $response;
