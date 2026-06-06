-- ═══════════════════════════════════════════════════════════════
--  CharDump v2.0 — AzerothCore WotLK 3.3.5a
--  Exporta datos del personaje actual como JSON cifrado.
--  Uso: /chardump  o  /cdump
-- ═══════════════════════════════════════════════════════════════

ChardumpDB = ChardumpDB or {}

-- ── Base64 encoder ────────────────────────────────────────────
local b64chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'

local function b64_encode(data)
    return ((data:gsub('.', function(x)
        local r, b = '', x:byte()
        for i = 8, 1, -1 do
            r = r .. (b % 2^i - b % 2^(i-1) > 0 and '1' or '0')
        end
        return r
    end) .. '0000'):gsub('%d%d%d?%d?%d?%d?', function(x)
        if (#x < 6) then return '' end
        local c = 0
        for i = 1, 6 do
            c = c + (x:sub(i, i) == '1' and 2^(6-i) or 0)
        end
        return b64chars:sub(c+1, c+1)
    end) .. ({ '', '==', '=' })[#data % 3 + 1])
end

-- ── JSON serializer mínimo ────────────────────────────────────
local function jsonString(s)
    s = tostring(s or '')
    s = s:gsub('\\', '\\\\')
    s = s:gsub('"',  '\\"')
    s = s:gsub('\n', '\\n')
    s = s:gsub('\r', '\\r')
    s = s:gsub('\t', '\\t')
    return '"' .. s .. '"'
end

local function jsonVal(v)
    local t = type(v)
    if t == 'number'  then return tostring(v) end
    if t == 'boolean' then return tostring(v) end
    if t == 'string'  then return jsonString(v) end
    if t == 'nil'     then return 'null' end
    if t == 'table'   then
        -- detectar array (claves numéricas 1..n consecutivas)
        local isArray = true
        local n = #v
        if n == 0 then
            for _ in pairs(v) do isArray = false; break end
        end
        if isArray then
            local parts = {}
            for i = 1, n do parts[i] = jsonVal(v[i]) end
            return '[' .. table.concat(parts, ',') .. ']'
        else
            local parts = {}
            for k, val in pairs(v) do
                parts[#parts+1] = jsonString(k) .. ':' .. jsonVal(val)
            end
            return '{' .. table.concat(parts, ',') .. '}'
        end
    end
    return 'null'
end

-- ── Cifrado: reverse(base64(json)) ───────────────────────────
-- PHP decryptDump hace: base64_decode(strrev(x))
-- por tanto ciframos: string.reverse(b64_encode(json))
local function encrypt(plaintext)
    return string.reverse(b64_encode(plaintext))
end

-- ── Recolectores de datos ─────────────────────────────────────

local function collectBasic()
    local _, cls = UnitClass('player')
    local _, race = UnitRace('player')
    local map, _, zone = GetPlayerMapPosition('player')
    return {
        name      = UnitName('player'),
        class     = cls,
        race      = race,
        gender    = UnitSex('player') - 2,  -- Lua: 2=male,3=female → DB: 0=male,1=female
        level     = UnitLevel('player'),
        zone      = GetZoneText(),
        copper    = GetMoney(),
        xp        = UnitXP('player'),
        honor     = GetHonorCurrency(),
        arena_pts = GetArenaCurrency(),
    }
end

-- Inventario equipado + bolsas equipadas (slots 1-23)
-- Slots 1-19 = equipo; Slots 20-23 = contenedores de bolsas equipadas
local EQUIP_SLOTS = {1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23}
local function collectEquipped()
    local items = {}
    for _, slot in ipairs(EQUIP_SLOTS) do
        local link = GetInventoryItemLink('player', slot)
        if link then
            local id = link:match('|Hitem:(%d+):')
            local ench,g1,g2,g3 = link:match('|Hitem:%d+:(%d+):(%d+):(%d+):(%d+):')
            if id then
                items[#items+1] = {
                    slot  = slot,
                    entry = tonumber(id),
                    ench  = tonumber(ench) or 0,
                    gem1  = tonumber(g1) or 0,
                    gem2  = tonumber(g2) or 0,
                    gem3  = tonumber(g3) or 0,
                    count = GetInventoryItemCount('player', slot),
                }
            end
        end
    end
    return items
end

-- Bolsas (bags 0-4, incluye bolsa principal)
local function collectBags()
    local all = {}
    for bag = 0, 4 do
        local slots = GetContainerNumSlots(bag)
        for slot = 1, slots do
            local link = GetContainerItemLink(bag, slot)
            if link then
                local id = link:match('|Hitem:(%d+):')
                local ench,g1,g2,g3 = link:match('|Hitem:%d+:(%d+):(%d+):(%d+):(%d+):')
                if id then
                    local count = select(2, GetContainerItemInfo(bag, slot)) or 1
                    all[#all+1] = {
                        bag   = bag,
                        slot  = slot,
                        entry = tonumber(id),
                        ench  = tonumber(ench) or 0,
                        gem1  = tonumber(g1) or 0,
                        gem2  = tonumber(g2) or 0,
                        gem3  = tonumber(g3) or 0,
                        count = count,
                    }
                end
            end
        end
    end
    return all
end

-- Banco (bags 5-11)
local function collectBank()
    local all = {}
    -- Slots directos del banco (slot IDs 39-67 en inventario del personaje)
    for slot = 39, 67 do
        local link = GetInventoryItemLink('player', slot)
        if link then
            local id = link:match('|Hitem:(%d+):')
            if id then
                local count = GetInventoryItemCount('player', slot)
                all[#all+1] = { bag = -1, slot = slot, entry = tonumber(id), count = count }
            end
        end
    end
    -- Bolsas del banco (bags 5-10)
    for bag = 5, 10 do
        local slots = GetContainerNumSlots(bag)
        for slot = 1, slots do
            local link = GetContainerItemLink(bag, slot)
            if link then
                local id = link:match('|Hitem:(%d+):')
                local ench,g1,g2,g3 = link:match('|Hitem:%d+:(%d+):(%d+):(%d+):(%d+):')
                if id then
                    local count = select(2, GetContainerItemInfo(bag, slot)) or 1
                    all[#all+1] = {
                        bag   = bag,
                        slot  = slot,
                        entry = tonumber(id),
                        ench  = tonumber(ench) or 0,
                        gem1  = tonumber(g1) or 0,
                        gem2  = tonumber(g2) or 0,
                        gem3  = tonumber(g3) or 0,
                        count = count,
                    }
                end
            end
        end
    end
    return all
end

-- Habilidades
local function collectSkills()
    local list = {}
    local i = 1
    while true do
        local name, _, skillVal, skillMax, _, _, skillId = GetSkillLineInfo(i)
        if not name then break end
        if name ~= '' and skillVal and skillVal > 0 then
            list[#list+1] = {
                id    = skillId or i,
                name  = name,
                value = skillVal,
                max   = skillMax,
            }
        end
        i = i + 1
    end
    return list
end

-- Spells conocidos (todos los que no son pasivos de rango de habilidad)
local function collectSpells()
    local list = {}
    local i = 1
    while true do
        local name, _, _, castTime, _, _, spellId = GetSpellInfo(i, 'BOOKTYPE_SPELL')
        if not name then break end
        if spellId then
            list[#list+1] = spellId
        end
        i = i + 1
    end
    return list
end

-- Talentos (tabs 1-3, hasta 30 talentos por tab)
local function collectTalents()
    local list = {}
    local numTabs = GetNumTalentTabs()
    for tab = 1, numTabs do
        local numTalents = GetNumTalents(tab)
        for idx = 1, numTalents do
            local name, _, _, _, currRank = GetTalentInfo(tab, idx)
            if name and currRank and currRank > 0 then
                -- Obtener spellId del link
                local link = GetTalentLink(tab, idx)
                local spellId = link and link:match('|Hspell:(%d+):') or nil
                list[#list+1] = {
                    tab   = tab,
                    idx   = idx,
                    rank  = currRank,
                    spell = tonumber(spellId) or 0,
                }
            end
        end
    end
    return list
end

-- Glifos (6 slots: 1-3 major, 4-6 minor en WotLK)
local function collectGlyphs()
    local list = {}
    if not GetGlyphSocketInfo then return list end
    for slot = 1, 6 do
        local _, _, glyphSpellId = GetGlyphSocketInfo(slot)
        if glyphSpellId and glyphSpellId > 0 then
            list[#list+1] = { slot = slot, spell = glyphSpellId }
        end
    end
    return list
end

-- Reputaciones
local function collectReputations()
    local list = {}
    local i = 1
    while true do
        local name, standing, barMin, barMax, barVal, header, _, _, factionId = GetFactionInfo(i)
        if not name then break end
        if not header and factionId then
            -- standing: 1=Hated..8=Exalted; barVal = valor dentro del rango actual
            -- calcular valor absoluto
            local BASE = {0, 36000, 3000, 3000, 6000, 12000, 21000, 42000}
            -- (estos son los breakpoints reales de reputación WoW)
            local BREAKPOINTS = {-42000, -6000, 0, 3000, 9000, 21000, 42000, 42999}
            local absVal = (BREAKPOINTS[standing] or 0) + barVal
            list[#list+1] = {
                id      = factionId,
                name    = name,
                value   = absVal,
                standing= standing,
            }
        end
        i = i + 1
    end
    return list
end

-- Logros completados
local function collectAchievements()
    local list = {}
    -- No hay API directa en 3.3.5 para iterar todos los logros del jugador
    -- Usamos el Achievement frame si está disponible
    if not GetNumCompletedAchievements then return list end
    -- Iterar categorías conocidas sería muy largo; exportamos solo los IDs
    -- que podemos obtener del frame de logros si está abierto
    -- En su lugar, hacemos lo que podemos: no es posible iterar sin módulo
    return list
end

-- Quests completadas (rewardadas)
local function collectQuests()
    local list = {}
    -- En 3.3.5a no hay API para iterar quests completadas directamente
    -- Solo podemos exportar las quests activas del log
    local numEntries = GetNumQuestLogEntries()
    for i = 1, numEntries do
        local title, _, _, isHeader, _, complete, _, questId = GetQuestLogTitle(i)
        if not isHeader and questId and questId > 0 then
            list[#list+1] = { id = questId, title = title or '', complete = complete == 1 }
        end
    end
    return list
end

-- ── Función principal de dump ─────────────────────────────────
local function DoDump()
    if not UnitIsConnected('player') then
        print('|cffff4444[CharDump]|r Debes estar conectado para hacer el dump.')
        return
    end

    print('|cffffff00[CharDump]|r Recopilando datos...')

    local data = {
        version      = 2,
        exported_at  = time(),
        basic        = collectBasic(),
        equipped     = collectEquipped(),
        bags         = collectBags(),
        bank         = collectBank(),
        skills       = collectSkills(),
        spells       = collectSpells(),
        talents      = collectTalents(),
        glyphs       = collectGlyphs(),
        reputations  = collectReputations(),
        quests       = collectQuests(),
    }

    local json    = jsonVal(data)
    local encoded = encrypt(json)

    -- Guardar en SavedVariables
    ChardumpDB['last'] = {
        char    = data.basic.name,
        level   = data.basic.level,
        data    = encoded,
        time    = data.exported_at,
    }

    local charName = data.basic.name
    local level    = data.basic.level
    local size     = #encoded

    print(string.format(
        '|cff00ff00[CharDump]|r ✔ Dump de |cffffd700%s|r (nivel %d) generado. Tamaño: %d bytes.',
        charName, level, size
    ))
    print('|cffffff00[CharDump]|r El archivo se guardará automáticamente como |cffffffffChardumpDB|r en tus SavedVariables de WoW.')
    print('|cffffff00[CharDump]|r Ruta: WoW\\WTF\\Account\\<CUENTA>\\SavedVariables\\chardump.lua')
    print('|cffffff00[CharDump]|r Sube ese archivo .lua en la web del servidor para completar la transferencia.')
end

-- ── Frame para eventos ────────────────────────────────────────
local frame = CreateFrame('Frame')
frame:RegisterEvent('ADDON_LOADED')
frame:SetScript('OnEvent', function(self, event, addon)
    if addon == 'chardump' then
        ChardumpDB = ChardumpDB or {}
        -- Mostrar info si hay un dump guardado
        if ChardumpDB['last'] and ChardumpDB['last'].char then
            local d = ChardumpDB['last']
            print(string.format(
                '|cffffff00[CharDump]|r Último dump: |cffffd700%s|r (nivel %d) — %s',
                d.char, d.level or 0,
                date('%d/%m/%Y %H:%M', d.time or 0)
            ))
        end
    end
end)

-- ── Slash commands ────────────────────────────────────────────
SLASH_CHARDUMP1 = '/chardump'
SLASH_CHARDUMP2 = '/cdump'

SlashCmdList['CHARDUMP'] = function(msg)
    local cmd = msg:lower():match('^%s*(.-)%s*$')

    if cmd == 'info' or cmd == 'status' then
        if ChardumpDB['last'] and ChardumpDB['last'].char then
            local d = ChardumpDB['last']
            print(string.format(
                '|cffffff00[CharDump]|r Último dump: |cffffd700%s|r (nivel %d)',
                d.char, d.level or 0
            ))
            print(string.format(
                '|cffffff00[CharDump]|r Fecha: %s | Tamaño datos: %d bytes',
                date('%d/%m/%Y %H:%M:%S', d.time or 0), #(d.data or '')
            ))
        else
            print('|cffff8800[CharDump]|r No hay ningún dump guardado.')
        end

    elseif cmd == 'clear' or cmd == 'borrar' then
        ChardumpDB['last'] = nil
        print('|cffffff00[CharDump]|r Dump anterior borrado.')

    elseif cmd == 'help' or cmd == '?' then
        print('|cffffff00[CharDump]|r Comandos disponibles:')
        print('  |cffffffff/chardump|r          — Genera el dump del personaje actual')
        print('  |cffffffff/chardump info|r     — Muestra info del último dump guardado')
        print('  |cffffffff/chardump clear|r    — Borra el dump guardado')
        print('  |cffffffff/chardump help|r     — Muestra esta ayuda')

    else
        DoDump()
    end
end
