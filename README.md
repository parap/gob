# Goblin

A browser-based fantasy RPG in the spirit of *Mother of Learning*. You start
out playing an ordinary hack-and-slash — kill monsters, grab loot — but the
real game is about **changing your model of the world**: as you learn skills
(language, empathy, observation, lore…), the same "monsters" and places reveal
deeper layers, and you can talk, trade, and side with the creatures you used
to just fight.

See `ideas.txt` for the full design direction.

## Stack

- **Backend:** PHP (JSON REST API under `/api`)
- **Database:** MySQL / MariaDB
- **Web server:** Caddy (reverse proxy + automatic HTTPS)
- **Frontend:** vanilla JavaScript (no framework, no build step)

## Status

Early development.
