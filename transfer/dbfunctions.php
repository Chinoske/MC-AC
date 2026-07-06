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
 * Devuelve el nivel GM de una cuenta (0 si no es GM).
 * account_access: id=account_id, gmlevel, RealmID (-1 = todos)
 * -1 cubre "todos los realms"; el resto son los realms realmente
 * configurados en REALMS (config.php), no solo el 1.
 */
function getGMLevel(int $accountId): int
{
    try {
        $realmIds     = array_merge([-1], array_keys(REALMS));
        $placeholders = implode(',', array_fill(0, count($realmIds), '?'));
        $row = DB::auth()->row(
            "SELECT MAX(`gmlevel`) AS `gmlevel`
               FROM `account_access`
              WHERE `id` = ? AND `RealmID` IN ({$placeholders})",
            [$accountId, ...$realmIds]
        );
        return $row ? (int) $row->gmlevel : 0;
    } catch (Throwable) {
        return 0;
    }
}

/**
 * ¿Tiene la cuenta acceso GM para aprobar transferencias?
 */
function hasGMAccess(int $accountId): bool
{
    return getGMLevel($accountId) >= GM_MIN_LEVEL;
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
 * - JSON (chardump v2): usa CharacterImporter para generar INSERTs seguros con nuevo GUID
 * - SQL  (chardump v1): ejecuta los statements directamente en transacción
 *
 * Devuelve el GUID del personaje importado (>0) o 0 en caso de error.
 */
function applyCharacterDump(int $realmId, string $dump, int $targetAccountId): int
{
    // ── Formato JSON — chardump v2 ────────────────────────────
    if (CharacterImporter::isJsonDump($dump)) {
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

    // ── Formato SQL — chardump v1 (legacy) ───────────────────
    try {
        $pdo = DB::chars($realmId)->getPdo();
        $pdo->beginTransaction();

        $stmts = array_filter(
            array_map('trim', explode(';', $dump)),
            fn($s) => !empty($s) && !str_starts_with(ltrim($s), '--')
        );

        foreach ($stmts as $sql) {
            if (!empty(trim($sql))) {
                $pdo->exec($sql);
            }
        }

        $pdo->commit();

        // Para SQL legacy, intentar extraer el GUID del dump
        return extractGuidFromDump($dump);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Migrador] Error aplicando dump SQL: ' . $e->getMessage());
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
 * Mueve el personaje a una cuenta GM temporalmente (durante revisión).
 */
function moveCharacterToGMAccount(int $guid, int $realmId, int $gmAccountId): void
{
    DB::chars($realmId)->update(
        'characters',
        ['account' => $gmAccountId],
        '`guid` = ?',
        [$guid]
    );
}

/**
 * Aprueba la transferencia: asigna el personaje a la cuenta real del jugador.
 */
function approveCharacterTransfer(int $guid, int $realmId, int $targetAccountId): void
{
    DB::chars($realmId)->update(
        'characters',
        ['account' => $targetAccountId],
        '`guid` = ?',
        [$guid]
    );
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

/**
 * Obtiene el account_id del usuario en sesión activa.
 */
function getSessionAccountId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}
