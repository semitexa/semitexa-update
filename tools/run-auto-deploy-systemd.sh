#!/usr/bin/env bash
set -euo pipefail

# Runs on the host, which has no PHP (the runtime is in the containers): the
# three top-level values it needs are read from the commands' one-line JSON
# with sed, and curl (which update:packages:bootstrap-remote installs) makes
# the health check.

PROJECT_ROOT="${1:-}"

if [ -z "${PROJECT_ROOT}" ]; then
    echo "Usage: run-auto-deploy-systemd.sh <project-root>" >&2
    exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
    echo "run-auto-deploy-systemd.sh needs curl on the host (apt-get install curl)." >&2
    exit 2
fi

# A scalar of a one-line JSON object, its first occurrence: the keys read
# here (status, restart_required, healthcheck_url) come before anything
# nested in what the commands print. A string (with \/ and \" as json_encode
# writes them; nothing else is unescaped, so keep the health-check URL ASCII),
# true, false, null or a number; empty when absent.
json_value() {
    local json="$1" key="$2" value
    value="$(printf '%s' "${json}" | { grep -oE '[,{]"'"${key}"'":("([^"\\]|\\.)*"|true|false|null|-?[0-9.]+)' || true; } | head -n 1)"
    value="${value#?\"${key}\":}"
    case "${value}" in
        \"*\") value="${value#\"}"; value="${value%\"}"; value="${value//\\\//\/}"; value="${value//\\\"/\"}" ;;
    esac
    printf '%s' "${value}"
}

# The JSON line, past any line bin/semitexa prints itself (an [INFO] when it
# starts the stack).
json_line() {
    printf '%s\n' "$1" | { grep -E '^\{' || true; } | tail -n 1
}

cd "${PROJECT_ROOT}"

if OUTPUT="$(./bin/semitexa update:packages:auto --json)"; then
    STATUS=0
else
    STATUS=$?
fi

printf '%s\n' "${OUTPUT}"

if [ "${STATUS}" -ne 0 ]; then
    exit "${STATUS}"
fi

JSON_PAYLOAD="$(json_line "${OUTPUT}")"
DEPLOY_STATUS="$(json_value "${JSON_PAYLOAD}" status)"

if [ -z "${DEPLOY_STATUS}" ]; then
    echo "Failed to decode update:packages:auto JSON output." >&2
    exit 2
fi

if [ "${DEPLOY_STATUS}" != "updated" ] || [ "$(json_value "${JSON_PAYLOAD}" restart_required)" != "true" ]; then
    exit 0
fi

# A restart, not `server:start`: on a running stack `up -d` recreates
# nothing, and classes the server loaded before the update stay old. Every
# container, not only app: the worker container runs the queue, scheduler and
# mail workers on the same code.
./bin/semitexa server:restart

if ! CHECK_OUTPUT="$(./bin/semitexa update:packages:check --json 2>/dev/null)"; then
    echo "Failed to inspect health check configuration after restart-required deployment." >&2
    exit 1
fi

HEALTHCHECK_JSON="$(json_line "${CHECK_OUTPUT}")"
if [ -z "${HEALTHCHECK_JSON}" ]; then
    echo "Failed to decode update:packages:check JSON output." >&2
    exit 2
fi

HEALTHCHECK_URL="$(json_value "${HEALTHCHECK_JSON}" healthcheck_url)"
if [ "${HEALTHCHECK_URL}" = "null" ]; then
    HEALTHCHECK_URL=""
fi

if [ -z "${HEALTHCHECK_URL}" ]; then
    exit 0
fi

# Never print the query string or credentials of the URL.
DISPLAY_URL="${HEALTHCHECK_URL%%\?*}"
DISPLAY_URL="$(printf '%s' "${DISPLAY_URL}" | sed -E 's#^([a-z]+://)[^@/]*@#\1#')"

# The server takes a moment to come back after the restart. A 2xx answer,
# as update:packages:auto's own check requires; a redirect is not one.
for attempt in 1 2 3 4 5 6; do
    CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 -A 'Semitexa-Auto-Deploy' "${HEALTHCHECK_URL}" 2>/dev/null || true)"
    case "${CODE}" in
        2[0-9][0-9]) exit 0 ;;
    esac
    if [ "${attempt}" -lt 6 ]; then
        sleep 5
    fi
done

echo "Health check failed for ${DISPLAY_URL}." >&2
exit 1
