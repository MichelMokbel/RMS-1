#!/bin/sh

set -eu

archive="$(mktemp /opt/layla-dev/incoming/orders-image.XXXXXX.tar.gz)"
lock_file=/run/lock/layla-dev-deploy.lock

cleanup() {
    rm -f "$archive"
}

rollback() {
    if docker image inspect layla-orders-dev:previous >/dev/null 2>&1; then
        docker tag layla-orders-dev:previous layla-orders-dev:latest
        docker compose --project-directory /opt/layla-dev --file /opt/layla-dev/compose.yaml up -d --no-deps --force-recreate orders
    fi
}

trap cleanup EXIT INT TERM

exec 9>"$lock_file"
flock -w 300 9

cat > "$archive"
gzip -t "$archive"

if docker image inspect layla-orders-dev:latest >/dev/null 2>&1; then
    docker tag layla-orders-dev:latest layla-orders-dev:previous
fi

gzip -dc "$archive" | docker load

if ! docker compose --project-directory /opt/layla-dev --file /opt/layla-dev/compose.yaml up -d --no-deps --force-recreate orders; then
    rollback
    exit 1
fi

attempt=0
while [ "$attempt" -lt 30 ]; do
    if curl --fail --silent --show-error --header 'X-Forwarded-Proto: https' http://127.0.0.1:8098/ >/dev/null 2>&1; then
        exit 0
    fi
    attempt=$((attempt + 1))
    sleep 2
done

docker compose --project-directory /opt/layla-dev --file /opt/layla-dev/compose.yaml logs --tail 100 orders
rollback
exit 1
