<?php
/**
 * PdumpWriter — Serializa un ImportBuffer al formato que lee `.pdump load`.
 *
 * El formato lo fija PlayerDump.cpp y es estricto:
 *
 *     INSERT INTO `tabla` (`col1`, `col2`) VALUES ('val1', 'val2');
 *
 *   - Una linea por fila, empezando exactamente en "INSERT INTO `" (GetTableName
 *     salta los 13 primeros caracteres a pelo).
 *   - Nombres de columna separados por "`, `".
 *   - TODOS los valores entre comillas simples, separados por "', '", incluidos
 *     los numeros. FindColumn cuenta posiciones contando esos separadores.
 *   - Un NULL se escribe como 'NULL' y el core lo destapa despues
 *     (FixNULLfields).
 *
 * Y sobre todo: **hay que emitir todas las columnas de la tabla, en el orden del
 * esquema**. El core resuelve cada columna por su indice posicional
 * (GetColumnIndexByName sobre un DESC de la tabla), asi que una lista parcial le
 * haria leer el valor equivocado.
 *
 * Los GUID de este fichero son locales y solo tienen que ser coherentes entre
 * si: PlayerDumpReader los reasigna con los generadores del core, que es
 * justamente el motivo de pasar por aqui.
 */
class PdumpWriter
{
    private PDO $pdo;

    /** Marca interna para "esta columna va a NULL". */
    private const NULL_MARKER = "\0null";

    /** @var array<string, array<int, array{name:string, default:string}>> tabla => columnas en orden */
    private array $schema = [];

    public function __construct(PDO $charsPdo)
    {
        $this->pdo = $charsPdo;
    }

    /** Genera el contenido del fichero pdump. */
    public function write(ImportBuffer $buffer): string
    {
        $out = [];
        foreach ($buffer->tables() as $table) {
            $cols = $this->columns($table);
            foreach ($buffer->rowsOf($table) as $row) {
                $out[] = $this->line($table, $cols, $row);
            }
        }
        return implode("\n", $out) . "\n";
    }

    private function line(string $table, array $cols, array $row): string
    {
        $names  = [];
        $values = [];
        foreach ($cols as $col) {
            $names[]  = '`' . $col['name'] . '`';
            $value    = array_key_exists($col['name'], $row) ? $row[$col['name']] : $col['default'];
            // el default de una columna nullable es un null de verdad
            if ($value === self::NULL_MARKER) {
                $value = null;
            }
            $values[] = "'" . $this->escape($value) . "'";
        }
        return 'INSERT INTO `' . $table . '` (' . implode(', ', $names)
             . ') VALUES (' . implode(', ', $values) . ');';
    }

    /**
     * Escapa el valor como CharacterDatabase.EscapeString (o sea
     * mysql_real_escape_string).
     *
     * Ojo con el NULL: el core lo escribe como 'NULL' y luego FixNULLfields lo
     * destapa a NULL de verdad. Al exportar, su writer manda 'NULL' tambien para
     * una cadena vacia, y eso rompe una columna NOT NULL como characters.taximask.
     * Aqui una cadena vacia se queda en '' y solo un null de PHP sale como NULL.
     */
    private function escape(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        // PDO::quote devuelve el valor con sus comillas: las quitamos porque
        // aqui las ponemos nosotros.
        return substr($this->pdo->quote((string) $value), 1, -1);
    }

    /**
     * Columnas de la tabla en el orden del esquema, con el valor a usar cuando el
     * import no dice nada de esa columna.
     *
     * @return array<int, array{name:string, default:string}>
     */
    private function columns(string $table): array
    {
        if (isset($this->schema[$table])) {
            return $this->schema[$table];
        }

        $cols = [];
        $rows = $this->pdo->query('DESC `' . str_replace('`', '', $table) . '`')
                          ->fetchAll(PDO::FETCH_OBJ);
        foreach ($rows as $r) {
            $cols[] = [
                'name'    => (string) $r->Field,
                'default' => self::defaultFor($r),
            ];
        }
        if (empty($cols)) {
            throw new RuntimeException("No se pudo leer el esquema de `{$table}`.");
        }
        return $this->schema[$table] = $cols;
    }

    /** Valor a escribir para una columna que el import deja sin tocar. */
    private static function defaultFor(object $desc): string
    {
        if ($desc->Default !== null) {
            return (string) $desc->Default;
        }
        if (strtoupper((string) $desc->Null) === 'YES') {
            return self::NULL_MARKER;
        }
        // NOT NULL y sin default: el 0 vale para los numericos y la cadena vacia
        // para el resto. Mejor eso que dejar que MySQL rechace la fila.
        return preg_match('/int|decimal|float|double|bit|year/i', (string) $desc->Type) ? '0' : '';
    }
}
