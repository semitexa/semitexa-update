#!/usr/bin/env bash
set -euo pipefail

DEPLOY_PATH="${SEMITEXA_DEPLOY_PATH:?SEMITEXA_DEPLOY_PATH is required}"
ARTIFACT_PATH="${SEMITEXA_ARTIFACT_PATH:?SEMITEXA_ARTIFACT_PATH is required}"
REMOTE_ENV_PATH="${SEMITEXA_REMOTE_ENV_PATH:-}"
FORCE_REINITIALIZE="${SEMITEXA_FORCE_REINITIALIZE:-0}"
SCENARIO_ID="${SEMITEXA_SCENARIO_ID:-ubuntu/20.04}"
DEPLOY_DOMAIN="${SEMITEXA_DEPLOY_DOMAIN:-}"
MARKER_PATH="${DEPLOY_PATH}/.semitexa-deployment.json"

if [ "$(id -u)" -eq 0 ]; then
    SUDO=""
elif command -v sudo >/dev/null 2>&1; then
    if ! sudo -n true 2>/dev/null; then
        echo "Remote bootstrap requires root or passwordless sudo." >&2
        exit 1
    fi
    SUDO="sudo"
else
    echo "Remote bootstrap requires root or passwordless sudo." >&2
    exit 1
fi

run_root() {
    if [ -n "$SUDO" ]; then
        "$SUDO" "$@"
    else
        "$@"
    fi
}

project_sh() {
    if [ -n "$SUDO" ]; then
        "$SUDO" -E "$@"
    else
        "$@"
    fi
}

docker_host_cmd() {
    if docker info >/dev/null 2>&1; then
        docker "$@"
    else
        run_root docker "$@"
    fi
}

docker_compose() {
    if docker_host_cmd compose version >/dev/null 2>&1; then
        docker_host_cmd compose "$@"
        return
    fi

    if command -v docker-compose >/dev/null 2>&1; then
        if [ -n "$SUDO" ]; then
            "$SUDO" docker-compose "$@"
        else
            docker-compose "$@"
        fi
        return
    fi

    echo "Docker Compose is not available on the remote host." >&2
    exit 1
}

compose_available() {
    docker_host_cmd compose version >/dev/null 2>&1 && return 0
    command -v docker-compose >/dev/null 2>&1 && docker-compose version >/dev/null 2>&1 && return 0
    return 1
}

ensure_bin() {
    if ! command -v "$1" >/dev/null 2>&1; then
        return 1
    fi

    return 0
}

ensure_apt_package() {
    run_root apt-get install -y "$@"
}

generate_app_secret() {
    head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
}

echo "[remote-bootstrap] Preparing Ubuntu host"
run_root apt-get update -y
ensure_apt_package ca-certificates curl jq tar git

if ! ensure_bin docker; then
    curl -fsSL https://get.docker.com | run_root sh
fi

if ! compose_available; then
    ensure_apt_package docker-compose-plugin || ensure_apt_package docker-compose-v2
fi

if command -v systemctl >/dev/null 2>&1; then
    run_root systemctl enable --now docker || true
fi

if [ "$FORCE_REINITIALIZE" = "1" ] && [ -d "$DEPLOY_PATH" ]; then
    if [ -f "${DEPLOY_PATH}/docker-compose.yml" ]; then
        (
            cd "$DEPLOY_PATH"
            docker_compose down --remove-orphans || true
        )
    fi
    run_root rm -rf "$DEPLOY_PATH"
fi

run_root mkdir -p "$DEPLOY_PATH"
run_root tar -xzf "$ARTIFACT_PATH" -C "$DEPLOY_PATH"

if [ ! -f "${DEPLOY_PATH}/bin/semitexa" ] || [ ! -f "${DEPLOY_PATH}/composer.json" ] || [ ! -f "${DEPLOY_PATH}/docker-compose.yml" ]; then
    echo "Uploaded artifact does not look like a Semitexa project." >&2
    exit 1
fi

run_root chmod +x "${DEPLOY_PATH}/bin/semitexa"

if [ -n "$REMOTE_ENV_PATH" ] && [ -f "$REMOTE_ENV_PATH" ]; then
    run_root cp "$REMOTE_ENV_PATH" "${DEPLOY_PATH}/.env"
elif [ -n "$REMOTE_ENV_PATH" ]; then
    echo "Remote environment file not found at ${REMOTE_ENV_PATH}." >&2
    exit 1
elif [ ! -f "${DEPLOY_PATH}/.env" ]; then
    APP_SECRET_VALUE="$(generate_app_secret)"
    cat <<'EOF' | run_root tee "${DEPLOY_PATH}/.env" >/dev/null
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=__SEMITEXA_APP_SECRET__
EOF
    run_root sed -i "s/__SEMITEXA_APP_SECRET__/${APP_SECRET_VALUE}/g" "${DEPLOY_PATH}/.env"
fi

run_root chown root:root "${DEPLOY_PATH}/.env" 2>/dev/null || true
run_root chmod 600 "${DEPLOY_PATH}/.env" 2>/dev/null || true

(
    cd "$DEPLOY_PATH"
    if [ ! -f "vendor/autoload.php" ] || [ ! -x "vendor/bin/semitexa" ]; then
        project_sh bin/semitexa install
    fi
    project_sh bin/semitexa server:start
    docker_compose exec -T app php vendor/bin/semitexa cache:clear
)

SOURCE_HOST="$(hostname 2>/dev/null || echo unknown)"
DEPLOYED_AT_UTC="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

jq -n \
    --arg artifact "semitexa.remote-bootstrap/v1" \
    --arg project_name "$(basename "$DEPLOY_PATH")" \
    --arg deployed_at_utc "$DEPLOYED_AT_UTC" \
    --arg source_host "$SOURCE_HOST" \
    --arg scenario "$SCENARIO_ID" \
    --arg deployment_path "$DEPLOY_PATH" \
    --arg domain "$DEPLOY_DOMAIN" \
    '{artifact: $artifact, project_name: $project_name, deployed_at_utc: $deployed_at_utc, source_host: $source_host, scenario: $scenario, deployment_path: $deployment_path, domain: $domain}' \
    | run_root tee "$MARKER_PATH" >/dev/null

echo "[remote-bootstrap] Bootstrap complete"
