#!/usr/bin/env bash
set -Eeuo pipefail

REPO=/root/asterisk-panel
APP=/opt/asterisk-panel
BRANCH=main
BACKUPS=/root/asterisk-panel-update-backups

version_from_file() {
    sed -n "s/.*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" "$1" | head -n 1
}

status() {
    local current latest
    exec 9>/run/asterisk-panel-update.lock
    flock 9
    git -C "$REPO" fetch --quiet --prune origin "$BRANCH"
    current="$(sed -n "s/.*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" "$APP/resources/config/app.php" | head -n 1)"
    latest="$(git -C "$REPO" show "origin/$BRANCH:resources/config/app.php" 2>/dev/null | sed -n "s/.*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" | head -n 1)"
    printf 'current_version=%s\nlatest_version=%s\n' "${current:-unknown}" "${latest:-unknown}"
}

update() {
    exec 9>/run/asterisk-panel-update.lock
    flock -n 9 || { echo 'Another update is already running.' >&2; exit 2; }
    git -C "$REPO" fetch --prune origin "$BRANCH"
    git -C "$REPO" merge-base --is-ancestor HEAD "origin/$BRANCH" || { echo 'Update is not a fast-forward; refusing.' >&2; exit 3; }
    local repo_head remote_head app_version repo_version stamp backup
    repo_head="$(git -C "$REPO" rev-parse HEAD)"
    remote_head="$(git -C "$REPO" rev-parse "origin/$BRANCH")"
    app_version="$(version_from_file "$APP/resources/config/app.php")"
    repo_version="$(git -C "$REPO" show "origin/$BRANCH:resources/config/app.php" | sed -n "s/.*'version'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" | head -n 1)"
    if [[ "$repo_head" == "$remote_head" && "$app_version" == "$repo_version" ]]; then
        echo 'Already up to date.'
        exit 0
    fi
    stamp="$(date +%Y%m%d%H%M%S)"
    backup="$BACKUPS/$stamp"
    install -d -m 0700 "$backup"
    tar -czf "$backup/application.tar.gz" -C "$APP" --exclude=storage --exclude=.env .
    cp -a "$APP/storage" "$backup/storage" 2>/dev/null || true
    if [[ "$repo_head" != "$remote_head" ]]; then git -C "$REPO" pull --ff-only origin "$BRANCH"; fi
    find "$REPO" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
    rsync -a --delete --exclude=storage/ --exclude=.env "$REPO/" "$APP/"
    install -d -o www-data -g asterisk -m 2775 /var/lib/asterisk/sounds/asterisk-panel /var/lib/asterisk/moh
    find /var/lib/asterisk/sounds/asterisk-panel -mindepth 1 -maxdepth 1 -type d \
        -exec chown www-data:asterisk {} + -exec chmod 2775 {} +
    install -o root -g root -m 0644 "$APP/resources/deploy/nginx-recordings.conf" /etc/nginx/snippets/asterisk-panel-recordings.conf
    install -d -o root -g root -m 0755 /usr/local/lib/asterisk-panel
    install -o root -g root -m 0644 "$APP/resources/deploy/nginx-https.conf.template" /usr/local/lib/asterisk-panel/nginx-https.conf.template
    install -o root -g root -m 0644 "$APP/resources/deploy/nginx-tenant-https.conf.template" /usr/local/lib/asterisk-panel/nginx-tenant-https.conf.template
    local pool=/etc/php/8.3/fpm/pool.d/asterisk-panel.conf
    if [[ -f "$pool" ]]; then
        sed -i '/^php_admin_value\[upload_max_filesize\]/d; /^php_admin_value\[post_max_size\]/d' "$pool"
        printf '\nphp_admin_value[upload_max_filesize] = 25M\nphp_admin_value[post_max_size] = 30M\n' >>"$pool"
    fi
    for site in /etc/nginx/sites-available/asterisk-panel /etc/nginx/sites-available/asterisk-panel-tenant-*; do
        [[ -f "$site" ]] || continue
        if grep -q 'client_max_body_size' "$site"; then
            sed -i 's/client_max_body_size[[:space:]][[:space:]]*[0-9][0-9]*[mM];/client_max_body_size 30m;/g' "$site"
        else
            sed -i '/^[[:space:]]*index index\.php;/a\    client_max_body_size 30m;' "$site"
        fi
    done
    DB_DRIVER=sqlite DB_NAME="$APP/storage/database/asterisk_panel.sqlite" php "$APP/scripts/migrate.php"
    DB_DRIVER=sqlite DB_NAME="$APP/storage/database/asterisk_panel.sqlite" php "$APP/scripts/apply-config.php"
    local voicemail_modules_changed=0
    if [[ -f /etc/asterisk/modules.conf ]]; then
        cp -an /etc/asterisk/modules.conf /etc/asterisk/modules.conf.pre-panel-voicemail
        for module in app_voicemail.so app_voicemail_odbc.so; do
            if ! grep -qE "^[[:space:]]*noload[[:space:]]*=>[[:space:]]*${module//./\\.}([[:space:]]|$)" /etc/asterisk/modules.conf; then
                sed -i "/^autoload=yes/a noload => $module" /etc/asterisk/modules.conf
                voicemail_modules_changed=1
            fi
        done
    fi
    if systemctl is-active --quiet asterisk; then
        if (( voicemail_modules_changed )); then
            systemctl restart asterisk
        else
            asterisk -rx 'pjsip reload'
            asterisk -rx 'dialplan reload'
            asterisk -rx 'module reload app_voicemail_imap.so'
            asterisk -rx 'module reload app_queue.so'
            asterisk -rx 'module reload res_parking.so'
            asterisk -rx 'module reload res_musiconhold.so'
            if asterisk -rx 'module show like app_confbridge' | grep -qE 'app_confbridge\.so.*Running'; then
                asterisk -rx 'module reload app_confbridge.so'
            else
                asterisk -rx 'module load app_confbridge.so'
            fi
        fi
    fi
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
Environment=DB_NAME=$APP/storage/database/asterisk_panel.sqlite
ExecStart=/usr/bin/php $APP/scripts/monitor-trunks.php
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
    install -o root -g root -m 0755 "$REPO/scripts/update-helper.sh" /usr/local/sbin/asterisk-panel-update
    systemctl restart php8.3-fpm
    nginx -t
    systemctl reload nginx
    systemctl restart asterisk-panel-cdr.timer
    systemctl restart asterisk-panel-trunk-monitor.timer
    printf 'updated=%s\nbackup=%s\n' "$(git -C "$REPO" rev-parse --short HEAD)" "$backup"
}

case "${1:-}" in
    status) status ;;
    update) update ;;
    *) echo "Usage: $0 status|update" >&2; exit 2 ;;
esac
