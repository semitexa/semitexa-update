#!/usr/bin/env bash
set -euo pipefail

AUTHORIZED_KEY="${AUTHORIZED_KEY:-}"

if [ -z "$AUTHORIZED_KEY" ]; then
    echo "AUTHORIZED_KEY is required." >&2
    exit 1
fi

printf '%s\n' "$AUTHORIZED_KEY" > /home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys

exec /usr/sbin/sshd -D -e
