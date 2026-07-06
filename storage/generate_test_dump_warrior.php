<?php
/**
 * generate_test_dump_warrior.php — Genera un chardump.lua de prueba (v2)
 * de un Guerrero nivel 80 con equipo de placa real, para probar la web.
 * Uso: php generate_test_dump_warrior.php
 */

$data = [
    'version'     => 2,
    'exported_at' => time(),
    'basic' => [
        'name'      => 'PruebaTank',
        'class'     => 1,   // Warrior
        'race'      => 2,   // Orc
        'gender'    => 0,   // Male
        'level'     => 80,
        'zone'      => 'Orgrimmar',
        'copper'    => 15_000_000,  // 1500g
        'xp'        => 0,
        'honor'     => 3000,
        'arena_pts' => 800,
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
    // Skills de arma/armadura/defensa de la clase los agrega
    // CharacterImporter automaticamente (playercreateinfo_skills) - no
    // hace falta listarlos a mano aca. Solo Riding, que no depende de
    // la clase.
    'skills' => [
        ['id' => 762, 'name' => 'Riding', 'value' => 150, 'max' => 150],
    ],
    'spells' => [
        100, 772, 78, 23922, 2565, 20243, 6343, 46968, 12809, 6673,
        1160, 18499, 6572, 871, 355, 694, 676, 285,
        1715, 2687, 5308, 3127,
    ],
    // Talentos/glifos omitidos a proposito: talent_dbc/talenttab_dbc estan
    // vacias en esta DB (son solo tablas auxiliares para el preview web, no
    // la fuente real del servidor), asi que no hay forma confiable de
    // validar un spell id de talento/glifo por SQL antes de insertarlo.
    // Un id inventado/incorrecto en `character_talent` crashea el
    // worldserver al cargar el personaje (confirmado: crash con
    // spell #16955 al testear esto) - mas vale dejarlo vacio que arriesgar
    // otro crash con un numero no verificado.
    'talents' => [],
    'glyphs' => [],
    'reputations' => [
        ['id' => 76,   'name' => 'Orgrimmar',       'value' => 42999, 'standing' => 8],
        ['id' => 1052, 'name' => 'Warsong Offensive','value' => 21000, 'standing' => 7],
        ['id' => 1011, 'name' => 'Argent Dawn',     'value' => 42999, 'standing' => 8],
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

file_put_contents(__DIR__ . '/chardump_warrior_qa.lua', $luaContent);
file_put_contents(__DIR__ . '/chardump_warrior_qa_raw.json', $json);
fwrite(STDERR, "[OK] Generado: " . __DIR__ . "/chardump_warrior_qa.lua (" . strlen($encoded) . " bytes cifrados)\n");
