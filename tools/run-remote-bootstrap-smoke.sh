#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PROJECT_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/../../.." && pwd)"
HARNESS_ROOT="${SCRIPT_DIR}/remote-smoke-target"
RUN_ID="$(date -u +%Y%m%d%H%M%S)-$$"
WORK_ROOT="/tmp/semitexa-remote-smoke/${RUN_ID}"
SHARED_ROOT="${WORK_ROOT}/shared"
SSH_DIR="${WORK_ROOT}/ssh"
SSH_PORT="${REMOTE_SMOKE_SSH_PORT:-22220}"
DEPLOY_PATH="${SHARED_ROOT}/deployment"
COMPOSE_FILE="${HARNESS_ROOT}/docker-compose.yml"
KEY_PATH="${SSH_DIR}/id_ed25519"
LOG_PATH="${WORK_ROOT}/bootstrap-output.log"
KEEP_ARTIFACTS="${KEEP_REMOTE_SMOKE_ARTIFACTS:-0}"

mkdir -p "${SHARED_ROOT}" "${SSH_DIR}"

cleanup() {
    set +e

    if [ -d "${DEPLOY_PATH}" ] && [ -f "${DEPLOY_PATH}/docker-compose.yml" ]; then
        docker compose -f "${DEPLOY_PATH}/docker-compose.yml" down --remove-orphans >/dev/null 2>&1 || true
    fi

    AUTHORIZED_KEY='' REMOTE_SMOKE_SHARED_ROOT="${SHARED_ROOT}" REMOTE_SMOKE_SSH_PORT="${SSH_PORT}" \
        docker compose -f "${COMPOSE_FILE}" down --remove-orphans --volumes >/dev/null 2>&1 || true

    if [ "${KEEP_ARTIFACTS}" != "1" ]; then
        rm -rf "${WORK_ROOT}"
    else
        printf 'Smoke artifacts kept at %s\n' "${WORK_ROOT}"
    fi
}

trap cleanup EXIT

ssh-keygen -t ed25519 -N '' -f "${KEY_PATH}" >/dev/null
AUTHORIZED_KEY="$(cat "${KEY_PATH}.pub")"

export AUTHORIZED_KEY
export REMOTE_SMOKE_SHARED_ROOT="${SHARED_ROOT}"
export REMOTE_SMOKE_SSH_PORT="${SSH_PORT}"

docker compose -f "${COMPOSE_FILE}" up -d --build

for _attempt in $(seq 1 30); do
    if ssh -i "${KEY_PATH}" -p "${SSH_PORT}" \
        -o BatchMode=yes \
        -o StrictHostKeyChecking=accept-new \
        deploy@127.0.0.1 'printf ready' >/dev/null 2>&1; then
        break
    fi

    if [ "${_attempt}" -eq 30 ]; then
        echo "Remote smoke target did not become reachable over SSH." >&2
        exit 1
    fi

    sleep 1
done

export SEMITEXA_REMOTE_DEPLOY_SSH_PORT="${SSH_PORT}"
export HOME="${WORK_ROOT}/home"
mkdir -p "${HOME}"
mkdir -p "${HOME}/.ssh"
cp "${KEY_PATH}" "${HOME}/.ssh/id_ed25519"
cp "${KEY_PATH}.pub" "${HOME}/.ssh/id_ed25519.pub"
chmod 700 "${HOME}/.ssh"
chmod 600 "${HOME}/.ssh/id_ed25519"
chmod 644 "${HOME}/.ssh/id_ed25519.pub"
export SEMITEXA_REMOTE_DEPLOY_SSH_IDENTITY_FILE="${HOME}/.ssh/id_ed25519"
export REMOTE_SMOKE_TARGET="deploy@127.0.0.1"
export REMOTE_SMOKE_DEPLOY_PATH="${DEPLOY_PATH}"

php "${SCRIPT_DIR}/run-remote-bootstrap-smoke.php" 2>&1 | tee "${LOG_PATH}"

if [ ! -f "${DEPLOY_PATH}/.semitexa-deployment.json" ]; then
    echo "Remote deployment marker was not created during smoke run." >&2
    exit 1
fi

printf '\nSmoke run completed.\n'
printf 'Marker: %s\n' "${DEPLOY_PATH}/.semitexa-deployment.json"
printf 'Log: %s\n' "${LOG_PATH}"
