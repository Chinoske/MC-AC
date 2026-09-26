<?php
/**
 * PdumpParser — Réplica en PHP del parser de PlayerDump.cpp, para los tests.
 *
 * El formato de `.pdump load` es muy quisquilloso y no se puede comprobar a ojo:
 * el core salta 13 caracteres a pelo para leer el nombre de tabla, cuenta los
 * valores contando separadores "', '" y resuelve cada columna por su índice
 * posicional en un DESC de la tabla. Un fichero que "parece bien" puede hacer
 * que lea el valor de la columna de al lado.
 *
 * Esta clase implementa las mismas funciones (GetTableName, ValidateFields,
 * FindColumn) para poder afirmar en un test que el core aceptaría el fichero,
 * sin tener que levantar un worldserver.
 *
 * Referencia: src/server/game/Tools/PlayerDump.cpp
 */
class PdumpParser
{
    /** Tablas que acepta `.pdump load`, de DumpTables[] en PlayerDump.cpp. */
    public const KNOWN_TABLES = [
        'characters', 'character_account_data', 'character_achievement',
        'character_achievement_progress', 'character_action', 'character_aura',
        'character_declinedname', 'character_equipmentsets', 'character_glyphs',
        'character_homebind', 'character_inventory', 'character_pet',
        'character_pet_declinedname', 'character_queststatus',
        'character_queststatus_daily', 'character_queststatus_weekly',
        'character_queststatus_monthly', 'character_queststatus_seasonal',
        'character_queststatus_rewarded', 'character_reputation',
        'character_skills', 'character_spell', 'character_spell_cooldown',
        'character_talent', 'mail', 'mail_items', 'pet_aura', 'pet_spell',
        'pet_spell_cooldown', 'item_instance', 'character_gifts',
    ];

    /** Restricciones de orden que el propio PlayerDump.cpp documenta. */
    public const MUST_COME_AFTER = [
        'mail_items'    => ['mail'],
        'item_instance' => ['character_inventory', 'mail_items'],
        'character_gifts' => ['item_instance'],
    ];

    /** @var array<string, string[]> tabla => columnas en el orden del esquema */
    private array $schema;

    /** @param array<string, string[]> $schema */
    public function __construct(array $schema)
    {
        $this->schema = $schema;
    }

    /** Lee el esquema de las tablas que hagan falta desde la DB. */
    public static function fromPdo(PDO $pdo, array $tables): self
    {
        $schema = [];
        foreach ($tables as $t) {
            $cols = [];
            foreach ($pdo->query('DESC `' . $t . '`')->fetchAll(PDO::FETCH_OBJ) as $r) {
                $cols[] = (string) $r->Field;
            }
            $schema[$t] = $cols;
        }
        return new self($schema);
    }

    /** GetTableName: salta los 13 primeros caracteres y lee hasta el backtick. */
    public function tableName(string $line): string
    {
        $e = strpos($line, '`', 13);
        return $e === false ? '' : substr($line, 13, $e - 13);
    }

    /**
     * ValidateFields: todas las columnas nombradas tienen que existir en la
     * tabla. Devuelve la lista de columnas leídas.
     *
     * @return string[]
     * @throws RuntimeException si el core rechazaría la línea
     */
    public function validateFields(string $table, string $line): array
    {
        if (strpos($line, '` VALUES (') !== false) {
            return []; // formato viejo sin columnas: el core lo deja pasar
        }
        $s = strpos($line, '` (`');
        if ($s === false) {
            throw new RuntimeException('formato no reconocido');
        }
        $s += 4;

        $valPos = strpos($line, "VALUES ('");
        $e      = strpos($line, '`', $s);
        if ($e === false || $valPos === false) {
            throw new RuntimeException('fin de linea inesperado');
        }

        $known = $this->schema[$table] ?? throw new RuntimeException("tabla `{$table}` sin esquema");
        $cols  = [];
        do {
            $column = substr($line, $s, $e - $s);
            if (!in_array($column, $known, true)) {
                throw new RuntimeException("columna desconocida `{$column}` en `{$table}`");
            }
            $cols[] = $column;
            $s = $e + 4;   // "`, `"
            $e = strpos($line, '`', $s);
        } while ($e !== false && $e < $valPos);

        return $cols;
    }

    /**
     * FindColumn: localiza el valor de una columna por su índice posicional en
     * el esquema, igual que el core.
     *
     * @throws RuntimeException si no lo encuentra
     */
    public function column(string $table, string $line, string $column): string
    {
        $known = $this->schema[$table] ?? throw new RuntimeException("tabla `{$table}` sin esquema");
        $idx   = array_search($column, $known, true);
        if ($idx === false) {
            throw new RuntimeException("columna `{$column}` no existe en `{$table}`");
        }
        $columnIndex = $idx + 1;   // el core compensa el 0-based igual

        $s = strpos($line, "VALUES ('");
        if ($s === false) {
            throw new RuntimeException('no hay VALUES');
        }
        $s += 9;

        $e = self::closingQuote($line, $s);
        for ($i = 1; $i < $columnIndex; $i++) {
            $s = $e + 4;   // "', '"
            $e = self::closingQuote($line, $s);
        }
        return substr($line, $s, $e - $s);
    }

    /** Siguiente comilla simple sin escapar, como hace el bucle del core. */
    private static function closingQuote(string $line, int $from): int
    {
        $e = $from;
        do {
            $e = strpos($line, "'", $e);
            if ($e === false) {
                throw new RuntimeException('falta la comilla de cierre');
            }
            if ($line[$e - 1] !== '\\') {
                return $e;
            }
            $e++;
        } while (true);
    }

    /**
     * Comprueba el fichero completo como lo haría el core y devuelve las filas
     * ya interpretadas.
     *
     * @return array<int, array{table: string, values: array<string, string>}>
     * @throws RuntimeException en el primer problema
     */
    public function parse(string $pdump): array
    {
        $rows = [];
        $seen = [];
        $n    = 0;
        foreach (explode("\n", $pdump) as $line) {
            $n++;
            if (trim($line) === '') {
                continue;
            }

            if (!str_starts_with($line, 'INSERT INTO `')) {
                throw new RuntimeException("linea {$n}: no empieza por 'INSERT INTO `'");
            }
            if (!str_ends_with(rtrim($line), ');')) {
                throw new RuntimeException("linea {$n}: no termina en ');'");
            }

            $table = $this->tableName($line);
            if ($table === '') {
                throw new RuntimeException("linea {$n}: no se pudo extraer la tabla");
            }
            if (!in_array($table, self::KNOWN_TABLES, true)) {
                throw new RuntimeException("linea {$n}: `{$table}` no la carga .pdump load");
            }

            foreach (self::MUST_COME_AFTER[$table] ?? [] as $before) {
                if (isset($seen[$before]) && $seen[$before] > ($seen[$table] ?? PHP_INT_MAX)) {
                    throw new RuntimeException("linea {$n}: `{$table}` sale antes de `{$before}`");
                }
            }
            $seen[$table] ??= $n;

            $cols = $this->validateFields($table, $line);

            // El core resuelve por indice posicional, asi que la lista tiene que
            // ser el esquema entero y en su orden.
            if ($cols !== $this->schema[$table]) {
                throw new RuntimeException(
                    "linea {$n}: las columnas de `{$table}` no son el esquema completo en orden"
                );
            }

            $values = [];
            foreach ($cols as $c) {
                $values[$c] = $this->column($table, $line, $c);
            }
            $rows[] = ['table' => $table, 'values' => $values];
        }

        // el orden entre tablas se comprueba con la primera aparicion de cada una
        foreach (self::MUST_COME_AFTER as $table => $deps) {
            if (!isset($seen[$table])) {
                continue;
            }
            foreach ($deps as $dep) {
                if (isset($seen[$dep]) && $seen[$dep] > $seen[$table]) {
                    throw new RuntimeException("`{$table}` aparece antes de `{$dep}`");
                }
            }
        }

        return $rows;
    }
}
