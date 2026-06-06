<?php
/**
 * functions.php — Lógica de transferencia de personajes
 * Compatible: AzerothCore WotLK 3.3.5a — última revisión
 */

/**
 * Descifra el dump generado por el addon chardump.
 * - v2 (JSON): el addon cifra con base64(reverse(json))  → decrypt: base64_decode(strrev(x))
 * - v1 (SQL):  igual que antes → base64_decode(strrev(x))
 * Ambas versiones usan el mismo cifrado; el resultado difiere en formato.
 */
function decryptDump(string $encrypted): string
{
    return base64_decode(strrev(trim($encrypted)));
}

/**
 * Valida la estructura básica de un dump descifrado.
 * Soporta dos formatos:
 *   - JSON (chardump v2): { "version": 2, "basic": { "name": "..." } }
 *   - SQL  (chardump v1): INSERT INTO `characters` ...
 * Devuelve el nombre del personaje o false si el dump no es válido.
 */
function validateDump(string $dump): string|false
{
    if (empty($dump)) return false;

    // ── Formato JSON (chardump v2) ────────────────────────────
    if (CharacterImporter::isJsonDump($dump)) {
        $name = CharacterImporter::extractName($dump);
        return $name !== false ? $name : false;
    }

    // ── Formato SQL (chardump v1, legacy) ────────────────────
    if (!str_contains($dump, 'INSERT INTO')) return false;

    if (preg_match(
        "/INSERT\s+INTO\s+`?characters`?\s*\([^)]+\)\s*VALUES\s*\(\s*\d+\s*,\s*\d+\s*,\s*'([^']{2,16})'/i",
        $dump,
        $m
    )) {
        return $m[1];
    }
    return false;
}

/**
 * Extrae el GUID del personaje desde el dump.
 * Para JSON siempre devuelve 0 (el GUID lo asigna CharacterImporter al importar).
 * Para SQL intenta extraerlo del INSERT.
 */
function extractGuidFromDump(string $dump): int
{
    if (CharacterImporter::isJsonDump($dump)) {
        return 0; // GUID generado en el servidor, no en el addon
    }
    if (preg_match(
        "/INSERT\s+INTO\s+`?characters`?\s*\([^)]+\)\s*VALUES\s*\(\s*(\d+)\s*,/i",
        $dump,
        $m
    )) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * Extrae el nivel del personaje desde el dump.
 */
function extractLevelFromDump(string $dump): int
{
    if (CharacterImporter::isJsonDump($dump)) {
        return CharacterImporter::extractLevel($dump);
    }
    if (preg_match("/`level`\s*=\s*(\d+)/i", $dump, $m)) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * Aplica las conversiones de items definidas en ITEM_CONVERSIONS.
 * Sustituye todos los entry IDs de items bloqueados o convertibles en el dump.
 */
function applyItemConversions(string &$dump): void
{
    foreach (ITEM_CONVERSIONS as $from => $to) {
        $dump = str_replace("item_entry = {$from}", "item_entry = {$to}", $dump);
        $dump = str_replace("itemEntry={$from}",   "itemEntry={$to}",   $dump);
        // Sustitución en VALUES directos
        $dump = preg_replace(
            "/\b{$from}\b(?=\s*[,)])/",
            (string) $to,
            $dump
        ) ?? $dump;
    }
}

/**
 * Elimina del dump los items de la blacklist (BLOCKED_ITEMS).
 * En lugar de borrar líneas SQL complejas, marca el item_entry como 0
 * para que sea ignorado al importar.
 */
function removeBlockedItems(string &$dump): void
{
    foreach (BLOCKED_ITEMS as $entry) {
        // Marcar filas con ese item_entry como comentarios en el dump
        $dump = preg_replace(
            "/(INSERT\s+INTO\s+`?character_inventory`[^\n]*\b{$entry}\b[^\n]*)/i",
            '-- BLOCKED ITEM (entry=' . $entry . '): $1',
            $dump
        ) ?? $dump;
    }
}

/**
 * Valida que el nivel del personaje no supere el máximo permitido.
 */
function checkLevel(int $level): bool
{
    return $level > 0 && $level <= MAX_LEVEL;
}

/**
 * Valida el oro/cobres del personaje.
 */
function sanitizeCopper(int $copper): int
{
    return min($copper, MAX_COPPER);
}

/**
 * Limita el honor del personaje al máximo configurado.
 */
function sanitizeHonor(int $honor): int
{
    return min($honor, MAX_HONOR);
}

/**
 * Limita los arena points al máximo configurado.
 */
function sanitizeArenaPoints(int $points): int
{
    return min($points, MAX_ARENA_POINTS);
}

/**
 * Limita el conteo de un item entre 1 y 1000.
 */
function sanitizeItemCount(int $count): int
{
    return max(1, min(1000, $count));
}

/**
 * Comprueba el mínimo de logros.
 */
function checkAchievements(int $guid, int $realmId): bool
{
    if (!CHECK_ACHIEVEMENTS || MIN_ACHIEVEMENTS === 0) {
        return true;
    }
    try {
        $count = DB::chars($realmId)->count(
            'SELECT COUNT(*) FROM `character_achievement` WHERE `guid` = ?',
            [$guid]
        );
        return $count >= MIN_ACHIEVEMENTS;
    } catch (Throwable) {
        return true; // Si no se puede verificar, dejar pasar
    }
}

/**
 * Envía items a un jugador por correo en el juego via SOAP.
 * $items: array de [entry => count]
 */
function sendItemsByMail(
    int    $realmId,
    string $charName,
    array  $items,
    string $subject = 'Character Transfer'
): void {
    try {
        $soap = new Soap($realmId);
        foreach ($items as $entry => $count) {
            $count = sanitizeItemCount((int) $count);
            $soap->command(
                "send items {$charName} \"{$subject}\" \"{$subject}\" {$entry}:{$count}"
            );
        }
    } catch (Throwable $e) {
        error_log('[Migrador] Error SOAP enviando items: ' . $e->getMessage());
    }
}

/**
 * Resetea los talentos de un personaje vía SOAP.
 */
function resetTalents(int $realmId, string $charName): void
{
    try {
        (new Soap($realmId))->command("reset talents {$charName}");
    } catch (Throwable $e) {
        error_log("[Migrador] Error reseteando talentos de {$charName}: " . $e->getMessage());
    }
}

/**
 * Valida que el nombre de personaje sea válido para AzerothCore.
 * Solo letras (incluyendo caracteres europeos), 2-16 chars.
 */
function isValidCharName(string $name): bool
{
    return (bool) preg_match('/^[a-zA-ZÀ-ÿ]{2,16}$/u', $name);
}

/**
 * Devuelve los spells de rango que se deben aprender al importar una profesión.
 * Clave: skillId, valor: array de spell IDs por umbral de nivel.
 */
function getProfessionExtraSpells(int $skillId, int $skillValue): array
{
    // [skillId] => [umbral => spellId]
    $map = [
        164 => [75=>2018, 150=>3273, 225=>3274, 300=>7411, 375=>51300, 450=>51302], // Blacksmithing
        165 => [75=>2259, 150=>2330, 225=>3115, 300=>10662, 375=>51303, 450=>51304], // Leatherworking
        171 => [75=>2550, 150=>3928, 225=>3929, 300=>10659, 375=>51305, 450=>51306], // Alchemy
        182 => [75=>2575, 150=>2576, 225=>3564, 300=>10498, 375=>51309, 450=>51310], // Herbalism
        186 => [75=>2656, 150=>2657, 225=>3564, 300=>10499, 375=>51311, 450=>51312], // Mining
        197 => [75=>7411, 150=>7412, 225=>7413, 300=>7414, 375=>45357, 450=>45358],  // Tailoring
        202 => [75=>4036, 150=>4037, 225=>4038, 300=>4039, 375=>51300, 450=>51302],  // Engineering
        333 => [75=>13262, 150=>13920, 225=>13921, 300=>18249, 375=>51306, 450=>51304], // Enchanting
        393 => [75=>8613, 150=>8617, 225=>8618, 300=>10768, 375=>51309, 450=>51310], // Skinning
        755 => [75=>25229, 150=>25230, 225=>28597, 300=>28596, 375=>45363, 450=>45365], // Jewelcrafting
        773 => [75=>45363, 150=>45414, 225=>45365, 300=>45417, 375=>45363, 450=>45365], // Inscription
    ];

    if (!isset($map[$skillId])) {
        return [];
    }

    $spells = [];
    foreach ($map[$skillId] as $threshold => $spellId) {
        if ($skillValue >= $threshold) {
            $spells[] = $spellId;
        }
    }
    return $spells;
}

/**
 * Devuelve la traducción del nombre de una skill de montura (multi-idioma).
 * Necesario porque el addon chardump exporta el nombre en el idioma del cliente.
 */
function getRidingSkillName(int $locale): string
{
    return match ($locale) {
        1 => 'Equitation',       // fr
        2 => 'Reiten',           // de
        4 => 'Montura',          // es
        6 => 'Верховая езда',    // ru
        default => 'Riding',     // en (0)
    };
}
