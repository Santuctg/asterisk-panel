#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'EOF'
Install the restricted root helper used by System -> SSL / HTTPS.

Usage:
  sudo bash scripts/install-web-ssl-helper.sh [--web-user www-data] [--php-socket /run/php/php8.2-fpm.sock]
EOF
}

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
WEB_USER="www-data"
PHP_SOCKET=""

while (($#)); do
    case "$1" in
        --web-user) WEB_USER="${2:-}"; shift 2 ;;
        --php-socket) PHP_SOCKET="${2:-}"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ $EUID -ne 0 ]]; then
    echo "Run this installer with sudo." >&2
    exit 1
fi
if [[ ! "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*[$]?$ ]] || ! id "$WEB_USER" >/dev/null 2>&1; then
    echo "Invalid or unknown web user: $WEB_USER" >&2
    exit 2
fi
if [[ ! -f "$APP_ROOT/public/index.php" ]]; then
    echo "Run this script from an Asterisk Panel checkout." >&2
    exit 2
fi

DEBIAN_FRONTEND=noninteractive apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y nginx certbot curl dnsutils python3 sudo

if [[ -z "$PHP_SOCKET" ]]; then
    PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' -print 2>/dev/null | sort -V | tail -n 1)"
fi
if [[ -z "$PHP_SOCKET" || ! -S "$PHP_SOCKET" ]]; then
    echo "PHP-FPM socket not found; pass --php-socket /run/php/php8.2-fpm.sock." >&2
    exit 1
fi

install -d -m 0755 /etc/asterisk-panel /usr/local/lib/asterisk-panel
install -d -m 0755 /etc/nginx/snippets
install -d -o "$WEB_USER" -g "$(id -gn "$WEB_USER")" -m 0700 "$APP_ROOT/storage/acme"
install -o root -g root -m 0755 "$SCRIPT_DIR/acme-dns-ssl-helper.sh" /usr/local/sbin/asterisk-panel-ssl
install -o root -g root -m 0755 "$SCRIPT_DIR/acme-dns-auth-hook.sh" /usr/local/lib/asterisk-panel/acme-dns-auth
install -o root -g root -m 0755 "$SCRIPT_DIR/acme-dns-cleanup-hook.sh" /usr/local/lib/asterisk-panel/acme-dns-cleanup
install -o root -g root -m 0755 "$SCRIPT_DIR/deploy-sip-tls-cert.sh" /usr/local/lib/asterisk-panel/deploy-sip-tls-cert
install -o root -g root -m 0644 "$APP_ROOT/resources/deploy/nginx-https.conf.template" /usr/local/lib/asterisk-panel/nginx-https.conf.template
install -o root -g root -m 0644 "$APP_ROOT/resources/deploy/nginx-tenant-https.conf.template" /usr/local/lib/asterisk-panel/nginx-tenant-https.conf.template
install -o root -g root -m 0644 "$APP_ROOT/resources/deploy/nginx-recordings.conf" /etc/nginx/snippets/asterisk-panel-recordings.conf

# Upgrade an older panel-generated site without replacing its certificate paths.
SITE=/etc/nginx/sites-available/asterisk-panel
if [[ -f "$SITE" ]] && ! grep -Fq 'asterisk-panel-recordings.conf' "$SITE"; then
    sed -i '/^[[:space:]]*location = \/index\.php {/i\    include /etc/nginx/snippets/asterisk-panel-recordings.conf;\n' "$SITE"
fi

{
    printf 'APP_ROOT=%q\n' "$APP_ROOT"
    printf 'WEB_USER=%q\n' "$WEB_USER"
    printf 'PHP_SOCKET=%q\n' "$PHP_SOCKET"
} >/etc/asterisk-panel/ssl-helper.conf
chmod 0600 /etc/asterisk-panel/ssl-helper.conf
chown root:root /etc/asterisk-panel/ssl-helper.conf

SUDOERS=/etc/sudoers.d/asterisk-panel-ssl
cat >"$SUDOERS" <<EOF
Cmnd_Alias ASTERISK_PANEL_SSL = /usr/local/sbin/asterisk-panel-ssl issue
Cmnd_Alias ASTERISK_PANEL_TENANT_SSL = /usr/local/sbin/asterisk-panel-ssl issue-tenant *
${WEB_USER} ALL=(root) NOPASSWD: ASTERISK_PANEL_SSL
${WEB_USER} ALL=(root) NOPASSWD: ASTERISK_PANEL_TENANT_SSL
EOF
chmod 0440 "$SUDOERS"
visudo -cf "$SUDOERS"

systemctl enable --now nginx
nginx -t
systemctl reload nginx
systemctl enable --now certbot.timer 2>/dev/null || true

cat <<EOF

Web SSL helper installed successfully.
Return to System -> SSL / HTTPS, verify the CNAME, then click Issue certificate.

Web user:  ${WEB_USER}
PHP socket: ${PHP_SOCKET}
EOF
