<?php
/**
 * functions.php — Lógica de transferencia de personajes
 * Compatible: AzerothCore WotLK 3.3.5a — última revisión
 */

/**
 * Decodifica el dump generado por el addon chardump.
 * El addon guarda strrev(base64(json)), asi que revertimos eso.
 * No es cifrado: cualquiera puede fabricar un dump, tratalo como entrada
 * no confiable.
 */
function decodeDump(string $encoded): string
{
    return base64_decode(strrev(trim($encoded)));
}

/**
 * Valida la estructura de un dump decodificado (chardump v2, JSON).
 * Devuelve el nombre del personaje o false si el dump no es valido.
 */
function validateDump(string $dump): string|false
{
    if ($dump === '' || !CharacterImporter::isJsonDump($dump)) {
        return false;
    }
    $name = CharacterImporter::extractName($dump);
    return $name !== false ? $name : false;
}

/**
 * Extrae el nivel del personaje desde el dump.
 */
function extractLevelFromDump(string $dump): int
{
    return CharacterImporter::extractLevel($dump);
}

/**
 * Valida que el nivel del personaje no supere el máximo permitido.
 */
function checkLevel(int $level): bool
{
    return $level > 0 && $level <= MAX_LEVEL;
}

/**
 * Limita el conteo de un item entre 1 y 1000.
 */
function sanitizeItemCount(int $count): int
{
    return max(1, min(1000, $count));
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
 * Valida que el nombre de personaje sea válido para AzerothCore.
 * Solo letras (incluyendo caracteres europeos), 2-12 chars: characters.name
 * es varchar(12) y un nombre mas largo se truncaba al insertar.
 */
function isValidCharName(string $name): bool
{
    return (bool) preg_match('/^[a-zA-ZÀ-ÿ]{2,12}$/u', $name);
}

