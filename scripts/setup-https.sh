#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'EOF'
Install an Nginx HTTPS virtual host and a Let's Encrypt certificate.

Usage:
  sudo bash scripts/setup-https.sh --domain pbx.example.com --email admin@example.com [options]

Options:
  --app-root PATH      Project root (default: directory containing this script/..)
  --php-socket PATH    PHP-FPM socket (auto-detected from /run/php)
  --no-install         Do not install missing apt packages
  -h, --help           Show this help

DNS for the domain must already point to this server and ports 80/443 must be open.
EOF
}

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
DOMAIN=""
EMAIL=""
PHP_SOCKET=""
INSTALL_PACKAGES=1

while (($#)); do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --email) EMAIL="${2:-}"; shift 2 ;;
        --app-root) APP_ROOT="$(realpath "${2:-}")"; shift 2 ;;
        --php-socket) PHP_SOCKET="${2:-}"; shift 2 ;;
        --no-install) INSTALL_PACKAGES=0; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this script as root (sudo)." >&2
    exit 1
fi
if [[ ! "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || [[ "$DOMAIN" != *.* ]]; then
    echo "A valid --domain is required." >&2
    exit 2
fi
if [[ ! "$EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
    echo "A valid --email is required." >&2
    exit 2
fi
if [[ ! -f "$APP_ROOT/public/index.php" ]]; then
    echo "No public/index.php found under --app-root: $APP_ROOT" >&2
    exit 2
fi

if ((! INSTALL_PACKAGES)); then
    command -v nginx >/dev/null || { echo "nginx is not installed." >&2; exit 1; }
    command -v certbot >/dev/null || { echo "certbot is not installed." >&2; exit 1; }
else
    missing=()
    command -v nginx >/dev/null || missing+=(nginx)
    command -v certbot >/dev/null || missing+=(certbot python3-certbot-nginx)
    if ((${#missing[@]})); then
        apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
    fi
fi

if [[ -z "$PHP_SOCKET" ]]; then
    PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' -print 2>/dev/null | sort -V | tail -n 1)"
fi
if [[ -z "$PHP_SOCKET" || ! -S "$PHP_SOCKET" ]]; then
    echo "PHP-FPM socket not found; pass --php-socket /run/php/php8.2-fpm.sock." >&2
    exit 1
fi

PUBLIC_ROOT="${APP_ROOT%/}/public"
TEMPLATE="${APP_ROOT%/}/resources/deploy/nginx-https.conf.template"
SITE="/etc/nginx/sites-available/asterisk-panel"
ACME_ROOT="/var/www/letsencrypt"
mkdir -p "$ACME_ROOT/.well-known/acme-challenge" /etc/nginx/sites-available /etc/nginx/sites-enabled

# Bootstrap HTTP so Certbot can complete the first ACME challenge.
cat >"$SITE" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    location ^~ /.well-known/acme-challenge/ {
        root ${ACME_ROOT};
        default_type text/plain;
        try_files \$uri =404;
    }
    location / { return 301 https://\$host\$request_uri; }
}
EOF
ln -sfn "$SITE" /etc/nginx/sites-enabled/asterisk-panel
nginx -t
systemctl enable --now nginx
systemctl reload nginx

if [[ ! -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; then
    certbot certonly --webroot -w "$ACME_ROOT" -d "$DOMAIN" \
        --email "$EMAIL" --agree-tos --no-eff-email --non-interactive
fi

escape_sed() { printf '%s' "$1" | sed 's/[&|]/\\&/g'; }
sed \
    -e "s|__DOMAIN__|$(escape_sed "$DOMAIN")|g" \
    -e "s|__PUBLIC_ROOT__|$(escape_sed "$PUBLIC_ROOT")|g" \
    -e "s|__PHP_SOCKET__|$(escape_sed "$PHP_SOCKET")|g" \
    "$TEMPLATE" >"${SITE}.tmp"
install -m 0644 "${SITE}.tmp" "$SITE"
rm -f "${SITE}.tmp"

nginx -t
systemctl reload nginx
systemctl enable --now certbot.timer 2>/dev/null || true

cat <<EOF

HTTPS is active: https://${DOMAIN}
APP_URL and secure cookies are passed to PHP-FPM by Nginx.

Next checks:
  curl -I http://${DOMAIN}
  curl -I https://${DOMAIN}/login
  certbot renew --dry-run

Ensure the PHP-FPM user can read ${APP_ROOT} and write ${APP_ROOT}/storage.
EOF
