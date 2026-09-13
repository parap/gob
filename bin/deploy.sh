#!/usr/bin/env bash
#
# Puts the current commit on the server and proves the game still answers from outside.
#
#   bin/deploy.sh              # test, deploy, verify
#   bin/deploy.sh --no-tests   # skip PHPUnit (it needs the local containers up)
#
# Two steps a "git pull && docker compose up" misses, both silently: the pre-deploy dump,
# and the migrations -- schema.sql runs only while the volume is being created, so a schema
# change reaches the server through db/migrations/ or not at all, and its absence surfaces
# as a missing column on somebody's request rather than here.
#
# There is deliberately no dependency step: composer.json requires only php, phpunit is
# dev-only, and public/index.php registers its own PSR-4 loader when vendor/ is absent.
#
# What it will not do is roll back. A failed check prints the previous commit and the
# command to return to it: the checkout moves backwards easily, the database does not.

set -euo pipefail

HOST=${DEPLOY_HOST:-oracle}
DIR=${DEPLOY_DIR:-gob}
REPO=${DEPLOY_REPO:-https://github.com/parap/gob.git}
URL=${DEPLOY_URL:-https://goblin.danskidioms.com}
BRANCH=${DEPLOY_BRANCH:-main}
COMPOSE="docker compose --progress quiet"

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
fail() { printf '\033[31m%s\033[0m\n' "$*" >&2; exit 1; }

# ---- the checkout being deployed must be the one that was tested ----------------------

say "checking the working tree"
[ -z "$(git status --porcelain)" ] || fail "uncommitted changes -- commit or stash them first"
git fetch -q origin
head=$(git rev-parse HEAD)
[ "$head" = "$(git rev-parse "origin/$BRANCH")" ] \
    || fail "HEAD is not what origin/$BRANCH points at -- push first"
echo "  ${head:0:7} $(git log -1 --format=%s)"

if [ "${1:-}" != "--no-tests" ]; then
    say "running the suite"
    docker exec gob_php vendor/bin/phpunit 2>&1 | tail -3
fi

# ---- first run: the checkout and the credentials it needs -----------------------------

say "preparing $HOST:$DIR"
ssh "$HOST" "set -euo pipefail
    [ -d $DIR/.git ] || git clone -q --branch $BRANCH $REPO $DIR
    cd $DIR

    # Generated on the server, read by nothing else, and never printed. The values the
    # laptop does not know and no transcript records; the passwords in this repository's
    # history are development ones and must not become the deployment's.
    #
    # Key by key rather than all-or-nothing, so a key added later lands on a server whose
    # .env already exists -- the alternative is a variable that is simply missing, and an
    # empty GOB_INVITE_CODE means the gate is open, which fails in the unsafe direction.
    umask 077
    touch .env
    add_key() {
        grep -q \"^\$1=\" .env && return 0
        echo \"\$1=\$2\" >> .env
        echo \"  .env: added \$1\"
    }
    secret() { openssl rand -base64 24 | tr -d '/+=' | head -c 32; }

    add_key DB_PASS \"\$(secret)\"
    add_key MYSQL_ROOT_PASSWORD \"\$(secret)\"
    add_key WEB_BIND 127.0.0.1:8090
    add_key PMA_BIND 127.0.0.1:8091
    add_key DB_BIND 127.0.0.1:3307
    # Non-empty, so a public name can be looked at but not signed up to.
    add_key GOB_INVITE_CODE \"\$(secret)\"
    chmod 600 .env

    docker network inspect edge >/dev/null 2>&1 || docker network create edge >/dev/null
"

# ---- the server -----------------------------------------------------------------------

say "deploying"
previous=$(ssh "$HOST" "cd $DIR && git rev-parse HEAD")
echo "  currently at ${previous:0:7}"

ssh "$HOST" "set -euo pipefail
    cd $DIR
    git pull -q origin $BRANCH
    echo '  now at' \$(git rev-parse --short HEAD)

    $COMPOSE up -d --build --remove-orphans

    # Compose returns when the containers start, which is before MySQL will answer. On a
    # first deploy it is also still importing schema.sql.
    for i in \$(seq 1 45); do
        curl -sf -m 5 -o /dev/null http://127.0.0.1:8090/ && break
        sleep 2
    done

    # Before the migrations, not after. git reset returns the code; nothing returns a
    # column a migration dropped, so this is the only thing between a bad migration and
    # every account on the server.
    # Assigned on its own line, not interpolated into an echo. A command substitution
    # used as an argument reports its status to nobody: the echo succeeds, set -e sees
    # nothing, and a refused dump is followed straight into the migrations it exists to
    # protect against. An assignment carries the status, so a failed dump stops here.
    dump=\$(bin/backup-db.sh pre-deploy \$(git rev-parse --short HEAD))
    echo \"  dumped to \$dump\"

    # schema.sql only ever ran while the volume was being created, so a schema change
    # arrives here or not at all.
    $COMPOSE exec -T php php bin/migrate.php | sed 's/^/  /'
"

# ---- the verdict, taken from outside --------------------------------------------------

say "checking $URL from here, not from the server"
problems=0
check() {
    local what=$1 got=$2 want=$3
    if [ "$got" = "$want" ]; then
        printf '  %-34s %s\n' "$what" "$got"
    else
        printf '  \033[31m%-34s %s (expected %s)\033[0m\n' "$what" "$got" "$want"
        problems=$((problems + 1))
    fi
}
code() { curl -s -o /dev/null --connect-timeout 8 -m 25 -w '%{http_code}' "$@" || echo 000; }

check "https answers"   "$(code "$URL/")"                     200
check "http redirects"  "$(code "${URL/https:/http:}/")"      308
# The API refusing an unauthenticated call proves Caddy reached php-fpm and php-fpm reached
# MySQL. The SPA shell loading proves only that a file was served off disk.
api=$(curl -s --connect-timeout 8 -m 25 "$URL/api/world" | grep -o '"error":"Not authenticated."' || true)
check "api reaches php" "${api:-nothing}"                     '"error":"Not authenticated."'

if [ "$problems" -ne 0 ]; then
    printf '\n\033[31m%s check(s) failed. The previous commit was %s.\033[0m\n' \
        "$problems" "${previous:0:7}" >&2
    printf 'To go back:\n  ssh %s "cd %s && git reset --hard %s && %s up -d --build"\n' \
        "$HOST" "$DIR" "$previous" "$COMPOSE" >&2
    exit 1
fi

say "deployed"
