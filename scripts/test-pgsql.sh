#!/usr/bin/env bash
# Run phpunit against a throwaway postgres:16-alpine (the database Firefly really runs on).
# SQLite stays the default for the rest of the suite; phpunit.xml <env> does not override
# variables that are already set, so the DB_* below win.
# Usage: scripts/test-pgsql.sh [phpunit args]   default: the Plaid link tests
# Needs docker and a vendor/ dir (copy it out of the image:
#   c=$(docker create fireflyiii/core:version-6.7.7); docker cp $c:/var/www/html/vendor ./vendor; docker rm $c)
set -euo pipefail
cd "$(dirname "$0")/.."
IMAGE=${FIREFLY_IMAGE:-fireflyiii/core:version-6.7.7}
NAME=plaid-pgtest-$$
trap 'docker rm -f "$NAME-db" >/dev/null 2>&1 || true; docker network rm "$NAME" >/dev/null 2>&1 || true' EXIT
docker network create "$NAME" >/dev/null
docker run -d --name "$NAME-db" --network "$NAME" -e POSTGRES_PASSWORD=t -e POSTGRES_DB=firefly --tmpfs /var/lib/postgresql/data \
  postgres:16-alpine -c fsync=off >/dev/null
until docker exec "$NAME-db" pg_isready -U postgres -d firefly >/dev/null 2>&1; do sleep 0.2; done
ARGS=("$@")
[ ${#ARGS[@]} -eq 0 ] && ARGS=(--filter 'PlaidLink|PlaidAccountDelete' tests/integration)
mkdir -p bootstrap/cache .phpunit.cache storage/framework/{cache,sessions,views} storage/logs storage/database
docker run --rm --user "$(id -u):$(id -g)" --network "$NAME" -v "$PWD":/var/www/html -w /var/www/html \
  -e DB_CONNECTION=pgsql -e DB_HOST="$NAME-db" -e DB_PORT=5432 -e DB_DATABASE=firefly -e DB_USERNAME=postgres -e DB_PASSWORD=t -e APP_KEY=SomeRandomStringOf32CharsExactly \
  --entrypoint sh "$IMAGE" -c '[ -f storage/oauth-private.key ] || php artisan passport:keys -q; exec php vendor/bin/phpunit --no-coverage "$@"' sh "${ARGS[@]}"
