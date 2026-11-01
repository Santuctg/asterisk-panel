#!/usr/bin/env bash
set -Eeuo pipefail

source /etc/asterisk-panel/ssl-helper.conf
STATE_FILE="${APP_ROOT}/storage/acme/acme-dns.json"
[[ -f "$STATE_FILE" && ! -L "$STATE_FILE" ]] || { echo "Secure ACME-DNS state is missing." >&2; exit 1; }

mapfile -t ACME < <(python3 - "$STATE_FILE" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
for key in ("domain", "api_base", "username", "password", "subdomain", "fulldomain"):
    print(state.get(key, ""))
PY
)

DOMAIN="${ACME[0]:-}"
API_BASE="${ACME[1]:-}"
USERNAME="${ACME[2]:-}"
PASSWORD="${ACME[3]:-}"
SUBDOMAIN="${ACME[4]:-}"
FULLDOMAIN="${ACME[5]:-}"

[[ "$CERTBOT_DOMAIN" == "$DOMAIN" ]] || { echo "Certificate domain does not match registered domain." >&2; exit 1; }
[[ "$API_BASE" == "https://auth.acme-dns.io" ]] || { echo "Unexpected ACME-DNS API endpoint." >&2; exit 1; }
[[ "$USERNAME" =~ ^[A-Za-z0-9_-]{20,}$ && "$PASSWORD" =~ ^[A-Za-z0-9_-]{20,}$ ]] || { echo "Invalid ACME-DNS credentials." >&2; exit 1; }
[[ "$SUBDOMAIN" =~ ^[A-Za-z0-9-]{20,}$ ]] || { echo "Invalid ACME-DNS subdomain." >&2; exit 1; }

PAYLOAD="$(python3 - "$SUBDOMAIN" "$CERTBOT_VALIDATION" <<'PY'
import json, sys
print(json.dumps({"subdomain": sys.argv[1], "txt": sys.argv[2]}))
PY
)"

curl --fail --silent --show-error \
    -H "Content-Type: application/json" \
    -H "X-Api-User: ${USERNAME}" \
    -H "X-Api-Key: ${PASSWORD}" \
    --data "$PAYLOAD" \
    "${API_BASE}/update" >/dev/null

# Wait until a public recursive lookup sees the exact challenge value.
for _ in $(seq 1 30); do
    if dig +short TXT "$FULLDOMAIN" | tr -d '"' | grep -Fx -- "$CERTBOT_VALIDATION" >/dev/null; then
        exit 0
    fi
    sleep 4
done

echo "Timed out waiting for the ACME-DNS TXT record to propagate." >&2
exit 1
