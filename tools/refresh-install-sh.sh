#!/usr/bin/env bash
# Refresh the project's local copy of install.sh from upstream master.
#
# Decoupled from semitexa-auto-deploy so that public installer freshness
# does not depend on a successful Composer deploy. This used to live as an
# ExecStartPost drop-in on semitexa-auto-deploy.service, where it only ran
# when the deploy succeeded — meaning a stuck deploy silently froze the
# installer too. Now it has its own service + timer.
#
# Atomic: writes to a sibling temp file then mv -f so a partial download
# can never replace the live install.sh.
set -euo pipefail

PROJECT_ROOT="${1:-}"

if [ -z "${PROJECT_ROOT}" ]; then
    echo "Usage: refresh-install-sh.sh <project-root>" >&2
    exit 1
fi

if [ ! -d "${PROJECT_ROOT}/packages/semitexa-ultimate" ]; then
    echo "Refusing to refresh: ${PROJECT_ROOT}/packages/semitexa-ultimate not found." >&2
    exit 1
fi

SOURCE_URL="${SEMITEXA_INSTALL_SH_SOURCE_URL:-https://raw.githubusercontent.com/semitexa/semitexa-ultimate/master/install.sh}"
DEST="${PROJECT_ROOT}/packages/semitexa-ultimate/install.sh"
DEST_DIR="$(dirname "${DEST}")"

TMP="$(mktemp "${DEST_DIR}/.install.sh.XXXXXX")"
trap 'rm -f "${TMP}"' EXIT

if ! curl -fsSL --retry 3 --max-time 30 "${SOURCE_URL}" -o "${TMP}"; then
    echo "Failed to fetch ${SOURCE_URL}" >&2
    exit 1
fi

if [ ! -s "${TMP}" ]; then
    echo "Fetched install.sh is empty." >&2
    exit 1
fi

if ! head -c 2 "${TMP}" | grep -q '^#!'; then
    echo "Fetched install.sh does not start with a shebang — refusing to install." >&2
    exit 1
fi

if [ -f "${DEST}" ]; then
    OWNER="$(stat -c '%u:%g' "${DEST}")"
    chown "${OWNER}" "${TMP}" 2>/dev/null || true
fi
chmod 0775 "${TMP}"

mv -f "${TMP}" "${DEST}"
trap - EXIT

echo "Refreshed: ${DEST}"
