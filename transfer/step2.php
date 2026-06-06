<?php
/**
 * step2.php — Preview paperdoll 3D del personaje + confirmación de transferencia
 * El personaje NO se importa aquí — queda pendiente para que el GM lo apruebe.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/language.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/dbfunctions.php';

$user = new User();
if (!$user->isLoggedIn()) { header('Location: ../index.php'); exit; }

if (empty($_SESSION['transfer_dump']) || empty($_SESSION['transfer_realm'])) {
    header('Location: step1.php'); exit;
}

$dump     = $_SESSION['transfer_dump'];
$realmId  = (int)$_SESSION['transfer_realm'];
$origName = $_SESSION['transfer_charname'] ?? '';
$error    = '';

// ── Parsear dump ──────────────────────────────────────────────────
$dumpData   = null;
$itemMap    = [];
$enchantMap = [];
$isJsonDump = CharacterImporter::isJsonDump($dump);

if ($isJsonDump) {
    $dumpData = json_decode($dump, true);

    // Recolectar todos los entries para batch lookup
    $allEntries = [];
    foreach (($dumpData['equipped']    ?? []) as $i) if (!empty($i['entry'])) $allEntries[] = (int)$i['entry'];
    foreach (($dumpData['bags']        ?? []) as $i) if (!empty($i['entry'])) $allEntries[] = (int)$i['entry'];
    foreach (($dumpData['bank']        ?? []) as $i) if (!empty($i['entry'])) $allEntries[] = (int)$i['entry'];

    $itemMap = CharacterImporter::getItemData($allEntries);

    $allEnchantIds = [];
    foreach (['equipped', 'bags', 'bank'] as $bucket) {
        foreach (($dumpData[$bucket] ?? []) as $i) {
            foreach (['ench', 'gem1', 'gem2', 'gem3'] as $field) {
                $id = (int)($i[$field] ?? 0);
                if ($id > 0) $allEnchantIds[] = $id;
            }
        }
    }
    $allEnchantIds = array_values(array_unique($allEnchantIds));
    if (!empty($allEnchantIds)) {
        try {
            $in = implode(',', $allEnchantIds);
            $rows = DB::world()->rows(
                "SELECT `ID`,
                        COALESCE(NULLIF(`Name_Lang_esES`,''), NULLIF(`Name_Lang_esMX`,''), NULLIF(`Name_Lang_enUS`,''), `Name_Lang_Unk`) AS `name`
                 FROM `spellitemenchantment_dbc` WHERE `ID` IN ({$in})"
            );
            foreach ($rows as $r) {
                $enchantMap[(int)$r->ID] = (string)$r->name;
            }
        } catch (Throwable) {
            $enchantMap = [];
        }
    }
    // Añadir IDs de bonus de socket (item_template.socketBonus) al enchantMap
    $sbIds = [];
    foreach ($itemMap as $_item) {
        $sb = (int)($_item->socketBonus ?? 0);
        if ($sb > 0 && !isset($enchantMap[$sb])) $sbIds[] = $sb;
    }
    $sbIds = array_values(array_unique($sbIds));
    if (!empty($sbIds)) {
        try {
            $in = implode(',', $sbIds);
            $r2 = DB::world()->rows(
                "SELECT `ID`, COALESCE(NULLIF(`Name_Lang_esES`,''), NULLIF(`Name_Lang_esMX`,''), NULLIF(`Name_Lang_enUS`,''), `Name_Lang_Unk`) AS `name`
                 FROM `spellitemenchantment_dbc` WHERE `ID` IN ({$in})"
            );
            foreach ($r2 as $r) $enchantMap[(int)$r->ID] = (string)$r->name;
        } catch (Throwable) {}
    }
}

// ── Paleta de calidades ────────────────────────────────────────────
$qColor = ['#9d9d9d','#ffffff','#1eff00','#0070dd','#a335ee','#ff8000','#e6cc80','#e6cc80'];
$qName  = ['Malo','Común','Poco común','Raro','Épico','Legendario','Artefacto','Reliquia'];
$qClass = ['q0','q1','q2','q3','q4','q5','q6','q7'];

// ── Construir mapa equipado: dbSlot → {entry, name, quality, invType, valid} ──
// Lua slots 1-23 → DB slots 0-22
$paperSlots = [];
foreach (($dumpData['equipped'] ?? []) as $item) {
    $lua   = (int)($item['slot'] ?? 0);
    $db    = $lua - 1;
    if ($db < 0 || $db > 22) continue;
    $entry = (int)($item['entry'] ?? 0);
    if ($entry <= 0) continue;

    $idata     = $itemMap[$entry] ?? null;
    $name      = $idata ? $idata->name             : "Item #{$entry}";
    $quality   = $idata ? (int)$idata->Quality      : 1;
    $invType   = $idata ? (int)$idata->InventoryType : 0;
    $displayId = $idata ? (int)$idata->displayid    : 0;
    $valid     = CharacterImporter::checkSlotValid($db, $invType);

    $paperSlots[$db] = [
        'entry'     => $entry,
        'name'      => $name,
        'quality'   => $quality,
        'invType'   => $invType,
        'valid'     => $valid,
        'displayid' => $displayId,
        'dump'      => $item,
        'meta'      => $idata,
    ];
}

// ── Disposición del paperdoll (igual que el panel de WoW) ──────────
// Columna izquierda: DB slots (Lua slot)
$leftCol  = [0,1,2,14,4,3,18,8];   // Head,Neck,Shoulders,Back,Chest,Shirt,Tabard,Wrists
$rightCol = [9,5,6,7,10,11,12,13]; // Hands,Waist,Legs,Feet,Ring1,Ring2,Trinket1,Trinket2
$weaponRow= [15,16,17];             // MainHand, OffHand, Ranged/Relic
$bagRow   = [19,20,21,22];          // Bag slots

// ── Datos del personaje ───────────────────────────────────────────
$basic = $dumpData['basic'] ?? [];
$copper   = (int)($basic['copper']    ?? 0);
$gold     = intdiv($copper, 10000);
$silver   = intdiv($copper % 10000, 100);
$honor    = (int)($basic['honor']     ?? 0);
$arenapts = (int)($basic['arena_pts'] ?? 0);

// Clase → color e ícono WoW
$classInfo = [
    1  => ['Guerrero',   '#C79C6E'], 2 => ['Paladín',    '#F58CBA'],
    3  => ['Cazador',    '#ABD473'], 4 => ['Pícaro',     '#FFF569'],
    5  => ['Sacerdote',  '#FFFFFF'], 6 => ['Caballero M','#C41F3B'],
    7  => ['Chamán',     '#0070DE'], 8 => ['Mago',       '#69CCF0'],
    9  => ['Brujo',      '#9482C9'], 11 => ['Druida',    '#FF7D0A'],
];
$cls      = is_numeric($basic['class'] ?? '') ? (int)$basic['class'] : 1;
$clsName  = $classInfo[$cls][0] ?? 'Desconocido';
$clsColor = $classInfo[$cls][1] ?? '#ffffff';

$raceNames = [1=>'Humano',2=>'Orco',3=>'Enano',4=>'Elfo de noche',5=>'No-muerto',
              6=>'Tauren',7=>'Gnomo',8=>'Trol',10=>'Elfo de sangre',11=>'Draenei'];
$race     = is_numeric($basic['race'] ?? '') ? (int)$basic['race'] : 1;
$raceName = $raceNames[$race] ?? 'Desconocida';
$gender   = (int)($basic['gender'] ?? 0);
$genderStr= $gender === 0 ? 'Masculino' : 'Femenino';

// Recuento de items en mochila y banco
$bags     = $dumpData['bags'] ?? [];
$bank     = $dumpData['bank'] ?? [];

// ── POST: confirmar transferencia ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Token::check(Input::get('token'))) {
        $error = t('token_error');
    } else {
        $newName = trim(Input::get('char_name'));
        if (!isValidCharName($newName)) {
            $error = t('invalid_char_name');
        } elseif (characterNameExists($newName, $realmId)) {
            $error = t('name_taken');
        } else {
            $finalDump = $dump;
            if ($isJsonDump) {
                try {
                    $obj = json_decode($dump, true, 512, JSON_THROW_ON_ERROR);
                    $obj['basic']['name'] = $newName;
                    $finalDump = json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (Throwable) {}
            }
            createTransferRecord($user->id(), $newName, 0, $realmId, $finalDump, REALMS[$realmId]['db_name']);
            unset($_SESSION['transfer_dump'], $_SESSION['transfer_realm'], $_SESSION['transfer_charname']);
            Session::flash('message', t('transfer_queued'));
            $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
                  . '://' . $_SERVER['HTTP_HOST']
                  . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
            header('Location: ' . $base . '/dashboard.php');
            exit;
        }
    }
}
$token = Token::generate();

// ── Helper: render de un slot ─────────────────────────────────────
function renderSlot(int $dbSlot, array $paperSlots, array $qColor, array $qClass, array $qName, string $label = ''): void
{
    $s       = $paperSlots[$dbSlot] ?? null;
    $isEmpty = $s === null;

    if ($isEmpty) {
        echo '<div class="pslot empty" title="' . htmlspecialchars($label) . '">';
        echo '<span class="pslot-lbl">' . htmlspecialchars($label) . '</span>';
        echo '</div>';
        return;
    }

    $q     = min(7, max(0, $s['quality']));
    $color = $qColor[$q];
    $cls   = $qClass[$q];
    $name  = htmlspecialchars($s['name']);
    $qn    = $qName[$q];
    $warn  = !$s['valid'] ? ' pslot-warn' : '';

    echo '<div class="pslot ' . $cls . $warn . '" ';
    echo 'data-entry="' . (int)$s['entry'] . '" ';
    echo 'data-name="' . $name . '" ';
    echo 'data-quality="' . $qn . '" ';
    echo 'data-invtype="' . (int)$s['invType'] . '" ';
    echo 'style="--qcolor:' . $color . '">';
    // Ícono — se carga asíncronamente vía api/icon.php (sin CORS)
    echo '<img class="pslot-icon" src="" data-entry="' . (int)$s['entry'] . '" alt="" loading="lazy">';
    echo '<span class="pslot-fallback"></span>';
    echo '<span class="pslot-spinner"></span>';
    if (!$s['valid']) echo '<span class="pslot-badge-warn" title="Item redirigido a mochila">⚠</span>';
    echo renderItemTooltip($s, $qColor, $qName, $label);
    echo '</div>';
}

$slotLabels = CharacterImporter::SLOT_NAMES;

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function itemField(?object $item, string $field, mixed $default = null): mixed
{
    return $item !== null && isset($item->{$field}) ? $item->{$field} : $default;
}

function itemStatName(int $type): string
{
    return [
        3 => 'Agilidad', 4 => 'Fuerza', 5 => 'Intelecto', 6 => 'Espíritu', 7 => 'Aguante',
        12 => 'Índice de defensa', 13 => 'Índice de esquivar', 14 => 'Índice de parar',
        15 => 'Índice de bloqueo', 16 => 'Índice de golpe cuerpo a cuerpo',
        17 => 'Índice de golpe a distancia', 18 => 'Índice de golpe con hechizos',
        19 => 'Índice de crítico cuerpo a cuerpo', 20 => 'Índice de crítico a distancia',
        21 => 'Índice de crítico con hechizos', 28 => 'Índice de celeridad cuerpo a cuerpo',
        29 => 'Índice de celeridad a distancia', 30 => 'Índice de celeridad con hechizos',
        31 => 'Índice de golpe', 32 => 'Índice de golpe crítico', 35 => 'Índice de temple',
        36 => 'Índice de celeridad', 37 => 'Índice de pericia', 38 => 'Poder de ataque',
        39 => 'Poder de ataque a distancia', 40 => 'Poder de ataque feral',
        41 => 'Sanación', 42 => 'Daño con hechizos', 43 => 'Maná cada 5 s',
        44 => 'Penetración de armadura', 45 => 'Poder con hechizos',
        46 => 'Salud cada 5 s', 47 => 'Penetración de hechizos', 48 => 'Valor de bloqueo',
    ][$type] ?? "Stat {$type}";
}

function itemDamageType(int $type): string
{
    return [0 => 'Físico', 1 => 'Sagrado', 2 => 'Fuego', 3 => 'Naturaleza', 4 => 'Escarcha', 5 => 'Sombras', 6 => 'Arcano'][$type] ?? "Tipo {$type}";
}

function itemClassName(int $class, int $subclass): string
{
    $classes = [
        0 => 'Consumible', 1 => 'Contenedor', 2 => 'Arma', 3 => 'Gema', 4 => 'Armadura',
        5 => 'Componente', 6 => 'Proyectil', 7 => 'Material', 9 => 'Receta',
        11 => 'Carcaj', 12 => 'Misión', 13 => 'Llave', 15 => 'Misceláneo', 16 => 'Glifo',
    ];
    $weapon = [
        0 => 'Hacha 1M', 1 => 'Hacha 2M', 2 => 'Arco', 3 => 'Arma de fuego', 4 => 'Maza 1M',
        5 => 'Maza 2M', 6 => 'Asta', 7 => 'Espada 1M', 8 => 'Espada 2M', 10 => 'Bastón',
        13 => 'Arma de puño', 15 => 'Daga', 16 => 'Arrojadiza', 18 => 'Ballesta', 19 => 'Varita',
        20 => 'Caña de pescar',
    ];
    $armor = [
        0 => 'Misceláneo', 1 => 'Tela', 2 => 'Cuero', 3 => 'Malla', 4 => 'Placas',
        6 => 'Escudo', 7 => 'Tratado', 8 => 'Ídolo', 9 => 'Tótem', 10 => 'Sigilo',
    ];
    if ($class === 2) return $weapon[$subclass] ?? 'Arma';
    if ($class === 4) return $armor[$subclass] ?? 'Armadura';
    return $classes[$class] ?? "Clase {$class}";
}

function itemBondingText(int $bonding): string
{
    return [
        1 => 'Se liga al recogerlo',
        2 => 'Se liga al equiparlo',
        3 => 'Se liga al usarlo',
        4 => 'Objeto de misión',
        5 => 'Objeto de misión',
    ][$bonding] ?? '';
}

function itemSocketName(int $socket): string
{
    $parts = [];
    if (($socket & 1) !== 0) $parts[] = 'Meta';
    if (($socket & 2) !== 0) $parts[] = 'Rojo';
    if (($socket & 4) !== 0) $parts[] = 'Amarillo';
    if (($socket & 8) !== 0) $parts[] = 'Azul';
    return empty($parts) ? "Color {$socket}" : implode('/', $parts);
}

function itemSpellTriggerName(int $trigger): string
{
    return [
        0 => 'Usar',
        1 => 'Al equipar',
        2 => 'Probabilidad al golpear',
        4 => 'Aprender hechizo',
        5 => 'Usar sin demora',
        6 => 'Aprender profesión',
    ][$trigger] ?? "Trigger {$trigger}";
}

function itemCooldownText(int $cooldown): string
{
    if ($cooldown <= 0) return '';
    $seconds = (int)ceil($cooldown / 1000);
    if ($seconds >= 3600) return number_format($seconds / 3600, 1, ',', '.') . ' h';
    if ($seconds >= 60) return number_format($seconds / 60, 1, ',', '.') . ' min';
    return $seconds . ' s';
}

function spellEffectValue(?object $spell, int $index): int
{
    if ($spell === null) return 0;
    $base = (int)($spell->{"EffectBasePoints_{$index}"} ?? 0);
    $dice = (int)($spell->{"EffectDieSides_{$index}"} ?? 0);
    return $base + max(1, $dice);
}

function spellTemplateText(string $text, ?object $spell): string
{
    if ($text === '' || $spell === null) return $text;

    $text = preg_replace_callback('/\\$([smob])([1-3])/', function (array $m) use ($spell): string {
        $idx = (int)$m[2];
        return match ($m[1]) {
            's' => (string)spellEffectValue($spell, $idx),
            'm' => (string)(int)($spell->{"EffectMultipleValue_{$idx}"} ?? 0),
            'o' => (string)(spellEffectValue($spell, $idx) * max(1, (int)ceil(((int)($spell->{"EffectAuraPeriod_{$idx}"} ?? 0)) / 1000))),
            'b' => (string)(int)($spell->{"EffectBasePoints_{$idx}"} ?? 0),
            default => $m[0],
        };
    }, $text) ?? $text;

    $text = preg_replace_callback('/\\$d\\b/', function () use ($spell): string {
        $durationIndex = (int)($spell->DurationIndex ?? 0);
        return $durationIndex > 0 ? "duración {$durationIndex}" : '';
    }, $text) ?? $text;

    $text = preg_replace('/\\$[a-zA-Z][0-9]?/', '', $text) ?? $text;
    return trim(preg_replace('/\\s+/', ' ', $text) ?? $text);
}

function renderItemTooltip(array $item, array $qColor, array $qName, string $slotLabel = ''): string
{
    global $enchantMap;

    $entry = (int)($item['entry'] ?? 0);
    $meta  = $item['meta'] ?? null;
    $dump  = is_array($item['dump'] ?? null) ? $item['dump'] : $item;
    $name  = (string)($item['name'] ?? itemField($meta, 'name', "Item #{$entry}"));
    $q     = min(7, max(0, (int)($item['quality'] ?? itemField($meta, 'Quality', 1))));
    $color = $qColor[$q] ?? '#ffffff';
    $count = max(1, (int)($dump['count'] ?? 1));

    $html = '<div class="item-tooltip" role="tooltip">';
    $html .= '<div class="it-name" style="color:' . h($color) . '">' . h($name) . '</div>';
    $html .= '<div class="it-line muted">' . h($qName[$q] ?? 'Calidad desconocida') . ($entry > 0 ? ' · Entry ' . $entry : '') . '</div>';

    $itemLevel = (int)itemField($meta, 'ItemLevel', 0);
    $reqLevel  = (int)itemField($meta, 'RequiredLevel', 0);
    $class     = (int)itemField($meta, 'class', -1);
    $subclass  = (int)itemField($meta, 'subclass', -1);
    $bonding   = itemBondingText((int)itemField($meta, 'bonding', 0));
    if ($slotLabel !== '' || $itemLevel > 0 || $reqLevel > 0 || $count > 1) {
        $bits = [];
        if ($slotLabel !== '') $bits[] = $slotLabel;
        if ($class >= 0 && $subclass >= 0) $bits[] = itemClassName($class, $subclass);
        if ($itemLevel > 0) $bits[] = "Nivel de item {$itemLevel}";
        if ($reqLevel > 0) $bits[] = "Requiere nivel {$reqLevel}";
        if ($count > 1) $bits[] = "Cantidad {$count}";
        $html .= '<div class="it-line">' . h(implode(' · ', $bits)) . '</div>';
    }
    if ($bonding !== '') $html .= '<div class="it-line muted">' . h($bonding) . '</div>';

    for ($i = 1; $i <= 2; $i++) {
        $min = (float)itemField($meta, "dmg_min{$i}", 0);
        $max = (float)itemField($meta, "dmg_max{$i}", 0);
        if ($max > 0) {
            $type = itemDamageType((int)itemField($meta, "dmg_type{$i}", 0));
            $html .= '<div class="it-line">' . h(rtrim(rtrim((string)$min, '0'), '.') . ' - ' . rtrim(rtrim((string)$max, '0'), '.') . " daño {$type}") . '</div>';
        }
    }
    $delay = (int)itemField($meta, 'delay', 0);
    if ($delay > 0) $html .= '<div class="it-line">Velocidad ' . h(number_format($delay / 1000, 2, ',', '.')) . '</div>';

    $armor = (int)itemField($meta, 'armor', 0);
    if ($armor > 0) $html .= '<div class="it-line">' . h($armor) . ' armadura</div>';
    $block = (int)itemField($meta, 'block', 0);
    if ($block > 0) $html .= '<div class="it-line">' . h($block) . ' bloqueo</div>';
    foreach (['holy_res' => 'Sagrado', 'fire_res' => 'Fuego', 'nature_res' => 'Naturaleza', 'frost_res' => 'Escarcha', 'shadow_res' => 'Sombras', 'arcane_res' => 'Arcano'] as $field => $label) {
        $value = (int)itemField($meta, $field, 0);
        if ($value > 0) $html .= '<div class="it-line">+' . h($value) . ' resistencia a ' . h($label) . '</div>';
    }

    for ($i = 1; $i <= 10; $i++) {
        $type  = (int)itemField($meta, "stat_type{$i}", 0);
        $value = (int)itemField($meta, "stat_value{$i}", 0);
        if ($type > 0 && $value !== 0) {
            $sign = $value > 0 ? '+' : '';
            $html .= '<div class="it-line stat">' . h($sign . $value . ' ' . itemStatName($type)) . '</div>';
        }
    }

    $maxDurability = (int)itemField($meta, 'MaxDurability', 0);
    if ($maxDurability > 0) $html .= '<div class="it-line muted">Durabilidad ' . h($maxDurability) . ' / ' . h($maxDurability) . '</div>';
    $itemSet = (int)itemField($meta, 'itemset', 0);
    if ($itemSet > 0) $html .= '<div class="it-line muted">Set de objeto: ' . h($itemSet) . '</div>';

    // ── Encantamiento ─────────────────────────────────────────────
    $ench = (int)($dump['ench'] ?? 0);
    if ($ench > 0) {
        $enchName = trim((string)($enchantMap[$ench] ?? ''));
        $html .= '<div class="it-line enchant">Encantamiento: ' . h($enchName ?: "#{$ench}") . '</div>';
    }

    // ── Sockets + joyas (estilo WoW) ──────────────────────────────
    $socketColorMap = [
        1   => ['Meta',     '⬡', '#aaaaaa'],
        2   => ['Rojo',     '◆', '#ff4444'],
        4   => ['Amarillo', '◆', '#ffdd00'],
        8   => ['Azul',     '◆', '#5599ff'],
        16  => ['Naranja',  '◆', '#ff8800'],
        32  => ['Morado',   '◆', '#aa44ee'],
        64  => ['Verde',    '◆', '#44cc44'],
        128 => ['Prism.',   '◆', '#e8e8ff'],
    ];
    $anySocket = false;
    for ($i = 1; $i <= 3; $i++) {
        $socketColor = (int)itemField($meta, "socketColor_{$i}", 0);
        if ($socketColor <= 0) continue;
        $anySocket = true;
        $sc  = $socketColorMap[$socketColor] ?? ['Socket', '◆', '#888888'];
        $gem = (int)($dump["gem{$i}"] ?? 0);
        if ($gem > 0) {
            $gemStat = trim((string)($enchantMap[$gem] ?? ''));
            $html .= '<div class="it-line socket-filled">'
                . '<span class="sock-icon" style="color:' . h($sc[2]) . '">' . $sc[1] . '</span>'
                . ' ' . h($gemStat ?: "Gema #{$gem}")
                . '</div>';
        } else {
            $html .= '<div class="it-line socket-empty">'
                . '<span class="sock-icon" style="color:' . h($sc[2]) . '">' . $sc[1] . '</span>'
                . ' Hueco ' . h($sc[0])
                . '</div>';
        }
    }
    if ($anySocket) {
        $socketBonus = (int)itemField($meta, 'socketBonus', 0);
        if ($socketBonus > 0) {
            $allFilled = true;
            for ($j = 1; $j <= 3; $j++) {
                $sc = (int)itemField($meta, "socketColor_{$j}", 0);
                if ($sc > 0 && !(int)($dump["gem{$j}"] ?? 0)) { $allFilled = false; break; }
            }
            $bonusName  = trim((string)($enchantMap[$socketBonus] ?? "Bonus #{$socketBonus}"));
            $bonusClass = $allFilled ? 'socket-bonus-on' : 'socket-bonus-off';
            $html .= '<div class="it-line ' . $bonusClass . '">Bonus de socket: ' . h($bonusName) . '</div>';
        }
    }

    // ── Efectos de hechizo (estilo WoW: Al equipar / Usar) ────────
    for ($i = 1; $i <= 5; $i++) {
        $spellId = (int)itemField($meta, "spellid_{$i}", 0);
        if ($spellId <= 0) continue;
        $trigger  = (int)itemField($meta, "spelltrigger_{$i}", 0);
        $cooldown = itemCooldownText((int)itemField($meta, "spellcooldown_{$i}", 0));
        $spellInfo = null;
        if ($meta !== null && !empty($meta->_spellInfo) && isset($meta->_spellInfo[$spellId])) {
            $spellInfo = $meta->_spellInfo[$spellId];
        }
        $spellDesc = spellTemplateText(trim((string)($spellInfo->description ?? '')), $spellInfo);
        $spellAura = spellTemplateText(trim((string)($spellInfo->aura ?? '')), $spellInfo);
        $desc = $spellDesc !== '' ? $spellDesc : $spellAura;
        if ($desc === '') $desc = trim((string)($spellInfo->name ?? ''));
        if ($desc === '') $desc = "Hechizo {$spellId}";
        $triggerLabel = match ($trigger) {
            0, 5    => 'Usar',
            1       => 'Al equipar',
            2       => 'Probabilidad al golpear',
            default => 'Al equipar',
        };
        $line        = $triggerLabel . ': ' . $desc;
        if ($cooldown !== '') $line .= ' (' . $cooldown . ' CD)';
        $effectClass = ($trigger === 0 || $trigger === 5) ? 'effect-yellow' : 'effect-green';
        $html .= '<div class="it-line ' . $effectClass . '">' . h($line) . '</div>';
    }

    $stackable = (int)itemField($meta, 'stackable', 0);
    $maxCount  = (int)itemField($meta, 'maxcount', 0);
    if ($stackable > 1 || $maxCount > 0) {
        $bits = [];
        if ($stackable > 1) $bits[] = "apilable {$stackable}";
        if ($maxCount > 0) $bits[] = "máximo {$maxCount}";
        $html .= '<div class="it-line muted">' . h(implode(' · ', $bits)) . '</div>';
    }

    $desc = trim((string)itemField($meta, 'description', ''));
    if ($desc !== '') $html .= '<div class="it-line desc">"' . h($desc) . '"</div>';

    $extra = [];
    foreach ($dump as $key => $value) {
        if (in_array($key, ['entry','slot','count','ench','gem1','gem2','gem3'], true)) continue;
        if (is_scalar($value) && (string)$value !== '' && (string)$value !== '0') {
            $extra[] = h($key) . ': ' . h($value);
        }
    }
    if (!empty($extra)) {
        $html .= '<div class="it-sep"></div><div class="it-line muted">Chardump</div>';
        foreach (array_slice($extra, 0, 10) as $line) $html .= '<div class="it-line tiny">' . $line . '</div>';
    }

    $html .= '</div>';
    return $html;
}

function renderInventoryTooltip(array $dumpItem, ?object $meta, array $qColor, array $qName, string $slotLabel): string
{
    $entry = (int)($dumpItem['entry'] ?? 0);
    return renderItemTooltip([
        'entry'   => $entry,
        'name'    => $meta ? (string)$meta->name : "Item #{$entry}",
        'quality' => $meta ? (int)$meta->Quality : 1,
        'dump'    => $dumpItem,
        'meta'    => $meta,
    ], $qColor, $qName, $slotLabel);
}

function modelViewerSlot(int $dbSlot, int $inventoryType = 0): int
{
    if ($dbSlot === 4 && $inventoryType === 20) return 20; // Robe/new chest slot
    if ($dbSlot === 15) return 21; // Main hand new display slot
    if ($dbSlot === 16) return 22; // Off hand new display slot
    return $dbSlot + 1;            // Viewer usa slots 1-based
}

// ── URL Vestidor 3D WoWHead ───────────────────────────────────────
// WoWHead slots = Lua slots (base-1) = DB slot + 1 (para slots 0-18)
// Formato hash: #race=X;gender=Y;items=;1:ENTRY;2:ENTRY;...
$drItemsList = [];
foreach ($paperSlots as $dbSlot => $s) {
    if ($dbSlot > 18) continue; // bolsas equipadas no van en vestidor
    $wowSlot       = $dbSlot + 1;
    $drItemsList[] = "{$wowSlot}:{$s['entry']}";
}
$dressingRoomUrl = 'https://www.wowhead.com/wotlk/dressing-room'
    . '#race=' . $race . ';gender=' . $gender
    . (!empty($drItemsList) ? ';items=;' . implode(';', $drItemsList) : '');

// ── Datos para el visor 3D ────────────────────────────────────
// Equipments: slot DB-0based + entry + displayid WotLK
// El JS los convierte a displayIds Retail via murlocvillage
$modelEquipments = [];
foreach ($paperSlots as $db => $s) {
    if ($db > 18 || ($s['displayid'] ?? 0) <= 0) continue;
    $modelEquipments[] = [
        'slot'     => $db,
        'item'     => ['entry' => (int)$s['entry'], 'displayid' => (int)$s['displayid']],
        'transmog' => new stdClass(),
    ];
}
$modelChar = [
    'race'        => $race,
    // wow-model-viewer usa 0=female, 1=male; AzerothCore/dump aquí usa 0=male, 1=female.
    'gender'      => $gender === 0 ? 0 : 1,
    'skin'        => 0,
    'face'        => 0,
    'hairStyle'   => 0,
    'hairColor'   => 0,
    'facialStyle' => 0,
];

// URLs absolutas del proxy 3D — sin rewrite rules, acceso directo a los .php
$_m3dScheme     = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
$_m3dBase       = $_m3dScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
// CONTENT_PATH con query-string: model_proxy.php?_path=modelviewer/live/<ruta>
$model3dContent = $_m3dBase . '/api/model_proxy.php?_path=modelviewer/live/';
// viewer.min.js vía el mismo proxy
$model3dViewer  = $_m3dBase . '/api/model_proxy.php?_path=modelviewer/live/viewer/viewer.min.js';

// ── Convertir displayIds WotLK → Retail (server-side, con caché permanente) ──
// Se hace en PHP para evitar dependencia de PATH_INFO/Apache en el navegador.
// Los resultados se cachean en storage/model_cache/display_ids/  (un JSON por item)
// → solo se llama a murlocvillage la PRIMERA vez que se ve ese item.
$modelItems = []; // [[viewerSlot, retailDisplayId], ...]  pasado directamente al JS
if (!empty($modelEquipments)) {
    $dispCacheDir = STORAGE_PATH . '/model_cache/display_ids/';
    if (!is_dir($dispCacheDir)) @mkdir($dispCacheDir, 0755, true);

    foreach ($modelEquipments as $eq) {
        $slot  = (int)$eq['slot'];
        $entry = (int)$eq['item']['entry'];
        $wotlk = (int)$eq['item']['displayid'];
        $invType = (int)($paperSlots[$slot]['invType'] ?? 0);
        if ($wotlk <= 0) continue;

        $cacheFile = $dispCacheDir . $entry . '_' . $wotlk . '.json';
        $newId     = $wotlk; // fallback: ID WotLK original

        if (is_file($cacheFile)) {
            // ── Cache hit (lectura instantánea) ──────────────────────────
            $d     = json_decode(@file_get_contents($cacheFile) ?: '{}', true);
            $newId = (int)($d['newDisplayId'] ?? $wotlk);
        } else {
            // ── Cache miss: llamar a murlocvillage ────────────────────────
            $apiUrl = "https://wotlk.murlocvillage.com/api/items/{$entry}/{$wotlk}";
            $ctx    = stream_context_create([
                'http' => [
                    'method'        => 'GET',
                    'timeout'       => 5,
                    'header'        => 'User-Agent: Migrador-WotLK/1.0',
                    'ignore_errors' => true,
                ],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $body = @file_get_contents($apiUrl, false, $ctx);
            if ($body !== false && strlen($body) > 2) {
                $d = json_decode($body, true);
                if (isset($d['data']['newDisplayId']))  $newId = (int)$d['data']['newDisplayId'];
                elseif (isset($d['newDisplayId']))      $newId = (int)$d['newDisplayId'];
            }
            if ($newId > 0) {
                @file_put_contents($cacheFile, json_encode(['newDisplayId' => $newId]));
            }
        }

        if ($newId > 0) {
            $viewerSlot = modelViewerSlot($slot, $invType);
            $modelItems[] = [$viewerSlot, $newId, 0];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= DEFAULT_LANG ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vista previa del personaje — <?= t('site_title') ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- jQuery requerido por el visor 3D de WoWHead -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" crossorigin="anonymous"></script>
    <style>
    /* ════════════════════════════════════════════════
       PAPERDOLL — WoW Character Panel Style
    ════════════════════════════════════════════════ */

    .paperdoll-page { background: var(--bg-deep); min-height: 100vh; }

    /* Panel principal */
    .paperdoll-wrap {
        background: linear-gradient(160deg, #0d0d1a 0%, #12081a 60%, #1a0d08 100%);
        border: 1px solid var(--border-gold);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 0 60px rgba(200,168,75,0.12), inset 0 0 80px rgba(0,0,0,0.6);
        position: relative;
        overflow: hidden;
    }
    .paperdoll-wrap::before {
        content: '';
        position: absolute; inset: 0;
        background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.01'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        pointer-events: none; opacity: 0.5;
    }

    /* Cabecera del personaje */
    .char-header { text-align: center; margin-bottom: 1.5rem; position: relative; }
    .char-name-big {
        font-size: 2rem; font-weight: 700;
        color: var(--gold-light);
        text-shadow: 0 0 30px rgba(240,208,112,0.5), 0 2px 4px rgba(0,0,0,0.8);
        margin-bottom: 0.25rem;
        letter-spacing: 0.05em;
    }
    .char-sub { font-size: 0.88rem; color: var(--text-muted); }
    .char-class-badge {
        display: inline-block;
        padding: 2px 12px;
        border-radius: 20px;
        font-size: 0.82rem;
        font-weight: 600;
        border: 1px solid currentColor;
        margin-top: 4px;
    }

    /* Grid de 3 columnas */
    .paperdoll-grid {
        display: grid;
        grid-template-columns: 64px 1fr 64px;
        gap: 0 1rem;
        align-items: center;
    }

    /* Columnas de slots */
    .pslot-col { display: flex; flex-direction: column; gap: 5px; }
    .pslot-col.right { align-items: flex-end; }

    /* ── SLOT ── */
    .pslot {
        width: 56px; height: 56px;
        border: 2px solid #3a3a4a;
        border-radius: 4px;
        background: #111118;
        position: relative;
        cursor: default;
        transition: transform 0.15s, box-shadow 0.15s;
        display: flex; align-items: center; justify-content: center;
        overflow: visible;
        flex-shrink: 0;
    }
    .pslot:hover { transform: scale(1.1); z-index: 10; }
    .has-tooltip { cursor: help; }
    .item-tooltip {
        position: absolute;
        left: calc(100% + 10px);
        top: 0;
        width: min(320px, calc(100vw - 32px));
        max-width: 320px;
        background: rgba(8, 8, 16, 0.98);
        border: 1px solid var(--qcolor, #555);
        border-radius: 5px;
        padding: 9px 11px;
        color: #f1ead7;
        font-size: 0.74rem;
        line-height: 1.35;
        text-align: left;
        pointer-events: none;
        opacity: 0;
        transform: translateY(-4px);
        transition: opacity 0.08s ease, transform 0.08s ease;
        z-index: 5000;
        box-shadow: 0 10px 32px rgba(0,0,0,0.85), inset 0 0 18px rgba(255,255,255,0.03);
        white-space: normal;
    }
    .cs-col.right .item-tooltip,
    .bag-group:nth-last-child(-n+2) .item-tooltip {
        left: auto;
        right: calc(100% + 10px);
    }
    .has-tooltip:hover .item-tooltip,
    .has-tooltip:focus-within .item-tooltip,
    .has-tooltip:focus .item-tooltip {
        opacity: 1;
        transform: translateY(0);
    }
    .it-name { font-size: 0.86rem; font-weight: 700; margin-bottom: 2px; }
    .it-line { color: #f1ead7; margin-top: 2px; }
    .it-line.muted { color: #9d9d9d; }
    .it-line.stat { color: #ffffff; }
    .it-line.enchant { color: #1eff00; }
    .it-line.socket-filled { color: #f1ead7; }
    .it-line.socket-empty  { color: #666; }
    .sock-icon { font-size: 0.9em; margin-right: 2px; vertical-align: middle; }
    .it-line.socket-bonus-on  { color: #1eff00; }
    .it-line.socket-bonus-off { color: #9d9d9d; }
    .it-line.effect-green  { color: #1eff00; }
    .it-line.effect-yellow { color: #ffd100; }
    .it-line.desc { color: #ffd100; margin-top: 6px; }
    .it-line.tiny { color: #b7b7c7; font-size: 0.68rem; }
    .it-sep { height: 1px; background: rgba(255,255,255,0.12); margin: 7px 0 5px; }

    .pslot.empty {
        border: 1px dashed #2a2a3a;
        background: rgba(0,0,0,0.3);
        cursor: default;
    }
    .pslot.empty .pslot-lbl {
        font-size: 0.48rem; color: #3a3a5a;
        text-align: center; padding: 2px;
        line-height: 1.2;
    }

    /* Ícono real del item */
    .pslot-icon {
        width: 100%; height: 100%;
        object-fit: cover;
        display: none; /* oculto hasta que cargue */
        border-radius: 2px;
    }
    .pslot-icon.loaded { display: block; }

    /* Fallback: degradado de calidad */
    .pslot-fallback {
        position: absolute; inset: 0;
        border-radius: 2px;
    }
    .pslot-icon.loaded + .pslot-fallback { display: none; }

    /* Calidades — bordes y fondos */
    .q0 { border-color: #9d9d9d; }
    .q0 .pslot-fallback { background: linear-gradient(135deg,#1a1a1a,#2a2a2a); }
    .q1 { border-color: #cccccc; }
    .q1 .pslot-fallback { background: linear-gradient(135deg,#1c1c1c,#282828); }
    .q2 { border-color: #1eff00; box-shadow: 0 0 6px rgba(30,255,0,0.3); }
    .q2 .pslot-fallback { background: linear-gradient(135deg,#071a05,#0e2e0a); }
    .q3 { border-color: #0070dd; box-shadow: 0 0 10px rgba(0,112,221,0.4); }
    .q3 .pslot-fallback { background: linear-gradient(135deg,#03091a,#071230); }
    .q4 { border-color: #a335ee; animation: epicGlow 2.5s ease-in-out infinite; }
    .q4 .pslot-fallback { background: linear-gradient(135deg,#120828,#1e0c42); }
    .q5 { border-color: #ff8000; animation: legGlow 1.8s ease-in-out infinite; }
    .q5 .pslot-fallback { background: linear-gradient(135deg,#240c00,#3c1600); }
    .q6, .q7 { border-color: #e6cc80; box-shadow: 0 0 8px rgba(230,204,128,0.4); }
    .q6 .pslot-fallback, .q7 .pslot-fallback { background: linear-gradient(135deg,#1a1608,#2e260e); }

    @keyframes epicGlow {
        0%,100% { box-shadow: 0 0 6px rgba(163,53,238,0.4); }
        50%      { box-shadow: 0 0 18px rgba(163,53,238,0.85); }
    }
    @keyframes legGlow {
        0%,100% { box-shadow: 0 0 10px rgba(255,128,0,0.5); }
        50%      { box-shadow: 0 0 26px rgba(255,128,0,1.0); }
    }

    /* Warn: item redirigido a mochila */
    .pslot-warn { opacity: 0.7; }
    .pslot-badge-warn {
        position: absolute; top: -6px; right: -6px;
        background: #e07000; color: #fff;
        border-radius: 50%; width: 14px; height: 14px;
        font-size: 8px; display: flex; align-items: center; justify-content: center;
        z-index: 5;
    }

    /* ── TOOLTIP ── */
    .pslot::after {
        content: attr(data-name) '\A' attr(data-quality);
        white-space: pre;
        position: absolute;
        bottom: calc(100% + 8px);
        left: 50%; transform: translateX(-50%);
        background: #0d0d18;
        border: 1px solid var(--qcolor, #555);
        border-radius: 5px;
        padding: 6px 10px;
        font-size: 0.75rem;
        color: var(--qcolor, #ddd);
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.1s;
        z-index: 200;
        min-width: 100px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.8);
    }
    .pslot:hover::after { opacity: 1; }

    /* ── CENTRO: Silueta del personaje ── */
    .char-center {
        display: flex; flex-direction: column; align-items: center;
        justify-content: flex-start; gap: 0.5rem;
        padding: 0 0.5rem;
    }
    .char-silhouette {
        width: 140px; height: 280px;
        filter: drop-shadow(0 0 20px rgba(200,168,75,0.25));
    }

    /* Stats mini debajo de la silueta */
    .char-mini-stats {
        display: flex; flex-direction: column; gap: 3px;
        font-size: 0.75rem; color: var(--text-muted);
        text-align: center;
    }
    .mini-stat { display: flex; justify-content: space-between; gap: 12px; }
    .mini-stat-key { color: #5a5a7a; }
    .mini-stat-val { color: var(--gold); font-weight: 600; }

    /* ── ARMAS (debajo del grid) ── */
    .weapon-bar {
        display: flex; justify-content: center; gap: 1.5rem;
        margin-top: 1rem; padding-top: 0.75rem;
        border-top: 1px solid rgba(122,95,26,0.3);
    }
    .weapon-group { display: flex; flex-direction: column; align-items: center; gap: 3px; }
    .weapon-label { font-size: 0.6rem; color: #5a5a7a; text-transform: uppercase; }

    /* ── BOLSAS EQUIPADAS ── */
    .bag-bar {
        display: flex; justify-content: center; gap: 8px;
        margin-top: 0.75rem; padding-top: 0.75rem;
        border-top: 1px solid rgba(122,95,26,0.2);
    }
    .bag-group { display: flex; flex-direction: column; align-items: center; gap: 2px; }
    .bag-label { font-size: 0.58rem; color: #4a4a6a; }

    /* ── PANEL DE STATS ── */
    .stats-bar {
        display: flex; flex-wrap: wrap; gap: 0.75rem;
        margin-top: 1rem; padding-top: 1rem;
        border-top: 1px solid rgba(122,95,26,0.3);
        justify-content: center;
    }
    .stat-pill {
        background: rgba(0,0,0,0.4);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 4px 14px;
        font-size: 0.8rem;
        display: flex; gap: 6px; align-items: center;
    }
    .stat-pill-key { color: var(--text-muted); }
    .stat-pill-val { color: var(--gold-light); font-weight: 700; }

    /* ── RESUMEN MOCHILA/BANCO ── */
    .inv-summary {
        display: grid; grid-template-columns: 1fr 1fr;
        gap: 0.75rem; margin-top: 1rem;
    }
    .inv-block {
        background: rgba(0,0,0,0.3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 0.75rem 1rem;
    }
    .inv-block-title { font-size: 0.75rem; color: var(--gold); font-weight: 600; margin-bottom: 0.4rem; }
    .inv-item-list { display: flex; flex-direction: column; gap: 2px; }
    .inv-item {
        font-size: 0.72rem; display: flex; justify-content: space-between;
        padding: 2px 0; border-bottom: 1px solid rgba(255,255,255,0.04);
        position: relative;
    }
    .inv-item-name { color: var(--text); }
    .inv-item-count { color: var(--text-muted); font-size: 0.68rem; }
    .inv-more { font-size: 0.68rem; color: var(--text-dim); margin-top: 3px; }

    /* ── FORM DE CONFIRMACIÓN ── */
    .confirm-section {
        background: rgba(0,0,0,0.35);
        border: 1px solid var(--border-gold);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
    }
    .confirm-title { font-size: 1rem; color: var(--gold); font-weight: 600; margin-bottom: 0.75rem; }

    /* BOTÓN de confirmación grande */
    .btn-confirm {
        background: linear-gradient(135deg, #7a5f1a 0%, #c8a84b 50%, #7a5f1a 100%);
        color: #0a0a0f;
        font-size: 1.05rem;
        font-weight: 700;
        padding: 0.85rem 2rem;
        border: none;
        border-radius: var(--radius);
        cursor: pointer;
        width: 100%;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        transition: opacity 0.2s, transform 0.1s;
        box-shadow: 0 4px 20px rgba(200,168,75,0.4);
    }
    .btn-confirm:hover { opacity: 0.9; transform: translateY(-1px); }
    .btn-confirm:active { transform: translateY(0); }

    @media (max-width: 600px) {
        .paperdoll-grid { grid-template-columns: 56px 1fr 56px; gap: 0 0.4rem; }
        .pslot { width: 50px; height: 50px; }
        .char-silhouette { width: 100px; height: 200px; }
        .inv-summary { grid-template-columns: 1fr; }
        .char-name-big { font-size: 1.4rem; }
        .item-tooltip {
            left: 50%;
            right: auto;
            top: calc(100% + 8px);
            transform: translate(-50%, -4px);
        }
        .cs-col.right .item-tooltip,
        .bag-group:nth-last-child(-n+2) .item-tooltip {
            left: 50%;
            right: auto;
        }
        .has-tooltip:hover .item-tooltip,
        .has-tooltip:focus-within .item-tooltip,
        .has-tooltip:focus .item-tooltip {
            transform: translate(-50%, 0);
        }
    }

    /* ── VESTIDOR 3D ──────────────────────────────────────────── */
    .dressing-room-bar {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        margin-top: 0.6rem;
        padding-top: 0.6rem;
        border-top: 1px solid rgba(163,53,238,0.2);
    }
    .btn-dressing-room {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        background: linear-gradient(135deg, #1a0828 0%, #260c42 100%);
        border: 1px solid #a335ee;
        color: #d09cff;
        padding: 0.5rem 1.25rem;
        border-radius: 20px;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        letter-spacing: 0.03em;
        transition: box-shadow 0.2s, transform 0.12s, border-color 0.2s;
        box-shadow: 0 0 12px rgba(163,53,238,0.2);
    }
    .btn-dressing-room:hover {
        border-color: #c87fff;
        color: #e8c8ff;
        box-shadow: 0 0 22px rgba(163,53,238,0.55);
        transform: translateY(-1px);
    }
    .btn-dressing-room .dr-icon { font-size: 1rem; }
    .dr-hint {
        font-size: 0.65rem;
        color: #5a3a7a;
        letter-spacing: 0.04em;
    }

    /* ── Ícono cargando (spinner) ─────────────────────────────── */
    .pslot-spinner {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }
    .pslot-spinner::after {
        content: '';
        width: 14px; height: 14px;
        border: 2px solid rgba(255,255,255,0.1);
        border-top-color: rgba(255,255,255,0.4);
        border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }
    .pslot-icon.loaded ~ .pslot-spinner { display: none; }
    .pslot.empty .pslot-spinner { display: none; }

    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── CHARACTER SHEET (sección visible en página) ────────── */
    .char-sheet-wrap {
        background: linear-gradient(160deg, #0a0a16 0%, #0e0618 50%, #0f0808 100%);
        border: 1px solid var(--border-gold);
        border-radius: var(--radius-lg);
        padding: 1.25rem 1.25rem 1rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 0 50px rgba(200,168,75,0.08);
    }
    .char-sheet-wrap .cs-silhouette { width: 140px; height: 280px; }

    /* ── Character Sheet: 3 columnas igual que paperdoll pero más grande ── */
    .cs-grid {
        display: grid;
        grid-template-columns: 80px 1fr 80px;
        gap: 0 0.75rem;
        align-items: stretch; /* columna central se estira hasta la altura de los slots */
    }
    .cs-col {
        display: flex; flex-direction: column;
        gap: 6px;
        justify-content: space-between; /* slots distribuidos uniformemente */
    }
    .cs-col.right { align-items: flex-end; }

    /* Slot grande */
    .cs-slot {
        width: 68px; height: 68px;
        border: 2px solid #3a3a4a;
        border-radius: 5px;
        background: #0c0c14;
        position: relative;
        overflow: visible;
        flex-shrink: 0;
    }
    .cs-slot.empty { border: 1px dashed #222230; opacity: 0.4; }
    .cs-slot-icon {
        width: 100%; height: 100%;
        object-fit: cover;
        display: none;
        border-radius: 3px;
    }
    .cs-slot-icon.loaded { display: block; }
    .cs-slot-fallback {
        position: absolute; inset: 0; border-radius: 3px;
    }
    .cs-slot-icon.loaded + .cs-slot-fallback { display: none; }
    /* Calidades */
    .cs-slot.q0 { border-color: #9d9d9d; } .cs-slot.q0 .cs-slot-fallback { background: linear-gradient(135deg,#111,#222); }
    .cs-slot.q1 { border-color: #ccc; }     .cs-slot.q1 .cs-slot-fallback { background: linear-gradient(135deg,#161616,#232323); }
    .cs-slot.q2 { border-color: #1eff00; box-shadow: 0 0 7px rgba(30,255,0,0.3); } .cs-slot.q2 .cs-slot-fallback { background: linear-gradient(135deg,#071a05,#0e2e0a); }
    .cs-slot.q3 { border-color: #0070dd; box-shadow: 0 0 12px rgba(0,112,221,0.45); } .cs-slot.q3 .cs-slot-fallback { background: linear-gradient(135deg,#03091a,#071230); }
    .cs-slot.q4 { border-color: #a335ee; animation: epicGlow 2.5s ease-in-out infinite; } .cs-slot.q4 .cs-slot-fallback { background: linear-gradient(135deg,#120828,#1e0c42); }
    .cs-slot.q5 { border-color: #ff8000; animation: legGlow 1.8s ease-in-out infinite; } .cs-slot.q5 .cs-slot-fallback { background: linear-gradient(135deg,#240c00,#3c1600); }
    .cs-slot.q6, .cs-slot.q7 { border-color: #e6cc80; box-shadow: 0 0 8px rgba(230,204,128,0.4); } .cs-slot.q6 .cs-slot-fallback, .cs-slot.q7 .cs-slot-fallback { background: linear-gradient(135deg,#1a1608,#2e260e); }

    /* Nombre debajo del slot */
    .cs-slot-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
    .cs-col.right .cs-slot-wrap { align-items: flex-end; }
    .cs-slot-name {
        font-size: 0.6rem; color: var(--text-muted);
        max-width: 68px; overflow: hidden;
        text-overflow: ellipsis; white-space: nowrap;
        line-height: 1.2;
    }
    .cs-slot-name.has-item { color: var(--gold); font-weight: 600; }

    /* Centro del character sheet */
    .cs-center {
        display: flex; flex-direction: column;
        align-items: center; justify-content: flex-start;
        gap: 0.75rem; padding: 0 0.5rem;
        /* se estira con align-items:stretch del grid */
    }
    .cs-silhouette {
        width: 160px; height: 320px;
        filter: drop-shadow(0 0 24px rgba(200,168,75,0.3));
    }

    /* ── Armas + bolsas en el modal ── */
    .cs-weapon-bar {
        display: flex; justify-content: center; gap: 1.5rem;
        padding-top: 0.75rem;
        border-top: 1px solid rgba(122,95,26,0.3);
    }
    .cs-weapon-group { display: flex; flex-direction: column; align-items: center; gap: 3px; }
    .cs-weapon-label { font-size: 0.58rem; color: #5a5a7a; text-transform: uppercase; }

    /* ── Resumen de stats en modal ── */
    .cs-stats {
        display: flex; flex-wrap: wrap; gap: 0.5rem;
        justify-content: center;
        padding: 0.75rem;
        background: rgba(0,0,0,0.3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
    }
    .cs-stat-pill {
        background: rgba(200,168,75,0.07);
        border: 1px solid rgba(200,168,75,0.2);
        border-radius: 20px; padding: 3px 12px;
        font-size: 0.75rem;
        display: flex; gap: 5px; align-items: center;
    }
    .cs-stat-key { color: var(--text-muted); }
    .cs-stat-val { color: var(--gold-light); font-weight: 700; }

    .mv-footer {
        display: flex; justify-content: center; gap: 0.75rem;
        padding: 0.6rem 1rem; flex-shrink: 0;
        border-top: 1px solid var(--border);
    }
    /* Botón vestidor */
    .btn-dressing-room {
        background: linear-gradient(135deg, #1a0828 0%, #260c42 100%);
        border: 1px solid #a335ee;
        cursor: pointer;
    }

    /* ── VISOR 3D ──────────────────────────────────────────── */
    .model-3d-outer {
        position: relative;
        width: 100%;
        flex: 1;          /* se estira para ocupar el espacio de la columna central */
        min-height: 360px; /* cuerpo completo sin quedar apretado */
        background: transparent;
        overflow: hidden;
        touch-action: none;
        user-select: none;
    }
    #model-3d {
        width: 100%;
        height: 100%;
        background: transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        touch-action: none;
    }
    /* El canvas de ZamModelViewer ocupa todo el contenedor */
    #model-3d canvas {
        display: block;
        width: 100% !important;
        height: 100% !important;
        background: transparent !important;
        touch-action: none;
    }
    .model-3d-loading {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
        color: var(--text-muted);
        font-size: 0.72rem;
        pointer-events: none;
        z-index: 10;
    }
    .model-3d-loading .m3d-spin {
        width: 28px; height: 28px;
        border: 3px solid rgba(200,168,75,0.15);
        border-top-color: rgba(200,168,75,0.7);
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
    }
    </style>
</head>
<body class="paperdoll-page">

<nav class="navbar">
    <div class="nav-brand">⚔ Migrador AC</div>
    <div class="nav-right">
        <span class="nav-user"><?= htmlspecialchars($user->name()) ?></span>
        <a href="step1.php" class="btn btn-sm btn-outline">← Cambiar dump</a>
        <a href="../logout.php" class="btn btn-sm btn-outline"><?= t('logout') ?></a>
    </div>
</nav>

<main class="container">

<?php if ($error): ?>
<div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($dumpData): ?>

<!-- ═══════════════════════════════════════════════════════════
     CHARACTER SHEET — panel único con header + equipo + stats
════════════════════════════════════════════════════════════ -->
<div class="char-sheet-wrap">

    <!-- Cabecera del personaje -->
    <div class="char-header">
        <div class="char-name-big"><?= htmlspecialchars($origName ?: ($basic['name'] ?? 'Personaje')) ?></div>
        <div class="char-sub">
            Nivel <strong><?= (int)($basic['level'] ?? 0) ?></strong> &nbsp;·&nbsp;
            <?= htmlspecialchars($raceName) ?> &nbsp;·&nbsp;
            <?= htmlspecialchars($genderStr) ?>
        </div>
        <div>
            <span class="char-class-badge" style="color:<?= $clsColor ?>">
                <?= htmlspecialchars($clsName) ?>
            </span>
        </div>
    </div>

    <div class="cs-grid">

        <!-- Columna izquierda -->
        <div class="cs-col left">
            <?php foreach ($leftCol as $db):
                $s   = $paperSlots[$db] ?? null;
                $lbl = $slotLabels[$db] ?? "Slot {$db}";
                if (!$s): ?>
            <div class="cs-slot-wrap">
                <div class="cs-slot empty"></div>
                <span class="cs-slot-name"><?= htmlspecialchars($lbl) ?></span>
            </div>
            <?php else: $q = min(7, max(0, $s['quality'])); ?>
            <div class="cs-slot-wrap">
                <div class="cs-slot q<?= $q ?> has-tooltip" tabindex="0" style="--qcolor:<?= $qColor[$q] ?>">
                    <img class="cs-slot-icon" src="" data-entry="<?= (int)$s['entry'] ?>" alt="">
                    <span class="cs-slot-fallback"></span>
                    <?= renderItemTooltip($s, $qColor, $qName, $lbl) ?>
                </div>
                <span class="cs-slot-name has-item" style="color:<?= $qColor[$q] ?>" title="<?= htmlspecialchars($s['name']) ?>">
                    <?= htmlspecialchars($s['name']) ?>
                </span>
            </div>
            <?php endif; endforeach; ?>
        </div>

        <!-- Centro: Visor 3D -->
        <div class="cs-center">
            <div class="model-3d-outer">

                <!-- Contenedor del modelo 3D (ZamModelViewer inyecta el canvas aquí) -->
                <div id="model-3d"></div>

                <!-- Spinner de carga -->
                <div class="model-3d-loading" id="model-3d-loading">
                    <div class="m3d-spin"></div>
                    <span>Cargando modelo…</span>
                </div>

            </div><!-- /model-3d-outer -->

            <!-- Link WoWHead vestidor -->
            <a href="<?= htmlspecialchars($dressingRoomUrl) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline" style="font-size:0.7rem;padding:4px 12px;margin-top:8px">
               Ver en WoWHead ↗
            </a>
        </div>

        <!-- Columna derecha -->
        <div class="cs-col right">
            <?php foreach ($rightCol as $db):
                $s   = $paperSlots[$db] ?? null;
                $lbl = $slotLabels[$db] ?? "Slot {$db}";
                if (!$s): ?>
            <div class="cs-slot-wrap">
                <div class="cs-slot empty"></div>
                <span class="cs-slot-name"><?= htmlspecialchars($lbl) ?></span>
            </div>
            <?php else: $q = min(7, max(0, $s['quality'])); ?>
            <div class="cs-slot-wrap">
                <div class="cs-slot q<?= $q ?> has-tooltip" tabindex="0" style="--qcolor:<?= $qColor[$q] ?>">
                    <img class="cs-slot-icon" src="" data-entry="<?= (int)$s['entry'] ?>" alt="">
                    <span class="cs-slot-fallback"></span>
                    <?= renderItemTooltip($s, $qColor, $qName, $lbl) ?>
                </div>
                <span class="cs-slot-name has-item" style="color:<?= $qColor[$q] ?>" title="<?= htmlspecialchars($s['name']) ?>">
                    <?= htmlspecialchars($s['name']) ?>
                </span>
            </div>
            <?php endif; endforeach; ?>
        </div>
    </div><!-- /cs-grid -->

    <!-- Armas -->
    <div class="cs-weapon-bar">
        <?php foreach ($weaponRow as $db):
            $s   = $paperSlots[$db] ?? null;
            $lbl = $slotLabels[$db] ?? "Slot {$db}";
        ?>
        <div class="cs-weapon-group">
            <?php if ($s): $q = min(7, max(0, $s['quality'])); ?>
            <div class="cs-slot q<?= $q ?> has-tooltip" tabindex="0" style="--qcolor:<?= $qColor[$q] ?>">
                <img class="cs-slot-icon" src="" data-entry="<?= (int)$s['entry'] ?>" alt="">
                <span class="cs-slot-fallback"></span>
                <?= renderItemTooltip($s, $qColor, $qName, $lbl) ?>
            </div>
            <?php else: ?>
            <div class="cs-slot empty"></div>
            <?php endif; ?>
            <span class="cs-weapon-label"><?= htmlspecialchars($lbl) ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Bolsas equipadas ── -->
    <?php $hasBags = !empty(array_filter($bagRow, fn($db) => isset($paperSlots[$db]))); ?>
    <?php if ($hasBags): ?>
    <div class="bag-bar">
        <?php foreach ($bagRow as $db):
            $lbl = $slotLabels[$db] ?? "Bolsa";
        ?>
        <div class="bag-group">
            <?php
                $s = $paperSlots[$db] ?? null;
                if ($s): $q = min(7, max(0, $s['quality']));
            ?>
            <div class="cs-slot q<?= $q ?> has-tooltip" tabindex="0" style="--qcolor:<?= $qColor[$q] ?>; width:52px; height:52px;">
                <img class="cs-slot-icon" src="" data-entry="<?= (int)$s['entry'] ?>" alt="">
                <span class="cs-slot-fallback"></span>
                <?= renderItemTooltip($s, $qColor, $qName, $lbl) ?>
            </div>
            <?php else: ?>
            <div class="cs-slot empty" style="width:52px;height:52px;"></div>
            <?php endif; ?>
            <span class="bag-label"><?= htmlspecialchars($lbl) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Stats (oro, honor, arena) ── -->
    <div class="stats-bar">
        <div class="stat-pill">
            <span class="stat-pill-key">💰 Oro</span>
            <span class="stat-pill-val"><?= number_format($gold, 0, ',', '.') ?>g <?= $silver ?>s</span>
        </div>
        <?php if ($honor > 0): ?>
        <div class="stat-pill">
            <span class="stat-pill-key">⚔ Honor</span>
            <span class="stat-pill-val"><?= number_format($honor, 0, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <?php if ($arenapts > 0): ?>
        <div class="stat-pill">
            <span class="stat-pill-key">🏆 Arena</span>
            <span class="stat-pill-val"><?= number_format($arenapts, 0, ',', '.') ?></span>
        </div>
        <?php endif; ?>
        <div class="stat-pill">
            <span class="stat-pill-key">📦 Mochila</span>
            <span class="stat-pill-val"><?= count($bags) ?> items</span>
        </div>
        <div class="stat-pill">
            <span class="stat-pill-key">🏦 Banco</span>
            <span class="stat-pill-val"><?= count($bank) ?> items</span>
        </div>
        <div class="stat-pill">
            <span class="stat-pill-key">✨ Spells</span>
            <span class="stat-pill-val"><?= count($dumpData['spells'] ?? []) ?></span>
        </div>
        <div class="stat-pill">
            <span class="stat-pill-key">🔮 Talentos</span>
            <span class="stat-pill-val"><?= count($dumpData['talents'] ?? []) ?></span>
        </div>
        <?php if (!empty($dumpData['glyphs'])): ?>
        <div class="stat-pill">
            <span class="stat-pill-key">🌟 Glifos</span>
            <span class="stat-pill-val"><?= count($dumpData['glyphs']) ?></span>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /char-sheet-wrap -->

<!-- ═══════════════════════════════════════════════════════════
     RESUMEN: Mochila + Banco
════════════════════════════════════════════════════════════ -->
<?php if (!empty($bags) || !empty($bank)): ?>
<div class="inv-summary">

    <?php if (!empty($bags)): ?>
    <div class="inv-block">
        <div class="inv-block-title">🎒 Mochila (<?= count($bags) ?> items)</div>
        <div class="inv-item-list">
        <?php foreach (array_slice($bags, 0, 8) as $bi):
            $e    = (int)($bi['entry'] ?? 0);
            $id   = $itemMap[$e] ?? null;
            $name = $id ? $id->name : "Item #{$e}";
            $q    = $id ? (int)$id->Quality : 1;
            $cnt  = (int)($bi['count'] ?? 1);
        ?>
            <div class="inv-item has-tooltip" tabindex="0" style="--qcolor:<?= $qColor[$q] ?? '#fff' ?>">
                <span class="inv-item-name" style="color:<?= $qColor[$q] ?? '#fff' ?>"><?= htmlspecialchars($name) ?></span>
                <span class="inv-item-count">×<?= $cnt ?></span>
                <?= renderInventoryTooltip($bi, $id, $qColor, $qName, 'Mochila') ?>
            </div>
        <?php endforeach; ?>
        <?php if (count($bags) > 8): ?>
            <div class="inv-more">… y <?= count($bags) - 8 ?> más</div>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($bank)): ?>
    <div class="inv-block">
        <div class="inv-block-title">🏦 Banco (<?= count($bank) ?> items)</div>
        <div class="inv-item-list">
        <?php foreach (array_slice($bank, 0, 8) as $bi):
            $e    = (int)($bi['entry'] ?? 0);
            $id   = $itemMap[$e] ?? null;
            $name = $id ? $id->name : "Item #{$e}";
            $q    = $id ? (int)$id->Quality : 1;
            $cnt  = (int)($bi['count'] ?? 1);
        ?>
            <div class="inv-item has-tooltip" tabindex="0" style="--qcolor:<?= $qColor[$q] ?? '#fff' ?>">
                <span class="inv-item-name" style="color:<?= $qColor[$q] ?? '#fff' ?>"><?= htmlspecialchars($name) ?></span>
                <span class="inv-item-count">×<?= $cnt ?></span>
                <?= renderInventoryTooltip($bi, $id, $qColor, $qName, 'Banco') ?>
            </div>
        <?php endforeach; ?>
        <?php if (count($bank) > 8): ?>
            <div class="inv-more">… y <?= count($bank) - 8 ?> más</div>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     FORMULARIO DE CONFIRMACIÓN
════════════════════════════════════════════════════════════ -->
<div class="confirm-section">
    <div class="confirm-title">📋 Confirmar transferencia</div>
    <p class="text-muted" style="font-size:.85rem;margin-bottom:1rem">
        Revisa que todo esté correcto en el panel de arriba.
        Puedes cambiar el nombre si ya está en uso en este servidor.
        Al confirmar, la transferencia quedará <strong>pendiente de aprobación GM</strong>.
    </p>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:.75rem"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="token" value="<?= $token ?>">
        <div class="form-group">
            <label for="char_name">Nombre del personaje en el servidor</label>
            <input type="text" name="char_name" id="char_name"
                   value="<?= htmlspecialchars($origName) ?>"
                   minlength="2" maxlength="12"
                   pattern="[a-zA-Z]{2,12}"
                   placeholder="Ej: Traspaso"
                   required>
            <small class="hint">Solo letras, 2-12 caracteres.</small>
        </div>
        <button type="submit" class="btn-confirm">
            ✅ Confirmar y enviar solicitud al GM
        </button>
    </form>
</div>

<?php else: ?>
<!-- Dump no es JSON — formulario simple -->
<div class="card">
    <div class="card-body">
        <div class="alert alert-error">El dump no tiene un formato reconocible. <a href="step1.php">Vuelve al paso 1</a>.</div>
    </div>
</div>
<?php endif; ?>


</main>

<!-- ════ CARGA ASÍNCRONA DE ÍCONOS ════ -->
<script>
(function() {
    const proxyBase = '../api/icon.php?entry=';
    const iconBase  = 'https://wow.zamimg.com/images/wow/icons/large/';

    document.querySelectorAll('.cs-slot-icon[data-entry]').forEach((img, i) => {
        const entry = img.dataset.entry;
        if (!entry) return;
        setTimeout(() => {
            fetch(proxyBase + entry, { cache: 'force-cache' })
                .then(r => r.ok ? r.text() : '')
                .then(icon => {
                    if (!icon || icon.length < 2) return;
                    img.onload  = () => img.classList.add('loaded');
                    img.onerror = () => {};
                    img.src = iconBase + icon.trim() + '.jpg';
                }).catch(() => {});
        }, i * 12);
    });
})();
</script>

<!-- ════ VISOR 3D — WoW Model Viewer ════ -->
<script type="module">
(async function() {
    const elViewer  = document.getElementById('model-3d');
    const elLoading = document.getElementById('model-3d-loading');
    const elStatus  = elLoading ? elLoading.querySelector('span') : null;

    function log(msg) {
        console.log('[3D]', msg);
        if (elStatus) elStatus.textContent = msg;
    }

    if (!elViewer) return;

    // ── PASO 1: Interceptar getContext para forzar alpha en WebGL ────────────
    // Debe hacerse ANTES de cargar viewer.min.js — así el viewer crea
    // el contexto WebGL con soporte de canal alfa (transparencia real).
    const _origGetCtx = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function(type, opts) {
        if (type === 'webgl' || type === 'webgl2' || type === 'experimental-webgl') {
            opts = Object.assign({}, opts || {}, { alpha: true, premultipliedAlpha: false });
        }
        return _origGetCtx.call(this, type, opts);
    };

    // ── PASO 2: Interceptar clearColor para fondo siempre transparente ───────
    // ZamModelViewer llama gl.clearColor(r,g,b,1) cada frame → forzamos a=0.
    const _patchClearColor = (proto) => {
        const _orig = proto.clearColor;
        proto.clearColor = function(r, g, b, a) { _orig.call(this, r, g, b, 0); };
    };
    _patchClearColor(WebGLRenderingContext.prototype);
    if (window.WebGL2RenderingContext) _patchClearColor(WebGL2RenderingContext.prototype);

    // ── Variables globales del viewer ────────────────────────────────────────
    // CONTENT_PATH necesario para que ZamModelViewer cargue los assets del modelo
    window.CONTENT_PATH = <?= json_encode($model3dContent) ?>;

    // charData incluye items ya convertidos a Retail displayId (conversión hecha en PHP)
    const charData = <?= json_encode($modelChar) ?>;
    charData.items = <?= json_encode($modelItems) ?>; // [[viewerSlot, retailDisplayId, enchant], ...]

    function loadScript(src, timeoutMs = 20000) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Timeout')), timeoutMs);
            const s = document.createElement('script');
            s.src = src;
            s.onload  = () => { clearTimeout(timer); resolve(); };
            s.onerror = () => { clearTimeout(timer); reject(new Error('Error cargando script')); };
            document.head.appendChild(s);
        });
    }

    function lockModelCamera(model, canvas) {
        const fixedDistance = 6.4;
        const fixedTarget = { x: 0, y: 0.95, z: 0 };

        const tryCall = (obj, names, ...args) => {
            if (!obj) return false;
            for (const name of names) {
                if (typeof obj[name] === 'function') {
                    try { obj[name](...args); return true; } catch {}
                }
            }
            return false;
        };

        const applyCamera = () => {
            tryCall(model, ['setDistance', 'setZoom'], fixedDistance);
            tryCall(model, ['setTarget', 'setPivot', 'setCenter'], fixedTarget.x, fixedTarget.y, fixedTarget.z);
            tryCall(model, ['setPan', 'setPositionOffset'], 0, 0, 0);

            const controls = model?.controls || model?.control || model?.orbitControls || model?.cameraControls;
            if (controls) {
                controls.enableZoom = false;
                controls.enablePan = false;
                controls.screenSpacePanning = false;
                controls.minDistance = fixedDistance;
                controls.maxDistance = fixedDistance;
                controls.target?.set?.(fixedTarget.x, fixedTarget.y, fixedTarget.z);
                controls.mouseButtons = Object.assign({}, controls.mouseButtons || {}, {
                    LEFT: controls.mouseButtons?.LEFT ?? 0,
                    MIDDLE: -1,
                    RIGHT: -1,
                });
                controls.touches = Object.assign({}, controls.touches || {}, {
                    ONE: controls.touches?.ONE ?? 0,
                    TWO: -1,
                });
                controls.update?.();
            }

            if (model?.camera) {
                model.camera.zoom = 1;
                model.camera.updateProjectionMatrix?.();
            }
        };

        applyCamera();
        setTimeout(applyCamera, 250);
        setTimeout(applyCamera, 1000);

        if (!canvas) return;
        canvas.style.background = 'transparent';
        canvas.style.touchAction = 'none';
        canvas.tabIndex = 0;
        canvas.addEventListener('contextmenu', e => e.preventDefault(), { capture: true });
        canvas.addEventListener('wheel', e => e.preventDefault(), { passive: false, capture: true });

        canvas.addEventListener('pointerdown', e => {
            if (e.button !== 0 || e.ctrlKey || e.shiftKey || e.altKey || e.metaKey) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            applyCamera();
        }, { passive: false, capture: true });

        canvas.addEventListener('pointermove', e => {
            if ((e.buttons & 2) || (e.buttons & 4) || e.ctrlKey || e.shiftKey || e.altKey || e.metaKey) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, { passive: false, capture: true });

        canvas.addEventListener('touchmove', e => {
            if (e.touches.length > 1) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        }, { passive: false, capture: true });
    }

    try {
        // 3. viewer.min.js — primero directo desde zamimg, luego vía proxy
        log('Cargando viewer…');
        const viewerDirect = 'https://wow.zamimg.com/modelviewer/live/viewer/viewer.min.js';
        const viewerProxy  = <?= json_encode($model3dViewer) ?>;
        try {
            await loadScript(viewerDirect, 12000);
            log('Viewer OK (directo)');
        } catch {
            log('Viewer vía proxy…');
            await loadScript(viewerProxy, 20000);
            log('Viewer OK (proxy)');
        }

        // 4. wow-model-viewer desde esm.sh (solo necesitamos generateModels)
        log('Cargando librería…');
        const { generateModels } = await import('https://esm.sh/wow-model-viewer@1.5.3');

        // 5. Renderizar — items ya incluidos en charData.items (convertidos en PHP)
        log('Renderizando…');
        const model = await generateModels(1, '#model-3d', charData);

        if (model && typeof model.updateItemViewer === 'function' && Array.isArray(charData.items)) {
            for (const item of charData.items) {
                const [slot, displayId, enchant = 0] = item;
                if (slot > 0 && displayId > 0) {
                    try { model.updateItemViewer(slot, displayId, enchant); } catch (e) {
                        console.warn('[3D] No se pudo equipar item', item, e);
                    }
                }
            }
        }

        // 7. Canvas sin fondo + cámara fija: solo rotación permitida ─────────
        const canvas = elViewer.querySelector('canvas');
        lockModelCamera(model, canvas);

        elLoading.style.display = 'none';

    } catch (err) {
        log('Error: ' + (err.message || err));
        console.error('[3D Viewer]', err);
    }

})();
</script>

<script src="../assets/js/app.js"></script>
</body>
</html>
