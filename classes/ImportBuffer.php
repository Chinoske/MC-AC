<?php
/**
 * ImportBuffer — Las filas que produce un import, antes de escribirlas.
 *
 * CharacterImporter llena este buffer y luego se escribe de una de dos formas:
 *
 *   - DirectWriter: INSERT directo en la DB del realm. Solo es seguro con el
 *     worldserver apagado, porque los GUID se reservan leyendo MAX(guid) y el
 *     core reparte los suyos desde memoria.
 *   - PdumpWriter: un fichero en el formato de `.pdump load`, que el propio core
 *     carga reasignando los GUID con sus generadores. Funciona con el servidor
 *     encendido.
 *
 * Las filas se guardan indexadas por su clave primaria, asi que volver a añadir
 * la misma clave sobreescribe en vez de duplicar. Eso sustituye a los
 * INSERT IGNORE y ON DUPLICATE KEY del import directo: el pdump no los admite
 * (el core ejecuta cada linea tal cual y un duplicado tumba la transaccion).
 */
class ImportBuffer
{
    /** @var array<string, array<string, array<string, mixed>>> tabla => pk => [columna => valor] */
    private array $rows = [];

    /** @var array<string, string[]> tabla => columnas que forman la pk */
    private const PRIMARY_KEYS = [
        'characters'            => ['guid'],
        'character_inventory'   => ['guid', 'bag', 'slot'],
        'item_instance'         => ['guid'],
        'character_skills'      => ['guid', 'skill'],
        'character_spell'       => ['guid', 'spell'],
        'character_talent'      => ['guid', 'spell'],
        'character_glyphs'      => ['guid', 'talentGroup'],
        'character_reputation'  => ['guid', 'faction'],
        'character_homebind'    => ['guid'],
        'character_action'      => ['guid', 'spec', 'button'],
        'character_queststatus' => ['guid', 'quest'],
        'mail'                  => ['id'],
        'mail_items'            => ['item_guid'],
    ];

    /**
     * El orden importa al cargar un pdump: mail antes de mail_items, y
     * item_instance despues de character_inventory y mail_items, porque el core
     * mapea los GUID en el orden en que los va viendo (PlayerDump.cpp).
     */
    public const TABLE_ORDER = [
        'characters',
        'character_skills',
        'character_spell',
        'character_talent',
        'character_glyphs',
        'character_reputation',
        'character_homebind',
        'character_action',
        'character_queststatus',
        'character_inventory',
        'mail',
        'mail_items',
        'item_instance',
    ];

    public function add(string $table, array $cols): void
    {
        $keyCols = self::PRIMARY_KEYS[$table]
            ?? throw new InvalidArgumentException("Tabla no soportada en el import: {$table}");

        $key = [];
        foreach ($keyCols as $c) {
            if (!array_key_exists($c, $cols)) {
                throw new InvalidArgumentException("Falta la columna `{$c}` de la pk de `{$table}`");
            }
            $key[] = (string) $cols[$c];
        }
        $this->rows[$table][implode(':', $key)] = $cols;
    }

    /** ¿Hay ya una fila con esa clave? */
    public function has(string $table, array $cols): bool
    {
        $keyCols = self::PRIMARY_KEYS[$table] ?? [];
        $key     = [];
        foreach ($keyCols as $c) {
            $key[] = (string) ($cols[$c] ?? '');
        }
        return isset($this->rows[$table][implode(':', $key)]);
    }

    /** Filas de una tabla, sin las claves. @return array<int, array<string, mixed>> */
    public function rowsOf(string $table): array
    {
        return array_values($this->rows[$table] ?? []);
    }

    /** Tablas con filas, en el orden en que hay que escribirlas. @return string[] */
    public function tables(): array
    {
        return array_values(array_filter(
            self::TABLE_ORDER,
            fn(string $t) => !empty($this->rows[$t])
        ));
    }

    public function count(string $table): int
    {
        return count($this->rows[$table] ?? []);
    }
}
