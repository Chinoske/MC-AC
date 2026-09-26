<?php
/**
 * dbfunctions.php — Operaciones de base de datos para el sistema de transferencia
 * Todas las queries usan prepared statements (sin riesgo de SQL injection)
 * Compatible: AzerothCore WotLK 3.3.5a — última revisión
 */

/**
 * Comprueba si un personaje está online en un realm.
 */
function isCharacterOnline(int $guid, int $realmId): bool
{
    try {
        $row = DB::chars($realmId)->row(
            'SELECT `online` FROM `characters` WHERE `guid` = ? LIMIT 1',
            [$guid]
        );
        return $row !== null && (int) $row->online === 1;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Devuelve el nombre de un personaje por su GUID o null si no existe.
 */
function getCharacterName(int $guid, int $realmId): ?string
{
    try {
        return DB::chars($realmId)->row(
            'SELECT `name` FROM `characters` WHERE `guid` = ? LIMIT 1',
            [$guid]
        )?->name;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Comprueba si un nombre de personaje ya existe en el realm.
 */
function characterNameExists(string $name, int $realmId): bool
{
    try {
        return DB::chars($realmId)->count(
            'SELECT COUNT(*) FROM `characters` WHERE `name` = ?',
            [$name]
        ) > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Comprueba si una cuenta está en la blacklist de transferencias.
 */
function isAccountBlacklisted(int $accountId): bool
{
    try {
        return DB::auth()->count(
            'SELECT COUNT(*) FROM `account_transfer_blacklist` WHERE `account_id` = ?',
            [$accountId]
        ) > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Devuelve el estado de una transferencia o -1 si no existe.
 * 0=En progreso | 1=Aprobado | 2=Denegado | 3=Cancelado | 4=Reenviado
 */
function getTransferStatus(int $transferId): int
{
    try {
        $row = DB::auth()->row(
            'SELECT `status` FROM `account_transfer` WHERE `id` = ? LIMIT 1',
            [$transferId]
        );
        return $row ? (int) $row->status : -1;
    } catch (Throwable) {
        return -1;
    }
}

/**
 * Actualiza el estado de una transferencia.
 */
function updateTransferStatus(int $transferId, int $status, string $reason = ''): void
{
    $data = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
    if ($reason !== '') {
        $data['reason'] = substr($reason, 0, 255);
    }
    DB::auth()->update('account_transfer', $data, '`id` = ?', [$transferId]);
}

/**
 * Crea un registro de transferencia y devuelve el ID generado.
 */
function createTransferRecord(
    int    $accountId,
    string $charName,
    int    $charGuid,
    int    $realmId,
    string $dumpData,
    string $realmlist
): string {
    return DB::auth()->insert('account_transfer', [
        'cAccount'   => $accountId,
        'cName'      => $charName,
        'cGUID'      => $charGuid,
        'cRealmID'   => $realmId,
        'cRealmList' => $realmlist,
        'cDump'      => $dumpData,
        'status'     => 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * Obtiene las transferencias de una cuenta (últimas 25).
 * Los GMs ven todas las transferencias (últimas 50).
 */
function getAccountTransfers(int $accountId, bool $asGM = false): array
{
    try {
        if ($asGM) {
            return DB::auth()->rows(
                'SELECT * FROM `account_transfer` ORDER BY `id` DESC LIMIT 50'
            );
        }
        return DB::auth()->rows(
            'SELECT * FROM `account_transfer`
              WHERE `cAccount` = ?
           ORDER BY `id` DESC LIMIT 25',
            [$accountId]
        );
    } catch (Throwable) {
        return [];
    }
}

/**
 * Aplica el dump del personaje en el realm.
 * Solo acepta el formato JSON de chardump v2, que CharacterImporter valida
 * campo a campo.
 *
 * Con el worldserver encendido se le pasa un pdump por SOAP y el core hace el
 * import: reasigna los GUID con sus generadores, refresca el CharacterCache y
 * actualiza el contador de personajes. Con el servidor apagado no hay con quien
 * hablar, y entonces el INSERT directo es seguro porque nadie mas esta
 * repartiendo GUID.
 *
 * Devuelve el GUID del personaje importado (>0) o 0 en caso de error.
 */
function applyCharacterDump(int $realmId, string $dump, int $targetAccountId): int
{
    // El dump lo sube el jugador y su "cifrado" es un base64 invertido, asi que
    // es entrada no confiable. La ruta v1 ejecutaba su SQL con PDO::exec(), lo
    // que permitia cualquier statement (p.ej. un INSERT en account_access) con
    // un clic de GM como unico filtro.
    if (!CharacterImporter::isJsonDump($dump)) {
        error_log('[Migrador] Dump rechazado: solo se acepta el formato JSON de chardump v2.');
        return 0;
    }

    if (Soap::isOnline($realmId)) {
        $guid = importViaPdump($realmId, $dump, $targetAccountId);
        if ($guid > 0) {
            return $guid;
        }
        error_log('[Migrador] El import por pdump falló; no se cae al INSERT directo '
                . 'porque el worldserver está encendido y los GUID colisionarían.');
        return 0;
    }

    return importDirect($realmId, $dump, $targetAccountId);
}

/**
 * Import por `.pdump load`: genera el fichero, se lo pasa al worldserver por
 * SOAP y busca el GUID que le asignó el core.
 */
function importViaPdump(int $realmId, string $dump, int $targetAccountId): int
{
    $file = null;
    try {
        $importer = new CharacterImporter($realmId, $targetAccountId);
        $built    = $importer->buildPdump($dump);

        $file = writePdumpFile($built['pdump']);
        $out  = (new Soap($realmId))->pdumpLoad($file, $targetAccountId, $built['name']);

        // El comando no devuelve el GUID, asi que lo buscamos por nombre. El core
        // renombra el personaje si el nombre estaba cogido, y en ese caso no
        // tenemos forma fiable de identificarlo: mejor avisar que adivinar.
        $guid = (int) (getCharacterGuidByName($built['name'], $realmId) ?? 0);
        if ($guid <= 0) {
            error_log('[Migrador] .pdump load respondió "' . trim($out) . '" pero no aparece '
                    . 'ningún personaje llamado ' . $built['name']
                    . ' (¿nombre ya cogido y renombrado por el core?).');
            return 0;
        }
        return $guid;
    } catch (Throwable $e) {
        error_log('[Migrador] import por pdump falló: ' . $e->getMessage());
        return 0;
    } finally {
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }
}

/** Import por INSERT directo. Solo con el worldserver apagado. */
function importDirect(int $realmId, string $dump, int $targetAccountId): int
{
    try {
        $importer = new CharacterImporter($realmId, $targetAccountId);
        $result   = $importer->import($dump);
        return (int) $result['guid'];
    } catch (Throwable $e) {
        error_log('[Migrador] CharacterImporter error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Deja el pdump en disco y devuelve la ruta que hay que darle al worldserver.
 * PDUMP_PATH existe porque el fichero lo abre el worldserver, no la web: si no
 * comparten filesystem hay que apuntar a una ruta que los dos vean.
 */
function writePdumpFile(string $contents): string
{
    $dir = defined('PDUMP_PATH') && PDUMP_PATH !== '' ? PDUMP_PATH : STORAGE_PATH . '/pdump';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("No se pudo crear el directorio de pdumps: {$dir}");
    }

    $file = rtrim($dir, '/\\') . '/migrador_' . bin2hex(random_bytes(8)) . '.pdump';
    if (@file_put_contents($file, $contents) === false) {
        throw new RuntimeException("No se pudo escribir el pdump en {$file}");
    }
    return str_replace('\\', '/', $file);
}

/** GUID de un personaje por nombre, o null si no existe. */
function getCharacterGuidByName(string $name, int $realmId): ?int
{
    try {
        $row = DB::chars($realmId)->row(
            'SELECT `guid` FROM `characters` WHERE `name` = ? ORDER BY `guid` DESC LIMIT 1',
            [$name]
        );
        return $row ? (int) $row->guid : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Cancela/revierte una transferencia: borra los datos del personaje insertado.
 */
function cancelOrDenyTransfer(int $guid, int $realmId): void
{
    $tables = [
        'characters',
        'character_inventory',
        'character_skills',
        'character_spell',
        'character_achievement',
        'character_achievement_progress',
        'character_glyphs',
        'character_talent',
        'character_reputation',
        'character_queststatus',
        'character_queststatus_rewarded',
        'character_homebind',
        'character_aura',
        'character_pet',
    ];
    foreach ($tables as $table) {
        try {
            DB::chars($realmId)->query(
                "DELETE FROM `{$table}` WHERE `guid` = ?",
                [$guid]
            );
        } catch (Throwable) {
            // Tabla inexistente o registro ya borrado: continuar
        }
    }
}

/**
 * Devuelve el nombre de un realm por ID.
 */
function getRealmName(int $realmId): string
{
    return REALMS[$realmId]['name'] ?? "Realm #{$realmId}";
}

/**
 * Devuelve todos los realms como array para selectores.
 */
function getRealmList(): array
{
    return array_map(
        fn($id, $r) => ['id' => $id, 'name' => $r['name']],
        array_keys(REALMS),
        array_values(REALMS)
    );
}

