#!/usr/bin/env bash
set -Eeuo pipefail

CLIENT=/usr/bin/fail2ban-client
JAILS=(asterisk asterisk-panel-login)

valid_ip() {
    python3 - "$1" <<'PY'
import ipaddress, sys
try:
    ipaddress.ip_address(sys.argv[1])
except ValueError:
    raise SystemExit(1)
PY
}

case "${1:-}" in
    status)
        [[ $# -eq 1 ]]
        "$CLIENT" ping >/dev/null
        for jail in "${JAILS[@]}"; do
            printf '__JAIL__:%s\n' "$jail"
            "$CLIENT" status "$jail"
        done
        ;;
    ban|unban)
        [[ $# -eq 3 ]] || { echo "Usage: $0 ban|unban JAIL IP" >&2; exit 2; }
        action="$1"; jail="$2"; ip="$3"
        case "$jail" in asterisk|asterisk-panel-login) ;; *) echo 'Invalid jail.' >&2; exit 2 ;; esac
        valid_ip "$ip" || { echo 'Invalid IP address.' >&2; exit 2; }
        if [[ "$action" == ban ]]; then
            "$CLIENT" set "$jail" banip "$ip"
        else
            "$CLIENT" set "$jail" unbanip "$ip"
        fi
        ;;
    *) echo "Usage: $0 status | ban|unban JAIL IP" >&2; exit 2 ;;
esac
