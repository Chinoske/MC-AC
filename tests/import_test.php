<?php
/**
 * import_test.php — Test de integracion de CharacterImporter contra MySQL.
 *
 * Crea dos bases de datos de prueba (mcac_test_auth / mcac_test_chars) copiando
 * el esquema de tu instalacion, importa los dumps de storage/ y comprueba el
 * resultado fila a fila. La base world se usa en solo lectura.
 *
 * Uso:
 *     php tests/import_test.php
 *     php tests/import_test.php --keep     (no borra las DBs al terminar)
 *
 * Lee la conexion de config.php, asi que no hay nada que configurar. No toca
 * tus bases reales: solo SELECT sobre world.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por consola.\n");
}

$keep = in_array('--keep', $argv, true);
require_once dirname(__DIR__) . '/config.php';
require_once TRANSFER_PATH . '/language.php';
require_once TRANSFER_PATH . '/functions.php';

const TEST_AUTH  = 'mcac_test_auth';
const TEST_CHARS = 'mcac_test_chars';

// Tablas que toca el importador, copiadas de tu instalacion real
const CHARS_TABLES = [
    'characters', 'character_inventory', 'item_instance', 'character_skills',
    'character_spell', 'character_talent', 'character_glyphs',
    'character_reputation', 'character_homebind', 'character_action',
    'character_queststatus', 'mail', 'mail_items',
];

// ── Assert ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function check(string $what, mixed $got, mixed $want): void
{
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-52s got=%s want=%s\n", $ok ? 'OK  ' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}
function note(string $s): void { echo "\n{$s}\n"; }

// ── Montaje ─────────────────────────────────────────────────────────
$realm = REALMS[array_key_first(REALMS)];
$admin = new PDO(
    "mysql:host={$realm['db_host']};port={$realm['db_port']};charset=utf8mb4",
    $realm['db_user'], $realm['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function teardown(PDO $admin): void
{
    $admin->exec('DROP DATABASE IF EXISTS `' . TEST_CHARS . '`');
    $admin->exec('DROP DATABASE IF EXISTS `' . TEST_AUTH . '`');
}

echo "Montando bases de prueba…\n";
teardown($admin);
$admin->exec('CREATE DATABASE `' . TEST_CHARS . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->exec('CREATE DATABASE `' . TEST_AUTH . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach (CHARS_TABLES as $t) {
    $admin->exec('CREATE TABLE `' . TEST_CHARS . "`.`{$t}` LIKE `{$realm['db_name']}`.`{$t}`");
}
$admin->exec('CREATE TABLE `' . TEST_AUTH . '`.`realmcharacters` LIKE `' . DB_AUTH_NAME . '`.`realmcharacters`');

// Redirigimos las conexiones del importador a las DBs de prueba
DB::overrideForTests(TEST_AUTH, TEST_CHARS);
$pdo = DB::chars(1)->getPdo();
$col = function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn();
};
$all = function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_OBJ);
};

$dumpFile = STORAGE_PATH . '/chardump_real_raw.json';
if (!is_file($dumpFile)) {
    teardown($admin);
    exit("Falta {$dumpFile}\n");
}
$dump = file_get_contents($dumpFile);
$src  = json_decode($dump, true);

try {

note('=== 1. Import del dump real ===');
$res  = (new CharacterImporter(1, 42))->import($dump);
$guid = (int) $res['guid'];
printf("  guid=%d  name=%s\n", $guid, $res['name']);
check('guid asignado', $guid > 0, true);

note('=== 2. Fila de characters ===');
$ch = $all('SELECT * FROM characters WHERE guid = ?', [$guid])[0] ?? null;
check('personaje existe', $ch !== null, true);
check('nombre', $ch->name, $src['basic']['name']);
check('cuenta', (int) $ch->account, 42);
check('nivel', (int) $ch->level, (int) $src['basic']['level']);
check('clase', (int) $ch->class, (int) $src['basic']['class']);
check('raza', (int) $ch->race, (int) $src['basic']['race']);
check('oro acotado a MAX_COPPER', (int) $ch->money, min((int) $src['basic']['copper'], MAX_COPPER));
check('honor acotado a MAX_HONOR', (int) $ch->totalHonorPoints, min((int) $src['basic']['honor'], MAX_HONOR));
check('arena acotado', (int) $ch->arenaPoints, min((int) $src['basic']['arena_pts'], MAX_ARENA_POINTS));
check('cinematic ya visto', (int) $ch->cinematic, 1);
check('exploredZones con 128 enteros', count(explode(' ', trim($ch->exploredZones))), 128);
check('knownTitles con 6 enteros', count(explode(' ', trim($ch->knownTitles))), 6);
check('equipmentCache con 23 pares',
    count(array_filter(explode(' ', trim($ch->equipmentCache)), fn($x) => $x !== '')), 46);

note('=== 3. Durabilidad ===');
check('ningun item con durabilidad 0 teniendo MaxDurability', (int) $col(
    'SELECT COUNT(*) FROM character_inventory ci
       JOIN item_instance ii ON ii.guid = ci.item
       JOIN `' . DB_WORLD_NAME . '`.item_template it ON it.entry = ii.itemEntry
      WHERE ci.guid = ? AND it.MaxDurability > 0 AND ii.durability = 0', [$guid]), 0);
check('hay items con durabilidad al maximo', (int) $col(
    'SELECT COUNT(*) FROM character_inventory ci
       JOIN item_instance ii ON ii.guid = ci.item
       JOIN `' . DB_WORLD_NAME . '`.item_template it ON it.entry = ii.itemEntry
      WHERE ci.guid = ? AND it.MaxDurability > 0
        AND ii.durability = it.MaxDurability', [$guid]) > 0, true);

note('=== 4. Posiciones del inventario ===');
$rows   = $all('SELECT ci.bag, ci.slot, ii.itemEntry FROM character_inventory ci
                  JOIN item_instance ii ON ii.guid = ci.item
                 WHERE ci.guid = ? ORDER BY ci.bag, ci.slot', [$guid]);
$bySlot = [];
foreach ($rows as $r) if ((int) $r->bag === 0) $bySlot[(int) $r->slot] = (int) $r->itemEntry;

$okEquip = true;
foreach ($src['equipped'] as $e) {
    $db = (int) $e['slot'] - 1;
    if ($db > 22) continue;
    if (($bySlot[$db] ?? 0) !== (int) $e['entry']) $okEquip = false;
}
check('cada item equipado en su slot DB', $okEquip, true);

$okBackpack = true;
foreach ($src['bags'] as $b) {
    if ((int) $b['bag'] !== 0) continue;
    if (($bySlot[23 + (int) $b['slot'] - 1] ?? 0) !== (int) $b['entry']) $okBackpack = false;
}
check('items de mochila en su slot original', $okBackpack, true);

$okBank = true;
foreach ($src['bank'] as $b) {
    $s = (int) $b['slot'];
    if ((int) $b['bag'] !== -1 || $s < 39 || $s > 66) continue;
    if (($bySlot[$s] ?? 0) !== (int) $b['entry']) $okBank = false;
}
check('items de banco en su slot original', $okBank, true);
check('ningun slot duplicado', count($rows),
    count(array_unique(array_map(fn($r) => $r->bag . ':' . $r->slot, $rows))));
check('4 bolsas equipadas', (int) $col(
    'SELECT COUNT(*) FROM character_inventory
      WHERE guid = ? AND bag = 0 AND slot BETWEEN 19 AND 22', [$guid]), 4);

note('=== 5. Gemas y encantamientos (Item.h: PERM=0, TEMP=1, SOCK=2..4) ===');
$gem = $src;
$gem['basic']['name'] = 'Gemas';
$gem['bags'] = $gem['bank'] = [];
$gem['equipped'] = [[
    'slot' => 1, 'entry' => (int) $src['equipped'][0]['entry'], 'count' => 1,
    'ench' => 3820, 'gem1' => 3521, 'gem2' => 3522, 'gem3' => 3523,
]];
$rg = (new CharacterImporter(1, 99))->import(json_encode($gem));
$sl = explode(' ', trim((string) $col(
    'SELECT ii.enchantments FROM character_inventory ci
       JOIN item_instance ii ON ii.guid = ci.item
      WHERE ci.guid = ? AND ci.slot = 0', [(int) $rg['guid']])));
check('18 slots x 3 valores', count($sl), 54);
check('slot 0 = encantamiento', (int) $sl[0], 3820);
check('slot 1 (temporal) vacio', (int) $sl[3], 0);
check('slot 2 = gema 1', (int) $sl[6], 3521);
check('slot 3 = gema 2', (int) $sl[9], 3522);
check('slot 4 = gema 3', (int) $sl[12], 3523);

note('=== 6. Skills, spells, talentos, glifos, reputaciones, barra ===');
check('spells', (int) $col('SELECT COUNT(*) FROM character_spell WHERE guid = ?', [$guid]),
    count($src['spells']));
check('talentos', (int) $col('SELECT COUNT(*) FROM character_talent WHERE guid = ?', [$guid]) > 0, true);
check('reputaciones', (int) $col('SELECT COUNT(*) FROM character_reputation WHERE guid = ?', [$guid]),
    count($src['reputations']));
check('glifos', (int) $col('SELECT COUNT(*) FROM character_glyphs WHERE guid = ?', [$guid]), 1);
check('homebind', (int) $col('SELECT COUNT(*) FROM character_homebind WHERE guid = ?', [$guid]), 1);
check('skills subidas a 400',
    (int) $col('SELECT COUNT(*) FROM character_skills
                 WHERE guid = ? AND value = 400 AND max = 400', [$guid]) > 0, true);
check('barra de acciones rellenada',
    (int) $col('SELECT COUNT(*) FROM character_action WHERE guid = ?', [$guid]) > 0, true);

note('=== 7. Quests (QuestDef.h: COMPLETE=1, INCOMPLETE=3) ===');
$byId = [];
foreach ($all('SELECT quest, status FROM character_queststatus WHERE guid = ?', [$guid]) as $r) {
    $byId[(int) $r->quest] = (int) $r->status;
}
printf("  en el dump: %d, importadas: %d (las que no existen en quest_template se omiten)\n",
    count($src['quests']), count($byId));
foreach ($src['quests'] as $q) {
    $id = (int) $q['id'];
    if (!isset($byId[$id])) continue;
    check("quest {$id}", $byId[$id], !empty($q['complete']) ? 1 : 3);
}

note('=== 8. realmcharacters ===');
check('numchars', (int) DB::auth()->count(
    'SELECT numchars FROM realmcharacters WHERE realmid = 1 AND acctid = 42'), 1);

note('=== 9. Reparto con el inventario desbordado ===');
$big = $src;
$big['basic']['name'] = 'Excedente';
$big['bags'] = [];
for ($i = 1; $i <= 200; $i++) {
    $big['bags'][] = ['bag' => 0, 'slot' => $i, 'entry' => 6948, 'count' => 1];
}
$r2 = (new CharacterImporter(1, 43))->import(json_encode($big));
$g2 = (int) $r2['guid'];

$inBackpack = (int) $col('SELECT COUNT(*) FROM character_inventory
                           WHERE guid = ? AND bag = 0 AND slot BETWEEN 23 AND 38', [$g2]);
$inBags     = (int) $col('SELECT COUNT(*) FROM character_inventory WHERE guid = ? AND bag <> 0', [$g2]);
$mailed     = (int) $col('SELECT COUNT(*) FROM mail_items WHERE receiver = ?', [$g2]);
printf("  200 items -> mochila %d, dentro de bolsas %d, por correo %d\n", $inBackpack, $inBags, $mailed);
check('mochila llena', $inBackpack, 16);
check('las bolsas se aprovechan', $inBags > 0, true);
check('nada se pierde', $inBackpack + $inBags + $mailed, 200);
check('bag apunta al guid de una bolsa equipada', (int) $col(
    'SELECT COUNT(*) FROM character_inventory ci
      WHERE ci.guid = ? AND ci.bag <> 0
        AND ci.bag NOT IN (SELECT item FROM character_inventory
                            WHERE guid = ci.guid AND bag = 0 AND slot BETWEEN 19 AND 22)', [$g2]), 0);

note('=== 10. Correos: mail.id no es AUTO_INCREMENT ===');
$big['basic']['name'] = 'Excedentedos';
$r3 = (new CharacterImporter(1, 44))->import(json_encode($big));
check('un segundo import con correo no choca', (int) $r3['guid'] > 0, true);
$mails = $all('SELECT id FROM mail ORDER BY id');
printf("  correos creados: %d\n", count($mails));
check('ids distintos', count($mails), count(array_unique(array_map(fn($m) => (int) $m->id, $mails))));
check('ninguno con id 0', (int) $col('SELECT COUNT(*) FROM mail WHERE id = 0'), 0);
check('todos marcados con adjuntos', (int) $col('SELECT COUNT(*) FROM mail WHERE has_items = 0'), 0);
check('ninguno pasa de 12 adjuntos (MAX_MAIL_ITEMS)', (int) $col(
    'SELECT COALESCE(MAX(c), 0) FROM (SELECT COUNT(*) AS c FROM mail_items GROUP BY mail_id) t') <= 12, true);
check('mail_items sin correo huerfano', (int) $col(
    'SELECT COUNT(*) FROM mail_items mi LEFT JOIN mail m ON m.id = mi.mail_id
      WHERE m.id IS NULL'), 0);

note('=== 11. Nombre repetido: characters.name no es UNIQUE ===');
try {
    (new CharacterImporter(1, 45))->import($dump);
    check('el import se rechaza', 'no lanzo', 'RuntimeException');
} catch (RuntimeException $e) {
    check('el import se rechaza', str_contains($e->getMessage(), 'ya está en uso'), true);
}
check('sigue habiendo un solo personaje con ese nombre',
    (int) $col('SELECT COUNT(*) FROM characters WHERE name = ?', [$src['basic']['name']]), 1);

note('=== 12. Blacklist y conversiones de item ===');
$blk = $src;
$blk['basic']['name'] = 'Bloqueados';
$blk['bank'] = [];
$blocked = BLOCKED_ITEMS[0];
$from    = array_key_first(ITEM_CONVERSIONS);
$blk['bags'] = [
    ['bag' => 0, 'slot' => 1, 'entry' => $blocked, 'count' => 1],
    ['bag' => 0, 'slot' => 2, 'entry' => $from,    'count' => 1],
];
$g4 = (int) (new CharacterImporter(1, 46))->import(json_encode($blk))['guid'];
check("item bloqueado ({$blocked}) no entra", (int) $col(
    'SELECT COUNT(*) FROM character_inventory ci JOIN item_instance ii ON ii.guid = ci.item
      WHERE ci.guid = ? AND ii.itemEntry = ?', [$g4, $blocked]), 0);
check("conversion {$from} -> " . ITEM_CONVERSIONS[$from], (int) $col(
    'SELECT COUNT(*) FROM character_inventory ci JOIN item_instance ii ON ii.guid = ci.item
      WHERE ci.guid = ? AND ii.itemEntry = ?', [$g4, ITEM_CONVERSIONS[$from]]), 1);
check('el oro no lo toca la conversion', (int) $col('SELECT money FROM characters WHERE guid = ?', [$g4]),
    min((int) $blk['basic']['copper'], MAX_COPPER));

note('=== 13. Rollback de un dump invalido ===');
$before = (int) $col('SELECT COUNT(*) FROM characters');
$bad = $src;
$bad['basic']['name']  = 'Rollback';
$bad['basic']['level'] = MAX_LEVEL + 100;
try { (new CharacterImporter(1, 47))->import(json_encode($bad)); } catch (Throwable) {}
check('no queda el personaje', (int) $col('SELECT COUNT(*) FROM characters'), $before);

note('=== 14. Integridad general ===');
check('guids de personaje unicos', (int) $col('SELECT COUNT(*) FROM characters'),
    (int) $col('SELECT COUNT(DISTINCT guid) FROM characters'));
check('guids de item unicos', (int) $col('SELECT COUNT(*) FROM item_instance'),
    (int) $col('SELECT COUNT(DISTINCT guid) FROM item_instance'));
check('ningun item_instance huerfano', (int) $col(
    'SELECT COUNT(*) FROM item_instance ii
       LEFT JOIN character_inventory ci ON ci.item = ii.guid
       LEFT JOIN mail_items mi ON mi.item_guid = ii.guid
      WHERE ci.item IS NULL AND mi.item_guid IS NULL'), 0);
check('owner_guid coherente', (int) $col(
    'SELECT COUNT(*) FROM character_inventory ci JOIN item_instance ii ON ii.guid = ci.item
      WHERE ii.owner_guid <> ci.guid'), 0);
check('ningun slot fuera de rango', (int) $col(
    'SELECT COUNT(*) FROM character_inventory WHERE bag = 0 AND (slot < 0 OR slot > 73)'), 0);

} finally {
    if ($keep) {
        echo "\nDBs de prueba conservadas (--keep): " . TEST_AUTH . ', ' . TEST_CHARS . "\n";
    } else {
        teardown($admin);
        echo "\nBases de prueba eliminadas.\n";
    }
}

printf("\n════════════════════════════════════\n  %d OK, %d FALLOS\n════════════════════════════════════\n",
    $pass, $fail);
exit($fail > 0 ? 1 : 0);
