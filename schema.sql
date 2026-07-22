-- Project GOB — database schema (MySQL 8 / MariaDB)
-- Run against the `gob` database:  mysql gob < schema.sql

CREATE TABLE IF NOT EXISTS players (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(32)  NOT NULL UNIQUE,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bearer tokens. One row per active login session.
CREATE TABLE IF NOT EXISTS sessions (
    token      CHAR(64)     NOT NULL PRIMARY KEY,
    player_id  INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NOT NULL,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A settlement stores current resources plus the production rates and the
-- timestamp of the last update. Actual amounts are computed on read from
-- (stored amount + rate * time elapsed since last_tick) — the "rates +
-- timestamps" model, so we never need a per-second background job.
CREATE TABLE IF NOT EXISTS settlements (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id           INT UNSIGNED NOT NULL,
    name                VARCHAR(64)  NOT NULL,
    terrain             ENUM('plains','forest','mountains','swamp') NOT NULL DEFAULT 'plains',

    gold                BIGINT UNSIGNED NOT NULL DEFAULT 500,
    wood                BIGINT UNSIGNED NOT NULL DEFAULT 500,
    stone               BIGINT UNSIGNED NOT NULL DEFAULT 0,

    rate_gold_per_hour  INT UNSIGNED NOT NULL DEFAULT 60,
    rate_wood_per_hour  INT UNSIGNED NOT NULL DEFAULT 40,
    rate_stone_per_hour INT UNSIGNED NOT NULL DEFAULT 20,

    capacity_gold       BIGINT UNSIGNED NOT NULL DEFAULT 10000,
    capacity_wood       BIGINT UNSIGNED NOT NULL DEFAULT 10000,
    capacity_stone      BIGINT UNSIGNED NOT NULL DEFAULT 10000,

    last_tick           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The player's hero. Vitals and the six stats live here as columns.
-- NB: full stat names are used because INT is a reserved SQL keyword;
-- the API maps them to the short keys str/dex/con/int/wis/cha.
CREATE TABLE IF NOT EXISTS characters (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id    INT UNSIGNED NOT NULL UNIQUE,
    name         VARCHAR(64) NOT NULL,

    hp           INT NOT NULL DEFAULT 100,
    hp_max       INT NOT NULL DEFAULT 100,
    mana         INT NOT NULL DEFAULT 100,
    mana_max     INT NOT NULL DEFAULT 100,
    courage      INT NOT NULL DEFAULT 100,
    courage_max  INT NOT NULL DEFAULT 100,

    strength     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    dexterity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    constitution SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    intelligence SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    wisdom       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    charisma     SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    -- Combat sub-stats. defense/protection are innate; attack/penetration
    -- default 0 (a bare-handed hero) and come mostly from the equipped weapon.
    defense      INT NOT NULL DEFAULT 0,
    protection   INT NOT NULL DEFAULT 0,
    attack       INT NOT NULL DEFAULT 0,
    penetration  INT NOT NULL DEFAULT 0,
    regen_bonus  INT NOT NULL DEFAULT 0,   -- extra HP/min from cleared regen sites

    last_loot_at    DATETIME NULL,       -- cooldown marker for loot searches
    last_regen_at   DATETIME NULL,       -- marker for passive HP regeneration
    last_explore_at DATETIME NULL,       -- cooldown marker for exploration
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per skill the character has trained. value starts at 1.
CREATE TABLE IF NOT EXISTS character_skills (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id INT UNSIGNED NOT NULL,
    skill        VARCHAR(32)  NOT NULL,
    value        INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_char_skill (character_id, skill),
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item definitions (templates). One row per kind of gear. slot_type says
-- which equipment slot it fits ('ring'/'bracelet' are generic and go in the
-- numbered slots). Bonuses are added to the character while equipped; they
-- may be negative (cursed gear).
CREATE TABLE IF NOT EXISTS items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64)  NOT NULL,
    slot_type     VARCHAR(32)  NOT NULL,
    weapon_skill  VARCHAR(32)  NULL,          -- for weapons: which skill it uses
    rarity        VARCHAR(16)  NOT NULL DEFAULT 'common',
    bonus_str     SMALLINT NOT NULL DEFAULT 0,
    bonus_dex     SMALLINT NOT NULL DEFAULT 0,
    bonus_con     SMALLINT NOT NULL DEFAULT 0,
    bonus_int     SMALLINT NOT NULL DEFAULT 0,
    bonus_wis     SMALLINT NOT NULL DEFAULT 0,
    bonus_cha     SMALLINT NOT NULL DEFAULT 0,
    bonus_hp      INT NOT NULL DEFAULT 0,
    bonus_mana    INT NOT NULL DEFAULT 0,
    bonus_courage INT NOT NULL DEFAULT 0,
    bonus_defense     SMALLINT NOT NULL DEFAULT 0,
    bonus_protection  SMALLINT NOT NULL DEFAULT 0,
    bonus_attack      SMALLINT NOT NULL DEFAULT 0,
    bonus_penetration SMALLINT NOT NULL DEFAULT 0,
    bonus_perception  SMALLINT NOT NULL DEFAULT 0,
    kind          VARCHAR(16)  NOT NULL DEFAULT 'gear',  -- 'gear' | 'consumable'
    heal_hp       INT NOT NULL DEFAULT 0,                -- HP restored when a consumable is used
    sell_value    INT NOT NULL DEFAULT 0,                -- gold gained when sold
    description   VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Item instances a character owns. equipped_slot is NULL when the item sits
-- in the backpack, or the specific slot name when worn. The UNIQUE key means
-- a slot can hold only one item (NULLs are exempt, so many can be unequipped).
CREATE TABLE IF NOT EXISTS character_items (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id  INT UNSIGNED NOT NULL,
    item_id       INT UNSIGNED NOT NULL,
    equipped_slot VARCHAR(32)  NULL,
    acquired_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_char_equipped_slot (character_id, equipped_slot),
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id)      REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A few starter item definitions to build on.
INSERT IGNORE INTO items
    (id, name, slot_type, weapon_skill, rarity, bonus_str, bonus_dex, bonus_hp, description) VALUES
    (1, 'Rusty Sword', 'weapon', 'sword', 'common', 1, 0,  0, 'A worn but serviceable blade.'),
    (2, 'Short Bow',   'weapon', 'bow',   'common', 0, 1,  0, 'Favoured by scouts and skirmishers.'),
    (3, 'Leather Cap', 'head',   NULL,    'common', 0, 0, 10, 'Basic protection for the head.'),
    (4, 'Iron Ring',   'ring',   NULL,    'common', 1, 0,  0, 'A plain iron band.'),
    (5, 'Oak Shield',  'shield', NULL,    'common', 0, 0, 20, 'Sturdy oak with an iron rim.');

-- More gear across every slot and rarity, for the loot table to draw from.
INSERT IGNORE INTO items
    (id, name, slot_type, weapon_skill, rarity, bonus_str, bonus_dex, bonus_int, bonus_wis, bonus_cha, bonus_hp, bonus_mana, bonus_courage, description) VALUES
    (6,  'Steel Axe',       'weapon',    'axe',  'uncommon', 2, 0, 0, 0, 0,  0,  0,  0, 'A heavy, well-balanced axe.'),
    (7,  'Hunting Bow',     'weapon',    'bow',  'uncommon', 0, 2, 0, 0, 0,  0,  0,  0, 'A finely strung longbow.'),
    (8,  'Silver Ring',     'ring',      NULL,   'uncommon', 0, 0, 1, 1, 0,  0, 10,  0, 'A ring humming with faint magic.'),
    (9,  'Chainmail',       'platemail', NULL,   'uncommon', 0, 0, 0, 0, 0, 30,  0,  0, 'Interlocking steel rings.'),
    (10, 'Runed Gauntlets', 'gauntlets', NULL,   'uncommon', 1, 0, 0, 0, 0, 10,  0,  0, 'Gauntlets etched with runes.'),
    (11, 'Leather Boots',   'foot',      NULL,   'common',   0, 1, 0, 0, 0,  5,  0,  0, 'Supple, quiet boots.'),
    (12, 'Padded Sleeves',  'sleeves',   NULL,   'common',   0, 0, 0, 0, 0,  8,  0,  0, 'Quilted arm protection.'),
    (13, 'Traveler Pants',  'pants',     NULL,   'common',   0, 0, 0, 0, 0,  8,  0,  0, 'Durable road-worn trousers.'),
    (14, 'Scholar Glasses', 'glasses',   NULL,   'rare',     0, 0, 2, 1, 0,  0, 20,  0, 'Lenses that sharpen the mind.'),
    (15, 'War Banner',      'banner',    NULL,   'rare',     0, 0, 0, 0, 1,  0,  0, 15, 'Rallies the spirit in battle.'),
    (16, 'Oak Bracer',      'bracelet',  NULL,   'common',   0, 0, 0, 0, 0,  6,  0,  0, 'A simple wooden bracer.');

-- Consumable healing potions (usable from the backpack, not equipped).
INSERT IGNORE INTO items (id, name, slot_type, rarity, kind, heal_hp, description) VALUES
    (17, 'Minor Healing Potion',   'potion', 'common',   'consumable',  50, 'Restores 50 HP.'),
    (18, 'Greater Healing Potion', 'potion', 'uncommon', 'consumable', 150, 'Restores 150 HP.');

-- Combat sub-stats for the seeded gear. Idempotent UPDATEs (run every time the
-- schema is applied) so both fresh and existing databases get these values.
UPDATE items SET bonus_attack = 5, bonus_penetration = 1 WHERE id = 1;   -- Rusty Sword
UPDATE items SET bonus_attack = 4, bonus_penetration = 2 WHERE id = 2;   -- Short Bow
UPDATE items SET bonus_defense = 2, bonus_protection = 1 WHERE id = 3;   -- Leather Cap
UPDATE items SET bonus_defense = 6, bonus_protection = 2 WHERE id = 5;   -- Oak Shield
UPDATE items SET bonus_attack = 8, bonus_penetration = 2 WHERE id = 6;   -- Steel Axe
UPDATE items SET bonus_attack = 6, bonus_penetration = 3 WHERE id = 7;   -- Hunting Bow
UPDATE items SET bonus_protection = 1 WHERE id = 8;                      -- Silver Ring
UPDATE items SET bonus_defense = 8, bonus_protection = 4 WHERE id = 9;   -- Chainmail
UPDATE items SET bonus_defense = 2, bonus_protection = 2 WHERE id = 10;  -- Runed Gauntlets
UPDATE items SET bonus_defense = 1 WHERE id = 11;                        -- Leather Boots
UPDATE items SET bonus_defense = 2 WHERE id = 12;                        -- Padded Sleeves
UPDATE items SET bonus_defense = 1 WHERE id = 13;                        -- Traveler Pants
UPDATE items SET bonus_protection = 1 WHERE id = 14;                     -- Scholar Glasses
UPDATE items SET bonus_attack = 2 WHERE id = 15;                         -- War Banner
UPDATE items SET bonus_defense = 1 WHERE id = 16;                        -- Oak Bracer
UPDATE items SET bonus_perception = 3 WHERE id = 14;                     -- Scholar Glasses
UPDATE items SET bonus_perception = 1 WHERE id = 8;                      -- Silver Ring

-- Sell value by rarity (idempotent; covers all items incl. potions).
UPDATE items SET sell_value = CASE rarity
    WHEN 'common'   THEN 10
    WHEN 'uncommon' THEN 40
    WHEN 'rare'     THEN 120
    WHEN 'epic'     THEN 300
    ELSE 10 END;

-- PvE enemies the hero can fight. loot_item_id + loot_chance (percent) give a
-- chance to drop that item on a win.
CREATE TABLE IF NOT EXISTS monsters (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(64) NOT NULL,
    level        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    hp           INT NOT NULL,
    attack       INT NOT NULL DEFAULT 0,
    defense      INT NOT NULL DEFAULT 0,
    protection   INT NOT NULL DEFAULT 0,
    penetration  INT NOT NULL DEFAULT 0,
    reward_gold  INT NOT NULL DEFAULT 0,
    loot_item_id INT UNSIGNED NULL,
    loot_chance  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    description  VARCHAR(255) NULL,
    FOREIGN KEY (loot_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO monsters
    (id, name, level, hp, attack, defense, protection, penetration, reward_gold, loot_item_id, loot_chance, description) VALUES
    (1, 'Goblin Scout',   1,  30,  4, 1, 0, 0,  20,  1, 10, 'A skittish goblin with a rusty dagger.'),
    (2, 'Gray Wolf',      2,  45,  7, 2, 1, 1,  35, 11, 15, 'Lean and quick, hunts in the hills.'),
    (3, 'Road Bandit',    3,  70, 10, 3, 2, 2,  60,  2, 15, 'A desperate outlaw preying on travelers.'),
    (4, 'Cave Ogre',      5, 140, 16, 4, 4, 3, 120,  9, 12, 'A hulking brute that fills the tunnel.'),
    (5, 'Goblin Warlord', 3,  90, 12, 3, 2, 2,  80,  2, 20, 'The banner-bearer of the warren.'),
    (6, 'Crypt Guardian', 6, 200, 20, 6, 6, 4, 200, 14, 25, 'An animated colossus of bone and iron.');

-- Explorable locations. Sites grant an ongoing settlement rate bonus on clear;
-- dungeons instead give better reward loot. reward_item_id is granted on clear.
CREATE TABLE IF NOT EXISTS locations (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type             ENUM('site','dungeon') NOT NULL,
    name             VARCHAR(64)  NOT NULL,
    description      VARCHAR(255) NULL,
    level            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    bonus_gold_rate  INT NOT NULL DEFAULT 0,
    bonus_wood_rate  INT NOT NULL DEFAULT 0,
    bonus_stone_rate INT NOT NULL DEFAULT 0,
    bonus_regen      INT NOT NULL DEFAULT 0,   -- extra HP/min granted to the hero on clear
    min_perception   SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- perception needed to discover it
    reward_item_id   INT UNSIGNED NULL,
    FOREIGN KEY (reward_item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The ordered monster encounters that make up a location.
CREATE TABLE IF NOT EXISTS location_stages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_id INT UNSIGNED NOT NULL,
    stage_no    SMALLINT UNSIGNED NOT NULL,
    monster_id  INT UNSIGNED NOT NULL,
    is_boss     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_location_stage (location_id, stage_no),
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
    FOREIGN KEY (monster_id)  REFERENCES monsters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A location a player has discovered, with how far they've cleared it.
CREATE TABLE IF NOT EXISTS player_locations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id     INT UNSIGNED NOT NULL,
    location_id   INT UNSIGNED NOT NULL,
    progress      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    state         ENUM('active', 'cleared') NOT NULL DEFAULT 'active',
    discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cleared_at    DATETIME NULL,
    UNIQUE KEY uq_player_location (player_id, location_id),
    FOREIGN KEY (player_id)   REFERENCES players(id)   ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO locations
    (id, type, name, description, level, bonus_gold_rate, bonus_wood_rate, bonus_stone_rate, reward_item_id) VALUES
    (1, 'site',    'Abandoned Mine',   'Goblins have moved into the old shafts.',    1,  5, 0, 15, 5),
    (2, 'site',    'Whispering Grove',  'A wolf pack dens beneath the ancient oaks.', 2,  0, 20, 0, 7),
    (3, 'dungeon', 'Sunken Crypt',      'A flooded tomb, and something guards it.',   4,  0, 0, 0, 9);

INSERT IGNORE INTO location_stages
    (location_id, stage_no, monster_id, is_boss) VALUES
    (1, 1, 1, 0), (1, 2, 1, 0), (1, 3, 3, 1),               -- Mine: Goblin, Goblin, Bandit (boss)
    (2, 1, 2, 0), (2, 2, 2, 0), (2, 3, 5, 1),               -- Grove: Wolf, Wolf, Warlord (boss)
    (3, 1, 3, 0), (3, 2, 2, 0), (3, 3, 4, 0), (3, 4, 6, 1); -- Crypt: Bandit, Wolf, Ogre, Guardian (boss)

-- A site whose ongoing reward is faster HP regeneration.
INSERT IGNORE INTO locations
    (id, type, name, description, level, bonus_regen, reward_item_id) VALUES
    (4, 'site', 'Sacred Spring', 'Healing waters, jealously guarded.', 2, 30, 18);
INSERT IGNORE INTO location_stages (location_id, stage_no, monster_id, is_boss) VALUES
    (4, 1, 2, 0), (4, 2, 3, 0), (4, 3, 5, 1);               -- Spring: Wolf, Bandit, Warlord (boss)

-- Perception thresholds to discover each location (idempotent).
UPDATE locations SET min_perception = 2 WHERE id = 1;   -- Abandoned Mine
UPDATE locations SET min_perception = 4 WHERE id = 2;   -- Whispering Grove
UPDATE locations SET min_perception = 8 WHERE id = 3;   -- Sunken Crypt
UPDATE locations SET min_perception = 4 WHERE id = 4;   -- Sacred Spring
