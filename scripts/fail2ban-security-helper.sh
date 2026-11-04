#!/usr/bin/env bash
set -Eeuo pipefail

CLIENT=/usr/bin/fail2ban-client
JAILS=(asterisk asterisk-panel-login)
WHITELIST_FILE=/etc/fail2ban/asterisk-panel-whitelist
WHITELIST_CONFIG=/etc/fail2ban/jail.d/asterisk-panel-whitelist.local

valid_ip() {
    python3 - "$1" <<'PY'
import ipaddress, sys
try:
    ipaddress.ip_address(sys.argv[1])
except ValueError:
    raise SystemExit(1)
PY
}

read_whitelist() {
    [[ -f "$WHITELIST_FILE" ]] || return 0
    grep -E '^[0-9A-Fa-f:.]+$' "$WHITELIST_FILE" || true
}

write_whitelist() {
    install -d -m 0750 /etc/fail2ban
    local tmp="${WHITELIST_FILE}.tmp"
    printf '%s\n' "$@" | sort -u > "$tmp"
    chown root:root "$tmp"
    chmod 0640 "$tmp"
    mv -f "$tmp" "$WHITELIST_FILE"
    local values
    values="$(read_whitelist | tr '\n' ' ')"
    install -d -m 0750 /etc/fail2ban/jail.d
    printf '[DEFAULT]\nignoreip = 127.0.0.1/8 ::1 %s\n' "$values" > "$WHITELIST_CONFIG"
    chown root:root "$WHITELIST_CONFIG"
    chmod 0640 "$WHITELIST_CONFIG"
}

apply_whitelist() {
    local ip jail
    while read -r ip; do
        [[ -n "$ip" ]] || continue
        for jail in "${JAILS[@]}"; do
            "$CLIENT" set "$jail" addignoreip "$ip" >/dev/null || true
        done
    done < <(read_whitelist)
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
    whitelist)
        [[ $# -eq 1 ]]
        read_whitelist
        ;;
    whitelist-add|whitelist-remove)
        [[ $# -eq 2 ]] || { echo "Usage: $0 whitelist-add|whitelist-remove IP" >&2; exit 2; }
        ip="$2"
        valid_ip "$ip" || { echo 'Invalid IP address.' >&2; exit 2; }
        mapfile -t entries < <(read_whitelist)
        if [[ "$1" == whitelist-add ]]; then
            entries+=("$ip")
        else
            filtered=()
            for entry in "${entries[@]}"; do [[ "$entry" != "$ip" ]] && filtered+=("$entry"); done
            entries=("${filtered[@]}")
        fi
        write_whitelist "${entries[@]}"
        "$CLIENT" reload >/dev/null
        apply_whitelist
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
    *) echo "Usage: $0 status|whitelist|whitelist-add|whitelist-remove|ban|unban ..." >&2; exit 2 ;;
esac
