#!/usr/bin/env bash
# Run phpunit against a throwaway postgres:16-alpine (the database Firefly really runs on).
# SQLite stays the default for the rest of the suite; phpunit.xml <env> does not override
# variables that are already set, so the DB_* below win.
# Usage: scripts/test-pgsql.sh [phpunit args]
#   no args: the five test groups (PairMergeTest, PairMergeStateTest, PairMergeFixesTest, PairConcurrency, PlaidLink|PlaidAccountDelete) run in PARALLEL, each in
#            its own isolated postgres stack; each group's wall time is printed; exit 1 if any group failed.
#            Measured 2026-10-08 on a busy 24-core box: the whole run is about the slowest group (see the printed wall times).
#   args:    one stack, those phpunit args (a run never stops at the first failure and fails on skipped tests).
# Needs docker and a vendor/ dir (copy it out of the image:
#   c=$(docker create fireflyiii/core:version-6.7.7); docker cp $c:/var/www/html/vendor ./vendor; docker rm $c)
set -euo pipefail
cd "$(dirname "$0")/.."
if [ $# -eq 0 ]; then
  LOG=$(mktemp -d)
  trap 'rm -rf "$LOG"' EXIT
  GROUPS_=(PairMerge PairState PairFixes PairConcurrency PlaidLink)
  declare -A FILTER=([PairMerge]='PairMergeTest' [PairState]='PairMergeStateTest' [PairFixes]='PairMergeFixesTest' [PairConcurrency]='PairConcurrency' [PlaidLink]='PlaidLink|PlaidAccountDelete')
  # the keys are written once here, so three parallel stacks do not race on the shared storage/ directory
  mkdir -p storage
  [ -f storage/oauth-private.key ] || docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/var/www/html -w /var/www/html --entrypoint php "${FIREFLY_IMAGE:-fireflyiii/core:version-6.7.7}" artisan passport:keys -q >/dev/null 2>&1 || true
  T0=$(date +%s.%N)
  declare -A PID
  for g in "${GROUPS_[@]}"; do
    ( s=$(date +%s.%N); rc=0; "$0" --filter "${FILTER[$g]}" tests/integration >"$LOG/$g.log" 2>&1 || rc=$?
      printf '%s %.1f\n' "$rc" "$(echo "$(date +%s.%N) - $s" | bc)" >"$LOG/$g.rc" ) &
    PID[$g]=$!
  done
  FAILED=0
  for g in "${GROUPS_[@]}"; do
    wait "${PID[$g]}" || true
    read -r rc wall <"$LOG/$g.rc"
    echo "=== $g: exit $rc, wall ${wall} s ==="
    tail -n 6 "$LOG/$g.log"
    [ "$rc" -eq 0 ] || { FAILED=1; echo "--- $g failed, full log ---"; cat "$LOG/$g.log"; }
  done
  printf 'total wall %.1f s\n' "$(echo "$(date +%s.%N) - $T0" | bc)"
  exit $FAILED
fi
IMAGE=${FIREFLY_IMAGE:-fireflyiii/core:version-6.7.7}
NAME=plaid-pgtest-$$
CONF=.phpunit.run.$$.xml
trap 'docker rm -f "$NAME-db" >/dev/null 2>&1 || true; docker network rm "$NAME" >/dev/null 2>&1 || true; rm -f "$CONF"' EXIT
# phpunit.xml stops at the first failure; a pair run reports every failure
sed -e 's/stopOnError="true"/stopOnError="false"/' -e 's/stopOnFailure="true"/stopOnFailure="false"/' phpunit.xml >"$CONF"
docker network create "$NAME" >/dev/null
docker run -d --name "$NAME-db" --network "$NAME" -e POSTGRES_PASSWORD=t -e POSTGRES_DB=firefly --tmpfs /var/lib/postgresql/data \
  postgres:16-alpine -c fsync=off >/dev/null
until docker exec "$NAME-db" pg_isready -U postgres -d firefly >/dev/null 2>&1; do sleep 0.2; done
ARGS=("$@")
mkdir -p bootstrap/cache .phpunit.cache storage/framework/{cache,sessions,views} storage/logs storage/database
docker run --rm --user "$(id -u):$(id -g)" --network "$NAME" -v "$PWD":/var/www/html -w /var/www/html \
  -e DB_CONNECTION=pgsql -e DB_HOST="$NAME-db" -e DB_PORT=5432 -e DB_DATABASE=firefly -e DB_USERNAME=postgres -e DB_PASSWORD=t -e APP_KEY=SomeRandomStringOf32CharsExactly -e CONF="$CONF" \
  --entrypoint sh "$IMAGE" -c '[ -f storage/oauth-private.key ] || php artisan passport:keys -q; exec php vendor/bin/phpunit -c "$CONF" --no-coverage --cache-directory /tmp/phpunit-cache --fail-on-skipped "$@"' sh "${ARGS[@]}"
