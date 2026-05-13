#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${PLAYGROUND_URL:-http://localhost:8080}"

pass=0
fail=0

check() {
    local label="$1"
    local result="$2"
    local expected="$3"
    if [ "$result" = "$expected" ]; then
        echo "[OK]   $label"
        ((pass++))
    else
        echo "[FAIL] $label (got: $result, expected: $expected)"
        ((fail++))
    fi
}

# T1 — 64 KB+1 payload → HTTP 413
LARGE_PAYLOAD=$(python3 -c "import json,sys; print(json.dumps({'markdown': 'A' * 65537}))")
STATUS=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" \
    -d "$LARGE_PAYLOAD" \
    "$BASE_URL/render")
check "POST >64KB returns 413" "$STATUS" "413"

# T2 — Security headers present on render endpoint
HEADERS=$(curl -s -I -X POST \
    -H "Content-Type: application/json" \
    -d '{"markdown":"# test"}' \
    "$BASE_URL/render")

XCTO=$(echo "$HEADERS" | grep -i "x-content-type-options" | tr -d '\r\n' | sed 's/.*: //')
check "render.php X-Content-Type-Options: nosniff" "$XCTO" "nosniff"

XFO=$(echo "$HEADERS" | grep -i "x-frame-options" | tr -d '\r\n' | sed 's/.*: //')
check "render.php X-Frame-Options: SAMEORIGIN" "$XFO" "SAMEORIGIN"

# T3 — Security headers present on index page
INDEX_HEADERS=$(curl -s -I "$BASE_URL/")

XCTO_INDEX=$(echo "$INDEX_HEADERS" | grep -i "x-content-type-options" | tr -d '\r\n' | sed 's/.*: //')
check "index page X-Content-Type-Options: nosniff" "$XCTO_INDEX" "nosniff"

XFO_INDEX=$(echo "$INDEX_HEADERS" | grep -i "x-frame-options" | tr -d '\r\n' | sed 's/.*: //')
check "index page X-Frame-Options: SAMEORIGIN" "$XFO_INDEX" "SAMEORIGIN"

# T4 — Container runs as non-root
WHOAMI=$(docker compose exec -T playground whoami 2>/dev/null || echo "docker-unavailable")
if [ "$WHOAMI" = "docker-unavailable" ]; then
    echo "[SKIP] non-root check (Docker Compose not available)"
else
    check "container user is not root" "$WHOAMI" "www"
fi

# T5 — PHP errors do not leak details
ERROR_BODY=$(curl -s -X POST \
    -H "Content-Type: application/json" \
    -d '{"not_markdown": true}' \
    "$BASE_URL/render")
HAS_TRACE=$(echo "$ERROR_BODY" | grep -c "Fatal\|Warning\|stack trace\|X-Powered-By" || true)
check "PHP error body contains no stack trace" "$HAS_TRACE" "0"

echo ""
echo "Results: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
