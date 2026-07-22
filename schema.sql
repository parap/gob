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

    last_loot_at DATETIME NULL,          -- cooldown marker for loot searches
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
