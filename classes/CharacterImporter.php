<?php
/**
 * CharacterImporter.php — Importa un personaje desde el dump JSON del addon chardump v2
 * Compatible: AzerothCore WotLK 3.3.5a — última revisión
 *
 * Flujo:
 *   1. Recibe el JSON descifrado (ya pasado por decryptDump())
 *   2. Genera un nuevo GUID único en el realm
 *   3. Inserta en todas las tablas necesarias (characters, inventory, skills, etc.)
 *   4. Items que no caben en el inventario se envían por correo
 *   5. Todo en una sola transacción PDO
 */
class CharacterImporter
{
    private PDO    $pdo;
    private int    $realmId;
    private int    $accountId;
    private array  $data;
    private int    $newGuid;
    private int    $nextItemGuid = 0; // contador global de item GUIDs dentro del import

    // Posiciones de spawn seguras por facción (mapa, x, y, z, orientación)
    private const SPAWN_ALLIANCE = ['map' => 0,  'x' => -8833.38, 'y' => 628.62,  'z' => 94.00,  'o' => 0.0];
    private const SPAWN_HORDE    = ['map' => 1,  'x' => 1569.59,  'y' => -4397.63,'z' => 16.06,  'o' => 0.0];

    // Object::_LoadIntoDataField() (Object.cpp) exige exactamente N enteros
    // separados por espacio o descarta el campo entero como "invalido" -
    // NULL/vacio no es aceptado, aunque el valor final sea "todo en cero".
    // PLAYER_EXPLORED_ZONES_SIZE = 128, KNOWN_TITLES_SIZE * 2 = 6 (Player.h)
    private const EMPTY_EXPLORED_ZONES = '0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0';
    private const EMPTY_KNOWN_TITLES   = '0 0 0 0 0 0';

    // Razas y su facción: 0=Alliance, 1=Horde
    private const RACE_FACTION = [
        1 => 0, // Human
        2 => 1, // Orc
        3 => 0, // Dwarf
        4 => 0, // NightElf
        5 => 1, // Undead
        6 => 1, // Tauren
        7 => 0, // Gnome
        8 => 1, // Troll
        10 => 1,// BloodElf
        11 => 0,// Draenei
    ];

    // Nombre legible de cada slot de equipo (base-0 = DB, base-1 = Lua)
    // DB slots 0-18 = equipo; DB slots 19-22 = contenedores de bolsas equipadas
    // Valores = claves de traducción (transfer/language.php), resueltas con t() en el consumidor.
    public const SLOT_NAMES = [
        0  => 'slot_head',
        1  => 'slot_neck',
        2  => 'slot_shoulders',
        3  => 'slot_shirt',
        4  => 'slot_chest',
        5  => 'slot_waist',
        6  => 'slot_legs',
        7  => 'slot_feet',
        8  => 'slot_wrists',
        9  => 'slot_hands',
        10 => 'slot_finger1',
        11 => 'slot_finger2',
        12 => 'slot_trinket1',
        13 => 'slot_trinket2',
        14 => 'slot_back',
        15 => 'slot_mainhand',
        16 => 'slot_offhand',
        17 => 'slot_ranged',
        18 => 'slot_tabard',
        19 => 'slot_bag1',
        20 => 'slot_bag2',
        21 => 'slot_bag3',
        22 => 'slot_bag4',
    ];

    /**
     * Mapeo DB slot (0-18) → InventoryType(s) permitidos según DBC WotLK 3.3.5a.
     *
     * InventoryType en item_template:
     *   1=Head  2=Neck  3=Shoulders  4=Shirt  5=Chest  6=Waist  7=Legs
     *   8=Feet  9=Wrists  10=Hands  11=Finger  12=Trinket
     *   13=Weapon(1H)  14=Shield  15=Ranged  16=Cloak  17=2HWeapon
     *   18=Bag  19=Tabard  20=Robe  21=MainHand  22=OffHand  23=Holdable
     *   24=Ammo  25=Thrown  26=RangedRight(wand)  28=Relic
     */
    private const SLOT_INVENTORY_TYPES = [
        0  => [1],              // Head
        1  => [2],              // Neck
        2  => [3],              // Shoulders
        3  => [4],              // Shirt / Body
        4  => [5, 20],          // Chest / Robe
        5  => [6],              // Waist
        6  => [7],              // Legs
        7  => [8],              // Feet
        8  => [9],              // Wrists
        9  => [10],             // Hands
        10 => [11],             // Finger 1
        11 => [11],             // Finger 2
        12 => [12],             // Trinket 1
        13 => [12],             // Trinket 2
        14 => [16],             // Back / Cloak
        15 => [13, 17, 21],     // Main Hand (1H, 2H, mainhand-only)
        16 => [13, 14, 22, 23], // Off Hand (1H dual, shield, offhand, holdable)
        17 => [15, 25, 26, 28], // Ranged slot (bow/gun/xbow, thrown, wand, relic)
        18 => [19],             // Tabard
        // Slots de contenedores de bolsas (DB 19-22 = Lua 20-23)
        19 => [18],             // Bag slot 1
        20 => [18],             // Bag slot 2
        21 => [18],             // Bag slot 3
        22 => [18],             // Bag slot 4
    ];

    public function __construct(int $realmId, int $accountId)
    {
        $this->realmId   = $realmId;
        $this->accountId = $accountId;
        $this->pdo       = DB::chars($realmId)->getPdo();
    }

    /**
     * Punto de entrada principal.
     * @param string $json JSON descifrado del addon
     * @return array ['guid' => int, 'name' => string] en éxito
     * @throws RuntimeException en error
     */
    public function import(string $json): array
    {
        $this->data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->validate();
        $this->loadValidEntries();

        $this->pdo->beginTransaction();
        try {
            $this->newGuid      = $this->getNextCharGuid();
            $this->nextItemGuid = $this->getNextItemGuidBase();

            $equipmentCache = $this->buildEquipmentCache();

            $this->insertCharacter($equipmentCache);
            $excessInventory = $this->insertInventory();
            $excessBank      = $this->insertBank();
            $this->insertSkills();
            $this->insertSpells();
            $this->insertTalents();
            $this->insertGlyphs();
            $this->insertReputations();
            $this->insertHomebind();
            $this->insertActionBar();

            // Enviar por correo los items que no cupieron
            $allExcess = array_merge($excessInventory, $excessBank);
            if (!empty($allExcess)) {
                $this->mailExcessItems($allExcess);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('CharacterImporter: ' . $e->getMessage(), 0, $e);
        }

        // Actualizar realmcharacters en acore_auth (fuera de la transacción de chars)
        $this->updateRealmCharacters();

        return ['guid' => $this->newGuid, 'name' => $this->data['basic']['name']];
    }

    // ── Validación mínima ────────────────────────────────────

    private function validate(): void
    {
        $basic = $this->data['basic'] ?? null;
        if (!$basic || empty($basic['name'])) {
            throw new RuntimeException('Dump inválido: falta información básica del personaje.');
        }
        $level = (int) ($basic['level'] ?? 0);
        if ($level < 1 || $level > MAX_LEVEL) {
            throw new RuntimeException("Nivel de personaje inválido: {$level}.");
        }
        if (strlen($basic['name']) < 2 || strlen($basic['name']) > 16) {
            throw new RuntimeException('Nombre de personaje inválido.');
        }
    }

    // ── Generadores de GUIDs ─────────────────────────────────

    private function getNextCharGuid(): int
    {
        $row = $this->pdo->query('SELECT MAX(`guid`) AS m FROM `characters`')->fetch(PDO::FETCH_OBJ);
        return (int)($row->m ?? 0) + 1;
    }

    private function getNextItemGuidBase(): int
    {
        $row = $this->pdo->query('SELECT MAX(`guid`) AS m FROM `item_instance`')->fetch(PDO::FETCH_OBJ);
        return (int)($row->m ?? 0) + 1;
    }

    /** Devuelve el siguiente GUID de item y avanza el contador interno */
    private function nextItemGuid(): int
    {
        return $this->nextItemGuid++;
    }

    // ── equipmentCache ───────────────────────────────────────
    //
    // Formato: 23 pares "displayId enchId " (19 equip + 4 bolsas)
    // displayId viene de acore_world.item_template.displayid
    // enchId = visual del encantamiento (0 simplificado)
    //
    // También carga los InventoryType de los items equipados en
    // $this->equippedItemMeta para que insertInventory() los valide.

    /** @var array<int,object{displayid:int,InventoryType:int}> entry → datos world */
    private array $equippedItemMeta = [];

    /** @var array<int,true> entries que realmente existen en item_template */
    private array $validEntries = [];

    /**
     * Carga en $validEntries todos los entries de equipped/bags/bank que
     * realmente existen en item_template. Un dump manipulado (o
     * desincronizado con la versión de item_template del servidor) puede
     * traer entries inexistentes; sin este chequeo se insertarían igual
     * en item_instance/character_inventory, dejando referencias muertas
     * que el cliente/servidor no pueden resolver al cargar el personaje.
     */
    private function loadValidEntries(): void
    {
        $entries = [];
        foreach (['equipped', 'bags', 'bank'] as $bucket) {
            foreach (($this->data[$bucket] ?? []) as $item) {
                $entry = $this->convertItem((int)($item['entry'] ?? 0));
                if ($entry > 0) $entries[] = $entry;
            }
        }
        $entries = array_unique($entries);
        if (empty($entries)) return;

        try {
            $in   = implode(',', $entries);
            $rows = $this->pdo->query(
                "SELECT entry FROM acore_world.item_template WHERE entry IN ({$in})"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $entry) {
                $this->validEntries[(int)$entry] = true;
            }
        } catch (Throwable $e) {
            // Si el lookup falla, no bloqueamos el import completo por esto -
            // isValidEntry() tratará todo como inválido y los items se
            // omitirán (más seguro que insertar entries sin verificar).
            error_log('[Migrador] loadValidEntries falló: ' . $e->getMessage());
        }
    }

    /** ¿El entry existe realmente en item_template? */
    private function isValidEntry(int $entry): bool
    {
        return isset($this->validEntries[$entry]);
    }

    private function buildEquipmentCache(): string
    {
        $equipped = $this->data['equipped'] ?? [];

        // Mapa slot-DB(0-22) => entry (ya convertido)
        // DB slots 0-18 = equipo normal; DB slots 19-22 = contenedores de bolsas (Lua 20-23)
        $slotEntry = [];
        foreach ($equipped as $item) {
            $luaSlot = (int)($item['slot'] ?? 0);
            $dbSlot  = $luaSlot - 1;
            if ($dbSlot < 0 || $dbSlot > 22) continue;
            $entry = (int)($item['entry'] ?? 0);
            if ($entry <= 0) continue;
            if ($this->isBlockedItem($entry)) continue;
            $slotEntry[$dbSlot] = $this->convertItem($entry);
        }

        // Batch lookup displayid + InventoryType desde world
        $this->equippedItemMeta = [];
        if (!empty($slotEntry)) {
            $entryList = implode(',', array_unique(array_values($slotEntry)));
            try {
                $rows = $this->pdo->query(
                    "SELECT entry, displayid, InventoryType
                     FROM acore_world.item_template
                     WHERE entry IN ({$entryList})"
                )->fetchAll(PDO::FETCH_OBJ);
                foreach ($rows as $r) {
                    $this->equippedItemMeta[(int)$r->entry] = $r;
                }
            } catch (Throwable) {
                // Si el lookup falla, el cache visual queda vacío (se reconstruye al login)
            }
        }

        // Construir los 23 pares (DB slots 0-22); validar InventoryType antes de incluir en cache
        // Slots 0-18 = equipo; Slots 19-22 = bolsas equipadas
        //
        // IMPORTANTE: cada par es "itemEntry enchantId", NO "displayId enchantId".
        // Player::BuildEnumData (Player.cpp) busca el ItemTemplate por entry y
        // recién ahí lee su DisplayInfoID - guardar el displayid directamente
        // aquí hacía que ese lookup buscara un item_template.entry que no
        // existe (o, peor, uno real pero equivocado), rompiendo el enum de
        // personajes de la cuenta entera al loguear.
        $parts = [];
        for ($s = 0; $s < 23; $s++) {
            $cacheEntry = 0;
            $entry = $slotEntry[$s] ?? 0;
            if ($entry > 0) {
                $meta    = $this->equippedItemMeta[$entry] ?? null;
                $invType = $meta ? (int)$meta->InventoryType : 0;
                if ($meta && $this->isValidForSlot($s, $invType)) {
                    $cacheEntry = $entry;
                }
            }
            $parts[] = "{$cacheEntry} 0";
        }
        return implode(' ', $parts) . ' ';
    }

    /**
     * Verifica si un InventoryType es válido para el slot de equipo DB dado (0-18).
     * Un InventoryType=0 (no equipable) nunca es válido en un slot de equipo.
     */
    private function isValidForSlot(int $dbSlot, int $inventoryType): bool
    {
        if ($inventoryType <= 0) return false;
        $allowed = self::SLOT_INVENTORY_TYPES[$dbSlot] ?? [];
        return in_array($inventoryType, $allowed, true);
    }

    /**
     * Batch-carga el InventoryType de un array de entries desde acore_world.
     * @param int[] $entries
     * @return array<int,int> entry => InventoryType
     */
    private function loadInventoryTypes(array $entries): array
    {
        $entries = array_unique(array_filter(array_map('intval', $entries)));
        if (empty($entries)) return [];

        $map = [];
        try {
            $in   = implode(',', $entries);
            $rows = $this->pdo->query(
                "SELECT entry, InventoryType FROM acore_world.item_template WHERE entry IN ({$in})"
            )->fetchAll(PDO::FETCH_OBJ);
            foreach ($rows as $r) {
                $map[(int)$r->entry] = (int)$r->InventoryType;
            }
        } catch (Throwable) {
            // Si falla, no validamos (items irán al slot de todas formas)
        }
        return $map;
    }

    // ── INSERT principal de characters ───────────────────────

    private function insertCharacter(string $equipmentCache = ''): void
    {
        $b    = $this->data['basic'];
        $name = substr($b['name'], 0, 12); // varchar(12)

        $race = $b['race'] ?? 1;
        $cls  = $b['class'] ?? 1;

        $raceId  = is_numeric($race) ? (int)$race : $this->raceNameToId((string)$race);
        $classId = is_numeric($cls)  ? (int)$cls  : $this->classNameToId((string)$cls);

        $faction = self::RACE_FACTION[$raceId] ?? 0;
        $spawn   = $faction === 1 ? self::SPAWN_HORDE : self::SPAWN_ALLIANCE;

        $copper   = min((int)($b['copper']    ?? 0), MAX_COPPER);
        $honor    = min((int)($b['honor']     ?? 0), MAX_HONOR);
        $arenapts = min((int)($b['arena_pts'] ?? 0), MAX_ARENA_POINTS);
        $level    = max(1, min((int)($b['level'] ?? 1), MAX_LEVEL));
        $xp       = (int)($b['xp'] ?? 0);

        // Zona de spawn: 1519 = Stormwind, 1637 = Orgrimmar
        $zone = $faction === 1 ? 1637 : 1519;

        // at_login = 5 (AT_LOGIN_RENAME | AT_LOGIN_RESET_TALENTS): fuerza el
        // dialogo de renombrar en el primer login (el jugador confirmo su
        // nombre en la web, pero esto obliga al cliente a re-registrar el
        // personaje como "nuevo" localmente) y resetea talentos ya que se
        // insertaron directo en character_talent sin pasar por el sistema
        // de puntos de talento del cliente.
        //
        // cinematic = 1: marca el video de introduccion de la raza como ya
        // visto, para que un personaje migrado no lo dispare al entrar por
        // primera vez (como si fuera recien creado).
        $stmt = $this->pdo->prepare(
            'INSERT INTO `characters`
             (`guid`,`account`,`name`,`race`,`class`,`gender`,`level`,`xp`,
              `money`,`skin`,`face`,`hairStyle`,`hairColor`,`facialStyle`,
              `bankSlots`,`restState`,`playerFlags`,
              `map`,`position_x`,`position_y`,`position_z`,`orientation`,
              `instance_id`,`instance_mode_mask`,`taximask`,`online`,`cinematic`,
              `totaltime`,`leveltime`,`logout_time`,`is_logout_resting`,`rest_bonus`,
              `resettalents_cost`,`resettalents_time`,
              `trans_x`,`trans_y`,`trans_z`,`trans_o`,`transguid`,
              `extra_flags`,`stable_slots`,`at_login`,`zone`,
              `death_expire_time`,`taxi_path`,
              `arenaPoints`,`totalHonorPoints`,`todayHonorPoints`,`yesterdayHonorPoints`,
              `totalKills`,`todayKills`,`yesterdayKills`,`chosenTitle`,
              `knownCurrencies`,`watchedFaction`,`drunk`,`health`,
              `power1`,`power2`,`power3`,`power4`,`power5`,`power6`,`power7`,
              `latency`,`talentGroupsCount`,`activeTalentGroup`,
              `exploredZones`,`equipmentCache`,`ammoId`,`knownTitles`,
              `actionBars`,`grantableLevels`,
              `deleteInfos_Account`,`deleteDate`,`deleteInfos_Name`,
              `innTriggerId`,`extraBonusTalentCount`)
             VALUES
             (?,?,?,?,?,?,?,?,
              ?,0,0,0,0,0,
              0,0,0,
              ?,?,?,?,?,
              0,0,"",0,1,
              0,0,0,0,0.0,
              0,0,
              0,0,0,0,0,
              0,0,5,?,
              0,NULL,
              ?,?,0,0,
              0,0,0,0,
              0,0,0,0,
              0,0,0,0,0,0,0,
              0,1,0,
              \'' . self::EMPTY_EXPLORED_ZONES . '\',?,0,\'' . self::EMPTY_KNOWN_TITLES . '\',
              0,0,
              NULL,NULL,NULL,
              0,0)'
        );

        // gender en el dump puede venir en dos formatos:
        //   Lua viejo: 2=Male, 3=Female (UnitSex sin conversión)
        //   Lua nuevo / ya convertido: 0=Male, 1=Female
        // AzerothCore DB espera: 0=Male, 1=Female
        $rawGender = (int)($b['gender'] ?? 2);
        $dbGender  = ($rawGender >= 2) ? max(0, min(1, $rawGender - 2)) : max(0, min(1, $rawGender));

        $stmt->execute([
            $this->newGuid, $this->accountId, $name,
            $raceId, $classId, $dbGender,
            $level, $xp, $copper,
            $spawn['map'], $spawn['x'], $spawn['y'], $spawn['z'], $spawn['o'],
            $zone,
            $arenapts, $honor,
            $equipmentCache ?: null,
        ]);
    }

    // ── Inventario equipado + bolsas ─────────────────────────
    //
    // Slots AzerothCore en character_inventory:
    //   bag=0, slot  0-18  → equip (head, neck, …, tabard)
    //   bag=0, slot 19-22  → slots de bolsas equipadas
    //   bag=0, slot 23-38  → mochila principal (16 slots)
    //   bag=0, slot 39-66  → banco (28 slots directos)
    //
    // La API Lua usa base-1: equipped slot 1=head → DB slot 0.
    // Bag 0 (mochila) slot 1-16 → DB slot 23-38.
    //
    // @return array Items que NO cupieron (para enviar por correo)

    private function insertInventory(): array
    {
        $equipped = $this->data['equipped'] ?? [];
        $bags     = $this->data['bags']     ?? [];
        $excess   = [];

        // ── 1) Items equipados ───────────────────────────────
        //
        // Validamos el InventoryType de cada item contra su slot DBC.
        // Items con InventoryType incorrecto se redirigen a la mochila
        // (o a correo si tampoco caben).
        //
        // Usamos $this->equippedItemMeta ya cargado por buildEquipmentCache().
        // Si algún entry no estaba en el meta (p.ej. entries nuevos no en
        // el cache), hacemos una carga lazy.

        // Recopilar entries que no estén en el meta aún
        $missingEntries = [];
        foreach ($equipped as $item) {
            $entry = $this->convertItem((int)($item['entry'] ?? 0));
            if ($entry > 0 && !isset($this->equippedItemMeta[$entry])) {
                $missingEntries[] = $entry;
            }
        }
        if (!empty($missingEntries)) {
            $extraTypes = $this->loadInventoryTypes($missingEntries);
            foreach ($extraTypes as $e => $invType) {
                if (!isset($this->equippedItemMeta[$e])) {
                    $obj               = new stdClass();
                    $obj->InventoryType = $invType;
                    $obj->displayid    = 0;
                    $this->equippedItemMeta[$e] = $obj;
                }
            }
        }

        $backpackSlot = 23; // mochila principal: DB slots 23-38 (16 slots)

        foreach ($equipped as $item) {
            $entry = (int)($item['entry'] ?? 0);
            if ($entry <= 0) continue;
            if ($this->isBlockedItem($entry)) continue;
            $entry = $this->convertItem($entry);
            if (!$this->isValidEntry($entry)) {
                error_log("[Migrador] Item entry={$entry} no existe en item_template, omitido (equipped).");
                continue;
            }

            $luaSlot = (int)($item['slot'] ?? 1);
            $dbSlot  = $luaSlot - 1;  // Lua 1-23 → DB 0-22
            if ($dbSlot < 0 || $dbSlot > 22) continue;

            $count   = max(1, (int)($item['count'] ?? 1));
            $enchStr = $this->buildEnchStr($item);

            // Validar InventoryType contra el slot DBC (incluye bolsas en slots 19-22)
            $meta    = $this->equippedItemMeta[$entry] ?? null;
            $invType = $meta ? (int)$meta->InventoryType : 0;
            $slotOk  = $this->isValidForSlot($dbSlot, $invType);

            if ($slotOk) {
                // Item correcto para este slot → insertar como equipado o bolsa
                $iguid = $this->nextItemGuid();
                $this->insertItemInstance($iguid, $entry, $count, $enchStr);
                $this->insertCharInventory($iguid, 0, $dbSlot);
            } else {
                // InventoryType no coincide con el slot → redirigir a mochila
                error_log("[Migrador] Item entry={$entry} invType={$invType} no válido para slot DB={$dbSlot}, moviendo a mochila.");
                if ($backpackSlot <= 38) {
                    $iguid = $this->nextItemGuid();
                    $this->insertItemInstance($iguid, $entry, $count, $enchStr);
                    $this->insertCharInventory($iguid, 0, $backpackSlot);
                    $backpackSlot++;
                } else {
                    $excess[] = array_merge($item, ['entry' => $entry]);
                }
            }
        }

        // ── 2) Items en bolsas (bags 0-4) ───────────────────
        // Intentamos meter los primeros items en la mochila principal (23-38).
        // El resto va a correo.
        foreach ($bags as $item) {
            $entry = (int)($item['entry'] ?? 0);
            if ($entry <= 0) continue;
            if ($this->isBlockedItem($entry)) continue;
            $entry = $this->convertItem($entry);
            if (!$this->isValidEntry($entry)) {
                error_log("[Migrador] Item entry={$entry} no existe en item_template, omitido (bags).");
                continue;
            }

            $count   = max(1, (int)($item['count'] ?? 1));
            $enchStr = $this->buildEnchStr($item);

            if ($backpackSlot <= 38) {
                $iguid = $this->nextItemGuid();
                $this->insertItemInstance($iguid, $entry, $count, $enchStr);
                $this->insertCharInventory($iguid, 0, $backpackSlot);
                $backpackSlot++;
            } else {
                // No cabe en mochila → correo
                $excess[] = array_merge($item, ['entry' => $entry]);
            }
        }

        return $excess;
    }

    // ── Banco ────────────────────────────────────────────────
    //
    // @return array Items que NO cupieron en el banco (para correo)

    private function insertBank(): array
    {
        $bank    = $this->data['bank'] ?? [];
        $excess  = [];
        $bankSlot = 39;  // banco slots 39-66 (28 slots directos)

        foreach ($bank as $item) {
            $entry = (int)($item['entry'] ?? 0);
            if ($entry <= 0) continue;
            if ($this->isBlockedItem($entry)) continue;
            $entry = $this->convertItem($entry);
            if (!$this->isValidEntry($entry)) {
                error_log("[Migrador] Item entry={$entry} no existe en item_template, omitido (bank).");
                continue;
            }

            $count   = max(1, (int)($item['count'] ?? 1));
            $enchStr = $this->buildEnchStr($item);

            if ($bankSlot <= 66) {
                $iguid = $this->nextItemGuid();
                $this->insertItemInstance($iguid, $entry, $count, $enchStr);
                $this->insertCharInventory($iguid, 0, $bankSlot);
                $bankSlot++;
            } else {
                $excess[] = array_merge($item, ['entry' => $entry]);
            }
        }

        return $excess;
    }

    // ── Envío de items excedentes por correo ─────────────────

    private function mailExcessItems(array $items): void
    {
        if (empty($items)) return;

        $now    = time();
        $mailId = $this->insertMail(
            0,                    // sender = 0 (system)
            $this->newGuid,       // receiver
            t('mail_subject_excess_items'),
            t('mail_body_excess_items'),
            $now,
            $now + (30 * 24 * 3600)  // expire in 30 days
        );

        foreach ($items as $item) {
            $entry = (int)($item['entry'] ?? 0);
            if ($entry <= 0) continue;

            $count   = max(1, (int)($item['count'] ?? 1));
            $enchStr = $this->buildEnchStr($item);
            $iguid   = $this->nextItemGuid();

            $this->insertItemInstance($iguid, $entry, $count, $enchStr);

            $this->pdo->prepare(
                'INSERT INTO `mail_items` (`mail_id`,`item_guid`,`receiver`) VALUES (?,?,?)'
            )->execute([$mailId, $iguid, $this->newGuid]);
        }

        // Actualizar has_items = 1
        $this->pdo->prepare(
            'UPDATE `mail` SET `has_items` = 1 WHERE `id` = ?'
        )->execute([$mailId]);
    }

    private function insertMail(
        int    $sender,
        int    $receiver,
        string $subject,
        string $body,
        int    $deliverTime,
        int    $expireTime
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `mail`
             (`messageType`,`stationery`,`mailTemplateId`,`sender`,`receiver`,
              `subject`,`body`,`has_items`,`expire_time`,`deliver_time`,
              `money`,`cod`,`checked`)
             VALUES (0,41,0,?,?,?,?,0,?,?,0,0,0)'
        );
        $stmt->execute([$sender, $receiver, $subject, $body, $expireTime, $deliverTime]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── Skills ───────────────────────────────────────────────
    //
    // Profesiones y secundarias (Pesca/Cocina/Primeros Auxilios) no
    // dependen del nivel del personaje sino de su propio entrenamiento -
    // no aparecen en acore_world.playercreateinfo_skills (esa tabla es
    // solo lo que un personaje tiene "de fabrica" al crearse). Si el dump
    // trae alguna, la llevamos a su tope (400) en vez de dejar el valor
    // que traiga.
    private const PROFESSION_AND_SECONDARY_SKILLS = [
        171, // Alchemy
        164, // Blacksmithing
        333, // Enchanting
        202, // Engineering
        182, // Herbalism
        773, // Inscription
        755, // Jewelcrafting
        165, // Leatherworking
        186, // Mining
        393, // Skinning
        197, // Tailoring
        356, // Fishing
        185, // Cooking
        129, // First Aid
    ];

    // Plate Mail (293): a diferencia de Mail/Leather/Shield/armas, esta
    // proficiency NO esta en playercreateinfo_skills para Guerrero(1) ni
    // Paladin(2) - un personaje de esas clases la consigue mas adelante
    // en el juego (nivel/quest), no "de fabrica". Death Knight(6) si la
    // tiene de fabrica (empieza pre-nivelado), asi que no necesita este
    // caso especial. Un personaje migrado directo a nivel alto se salta
    // ese paso intermedio, asi que se la damos a mano.
    private const PLATE_MAIL_SKILL      = 293;
    private const PLATE_CLASSES_MISSING = [1, 2]; // Warrior, Paladin

    // Comentarios de playercreateinfo_skills que NO escalan con nivel/
    // combate (idiomas, pasivas raciales, monturas, mascota de compañia)
    // y por lo tanto no tiene sentido forzar a 400.
    private const SKIP_SKILL_COMMENT_PATTERNS = [
        'Language:%', '%- Racial', 'Mounts', 'Companion Pets', 'GENERIC (DND)',
    ];

    private function insertSkills(): void
    {
        $skills = $this->data['skills'] ?? [];
        $stmt   = $this->pdo->prepare(
            'INSERT IGNORE INTO `character_skills` (`guid`,`skill`,`value`,`max`) VALUES (?,?,?,?)'
        );
        foreach ($skills as $sk) {
            $id    = (int)($sk['id']    ?? 0);
            $value = (int)($sk['value'] ?? 0);
            $max   = (int)($sk['max']   ?? 300);
            if ($id > 0) {
                $stmt->execute([$this->newGuid, $id, $value, $max]);
            }
        }

        $this->maxClassWeaponAndArmorSkills();
        $this->maxKnownProfessionSkills();
    }

    /**
     * Sube al tope (400) los skills de arma/armadura/defensa que le
     * corresponden de fabrica a la raza/clase del personaje, leyendo
     * directo de acore_world.playercreateinfo_skills (misma tabla que usa
     * Player::LearnDefaultSkills() en el core) - no hay que adivinar
     * ningun id a mano.
     */
    private function maxClassWeaponAndArmorSkills(): void
    {
        $basic   = $this->data['basic'] ?? [];
        $raceId  = is_numeric($basic['race'] ?? '') ? (int)$basic['race'] : 1;
        $classId = is_numeric($basic['class'] ?? '') ? (int)$basic['class'] : 1;

        $raceMask  = 1 << ($raceId - 1);
        $classMask = 1 << ($classId - 1);

        $skipConds = implode(' AND ', array_fill(0, count(self::SKIP_SKILL_COMMENT_PATTERNS), '`comment` NOT LIKE ?'));

        try {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT skill FROM acore_world.playercreateinfo_skills
                 WHERE (raceMask = 0 OR raceMask & {$raceMask})
                   AND (classMask = 0 OR classMask & {$classMask})
                   AND {$skipConds}"
            );
            $stmt->execute(self::SKIP_SKILL_COMMENT_PATTERNS);
            $skillIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return;
        }

        if (in_array($classId, self::PLATE_CLASSES_MISSING, true)) {
            $skillIds[] = self::PLATE_MAIL_SKILL;
        }

        $upsert = $this->pdo->prepare(
            'INSERT INTO `character_skills` (`guid`,`skill`,`value`,`max`) VALUES (?,?,400,400)
             ON DUPLICATE KEY UPDATE `value` = 400, `max` = 400'
        );
        foreach (array_unique($skillIds) as $skillId) {
            $upsert->execute([$this->newGuid, (int)$skillId]);
        }
    }

    /** Sube a 400 las profesiones/secundarias que el dump ya trae. */
    private function maxKnownProfessionSkills(): void
    {
        $skills = $this->data['skills'] ?? [];
        $upsert = $this->pdo->prepare(
            'INSERT INTO `character_skills` (`guid`,`skill`,`value`,`max`) VALUES (?,?,400,400)
             ON DUPLICATE KEY UPDATE `value` = 400, `max` = 400'
        );
        foreach ($skills as $sk) {
            $id = (int)($sk['id'] ?? 0);
            if (in_array($id, self::PROFESSION_AND_SECONDARY_SKILLS, true)) {
                $upsert->execute([$this->newGuid, $id]);
            }
        }
    }

    // ── Spells ───────────────────────────────────────────────

    private function insertSpells(): void
    {
        $spells = $this->data['spells'] ?? [];
        // character_spell: guid, spell, specMask (255 = todos los specs)
        $stmt   = $this->pdo->prepare(
            'INSERT IGNORE INTO `character_spell` (`guid`,`spell`,`specMask`) VALUES (?,?,255)'
        );
        foreach ($spells as $spellId) {
            $id = (int)$spellId;
            if ($id > 0) {
                $stmt->execute([$this->newGuid, $id]);
            }
        }
    }

    // ── Talentos ─────────────────────────────────────────────

    private function insertTalents(): void
    {
        $talents = $this->data['talents'] ?? [];
        // character_talent: guid, spell, specMask (1 = primer spec)
        $stmt    = $this->pdo->prepare(
            'INSERT IGNORE INTO `character_talent` (`guid`,`spell`,`specMask`) VALUES (?,?,1)'
        );
        foreach ($talents as $t) {
            $spellId = (int)($t['spell'] ?? 0);
            if ($spellId > 0) {
                $stmt->execute([$this->newGuid, $spellId]);
            }
        }
    }

    // ── Glifos ───────────────────────────────────────────────

    private function insertGlyphs(): void
    {
        $glyphs = $this->data['glyphs'] ?? [];
        if (empty($glyphs)) return;

        $g = array_fill(0, 6, 0);
        foreach ($glyphs as $gl) {
            $slot  = (int)($gl['slot']  ?? 0) - 1;
            $spell = (int)($gl['spell'] ?? 0);
            if ($slot >= 0 && $slot < 6 && $spell > 0) {
                $g[$slot] = $spell;
            }
        }
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO `character_glyphs`
             (`guid`,`talentGroup`,`glyph1`,`glyph2`,`glyph3`,`glyph4`,`glyph5`,`glyph6`)
             VALUES (?,0,?,?,?,?,?,?)'
        );
        $stmt->execute([$this->newGuid, $g[0],$g[1],$g[2],$g[3],$g[4],$g[5]]);
    }

    // ── Reputaciones ─────────────────────────────────────────

    private function insertReputations(): void
    {
        $reps = $this->data['reputations'] ?? [];
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO `character_reputation`
             (`guid`,`faction`,`standing`,`flags`) VALUES (?,?,?,4)'
        );
        foreach ($reps as $r) {
            $factionId = (int)($r['id']    ?? 0);
            $value     = (int)($r['value'] ?? 0);
            $value     = max(-42000, min(42999, $value));
            if ($factionId > 0) {
                $stmt->execute([$this->newGuid, $factionId, $value]);
            }
        }
    }

    // ── Homebind ─────────────────────────────────────────────

    private function insertHomebind(): void
    {
        $basic   = $this->data['basic'] ?? [];
        $raceId  = is_numeric($basic['race'] ?? '') ? (int)$basic['race'] : 1;
        $faction = self::RACE_FACTION[$raceId] ?? 0;
        $spawn   = $faction === 1 ? self::SPAWN_HORDE : self::SPAWN_ALLIANCE;
        $areaId  = $faction === 1 ? 1637 : 1537;

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO `character_homebind`
             (`guid`,`mapId`,`zoneId`,`posX`,`posY`,`posZ`) VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([
            $this->newGuid,
            $spawn['map'], $areaId,
            $spawn['x'], $spawn['y'], $spawn['z'],
        ]);
    }

    // ── Barra de acciones por defecto ─────────────────────────
    //
    // Player::Create() rellena la barra de acciones desde
    // acore_world.playercreateinfo_action al crear un personaje nuevo por
    // el cliente. Un INSERT directo se salta ese paso y deja la barra
    // vacia - ni el boton de Ataque basico. playercreateinfo_action es una
    // tabla real y ya poblada (a diferencia de las tablas *_dbc, que estan
    // vacias en esta instalacion), asi que podemos leerla directo.

    private function insertActionBar(): void
    {
        $basic  = $this->data['basic'] ?? [];
        $raceId  = is_numeric($basic['race'] ?? '') ? (int)$basic['race'] : 1;
        $classId = is_numeric($basic['class'] ?? '') ? (int)$basic['class'] : 1;

        try {
            $rows = $this->pdo->query(
                "SELECT button, action, type FROM acore_world.playercreateinfo_action
                 WHERE race = {$raceId} AND class = {$classId}"
            )->fetchAll(PDO::FETCH_OBJ);
        } catch (Throwable) {
            return; // sin barra de acciones por defecto, no es fatal
        }
        if (empty($rows)) return;

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO `character_action` (`guid`,`spec`,`button`,`action`,`type`) VALUES (?,0,?,?,?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([$this->newGuid, (int)$r->button, (int)$r->action, (int)$r->type]);
        }
    }

    // ── Sincronización realmcharacters ───────────────────────
    //
    // AzerothCore guarda el número de personajes por cuenta y realm
    // en acore_auth.realmcharacters. Si no coincide con los reales,
    // el worldserver puede no mostrar el personaje.

    private function updateRealmCharacters(): void
    {
        try {
            // Contar personajes activos del account en este realm
            $real = (int) $this->pdo
                ->query("SELECT COUNT(*) FROM `characters`
                          WHERE `account` = {$this->accountId}
                            AND `deleteInfos_Account` IS NULL")
                ->fetchColumn();

            // Upsert en acore_auth.realmcharacters
            DB::auth()->getPdo()->prepare(
                'INSERT INTO `realmcharacters` (`realmid`,`acctid`,`numchars`)
                 VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE `numchars` = ?'
            )->execute([$this->realmId, $this->accountId, $real, $real]);
        } catch (Throwable $e) {
            error_log('[Migrador] updateRealmCharacters falló: ' . $e->getMessage());
        }
    }

    // ── Helpers privados de items ────────────────────────────

    private function isBlockedItem(int $entry): bool
    {
        return in_array($entry, BLOCKED_ITEMS, true);
    }

    private function convertItem(int $entry): int
    {
        return ITEM_CONVERSIONS[$entry] ?? $entry;
    }

    private function buildEnchStr(array $item): string
    {
        $ench = (int)($item['ench'] ?? 0);
        $gems = [(int)($item['gem1'] ?? 0), (int)($item['gem2'] ?? 0), (int)($item['gem3'] ?? 0)];

        // 18 enchant slots × 3 valores (id, duration, charges)
        $slots = array_fill(0, 18, '0 0 0');
        if ($ench > 0) $slots[0] = "{$ench} 1 0";
        for ($i = 0; $i < 3; $i++) {
            if ($gems[$i] > 0) $slots[$i + 1] = "{$gems[$i]} 1 0";
        }
        return implode(' ', $slots) . ' ';
    }

    private function insertItemInstance(int $iguid, int $entry, int $count, string $enchStr): void
    {
        $this->pdo->prepare(
            'INSERT INTO `item_instance`
             (`guid`,`owner_guid`,`itemEntry`,`creatorGuid`,`giftCreatorGuid`,
              `count`,`duration`,`charges`,`flags`,`enchantments`,`randomPropertyId`,
              `durability`,`playedTime`,`text`)
             VALUES (?,?,?,0,0,?,0,\'\',0,?,0,0,0,\'\')'
        )->execute([$iguid, $this->newGuid, $entry, $count, $enchStr]);
    }

    private function insertCharInventory(int $iguid, int $bag, int $slot): void
    {
        $this->pdo->prepare(
            'INSERT INTO `character_inventory` (`guid`,`bag`,`slot`,`item`) VALUES (?,?,?,?)'
        )->execute([$this->newGuid, $bag, $slot, $iguid]);
    }

    // ── Helpers de conversión nombre→ID ─────────────────────

    private function raceNameToId(string $race): int
    {
        return match (strtolower($race)) {
            'human'                    => 1,
            'orc'                      => 2,
            'dwarf'                    => 3,
            'nightelf','night elf'     => 4,
            'undead','scourge'         => 5,
            'tauren'                   => 6,
            'gnome'                    => 7,
            'troll'                    => 8,
            'bloodelf','blood elf'     => 10,
            'draenei'                  => 11,
            default                    => 1,
        };
    }

    private function classNameToId(string $cls): int
    {
        return match (strtolower($cls)) {
            'warrior'                         => 1,
            'paladin'                         => 2,
            'hunter'                          => 3,
            'rogue'                           => 4,
            'priest'                          => 5,
            'deathknight','death knight'      => 6,
            'shaman'                          => 7,
            'mage'                            => 8,
            'warlock'                         => 9,
            'druid'                           => 11,
            default                           => 1,
        };
    }

    // ── Helpers públicos estáticos ───────────────────────────

    /** Extrae el nombre del personaje del JSON dump */
    public static function extractName(string $json): string|false
    {
        try {
            $d    = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $name = $d['basic']['name'] ?? '';
            return (strlen($name) >= 2 && strlen($name) <= 16) ? $name : false;
        } catch (Throwable) {
            return false;
        }
    }

    /** Extrae el nivel del personaje del JSON dump */
    public static function extractLevel(string $json): int
    {
        try {
            $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return (int)($d['basic']['level'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** Devuelve true si el string parece ser un JSON de chardump v2 */
    public static function isJsonDump(string $content): bool
    {
        $trimmed = ltrim($content);
        if (!str_starts_with($trimmed, '{')) return false;
        $d = json_decode($trimmed, true);
        return is_array($d) && isset($d['version'], $d['basic']);
    }

    /**
     * Carga datos de items desde acore_world para mostrar en preview.
     * @param int[] $entries
     * @return array<int, object>
     */
    public static function getItemData(array $entries): array
    {
        if (empty($entries)) return [];
        $entries = array_unique(array_filter(array_map('intval', $entries)));
        if (empty($entries)) return [];

        try {
            $in   = implode(',', $entries);
            $rows = DB::world()->rows(
                "SELECT `entry`, `class`, `subclass`, `name`, `Quality`, `InventoryType`, `displayid`,
                        `ItemLevel`, `RequiredLevel`, `bonding`, `description`, `stackable`, `maxcount`,
                        `dmg_min1`, `dmg_max1`, `dmg_type1`, `dmg_min2`, `dmg_max2`, `dmg_type2`,
                        `armor`, `holy_res`, `fire_res`, `nature_res`, `frost_res`, `shadow_res`, `arcane_res`,
                        `delay`, `block`, `MaxDurability`, `itemset`,
                        `stat_type1`, `stat_value1`, `stat_type2`, `stat_value2`, `stat_type3`, `stat_value3`,
                        `stat_type4`, `stat_value4`, `stat_type5`, `stat_value5`, `stat_type6`, `stat_value6`,
                        `stat_type7`, `stat_value7`, `stat_type8`, `stat_value8`, `stat_type9`, `stat_value9`,
                        `stat_type10`, `stat_value10`,
                        `socketColor_1`, `socketContent_1`, `socketColor_2`, `socketContent_2`,
                        `socketColor_3`, `socketContent_3`, `socketBonus`,
                        `spellid_1`, `spelltrigger_1`, `spellcharges_1`, `spellppmRate_1`, `spellcooldown_1`, `spellcategory_1`, `spellcategorycooldown_1`,
                        `spellid_2`, `spelltrigger_2`, `spellcharges_2`, `spellppmRate_2`, `spellcooldown_2`, `spellcategory_2`, `spellcategorycooldown_2`,
                        `spellid_3`, `spelltrigger_3`, `spellcharges_3`, `spellppmRate_3`, `spellcooldown_3`, `spellcategory_3`, `spellcategorycooldown_3`,
                        `spellid_4`, `spelltrigger_4`, `spellcharges_4`, `spellppmRate_4`, `spellcooldown_4`, `spellcategory_4`, `spellcategorycooldown_4`,
                        `spellid_5`, `spelltrigger_5`, `spellcharges_5`, `spellppmRate_5`, `spellcooldown_5`, `spellcategory_5`, `spellcategorycooldown_5`
                 FROM `item_template` WHERE `entry` IN ({$in})"
            );
            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r->entry] = $r;
            }
            $spellIds = [];
            foreach ($rows as $r) {
                for ($i = 1; $i <= 5; $i++) {
                    $spell = (int)($r->{"spellid_{$i}"} ?? 0);
                    if ($spell > 0) $spellIds[] = $spell;
                }
            }
            $spellIds = array_values(array_unique($spellIds));
            if (!empty($spellIds)) {
                $spellIn = implode(',', $spellIds);
                $spellRows = DB::world()->rows(
                    "SELECT `ID`,
                            COALESCE(NULLIF(`Name_Lang_esES`,''), NULLIF(`Name_Lang_esMX`,''), NULLIF(`Name_Lang_enUS`,''), `Name_Lang_Unk`) AS `name`,
                            COALESCE(NULLIF(`Description_Lang_esES`,''), NULLIF(`Description_Lang_esMX`,''), NULLIF(`Description_Lang_enUS`,''), `Description_Lang_Unk`) AS `description`,
                            COALESCE(NULLIF(`AuraDescription_Lang_esES`,''), NULLIF(`AuraDescription_Lang_esMX`,''), NULLIF(`AuraDescription_Lang_enUS`,''), `AuraDescription_Lang_Unk`) AS `aura`,
                            `DurationIndex`, `Effect_1`, `Effect_2`, `Effect_3`,
                            `EffectBasePoints_1`, `EffectBasePoints_2`, `EffectBasePoints_3`,
                            `EffectDieSides_1`, `EffectDieSides_2`, `EffectDieSides_3`,
                            `EffectMultipleValue_1`, `EffectMultipleValue_2`, `EffectMultipleValue_3`,
                            `EffectAuraPeriod_1`, `EffectAuraPeriod_2`, `EffectAuraPeriod_3`,
                            `EffectTriggerSpell_1`, `EffectTriggerSpell_2`, `EffectTriggerSpell_3`
                     FROM `spell_dbc` WHERE `ID` IN ({$spellIn})"
                );
                $spellMap = [];
                foreach ($spellRows as $s) {
                    $spellMap[(int)$s->ID] = $s;
                }
                foreach ($map as $item) {
                    $item->_spellInfo = [];
                    for ($i = 1; $i <= 5; $i++) {
                        $spell = (int)($item->{"spellid_{$i}"} ?? 0);
                        if ($spell > 0 && isset($spellMap[$spell])) {
                            $item->_spellInfo[$spell] = $spellMap[$spell];
                        }
                    }
                }
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Verifica si un InventoryType es válido para el slot DB 0-18 (estático, para previews).
     */
    public static function checkSlotValid(int $dbSlot, int $inventoryType): bool
    {
        if ($inventoryType <= 0) return false;
        $allowed = self::SLOT_INVENTORY_TYPES[$dbSlot] ?? [];
        return in_array($inventoryType, $allowed, true);
    }
}
