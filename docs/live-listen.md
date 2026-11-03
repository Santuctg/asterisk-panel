# Live Listen (Ubuntu production deployment)

Two **listen-only** options are available at `/live-listen` for tenant admins
and superadmins. The selected tenant and the target's extension are checked
against a fresh AMI channel snapshot on each request; requests are CSRF
protected and successful originate requests are recorded in `audit_log` as
`call.listen`. Ordinary panel users have no listen permission. No whisper or
barge controls are exposed.

1. Open `/live-listen` and select a connected extension channel and a **different**
   supervisor extension from the same tenant.
2. **Phone**: Asterisk calls the supervisor's registered SIP phone. Answer to
   hear the selected live call; hang up to stop. The web response confirms only
   that Asterisk accepted the request, not that the phone was answered.
3. **Browser**: requires HTTPS, a working Asterisk `/ws` proxy, a supported
   WebRTC browser, and the supervisor extension's **SIP password** (not the
   panel login password). Enter the SIP password in the form; it is used in
   browser memory for SIP registration, not posted to the panel or saved by it.
   Browser Listen rings this tab and answers automatically; use **Stop** to
   disconnect. Other devices may be logged in with the same phone extension;
   the browser registers a **separate** `-web` identity.

The Ubuntu installer writes `http.conf` with Asterisk HTTP/WebSocket bound to
127.0.0.1:8088, generates WebRTC PJSIP identities, and the HTTPS Nginx site
proxies **same-origin** WSS `/ws` to Asterisk. Do **not** expose 8088, AMI
5038, or browser SIP credentials to the Internet. Phone Listen works without
HTTPS; Browser Listen needs the HTTPS install (`scripts/setup-https.sh`). If
you upgraded an older installation, redeploy the updated
`resources/deploy/asterisk/pjsip-base.conf`, enable the managed Asterisk
`http.conf` include, regenerate configs **as `www-data` with the production
`DB_NAME` and `ASTERISK_CONFIG_DIR` environment variables** (not as root),
reload Asterisk and rerun HTTPS setup to add the `/ws` proxy. Back up the
current Asterisk and Nginx configuration before changing a live system.

Verification on the PBX: `asterisk -rx 'http show status'` should report the
HTTP server on 127.0.0.1:8088 with `/ws`; `asterisk -rx 'pjsip show transports'`
should list the WS transport; `asterisk -rx 'core show channels concise'`
should list connected PJSIP extension channels. Then make a test call, choose
a different supervisor extension, and test both buttons with a real SIP phone
and a browser. Audio/media is **not** testable with PHP lint alone.

**Privacy/legal:** live listening may require notification, consent or a
written monitoring policy in your jurisdiction. Limit tenant-admin access
accordingly. Asterisk's dialplan `ChanSpy` must be installed. This feature
monitors only channel names belonging to enabled local extensions; trunk-only
channels and non-connected calls are not offered. SIP credentials can receive
calls even when the panel is closed; rotate them if compromised.

The browser uses a locally bundled copy of JsSIP 3.13.9, downloaded from
`https://esm.sh/jssip@3.13.9/es2022/jssip.bundle.mjs`. MIT notices for JsSIP
and bundled `debug`, `events` and `sdp-transform` are in
`public/assets/vendor/*-LICENSE*`; there is no runtime CDN dependency.
