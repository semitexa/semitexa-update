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

# The restart under the update lock update:packages:auto took and released:
# a bin/semitexa update starting in between had its one-off container killed
# by server:restart mid-composer, vendor/ half-updated. flock is util-linux,
# on every supported host; without it, or with a lock file this user cannot
# open, the restart runs unguarded rather than not at all.
restart_stack() {
    mkdir -p var/lock 2>/dev/null || true
    if command -v flock >/dev/null 2>&1 && { [ -r var/lock/semitexa-update.lock ] || : >> var/lock/semitexa-update.lock; } 2>/dev/null; then
        flock var/lock/semitexa-update.lock ./bin/semitexa server:restart
    else
        ./bin/semitexa server:restart
    fi
}

cd "${PROJECT_ROOT}"

if OUTPUT="$(./bin/semitexa update:packages:auto --json)"; then
    STATUS=0
else
    STATUS=$?
fi

printf '%s\n' "${OUTPUT}"

JSON_PAYLOAD="$(json_line "${OUTPUT}")"
DEPLOY_STATUS="$(json_value "${JSON_PAYLOAD}" status)"

if [ "${STATUS}" -ne 0 ]; then
    # A failed deploy rolled back inside the app container, where nothing
    # restarts: the old vendor/ is back, but only a restart puts every
    # container on it.
    if [ "${DEPLOY_STATUS}" = "failed" ] && [ "$(json_value "${JSON_PAYLOAD}" restart_required)" = "true" ]; then
        echo "The deploy failed and was rolled back; restarting the stack onto the restored release." >&2
        restart_stack || true
    fi
    exit "${STATUS}"
fi

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
# Back to the release before when the new one does not come up, then exit 1.
# The next tick does not deploy it again (update:packages:auto skips releases
# a rollback backed out); without the rollback it stayed live.
roll_back_and_fail() {
    local output="" json
    if output="$(./bin/semitexa update:packages:rollback --json)"; then
        :
    fi
    printf '%s\n' "${output}"
    json="$(json_line "${output}")"
    if [ "$(json_value "${json}" status)" = "rolled_back" ]; then
        echo "Rolled back to the release before the deploy." >&2
        # Inside the app container the rollback cannot restart the stack itself.
        if [ "$(json_value "${json}" restart_required)" = "true" ]; then
            restart_stack || true
        fi
    else
        echo "Not rolled back: see the line above. The failing release is still live." >&2
    fi
    exit 1
}

if ! restart_stack; then
    echo "Restart onto the new release failed." >&2
    roll_back_and_fail
fi

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

# Never print the query string or credentials of the URL. Credentials first,
# up to the last @ before the path: a password with a # or ? in it was cut
# there and its first half printed.
DISPLAY_URL="$(printf '%s' "${HEALTHCHECK_URL}" | sed -E 's#^([A-Za-z][A-Za-z0-9+.-]*://)[^/]*@#\1#')"
DISPLAY_URL="${DISPLAY_URL%%\?*}"
DISPLAY_URL="${DISPLAY_URL%%#*}"

# The URL goes to curl on stdin, not in its argv, where ps shows it to every
# user on the host. curl's config syntax: backslash and quote escaped.
CURL_URL_CONFIG="url = \"$(printf '%s' "${HEALTHCHECK_URL}" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')\""

# The server takes a moment to come back after the restart. A 2xx answer,
# as update:packages:auto's own check requires; a redirect is not one.
for attempt in 1 2 3 4 5 6; do
    CODE="$(printf '%s\n' "${CURL_URL_CONFIG}" | curl -sS -o /dev/null -w '%{http_code}' --max-time 10 -A 'Semitexa-Auto-Deploy' --config - 2>/dev/null || true)"
    case "${CODE}" in
        2[0-9][0-9]) exit 0 ;;
    esac
    if [ "${attempt}" -lt 6 ]; then
        sleep 5
    fi
done

echo "Health check failed for ${DISPLAY_URL}." >&2

roll_back_and_fail
