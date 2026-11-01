#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

[[ ${EUID} -eq 0 ]] || { echo "This helper must run as root." >&2; exit 1; }
[[ "${1:-}" == "issue" && $# -eq 1 ]] || { echo "Usage: asterisk-panel-ssl issue" >&2; exit 2; }
[[ -f /etc/asterisk-panel/ssl-helper.conf && ! -L /etc/asterisk-panel/ssl-helper.conf ]] || { echo "Helper configuration is missing." >&2; exit 1; }
source /etc/asterisk-panel/ssl-helper.conf

STATE_FILE="${APP_ROOT}/storage/acme/acme-dns.json"
TEMPLATE=/usr/local/lib/asterisk-panel/nginx-https.conf.template
AUTH_HOOK=/usr/local/lib/asterisk-panel/acme-dns-auth
CLEANUP_HOOK=/usr/local/lib/asterisk-panel/acme-dns-cleanup
SITE=/etc/nginx/sites-available/asterisk-panel

[[ -f "$STATE_FILE" && ! -L "$STATE_FILE" ]] || { echo "Secure ACME-DNS registration is missing." >&2; exit 1; }
[[ -f "$TEMPLATE" && ! -L "$TEMPLATE" ]] || { echo "Nginx template is missing." >&2; exit 1; }
[[ -x "$AUTH_HOOK" && -x "$CLEANUP_HOOK" ]] || { echo "Certbot hooks are missing." >&2; exit 1; }
[[ -S "$PHP_SOCKET" ]] || { echo "Configured PHP-FPM socket does not exist." >&2; exit 1; }

mapfile -t STATE < <(python3 - "$STATE_FILE" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
for key in ("domain", "email", "api_base", "username", "password", "subdomain", "fulldomain"):
    print(state.get(key, ""))
PY
)
DOMAIN="${STATE[0]:-}"
EMAIL="${STATE[1]:-}"
API_BASE="${STATE[2]:-}"
USERNAME="${STATE[3]:-}"
PASSWORD="${STATE[4]:-}"
SUBDOMAIN="${STATE[5]:-}"
FULLDOMAIN="${STATE[6]:-}"

[[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || { echo "Invalid certificate domain." >&2; exit 1; }
[[ "$EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || { echo "Invalid certificate email." >&2; exit 1; }
[[ "$API_BASE" == "https://auth.acme-dns.io" ]] || { echo "Only hosted auth.acme-dns.io is allowed." >&2; exit 1; }
[[ "$USERNAME" =~ ^[A-Za-z0-9_-]{20,}$ && "$PASSWORD" =~ ^[A-Za-z0-9_-]{20,}$ ]] || { echo "Invalid ACME-DNS credentials." >&2; exit 1; }
[[ "$SUBDOMAIN" =~ ^[A-Za-z0-9-]{20,}$ ]] || { echo "Invalid ACME-DNS subdomain." >&2; exit 1; }
[[ "$FULLDOMAIN" == "${SUBDOMAIN}.auth.acme-dns.io" ]] || { echo "Invalid ACME-DNS target." >&2; exit 1; }

EXPECTED="${FULLDOMAIN}."
VISIBLE="$(dig +short CNAME "_acme-challenge.${DOMAIN}" | tail -n 1)"
[[ "${VISIBLE,,}" == "${EXPECTED,,}" ]] || { echo "Required public CNAME is not visible." >&2; exit 1; }

certbot certonly --manual --preferred-challenges dns \
    --manual-auth-hook "$AUTH_HOOK" \
    --manual-cleanup-hook "$CLEANUP_HOOK" \
    --deploy-hook "systemctl reload nginx" \
    --non-interactive --agree-tos --email "$EMAIL" \
    --cert-name "$DOMAIN" --force-renewal -d "$DOMAIN"

[[ -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]] || { echo "Certbot did not create the expected certificate." >&2; exit 1; }

escape_sed() { printf '%s' "$1" | sed 's/[&|]/\\&/g'; }
sed \
    -e "s|__DOMAIN__|$(escape_sed "$DOMAIN")|g" \
    -e "s|__PUBLIC_ROOT__|$(escape_sed "${APP_ROOT%/}/public")|g" \
    -e "s|__PHP_SOCKET__|$(escape_sed "$PHP_SOCKET")|g" \
    "$TEMPLATE" >"${SITE}.tmp"
install -o root -g root -m 0644 "${SITE}.tmp" "$SITE"
rm -f "${SITE}.tmp"
ln -sfn "$SITE" /etc/nginx/sites-enabled/asterisk-panel
mkdir -p /var/www/letsencrypt/.well-known/acme-challenge

nginx -t
systemctl enable --now nginx
systemctl reload nginx
systemctl enable --now certbot.timer 2>/dev/null || true

echo "HTTPS activated successfully for ${DOMAIN}."
