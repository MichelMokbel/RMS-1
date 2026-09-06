#!/bin/sh

set -eu

archive="$(mktemp /opt/layla-dev/incoming/rms-image.XXXXXX.tar.gz)"
lock_file=/run/lock/layla-dev-deploy.lock

cleanup() {
    rm -f "$archive"
}

rollback() {
    if docker image inspect layla-rms-dev:previous >/dev/null 2>&1; then
        docker tag layla-rms-dev:previous layla-rms-dev:latest
        docker compose --project-directory /opt/layla-dev --file /opt/layla-dev/compose.yaml up -d --no-deps --force-recreate rms queue scheduler
    fi
}

trap cleanup EXIT INT TERM

exec 9>"$lock_file"
flock -w 300 9

# Keep one rollback image only. Removing the obsolete rollback tag before
# receiving the next archive prevents repeated deployments from exhausting the
# small development VM disk while preserving the currently running image.
docker image rm layla-rms-dev:previous >/dev/null 2>&1 || true
docker image prune --all --force >/dev/null

cat > "$archive"
gzip -t "$archive"

if docker image inspect layla-rms-dev:latest >/dev/null 2>&1; then
    docker tag layla-rms-dev:latest layla-rms-dev:previous
fi

gzip -dc "$archive" | docker load

if ! docker compose --project-directory /opt/layla-dev --file /opt/layla-dev/compose.yaml run --rm rms php artisan migrate --force; then
    rollback
    exit 1
fi

if ! docker compose --project-directory /opt/layla-dev --file /opt/layla-dev/compose.yaml up -d --no-deps --force-recreate rms queue scheduler; then
    rollback
    exit 1
fi

attempt=0
while [ "$attempt" -lt 30 ]; do
    if curl --fail --silent --show-error http://127.0.0.1:8099/up >/dev/null 2>&1; then
        exit 0
    fi
    attempt=$((attempt + 1))
    sleep 2
done

docker compose --project-directory /opt/layla-dev --file /opt/layla-dev/compose.yaml logs --tail 100 rms queue scheduler
rollback
exit 1
