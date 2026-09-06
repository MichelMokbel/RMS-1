#!/bin/sh

set -eu

env_file=/opt/layla-dev/env/rms.env

if [ ! -f "$env_file" ]; then
    echo "RMS development environment file is missing." >&2
    exit 1
fi

set_flag() {
    key="$1"
    if grep -q "^${key}=" "$env_file"; then
        sed -i "s/^${key}=.*/${key}=true/" "$env_file"
    else
        printf '\n%s=true\n' "$key" >> "$env_file"
    fi
}

set_flag MEMBERSHIP_CHECKOUT_ENABLED
set_flag MEMBERSHIP_QUEUE_ENABLED
set_flag MEMBERSHIP_BOOKING_ENABLED
