#!/usr/bin/env bash
set -Eeuo pipefail

[[ ${EUID} -eq 0 ]] || { echo 'Certificate deploy hook must run as root.' >&2; exit 1; }
LINEAGE="${RENEWED_LINEAGE:-}"
[[ -n "$LINEAGE" && -f "$LINEAGE/fullchain.pem" && -f "$LINEAGE/privkey.pem" ]] \
    || { echo 'Certbot renewal lineage is unavailable.' >&2; exit 1; }

install -d -o root -g asterisk -m 0750 /etc/asterisk/keys
install -o root -g asterisk -m 0640 "$LINEAGE/fullchain.pem" /etc/asterisk/keys/asterisk-panel.crt
install -o root -g asterisk -m 0640 "$LINEAGE/privkey.pem" /etc/asterisk/keys/asterisk-panel.key
systemctl reload nginx
if systemctl is-active --quiet asterisk; then asterisk -rx 'pjsip reload' >/dev/null || true; fi
