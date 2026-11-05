#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

usage() {
    cat <<'EOF'
Install Asterisk Panel and Asterisk together on Ubuntu 24.04.

Usage:
  sudo bash scripts/install-server-ubuntu.sh [options]

Options:
  --domain HOST            Public panel hostname (recommended, e.g. pbx.example.com)
  --install-dir PATH       Deployment path (default: /opt/asterisk-panel)
  --admin-user USER        Initial panel superadmin (default: admin)
  --admin-password PASS    Initial password (generated when omitted)
  --admin-email EMAIL      Initial superadmin email
  --tenant NAME            Initial tenant name (default: Default Business)
  --no-firewall-rules      Do not add rules when UFW is already active
  -h, --help               Show help

The installer is safe to re-run. Runtime databases and ACME credentials in the
deployment directory are preserved.
EOF
}

SOURCE_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_DIR=/opt/asterisk-panel
DOMAIN=""
ADMIN_USER=admin
ADMIN_PASSWORD=""
ADMIN_PASSWORD_EXPLICIT=0
ADMIN_EMAIL=""
TENANT_NAME="Default Business"
FIREWALL_RULES=1

while (($#)); do
    case "$1" in
        --domain) DOMAIN="${2:-}"; shift 2 ;;
        --install-dir) INSTALL_DIR="${2:-}"; shift 2 ;;
        --admin-user) ADMIN_USER="${2:-}"; shift 2 ;;
        --admin-password) ADMIN_PASSWORD="${2:-}"; ADMIN_PASSWORD_EXPLICIT=1; shift 2 ;;
        --admin-email) ADMIN_EMAIL="${2:-}"; shift 2 ;;
        --tenant) TENANT_NAME="${2:-}"; shift 2 ;;
        --no-firewall-rules) FIREWALL_RULES=0; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
done

if [[ $EUID -ne 0 ]]; then
    echo "Run this installer with sudo." >&2
    exit 1
fi

source /etc/os-release
if [[ "${ID:-}" != ubuntu || "${VERSION_ID:-}" != 24.04 ]]; then
    echo "This installer supports Ubuntu 24.04 only (detected ${PRETTY_NAME:-unknown})." >&2
    exit 1
fi
if [[ -n "$DOMAIN" ]]; then
    DOMAIN="${DOMAIN,,}"
    [[ ${#DOMAIN} -le 253 && "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || { echo "Invalid --domain." >&2; exit 2; }
fi
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9_.-]{3,80}$ ]] || { echo "Invalid --admin-user." >&2; exit 2; }
if ((ADMIN_PASSWORD_EXPLICIT)) && [[ ${#ADMIN_PASSWORD} -lt 12 ]]; then
    echo "--admin-password must contain at least 12 characters." >&2
    exit 2
fi
if [[ -n "$ADMIN_EMAIL" ]]; then
    [[ "$ADMIN_EMAIL" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]] || { echo "Invalid --admin-email." >&2; exit 2; }
fi
[[ "$INSTALL_DIR" =~ ^/[A-Za-z0-9._/-]+$ && "$INSTALL_DIR" != / && "$INSTALL_DIR" != /etc && "$INSTALL_DIR" != /usr ]] || { echo "Use a dedicated absolute --install-dir without spaces." >&2; exit 2; }

trap 'echo "Installation failed at line ${LINENO}. Review the command output above." >&2' ERR

echo "[1/11] Installing Ubuntu packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common
add-apt-repository -y universe
apt-get update
apt-get install -y \
    asterisk nginx fail2ban rsync openssl sudo curl dnsutils certbot python3 ufw sox libsox-fmt-all ffmpeg espeak-ng \
    php8.3-cli php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-curl php8.3-xml php8.3-opcache
usermod -a -G asterisk www-data

echo "[2/11] Deploying the panel to ${INSTALL_DIR}..."
install -d -o root -g root -m 0755 "$INSTALL_DIR"
if [[ "$(realpath "$SOURCE_ROOT")" != "$(realpath "$INSTALL_DIR")" ]]; then
    rsync -a \
        --exclude='.git/' --exclude='.env' --exclude='.env.*' \
        --exclude='storage/database/' --exclude='storage/logs/' \
        --exclude='storage/cache/' --exclude='storage/provision/' \
        --exclude='storage/asterisk/' --exclude='storage/acme/' \
        "$SOURCE_ROOT/" "$INSTALL_DIR/"
fi

find "$INSTALL_DIR" -path "$INSTALL_DIR/storage" -prune -o -type d -exec chmod 0755 {} +
find "$INSTALL_DIR" -path "$INSTALL_DIR/storage" -prune -o -type f -exec chmod 0644 {} +
chown -R root:root "$INSTALL_DIR"
install -d -o www-data -g www-data -m 0770 "$INSTALL_DIR/storage"
for dir in database logs cache provision asterisk acme; do
    install -d -o www-data -g www-data -m 0770 "$INSTALL_DIR/storage/$dir"
done
chown -R www-data:www-data "$INSTALL_DIR/storage"

echo "[3/11] Creating persistent server credentials..."
install -d -o root -g root -m 0750 /etc/asterisk-panel
SERVER_ENV=/etc/asterisk-panel/server.env
if [[ -f "$SERVER_ENV" ]]; then
    # This file is root-owned and generated only by this installer.
    source "$SERVER_ENV"
else
    AMI_SECRET="$(openssl rand -hex 32)"
fi
[[ "${AMI_SECRET:-}" =~ ^[a-f0-9]{64}$ ]] || { echo "Invalid persisted AMI secret in $SERVER_ENV." >&2; exit 1; }
ADMIN_GENERATED=0
DB_PATH="$INSTALL_DIR/storage/database/asterisk_panel.sqlite"
if [[ -z "$ADMIN_PASSWORD" && ! -f "$DB_PATH" ]]; then
    ADMIN_PASSWORD="$(openssl rand -hex 10)"
    ADMIN_GENERATED=1
fi

if [[ -n "$DOMAIN" ]]; then
    APP_URL="http://${DOMAIN}"
    SERVER_NAME="$DOMAIN"
    SIP_HOST="$DOMAIN"
else
    SERVER_IP="$(hostname -I | awk '{print $1}')"
    APP_URL="http://${SERVER_IP:-127.0.0.1}"
    SERVER_NAME="_"
    SIP_HOST="${SERVER_IP:-127.0.0.1}"
fi
PRIMARY_HOST="${DOMAIN:-${SERVER_IP:-127.0.0.1}}"
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_SOCKET=/run/php/asterisk-panel.sock

{
    printf 'APP_ROOT=%q\n' "$INSTALL_DIR"
    printf 'APP_URL=%q\n' "$APP_URL"
    printf 'APP_PRIMARY_HOST=%q\n' "$PRIMARY_HOST"
    printf 'AMI_USER=%q\n' 'asterisk-panel'
    printf 'AMI_SECRET=%q\n' "$AMI_SECRET"
    printf 'PHP_SOCKET=%q\n' "$PHP_SOCKET"
    printf 'SIP_HOST=%q\n' "$SIP_HOST"
} >"$SERVER_ENV"
chmod 0600 "$SERVER_ENV"

echo "[4/11] Configuring the dedicated PHP-FPM pool..."
POOL_FILE="/etc/php/${PHP_VERSION}/fpm/pool.d/asterisk-panel.conf"
cat >"$POOL_FILE" <<EOF
[asterisk-panel]
user = www-data
group = www-data
listen = ${PHP_SOCKET}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 12
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 30M
clear_env = no
env[APP_URL] = ${APP_URL}
env[APP_PRIMARY_HOST] = ${PRIMARY_HOST}
env[APP_DEBUG] = 0
env[APP_SECURE_COOKIES] = 0
env[DB_DRIVER] = sqlite
env[DB_NAME] = ${INSTALL_DIR}/storage/database/asterisk_panel.sqlite
env[AMI_HOST] = 127.0.0.1
env[AMI_PORT] = 5038
env[AMI_USER] = asterisk-panel
env[AMI_SECRET] = ${AMI_SECRET}
env[ASTERISK_CONFIG_DIR] = /etc/asterisk/asterisk_panel
env[ASTERISK_SIP_HOST] = ${SIP_HOST}
env[ASTERISK_RECORDING_DIR] = /var/spool/asterisk/monitor/asterisk-panel
env[VM_MAILCMD] = /usr/bin/php ${INSTALL_DIR}/scripts/asterisk-mail.php
EOF
chmod 0640 "$POOL_FILE"
systemctl enable --now "$PHP_FPM_SERVICE"
systemctl restart "$PHP_FPM_SERVICE"
[[ -S "$PHP_SOCKET" ]] || { echo "PHP-FPM socket was not created: $PHP_SOCKET" >&2; exit 1; }
for extension in pdo_sqlite mbstring openssl fileinfo; do
    php -m | grep -Fxiq "$extension" || { echo "Required PHP extension is missing: $extension" >&2; exit 1; }
done

echo "[5/11] Initializing the panel database..."
INSTALL_ARGS=("--username=$ADMIN_USER" "--tenant=$TENANT_NAME")
if [[ -n "$ADMIN_EMAIL" ]]; then
    INSTALL_ARGS+=("--email=$ADMIN_EMAIL")
fi
if [[ -n "$ADMIN_PASSWORD" ]]; then
    INSTALL_ARGS+=("--password=$ADMIN_PASSWORD")
fi
if ((ADMIN_PASSWORD_EXPLICIT)); then
    INSTALL_ARGS+=(--force)
fi
runuser -u www-data -- env \
    APP_URL="$APP_URL" DB_DRIVER=sqlite DB_NAME="$DB_PATH" \
    php "$INSTALL_DIR/scripts/install.php" "${INSTALL_ARGS[@]}"
runuser -u www-data -- env \
    APP_URL="$APP_URL" DB_DRIVER=sqlite DB_NAME="$DB_PATH" \
    php "$INSTALL_DIR/scripts/migrate.php"

echo "[6/11] Configuring Asterisk AMI and managed includes..."
install -d -o www-data -g asterisk -m 2775 /etc/asterisk/asterisk_panel
install -d -o www-data -g asterisk -m 2775 /var/lib/asterisk/sounds/asterisk-panel
ln -sfn /var/lib/asterisk/sounds/asterisk-panel /usr/share/asterisk/sounds/asterisk-panel
install -d -o www-data -g asterisk -m 2775 /var/lib/asterisk/moh
install -d -o asterisk -g asterisk -m 2770 /var/spool/asterisk/monitor/asterisk-panel
install -d -o asterisk -g asterisk -m 2775 /var/lib/asterisk/moh
install -o root -g asterisk -m 0644 "$INSTALL_DIR/resources/deploy/asterisk/pjsip-base.conf" /etc/asterisk/asterisk_panel/pjsip_base.conf
install -d -o root -g asterisk -m 0750 /etc/asterisk/manager.d
cat >/etc/asterisk/manager.d/asterisk-panel.conf <<EOF
[general](+)
enabled = yes
webenabled = no
bindaddr = 127.0.0.1
port = 5038

[asterisk-panel]
secret = ${AMI_SECRET}
deny = 0.0.0.0/0.0.0.0
permit = 127.0.0.1/255.255.255.255
read = all
write = all
EOF
chown root:asterisk /etc/asterisk/manager.d/asterisk-panel.conf
chmod 0640 /etc/asterisk/manager.d/asterisk-panel.conf

ensure_include() {
    local file="$1" line="$2"
    touch "$file"
    if ! grep -Fqx -- "$line" "$file"; then
        printf '\n; --- Asterisk Panel (managed include) ---\n%s\n' "$line" >>"$file"
    fi
}

# Ubuntu's stock manager.conf already includes manager.d/*.conf. Avoid
# loading the same credentials twice when that wildcard is present.
if ! grep -Eq '^[[:space:]]*#include[[:space:]]+"?manager\.d/\*\.conf"?[[:space:]]*$' /etc/asterisk/manager.conf; then
    ensure_include /etc/asterisk/manager.conf '#include manager.d/asterisk-panel.conf'
fi
ensure_include /etc/asterisk/pjsip.conf '#include asterisk_panel/pjsip_base.conf'
ensure_include /etc/asterisk/pjsip.conf '#include asterisk_panel/pjsip_endpoints.conf'
ensure_include /etc/asterisk/pjsip.conf '#include asterisk_panel/pjsip_trunks.conf'
cat >/etc/asterisk/asterisk_panel/http.conf <<'EOF'
[general](+)
enabled = yes
bindaddr = 127.0.0.1
bindport = 8088
EOF
ensure_include /etc/asterisk/http.conf '#include asterisk_panel/http.conf'
ensure_include /etc/asterisk/extensions.conf '#include asterisk_panel/dialplan.conf'
ensure_include /etc/asterisk/voicemail.conf '#include asterisk_panel/voicemail.conf'
ensure_include /etc/asterisk/queues.conf '#include asterisk_panel/queues.conf'
ensure_include /etc/asterisk/res_parking.conf '#include asterisk_panel/parking.conf'
ensure_include /etc/asterisk/musiconhold.conf '#include asterisk_panel/musiconhold.conf'
ensure_include /etc/asterisk/confbridge.conf '#include asterisk_panel/confbridge.conf'
cat >/etc/asterisk/asterisk_panel/cdr.conf <<'EOF'
[general]
enable = yes
unanswered = yes
congestion = yes
EOF
cat >/etc/asterisk/asterisk_panel/cdr_csv.conf <<'EOF'
[general]
usegmtime = no
loguniqueid = yes
loguserfield = yes
accountlogs = no
EOF
ensure_include /etc/asterisk/cdr.conf '#include asterisk_panel/cdr.conf'
ensure_include /etc/asterisk/cdr_csv.conf '#include asterisk_panel/cdr_csv.conf'
install -d -o root -g asterisk -m 0750 /etc/asterisk/logger.d
cat >/etc/asterisk/logger.d/asterisk-panel.conf <<'EOF'
[logfiles]
security => security
EOF
chown root:asterisk /etc/asterisk/logger.d/asterisk-panel.conf
chmod 0640 /etc/asterisk/logger.d/asterisk-panel.conf
ensure_include /etc/asterisk/logger.conf '#include logger.d/asterisk-panel.conf'

echo "[7/11] Generating initial Asterisk configuration..."
runuser -u www-data -- env \
    DB_DRIVER=sqlite DB_NAME="$INSTALL_DIR/storage/database/asterisk_panel.sqlite" \
    AMI_HOST=127.0.0.1 AMI_PORT=5038 AMI_USER=asterisk-panel AMI_SECRET="$AMI_SECRET" \
    ASTERISK_CONFIG_DIR=/etc/asterisk/asterisk_panel \
    ASTERISK_RECORDING_DIR=/var/spool/asterisk/monitor/asterisk-panel \
    VM_MAILCMD="/usr/bin/php $INSTALL_DIR/scripts/asterisk-mail.php" \
    php "$INSTALL_DIR/scripts/apply-config.php"
for generated in pjsip_port.conf pjsip_endpoints.conf pjsip_trunks.conf dialplan.conf voicemail.conf queues.conf parking.conf musiconhold.conf confbridge.conf; do
    [[ -s "/etc/asterisk/asterisk_panel/$generated" ]] || { echo "Missing generated Asterisk config: $generated" >&2; exit 1; }
done
chgrp -R asterisk /etc/asterisk/asterisk_panel
find /etc/asterisk/asterisk_panel -type f -exec chmod 0640 {} +

echo "[8/11] Starting Asterisk..."
# The panel only configures PJSIP; avoid exposing unused IAX2 (UDP 4569).
if [[ -f /etc/asterisk/modules.conf ]] && ! grep -qE '^[[:space:]]*noload[[:space:]]*=>[[:space:]]*chan_iax2\.so' /etc/asterisk/modules.conf; then
    cp -an /etc/asterisk/modules.conf /etc/asterisk/modules.conf.pre-panel-iax
    sed -i '/^autoload=yes/a noload => chan_iax2.so' /etc/asterisk/modules.conf
fi
# Ubuntu ships multiple mutually exclusive voicemail storage modules. Keep the
# IMAP variant and prevent the plain/ODBC variants from stealing VM_INFO during
# autoload, which otherwise leaves Voicemail() and VoicemailMain() unregistered.
if [[ -f /etc/asterisk/modules.conf ]]; then
    cp -an /etc/asterisk/modules.conf /etc/asterisk/modules.conf.pre-panel-voicemail
    for module in app_voicemail.so app_voicemail_odbc.so; do
        if ! grep -qE "^[[:space:]]*noload[[:space:]]*=>[[:space:]]*${module//./\\.}([[:space:]]|$)" /etc/asterisk/modules.conf; then
            sed -i "/^autoload=yes/a noload => $module" /etc/asterisk/modules.conf
        fi
    done
fi
# Ubuntu also autoloads chan_sip, which otherwise claims the SIP WebSocket
# protocol before PJSIP and leaves res_pjsip_transport_websocket declined.
# Keep chan_sip available for existing installations, but give WS to PJSIP.
if [[ -f /etc/asterisk/sip.conf ]]; then
    cp -an /etc/asterisk/sip.conf /etc/asterisk/sip.conf.pre-panel-websocket
    if grep -qE '^[[:space:]]*websocket_enabled[[:space:]]*=' /etc/asterisk/sip.conf; then
        sed -i -E 's/^[[:space:]]*websocket_enabled[[:space:]]*=.*/websocket_enabled = false/' /etc/asterisk/sip.conf
    else
        sed -i '/^\[general\]/a websocket_enabled = false' /etc/asterisk/sip.conf
    fi
fi
systemctl enable asterisk
systemctl restart asterisk
sleep 2
systemctl is-active --quiet asterisk || { journalctl -u asterisk -n 80 --no-pager; exit 1; }
asterisk -rx 'core show version'
asterisk -rx 'manager show settings' >/dev/null
asterisk -rx 'module show like res_pjsip_transport_websocket' | grep -qE 'res_pjsip_transport_websocket\.so.*Running' || {
    echo 'PJSIP WebSocket transport is not running' >&2; exit 1;
}

install -d -o asterisk -g asterisk -m 0750 /var/log/asterisk/cdr-csv
touch /var/log/asterisk/cdr-csv/Master.csv
chown asterisk:asterisk /var/log/asterisk/cdr-csv/Master.csv
chmod 0640 /var/log/asterisk/cdr-csv/Master.csv
cat >/etc/systemd/system/asterisk-panel-cdr.service <<EOF
[Unit]
Description=Import Asterisk CDR into Asterisk Panel
After=asterisk.service

[Service]
Type=oneshot
User=www-data
Group=www-data
SupplementaryGroups=asterisk
Environment=DB_DRIVER=sqlite
Environment=DB_NAME=${DB_PATH}
Environment=CDR_CSV_PATH=/var/log/asterisk/cdr-csv/Master.csv
ExecStart=/usr/bin/php ${INSTALL_DIR}/scripts/import-cdr.php
EOF
cat >/etc/systemd/system/asterisk-panel-cdr.timer <<'EOF'
[Unit]
Description=Import Asterisk CDR every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now asterisk-panel-cdr.timer
cat >/etc/systemd/system/asterisk-panel-trunk-monitor.service <<EOF
[Unit]
Description=Record Asterisk Panel SIP status history (trunks and extensions)
After=asterisk.service

[Service]
Type=oneshot
User=www-data
Group=www-data
SupplementaryGroups=asterisk
EnvironmentFile=/etc/asterisk-panel/server.env
Environment=DB_DRIVER=sqlite
Environment=DB_NAME=${DB_PATH}
ExecStart=/usr/bin/php ${INSTALL_DIR}/scripts/monitor-trunks.php
EOF
cat >/etc/systemd/system/asterisk-panel-trunk-monitor.timer <<'EOF'
[Unit]
Description=Sample SIP registration status every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now asterisk-panel-trunk-monitor.timer

echo "[9/11] Configuring the initial Nginx site..."
SITE=/etc/nginx/sites-available/asterisk-panel
if [[ ! -f "$SITE" ]] || ! grep -qE 'listen[[:space:]]+443' "$SITE"; then
    cat >"$SITE" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};
    root "${INSTALL_DIR}/public";
    index index.php;
    client_max_body_size 30m;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    include /etc/nginx/snippets/asterisk-panel-recordings.conf;
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param APP_URL ${APP_URL};
        fastcgi_param APP_SECURE_COOKIES 0;
        fastcgi_pass unix:${PHP_SOCKET};
    }
    location ~ \.php\$ { return 404; }
    location ~ /\. { deny all; }
}
EOF
fi
install -d -m 0755 /etc/nginx/snippets
install -o root -g root -m 0644 "$INSTALL_DIR/resources/deploy/nginx-recordings.conf" /etc/nginx/snippets/asterisk-panel-recordings.conf
if ! grep -Fq 'asterisk-panel-recordings.conf' "$SITE"; then
    sed -i '/^[[:space:]]*location = \/index\.php {/i\    include /etc/nginx/snippets/asterisk-panel-recordings.conf;\n' "$SITE"
fi
ln -sfn "$SITE" /etc/nginx/sites-enabled/asterisk-panel
if [[ -L /etc/nginx/sites-enabled/default ]] \
    && [[ "$(readlink -f /etc/nginx/sites-enabled/default)" == /etc/nginx/sites-available/default ]]; then
    unlink /etc/nginx/sites-enabled/default
fi
nginx -t
systemctl enable --now nginx
systemctl reload nginx

echo "[10/11] Configuring Fail2ban..."
install -o root -g root -m 0644 \
    "$INSTALL_DIR/resources/deploy/fail2ban/asterisk-panel-login.conf" \
    /etc/fail2ban/filter.d/asterisk-panel-login.conf
touch /var/log/asterisk/security
chown asterisk:asterisk /var/log/asterisk/security
touch "$INSTALL_DIR/storage/logs/app-$(date +%F).log"
chown www-data:www-data "$INSTALL_DIR/storage/logs/app-$(date +%F).log"
cat >/etc/fail2ban/jail.d/asterisk-panel.local <<EOF
[asterisk]
enabled = true
filter = asterisk
backend = polling
port = 5060,5061
protocol = all
logpath = /var/log/asterisk/security
maxretry = 5
findtime = 10m
bantime = 1h
ignoreip = 127.0.0.1/8 ::1

[asterisk-panel-login]
enabled = true
filter = asterisk-panel-login
backend = polling
port = http,https
protocol = tcp
logpath = ${INSTALL_DIR}/storage/logs/app-*.log
maxretry = 5
findtime = 10m
bantime = 1h
ignoreip = 127.0.0.1/8 ::1
EOF
systemctl enable --now fail2ban
systemctl restart fail2ban
# systemctl restart returns before fail2ban-server opens its control socket.
# Wait briefly instead of failing a healthy installation on the first probe.
for attempt in {1..30}; do
    if fail2ban-client ping >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
fail2ban-client ping >/dev/null
fail2ban-client status asterisk >/dev/null
fail2ban-client status asterisk-panel-login >/dev/null
bash "$INSTALL_DIR/scripts/install-security-helper.sh" --web-user www-data
bash "$INSTALL_DIR/scripts/install-update-helper.sh" www-data

echo "[11/11] Installing the web SSL helper..."
bash "$INSTALL_DIR/scripts/install-web-ssl-helper.sh" --web-user www-data --php-socket "$PHP_SOCKET"

if ((FIREWALL_RULES)) && ufw status | grep -q '^Status: active'; then
    ufw allow 80/tcp comment 'Asterisk Panel HTTP'
    ufw allow 443/tcp comment 'Asterisk Panel HTTPS'
    ufw allow 5060/udp comment 'Asterisk PJSIP'
    ufw allow 5060/tcp comment 'Asterisk PJSIP TCP'
    ufw allow 10000:20000/udp comment 'Asterisk RTP'
fi

cat <<EOF

============================================================
Asterisk Panel installation completed.
============================================================
Panel URL:    ${APP_URL}/login
Username:     ${ADMIN_USER}
Password:     ${ADMIN_PASSWORD:-unchanged (existing installation)}
Asterisk:     $(asterisk -rx 'core show version' | head -n 1)
AMI:          listening on 127.0.0.1:5038
Fail2ban:     Asterisk SIP + panel login jails active
Recordings:   /var/spool/asterisk/monitor/asterisk-panel

Next:
  1. Sign in and change the generated password if applicable.
  2. Open System -> SSL / HTTPS and register ${DOMAIN:-your domain}.
  3. Add the displayed CNAME, verify it, and issue the certificate.
EOF

if ((ADMIN_GENERATED)); then
    echo "Save the generated password now; it is not written to a plaintext project file."
fi
