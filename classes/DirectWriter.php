<?php
/**
 * DirectWriter — Escribe un ImportBuffer con INSERT directos en la DB del realm.
 *
 * Es la via de respaldo, para cuando el worldserver esta apagado y no hay SOAP
 * con el que pedirle un `.pdump load`. Con el servidor encendido no es segura:
 * los GUID se reservaron leyendo MAX(guid) y el core reparte los suyos desde
 * memoria desde que arranco (ObjectMgr::SetHighestGuids).
 *
 * El buffer ya viene deduplicado por clave primaria, asi que aqui no hacen falta
 * INSERT IGNORE ni ON DUPLICATE KEY.
 */
class DirectWriter
{
    private PDO $pdo;

    public function __construct(PDO $charsPdo)
    {
        $this->pdo = $charsPdo;
    }

    /** Ejecuta todas las filas. Debe llamarse dentro de una transaccion. */
    public function write(ImportBuffer $buffer): void
    {
        foreach ($buffer->tables() as $table) {
            $rows = $buffer->rowsOf($table);
            if (empty($rows)) {
                continue;
            }

            // Agrupamos por juego de columnas para preparar una sola sentencia
            // por forma de fila, sin dar por hecho que todas son iguales.
            $groups = [];
            foreach ($rows as $row) {
                $groups[implode(',', array_keys($row))][] = $row;
            }

            foreach ($groups as $group) {
                $cols  = array_keys($group[0]);
                $names = implode(', ', array_map(fn($c) => '`' . $c . '`', $cols));
                $marks = implode(', ', array_fill(0, count($cols), '?'));
                $stmt  = $this->pdo->prepare(
                    "INSERT INTO `{$table}` ({$names}) VALUES ({$marks})"
                );
                foreach ($group as $row) {
                    $stmt->execute(array_values($row));
                }
            }
        }
    }
}
