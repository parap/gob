# Goblin

A browser-based fantasy RPG in the spirit of *Mother of Learning*. You start
out playing an ordinary hack-and-slash — kill monsters, grab loot — but the
real game is about **changing your model of the world**: as you learn skills
(language, empathy, observation, lore…), the same "monsters" and places reveal
deeper layers, and you can talk, trade, and side with the creatures you used
to just fight.

See `ideas.md` for the full design direction.

## Stack

- **Backend:** PHP (JSON REST API under `/api`)
- **Database:** MySQL / MariaDB
- **Web server:** Caddy (reverse proxy + automatic HTTPS)
- **Frontend:** vanilla JavaScript (no framework, no build step)

## Tests

PHPUnit, run inside the app container — the container has the PHP 8.2+ the
project requires, and reaches both the database and the running app:

```
docker compose up -d
docker exec gob-php-1 vendor/bin/phpunit
```

Four layers, each tested where its behaviour actually lives:

- `tests/Domain` — the pure rules. No database, no clock.
- `tests/Repository` — real SQL against `gob_test`, a throwaway schema built
  from `schema.sql`. Each test deletes the players it created; the seeded
  catalogue survives. The base class refuses any database whose name does not
  end in `_test`.
- `tests/Handler` — the procedural layer, called as plain functions.
- `tests/Api` — over HTTP against the running stack, for refusals a handler
  delivers by ending the request. These use the development database and
  remove the throwaway players they register; they skip when nothing is
  serving.

`gob_test` is created with the database on a fresh volume. On a volume that
predates it:

```
docker exec gob-db-1 mysql -uroot -prootpass -e \
  "CREATE DATABASE IF NOT EXISTS gob_test; GRANT ALL ON gob_test.* TO 'gob'@'%';"
```

## Status

Early development.
