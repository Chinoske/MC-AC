<?php
/**
 * api/precache_icons.php — Pre-cachea íconos de TODOS los items de item_template
 *
 * Uso CLI:  php precache_icons.php
 * Uso web:  GET /api/precache_icons.php          → procesa todos
 *           GET /api/precache_icons.php?reset=1  → borra caché y reprocesa
 *
 * Resultado: storage/icon_cache/<entry>.txt por cada item con ícono.
 * El índice DBC (_dbc_index.json) se reutiliza si ya existe.
 */

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    // Desde browser: output con flush para ver progreso en tiempo real
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    ob_implicit_flush(true);
    ob_end_flush();
}

$t0 = microtime(true);

require_once dirname(__DIR__) . '/config.php';

// ── Directorios ───────────────────────────────────────────────────
$cacheDir = STORAGE_PATH . '/icon_cache/';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

// ── Borrar caché si se pide reset ────────────────────────────────
if (!$isCli && isset($_GET['reset'])) {
    $deleted = 0;
    foreach (glob($cacheDir . '*.txt') as $f) {
        unlink($f);
        $deleted++;
    }
    out("Reset: $deleted archivos .txt eliminados.");
}

// ── 1. Cargar/construir índice DBC ───────────────────────────────
out("Cargando índice DBC…");
$dbcIndex = loadDBCIndex($cacheDir);
$dbcCount = count($dbcIndex);
out("  → $dbcCount entradas en ItemDisplayInfo.dbc");

if ($dbcCount === 0) {
    out("ERROR: No se pudo cargar el índice DBC. Verifica que exista server/dbc/ItemDisplayInfo.dbc");
    exit(1);
}

// ── 2. Leer todos los items de item_template ──────────────────────
out("Consultando item_template…");
try {
    $pdo = new PDO(
        "mysql:host=" . DB_WORLD_HOST . ";port=" . DB_WORLD_PORT
            . ";dbname=" . DB_WORLD_NAME . ";charset=utf8",
        DB_WORLD_USER, DB_WORLD_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query('SELECT `entry`, `displayid` FROM `item_template` WHERE `displayid` > 0 ORDER BY `entry`');
    $items = $stmt->fetchAll(PDO::FETCH_OBJ);
} catch (Throwable $e) {
    out("ERROR DB: " . $e->getMessage());
    exit(1);
}

$total   = count($items);
$written = 0;
$skipped = 0;
$noIcon  = 0;
out("  → $total items a procesar");

// ── 3. Cachear ────────────────────────────────────────────────────
out("Cacheando íconos…");
$dot = 0;
foreach ($items as $row) {
    $entry     = (int)$row->entry;
    $displayId = (int)$row->displayid;
    $cacheFile = $cacheDir . $entry . '.txt';

    // Skip si ya existe
    if (is_file($cacheFile)) {
        $skipped++;
        continue;
    }

    $iconName = $dbcIndex[$displayId] ?? '';

    if ($iconName !== '') {
        file_put_contents($cacheFile, $iconName);
        $written++;
    } else {
        $noIcon++;
        // Escribimos vacío para no reintentar en cada carga de página
        file_put_contents($cacheFile, '');
    }

    // Progreso cada 500 items
    $dot++;
    if ($dot % 500 === 0) {
        $pct  = round($dot / $total * 100);
        $secs = round(microtime(true) - $t0, 1);
        out("  {$dot}/{$total} ({$pct}%) — {$written} escritos, {$skipped} saltados — {$secs}s");
    }
}

// ── 4. Resumen ────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $t0, 2);
out("");
out("══════════════════════════════════");
out("COMPLETADO en {$elapsed}s");
out("  Total items:    {$total}");
out("  Con ícono:      {$written}");
out("  Sin ícono DBC:  {$noIcon}");
out("  Ya cacheados:   {$skipped}");
out("  Archivos en:    {$cacheDir}");
out("══════════════════════════════════");

// ─────────────────────────────────────────────────────────────────

function out(string $msg): void
{
    echo $msg . (PHP_SAPI === 'cli' ? PHP_EOL : "\n");
    if (PHP_SAPI !== 'cli') flush();
}

/**
 * Carga el índice DBC desde caché JSON, o lo construye si no existe.
 * @return array<int,string>  displayid → iconName
 */
function loadDBCIndex(string $cacheDir): array
{
    $indexFile = $cacheDir . '_dbc_index.json';

    if (is_file($indexFile) && filesize($indexFile) > 1000
        && (time() - filemtime($indexFile)) < 2592000)
    {
        $data = json_decode(file_get_contents($indexFile), true);
        if (is_array($data) && count($data) > 1000) return $data;
    }

    $dbcPath = DBC_PATH . '/ItemDisplayInfo.dbc';
    $index   = buildDBCIndex($dbcPath);

    if (!empty($index)) {
        file_put_contents($indexFile, json_encode($index));
    }
    return $index;
}

/**
 * Parsea ItemDisplayInfo.dbc → mapa displayid → iconName.
 * WotLK 3.3.5: 25 campos, 100 bytes/record, field[5] = InventoryIcon[0]
 */
function buildDBCIndex(string $dbcPath): array
{
    if (!is_file($dbcPath)) {
        out("  DBC no encontrado: $dbcPath");
        return [];
    }

    $fh  = fopen($dbcPath, 'rb');
    if (!$fh) return [];

    $hdrVals = array_values(unpack('V5', fread($fh, 20)));
    [$magic, $records, $fields, $recSize, $strSize] = $hdrVals;

    if ($records <= 0 || $records > 200000 || $recSize !== $fields * 4) {
        fclose($fh);
        return [];
    }

    $data     = fread($fh, $records * $recSize);
    $strBlock = fread($fh, $strSize);
    fclose($fh);

    $iconFieldOffset = 5 * 4; // field[5] = InventoryIcon[0]
    $index = [];

    for ($i = 0; $i < $records; $i++) {
        $recOff     = $i * $recSize;
        $id         = unpack('V', substr($data, $recOff, 4))[1];
        $iconStrOff = unpack('V', substr($data, $recOff + $iconFieldOffset, 4))[1];

        if ($iconStrOff > 0 && $iconStrOff < $strSize) {
            $nullPos  = strpos($strBlock, "\0", $iconStrOff);
            $iconPath = substr($strBlock, $iconStrOff,
                ($nullPos !== false ? $nullPos : strlen($strBlock)) - $iconStrOff);
            if ($iconPath !== '') {
                $name = strtolower(basename(str_replace('\\', '/', $iconPath)));
                if ($name !== '') $index[$id] = $name;
            }
        }
    }

    return $index;
}
