#!/usr/bin/env bash
set -Eeuo pipefail
[[ ${EUID} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
WEB_USER=www-data
if [[ "${1:-}" == --web-user ]]; then WEB_USER="${2:-}"; fi
id "$WEB_USER" >/dev/null 2>&1 || { echo 'Invalid web user.' >&2; exit 2; }
install -o root -g root -m 0755 "$APP_ROOT/scripts/fail2ban-security-helper.sh" /usr/local/sbin/asterisk-panel-security
cat >/etc/sudoers.d/asterisk-panel-security <<EOF
Cmnd_Alias ASTERISK_PANEL_SECURITY_STATUS = /usr/local/sbin/asterisk-panel-security status
Cmnd_Alias ASTERISK_PANEL_SECURITY_ACTION = /usr/local/sbin/asterisk-panel-security ban *, /usr/local/sbin/asterisk-panel-security unban *
${WEB_USER} ALL=(root) NOPASSWD: ASTERISK_PANEL_SECURITY_STATUS, ASTERISK_PANEL_SECURITY_ACTION
EOF
chmod 0440 /etc/sudoers.d/asterisk-panel-security
visudo -cf /etc/sudoers.d/asterisk-panel-security >/dev/null
echo 'Fail2ban panel helper installed.'
