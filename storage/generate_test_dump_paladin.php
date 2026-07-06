<?php
/**
 * generate_test_dump_paladin.php — Genera un chardump.lua de prueba (v2)
 * de un Paladin nivel 80 con profesion, para probar el import completo
 * (barra de acciones + skills de clase + Plate Mail especial + profesion).
 * Uso: php generate_test_dump_paladin.php
 */

$data = [
    'version'     => 2,
    'exported_at' => time(),
    'basic' => [
        'name'      => 'PruebaPala',
        'class'     => 2,   // Paladin
        'race'      => 11,  // Draenei
        'gender'    => 0,   // Male
        'level'     => 80,
        'zone'      => 'The Exodar',
        'copper'    => 8_000_000,   // 800g
        'xp'        => 0,
        'honor'     => 500,
        'arena_pts' => 0,
    ],
    'equipped' => [
        ['slot' => 1,  'entry' => 50701, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Head
        ['slot' => 2,  'entry' => 54581, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Neck
        ['slot' => 3,  'entry' => 50660, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Shoulder
        ['slot' => 4,  'entry' => 45,    'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Shirt
        ['slot' => 5,  'entry' => 50681, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Chest
        ['slot' => 6,  'entry' => 50667, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Waist
        ['slot' => 7,  'entry' => 50624, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Legs
        ['slot' => 8,  'entry' => 54579, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Feet
        ['slot' => 9,  'entry' => 54584, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Wrist
        ['slot' => 10, 'entry' => 50716, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Hands
        ['slot' => 11, 'entry' => 54576, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Finger1
        ['slot' => 12, 'entry' => 50398, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Finger2
        ['slot' => 13, 'entry' => 54588, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Trinket1
        ['slot' => 14, 'entry' => 54590, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Trinket2
        ['slot' => 15, 'entry' => 54583, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Back
        ['slot' => 16, 'entry' => 50738, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Main Hand (1H mace)
        ['slot' => 17, 'entry' => 50616, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1], // Off Hand (shield)
    ],
    'bags' => [
        ['bag' => 0, 'slot' => 1, 'entry' => 858,  'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 10],
        ['bag' => 0, 'slot' => 2, 'entry' => 929,  'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 8],
        ['bag' => 0, 'slot' => 3, 'entry' => 6948, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1],
    ],
    'bank' => [
        ['bag' => -1, 'slot' => 39, 'entry' => 3820, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 15],
        ['bag' => -1, 'slot' => 40, 'entry' => 8007, 'ench' => 0, 'gem1' => 0, 'gem2' => 0, 'gem3' => 0, 'count' => 1],
    ],
    // Skills de clase (armas/armadura/defensa, incluye Plate Mail para
    // Paladin) y Riding los agrega CharacterImporter automaticamente.
    // Blacksmithing va a un valor bajo a proposito, para probar que el
    // import lo sube a 400.
    'skills' => [
        ['id' => 762, 'name' => 'Riding',       'value' => 150, 'max' => 150],
        ['id' => 164, 'name' => 'Blacksmithing','value' => 50,  'max' => 375],
    ],
    'spells' => [
        642, 465, 633, 853, 879, 1152, 1022, 6940,
        20164, 20165, 20166, 25780, 31789, 31801,
        31884, 48932, 48934, 48936, 48941, 48943, 48945,
    ],
    // Talentos/glifos omitidos - no hay forma confiable de validarlos
    // por SQL en esta DB (ver mod-default-actionbar/README.md, mismo
    // motivo por el que se retiro ese modulo).
    'talents' => [],
    'glyphs' => [],
    'reputations' => [
        ['id' => 730,  'name' => 'Exodar',        'value' => 42999, 'standing' => 8],
        ['id' => 1011, 'name' => 'Argent Dawn',   'value' => 42999, 'standing' => 8],
        ['id' => 1106, 'name' => 'Argent Crusade','value' => 21000, 'standing' => 7],
    ],
    'quests' => [],
];

$json = json_encode($data, JSON_UNESCAPED_UNICODE);
$encoded = strrev(base64_encode($json));

$luaContent = "ChardumpDB = {\n"
    . "\t[\"last\"] = {\n"
    . "\t\t[\"char\"] = \"{$data['basic']['name']}\",\n"
    . "\t\t[\"level\"] = {$data['basic']['level']},\n"
    . "\t\t[\"data\"] = \"{$encoded}\",\n"
    . "\t\t[\"time\"] = {$data['exported_at']},\n"
    . "\t},\n"
    . "}\n";

file_put_contents(__DIR__ . '/chardump_paladin_qa.lua', $luaContent);
file_put_contents(__DIR__ . '/chardump_paladin_qa_raw.json', $json);
fwrite(STDERR, "[OK] Generado: " . __DIR__ . "/chardump_paladin_qa.lua (" . strlen($encoded) . " bytes cifrados)\n");
