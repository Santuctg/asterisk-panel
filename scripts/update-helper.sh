#!/usr/bin/env bash
set -Eeuo pipefail

REPO=/root/asterisk-panel
APP=/opt/asterisk-panel
BRANCH=main
BACKUPS=/root/asterisk-panel-update-backups

status() {
    local current remote
    current="$(git -C "$REPO" rev-parse --short HEAD)"
    remote="$(git -C "$REPO" ls-remote origin "refs/heads/$BRANCH" | awk '{print substr($1,1,7)}')"
    printf 'current=%s\nremote=%s\nbranch=%s\n' "$current" "$remote" "$BRANCH"
}

update() {
    exec 9>/run/asterisk-panel-update.lock
    flock -n 9 || { echo 'Another update is already running.' >&2; exit 2; }
    git -C "$REPO" fetch --prune origin "$BRANCH"
    git -C "$REPO" merge-base --is-ancestor HEAD "origin/$BRANCH" || { echo 'Update is not a fast-forward; refusing.' >&2; exit 3; }
    if [[ "$(git -C "$REPO" rev-parse HEAD)" == "$(git -C "$REPO" rev-parse "origin/$BRANCH")" ]]; then
        echo 'Already up to date.'
        exit 0
    fi
    local stamp backup
    stamp="$(date +%Y%m%d%H%M%S)"
    backup="$BACKUPS/$stamp"
    install -d -m 0700 "$backup"
    tar -czf "$backup/application.tar.gz" -C "$APP" --exclude=storage --exclude=.env .
    cp -a "$APP/storage" "$backup/storage" 2>/dev/null || true
    git -C "$REPO" pull --ff-only origin "$BRANCH"
    find "$REPO" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
    rsync -a --delete --exclude=storage/ --exclude=.env "$REPO/" "$APP/"
    DB_DRIVER=sqlite DB_NAME="$APP/storage/database/asterisk_panel.sqlite" php "$APP/scripts/migrate.php"
    systemctl restart php8.3-fpm
    systemctl restart asterisk-panel-cdr.timer
    printf 'updated=%s\nbackup=%s\n' "$(git -C "$REPO" rev-parse --short HEAD)" "$backup"
}

case "${1:-}" in
    status) status ;;
    update) update ;;
    *) echo "Usage: $0 status|update" >&2; exit 2 ;;
esac
