#!/usr/bin/env bash
set -Eeuo pipefail
[[ ${EUID} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WEB_USER="${1:-www-data}"
id "$WEB_USER" >/dev/null 2>&1 || { echo 'Invalid web user.' >&2; exit 2; }
install -o root -g root -m 0755 "$SCRIPT_DIR/update-helper.sh" /usr/local/sbin/asterisk-panel-update
cat >/etc/sudoers.d/asterisk-panel-update <<EOF
Cmnd_Alias ASTERISK_PANEL_UPDATE = /usr/local/sbin/asterisk-panel-update status, /usr/local/sbin/asterisk-panel-update update
${WEB_USER} ALL=(root) NOPASSWD: ASTERISK_PANEL_UPDATE
EOF
chmod 0440 /etc/sudoers.d/asterisk-panel-update
visudo -cf /etc/sudoers.d/asterisk-panel-update >/dev/null
