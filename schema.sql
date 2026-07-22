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

-- The equipment paperdoll: one row per slot. item_id is NULL when empty
-- and will reference a future `items` table once loot exists.
CREATE TABLE IF NOT EXISTS character_equipment (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    character_id INT UNSIGNED NOT NULL,
    slot         VARCHAR(32)  NOT NULL,
    item_id      INT UNSIGNED NULL,
    UNIQUE KEY uq_char_slot (character_id, slot),
    FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
