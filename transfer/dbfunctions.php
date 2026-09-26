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
 * Aplica el dump del personaje en la DB del realm.
 * Solo acepta el formato JSON de chardump v2, que CharacterImporter valida
 * campo a campo.
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

    try {
        $importer = new CharacterImporter($realmId, $targetAccountId);
        $result   = $importer->import($dump);
        refreshCharacterCache($realmId, $result['name']);
        return (int) $result['guid'];
    } catch (Throwable $e) {
        error_log('[Migrador] CharacterImporter error: ' . $e->getMessage());
        return 0;
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
 * Registra un personaje recien importado en el CharacterCache en memoria
 * del worldserver (GetCharacterCacheByGuid). Un INSERT directo en
 * `characters` nunca pasa por Player::Create() ni por el resto de los
 * puntos donde el core normalmente llama
 * sCharacterCache->AddCharacterCacheEntry() - sin esto, cualquier
 * SMSG_NAME_QUERY para ese GUID (chat, /who, nameplates, etc.) devuelve
 * "Unknown Entity" hasta que se reinicie el worldserver, aunque el
 * personaje cargue y juegue con normalidad.
 *
 * `.cache refresh` (cs_cache.cpp) es exactamente el comando GM que ya usa
 * AzerothCore para este mismo problema en su propia herramienta de
 * importación (.pdump load) - lo disparamos por SOAP en vez de duplicar
 * su lógica.
 */
function refreshCharacterCache(int $realmId, string $charName): void
{
    try {
        (new Soap($realmId))->command("cache refresh {$charName}");
    } catch (Throwable $e) {
        error_log("[Migrador] cache refresh falló para {$charName}: " . $e->getMessage());
    }
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

