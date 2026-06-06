<?php
/**
 * api/icon.php — Íconos de items desde ItemDisplayInfo.dbc local
 *
 * Flujo: entry → item_template.displayid → ItemDisplayInfo.dbc[field5] → iconName
 * Cachea: storage/icon_cache/<entry>.txt (por ítem) + _dbc_index.json (índice DBC completo)
 *
 * GET /api/icon.php?entry=XXXXX → nombre del ícono (ej: "inv_sword_04")
 *                                  o string vacío si no se encontró.
 *
 * Estructura ItemDisplayInfo.dbc (WotLK 3.3.5 — 25 campos, 100 bytes/record):
 *   field[0]  = ID
 *   field[1]  = ModelName[0]     (string ref → .mdx)
 *   field[2]  = ModelName[1]
 *   field[3]  = ModelTexture[0]  (string ref)
 *   field[4]  = ModelTexture[1]
 *   field[5]  = InventoryIcon[0] (string ref → icon name)  ← lo que usamos
 *   field[6]  = InventoryIcon[1]
 *   ...
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=2592000'); // 30 días en el navegador
header('Access-Control-Allow-Origin: *');

$entry = (int)($_GET['entry'] ?? 0);
if ($entry <= 0 || $entry > 200000) {
    http_response_code(400);
    exit;
}

require_once dirname(__DIR__) . '/config.php';

$cacheDir  = STORAGE_PATH . '/icon_cache/';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

$cacheFile = $cacheDir . $entry . '.txt';

// ── Cache hit por ítem ────────────────────────────────────────────
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
    echo trim(file_get_contents($cacheFile));
    exit;
}

// ── Buscar ícono ─────────────────────────────────────────────────
$icon = resolveIcon($entry, $cacheDir);

@file_put_contents($cacheFile, $icon);
echo $icon;

// ─────────────────────────────────────────────────────────────────

/**
 * Resuelve el nombre del ícono para un entry de item_template.
 * 1) Lee displayid desde acore_world.item_template
 * 2) Busca el ícono en el índice DBC (o lo construye)
 */
function resolveIcon(int $entry, string $cacheDir): string
{
    // 1. Obtener displayid desde la DB
    $displayId = 0;
    try {
        $row = DB::world()->row(
            'SELECT `displayid` FROM `item_template` WHERE `entry` = ? LIMIT 1',
            [$entry]
        );
        $displayId = $row ? (int)$row->displayid : 0;
    } catch (Throwable $e) {
        error_log('[icon.php] DB error: ' . $e->getMessage());
        return '';
    }

    if ($displayId <= 0) return '';

    // 2. Cargar índice DBC (displayid → iconName)
    $index = loadDBCIndex($cacheDir);
    return $index[$displayId] ?? '';
}

/**
 * Carga el índice DBC desde caché JSON.
 * Si no existe o está vacío, construye el índice parseando ItemDisplayInfo.dbc.
 *
 * @return array<int, string>  displayid → iconName
 */
function loadDBCIndex(string $cacheDir): array
{
    $indexFile = $cacheDir . '_dbc_index.json';

    // Caché válida: > 1 MB y < 30 días
    if (is_file($indexFile) && filesize($indexFile) > 1000 && (time() - filemtime($indexFile)) < 2592000) {
        $data = @json_decode(file_get_contents($indexFile), true);
        if (is_array($data) && count($data) > 1000) return $data;
    }

    // Construir índice desde DBC (ruta configurada en config.php → DBC_PATH)
    $dbcPath = DBC_PATH . '/ItemDisplayInfo.dbc';
    $index   = buildDBCIndex($dbcPath);

    if (!empty($index)) {
        @file_put_contents($indexFile, json_encode($index));
    }

    return $index;
}

/**
 * Parsea ItemDisplayInfo.dbc y construye un mapa displayid → iconName.
 * Solo extrae el campo del ícono (field[5]) para minimizar tiempo/memoria.
 */
function buildDBCIndex(string $dbcPath): array
{
    if (!is_file($dbcPath)) {
        error_log('[icon.php] DBC not found: ' . $dbcPath);
        return [];
    }

    $fh = fopen($dbcPath, 'rb');
    if (!$fh) return [];

    // Cabecera (20 bytes): magic(4) + recordCount(4) + fieldCount(4) + recordSize(4) + stringBlockSize(4)
    $rawHdr   = fread($fh, 20);
    $hdrVals  = array_values(unpack('V5', $rawHdr)); // 0-indexed tras array_values
    $records  = $hdrVals[1];
    $fields   = $hdrVals[2];
    $recSize  = $hdrVals[3];
    $strSize  = $hdrVals[4];

    // Sanity check
    if ($records <= 0 || $records > 200000 || $recSize !== $fields * 4) {
        fclose($fh);
        error_log("[icon.php] DBC header inválido: records={$records} fields={$fields} recSize={$recSize}");
        return [];
    }

    // Leer datos y bloque de strings
    $data     = fread($fh, $records * $recSize);
    $strBlock = fread($fh, $strSize);
    fclose($fh);

    // Offset del campo InventoryIcon[0] dentro de cada record:
    // field[5] → byte offset = 5 × 4 = 20
    $iconFieldOffset = 5 * 4; // = 20

    $index = [];
    for ($i = 0; $i < $records; $i++) {
        $recOff = $i * $recSize;

        // ID del record (field[0])
        $id = unpack('V', substr($data, $recOff, 4))[1];

        // String offset del ícono (field[5])
        $iconStrOff = unpack('V', substr($data, $recOff + $iconFieldOffset, 4))[1];

        if ($iconStrOff > 0 && $iconStrOff < $strSize) {
            $nullPos  = strpos($strBlock, "\0", $iconStrOff);
            $iconPath = substr($strBlock, $iconStrOff,
                ($nullPos !== false ? $nullPos : strlen($strBlock)) - $iconStrOff
            );

            if ($iconPath !== '') {
                // "Interface\Icons\INV_Sword_04" → "inv_sword_04"
                $iconName = strtolower(basename(str_replace('\\', '/', $iconPath)));
                if ($iconName !== '') {
                    $index[$id] = $iconName;
                }
            }
        }
    }

    return $index;
}
