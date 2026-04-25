#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="${1:-}"

if [ -z "${PROJECT_ROOT}" ]; then
    echo "Usage: run-auto-deploy-systemd.sh <project-root>" >&2
    exit 1
fi

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

JSON_PAYLOAD="${OUTPUT}"

if [ -z "${JSON_PAYLOAD}" ]; then
    echo "Failed to locate update:packages:auto JSON payload." >&2
    exit 2
fi

PARSED_PAYLOAD="$(printf '%s' "${JSON_PAYLOAD}" | php -r '
$data = json_decode(stream_get_contents(STDIN), true);
if (!is_array($data)) {
    fwrite(STDERR, "Failed to decode update:packages:auto JSON output.\n");
    exit(2);
}

$updated = ($data["status"] ?? null) === "updated";
$restartRequired = (bool) ($data["restart_required"] ?? false);
echo ($updated ? "1" : "0"), "\t", (($updated && $restartRequired) ? "1" : "0");
')"

IFS=$'\t' read -r UPDATED RESTART_REQUIRED <<< "${PARSED_PAYLOAD}"

if [ "${RESTART_REQUIRED}" = "1" ]; then
    ./bin/semitexa server:start
fi

HEALTHCHECK_URL=""
if [ "${UPDATED}" = "1" ] && [ "${RESTART_REQUIRED}" = "1" ]; then
    if ! HEALTHCHECK_JSON="$(./bin/semitexa update:packages:check --json 2>/dev/null)"; then
        echo "Failed to inspect health check configuration after restart-required deployment." >&2
        exit 1
    fi

    HEALTHCHECK_URL="$(printf '%s' "${HEALTHCHECK_JSON}" | php -r '
$data = json_decode(stream_get_contents(STDIN), true);
if (!is_array($data)) {
    fwrite(STDERR, "Failed to decode update:packages:check JSON output.\n");
    exit(2);
}

$url = $data["healthcheck_url"] ?? "";
if (is_string($url)) {
    echo $url;
}
')"
fi

if [ "${UPDATED}" = "1" ] && [ "${RESTART_REQUIRED}" = "1" ] && [ -n "${HEALTHCHECK_URL}" ]; then
    php -r '
$url = $argv[1] ?? "";
if ($url === "") {
    fwrite(STDERR, "Health check URL is empty.\n");
    exit(2);
}

$displayUrl = $url;
$parts = parse_url($url);
if (is_array($parts)) {
    $scheme = isset($parts["scheme"]) ? $parts["scheme"] . "://" : "";
    $host = $parts["host"] ?? "";
    $port = isset($parts["port"]) ? ":" . $parts["port"] : "";
    $path = $parts["path"] ?? "";
    $displayUrl = $scheme . $host . $port . $path;
}

$context = stream_context_create([
    "http" => [
        "timeout" => 10,
        "ignore_errors" => true,
        "header" => "User-Agent: Semitexa-Dev-Auto-Deploy\r\n",
    ],
]);

$response = @file_get_contents($url, false, $context);
$statusCode = 0;
if (isset($http_response_header[0]) && preg_match("/\s(\d{3})\s/", $http_response_header[0], $m) === 1) {
    $statusCode = (int) $m[1];
}

if ($response === false || $statusCode < 200 || $statusCode >= 300) {
    fwrite(STDERR, "Health check failed for {$displayUrl}.\n");
    exit(1);
}
' "${HEALTHCHECK_URL}"
fi
