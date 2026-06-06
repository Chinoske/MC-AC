<?php
/**
 * DB — Wrapper PDO singleton con soporte multi-conexión
 * PHP 8.0+ | MySQL 8 / MariaDB 10.5+
 */
class DB
{
    /** @var array<string, self> */
    private static array $instances = [];
    private PDO $pdo;

    private function __construct(
        string $host,
        int    $port,
        string $user,
        string $pass,
        string $name
    ) {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /** Conexión a la base de datos Auth (acore_auth) */
    public static function auth(): self
    {
        if (!isset(self::$instances['auth'])) {
            self::$instances['auth'] = new self(
                DB_AUTH_HOST, DB_AUTH_PORT,
                DB_AUTH_USER, DB_AUTH_PASS,
                DB_AUTH_NAME
            );
        }
        return self::$instances['auth'];
    }

    /** Conexión a la base de datos World (item_template, etc.) */
    public static function world(): self
    {
        if (!isset(self::$instances['world'])) {
            self::$instances['world'] = new self(
                DB_WORLD_HOST, DB_WORLD_PORT,
                DB_WORLD_USER, DB_WORLD_PASS,
                DB_WORLD_NAME
            );
        }
        return self::$instances['world'];
    }

    /** Conexión a la base de datos de personajes de un realm */
    public static function chars(int $realmId = 1): self
    {
        $key = 'chars_' . $realmId;
        if (!isset(self::$instances[$key])) {
            $r = REALMS[$realmId]
                ?? throw new RuntimeException("Realm {$realmId} no configurado en config.php");
            self::$instances[$key] = new self(
                $r['db_host'], $r['db_port'],
                $r['db_user'], $r['db_pass'],
                $r['db_name']
            );
        }
        return self::$instances[$key];
    }

    /** Ejecuta una query preparada y devuelve el PDOStatement */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Devuelve un único registro como stdObject o null */
    public function row(string $sql, array $params = []): ?object
    {
        $result = $this->query($sql, $params)->fetch();
        return $result !== false ? $result : null;
    }

    /** Devuelve todos los registros como array de stdObject */
    public function rows(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** Cuenta filas (espera una query con COUNT(*)) */
    public function count(string $sql, array $params = []): int
    {
        $val = $this->query($sql, $params)->fetchColumn();
        return $val !== false ? (int) $val : 0;
    }

    /**
     * INSERT en una tabla.
     * $data: ['columna' => valor, ...]
     * Devuelve el lastInsertId como string.
     */
    public function insert(string $table, array $data): string
    {
        $cols   = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($data)));
        $places = implode(', ', array_fill(0, count($data), '?'));
        $this->query("INSERT INTO `{$table}` ({$cols}) VALUES ({$places})", array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * UPDATE con cláusula WHERE personalizada.
     * Devuelve el número de filas afectadas.
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set  = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($data)));
        $stmt = $this->query(
            "UPDATE `{$table}` SET {$set} WHERE {$where}",
            array_merge(array_values($data), $whereParams)
        );
        return $stmt->rowCount();
    }

    /** Expone el PDO nativo (para transacciones manuales) */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
