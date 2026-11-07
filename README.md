# Asterisk Panel

A clean, modern web admin panel for an **Asterisk** phone server — think
FusionPBX, but for Asterisk. Manage one business or many tenants from a single
app.

* PHP 8.2+ · no framework dependencies · no Composer required
* SQLite by default (MySQL supported)
* Themeable views, separated public/core/theme/resource layout
* Generates real Asterisk config (PJSIP, dialplan, voicemail, queues,
  parking, MOH, ConfBridge) and applies it over AMI

Required PHP extensions: `pdo_sqlite` (or `pdo_mysql`), `mbstring`,
`openssl`, and `fileinfo`.

---

## Feature set

Live call audio: tenant admins can use **Phone Listen** (Asterisk calls a
supervisor extension) or **Browser Listen** (WebRTC over HTTPS/WSS). See
[docs/live-listen.md](docs/live-listen.md) for requirements and verification.

| Area | What you can do |
|---|---|
| **Authentication** | Login/logout, sessions, CSRF, roles, throttling, and Fail2ban protection for web/SIP attacks |
| **Dashboard** | Live Asterisk status, active calls, registrations, per-feature counts, recent CDR |
| **Tenants** | Multiple businesses, each with its own Asterisk dialplan `context` so numbers never collide |
| **Extensions** | PJSIP endpoints, caller ID, ring timeout, regenerate SIP secret, voicemail binding, live registered IP per device |
| **Voicemail to email** | Mailboxes, PINs, attach audio / notify-only, envelope, per-mailbox timezone |
| **Device provisioning** | MAC-addressed devices, vendor templates (Yealink, Polycom, Grandstream, Fanvil), resync |
| **Ring groups** | Ring-all / least-recent / random / sequential, member selection, timeout |
| **Call parking** | Parking lots, orbit ranges, timeouts, courtesy tones, live parked-call view |
| **IVR menus** | Greeting, digit/response timeouts, key→destination mappings, timeout action |
| **Call routing** | Inbound (DID) routes, outbound routes, SIP trunks, time conditions |
| **Music on hold** | MOH classes bound to directories, random/shuffle |
| **Call center** | Queues with ring strategies, wrap-up, penalties, agent assignment, live status |
| **Conferences** | ConfBridge rooms, participant/admin PINs, max members, recording |
| **Call history** | Automatic CDR import, filters, stats, tenant-scoped WAV recording downloads |
| **System** | Live status with realtime host CPU/memory/disk/network + 24-hour resource graph, automatic inbound/outbound/all-call recording, settings, audit log |

---

## Quick start

### Ubuntu 24.04 production server (panel + Asterisk)

From a checked-out copy of this repository, run one command:

```bash
sudo bash scripts/install-server-ubuntu.sh \
  --domain pbx.example.com \
  --admin-email admin@example.com
```

This deploys the application to `/opt/asterisk-panel` and installs/configures
Asterisk, PHP 8.3-FPM, Nginx, SQLite, localhost-only AMI, Fail2ban, automatic
CDR import, protected call-recording storage, all managed Asterisk includes,
permissions, the initial superadmin, and the web SSL helper. A secure admin
password is generated and printed when `--admin-password` is omitted.
After signing in, finish Hosted ACME-DNS under **System → SSL / HTTPS**.

The installer supports Ubuntu **24.04**, preserves runtime data when re-run, and
only adds SIP/RTP/web firewall rules when UFW is already active.

### Development

```bash
# 1. Install (creates DB schema + admin user)
php scripts/install.php --username=admin --password=YourSecret1 --email=admin@example.com

# 2. Run it
php -S 0.0.0.0:8080 -t public public/router.php

# 3. Sign in at http://localhost:8080/login
```

For Apache, point the document root at `public/` (the included `.htaccess`
handles rewriting). For nginx, use the standard `try_files $uri $uri/ /index.php?$query_string;`
and set the root to `public/`.

### Production HTTPS

The recommended setup is available inside the panel at **System → SSL / HTTPS**.
It uses hosted `auth.acme-dns.io`, so certificate validation does not require
port 80 or DNS-provider API credentials. The panel hostname still needs an
`A`/`AAAA` record pointing to this server so browsers and phones can reach it:

1. Enter the panel hostname and certificate email in the web UI.
2. Add the displayed permanent `_acme-challenge` CNAME at your DNS provider.
3. Install the restricted helper once over SSH:

```bash
sudo bash scripts/install-web-ssl-helper.sh
```

4. Verify the CNAME and click **Issue certificate & enable HTTPS**.

The ACME credential can update only that delegated TXT challenge record. It is
stored outside the web root and is never rendered into the page. The helper has
one allow-listed sudo action rather than granting PHP general root access.

For a conventional HTTP-01 setup instead, point the domain's DNS at the server,
open TCP 80/443, and run:

```bash
sudo bash scripts/setup-https.sh \
  --domain pbx.example.com \
  --email admin@example.com
```

That idempotent installer configures Nginx, obtains a Let's Encrypt certificate,
redirects HTTP to HTTPS, enables renewal, and passes `APP_URL=https://...` plus
`APP_SECURE_COOKIES=1` to PHP-FPM. See
**[docs/asterisk-setup.md](docs/asterisk-setup.md#production-https--lets-encrypt)**
for manual setup and verification.

---

## Project layout

```
public/          Web root — front controller + static assets ONLY
  index.php        Front controller / dispatcher
  router.php       Dev router for `php -S`
  assets/          Public CSS/JS/images

app/             Core application logic (never web-accessible)
  bootstrap.php    Autoloading, error handling, config
  Core/            Micro-framework: Router, Request, Response, Database,
                   Auth, Session, Csrf, Flash, View, Validator, Logger, Model
  Controllers/     One per feature area
  Models/          (thin) data access
  Services/
    Asterisk/      AmiClient (TCP AMI), AsteriskManager, ConfigGenerator
    ProvisioningService.php, Mailer.php
  Support/         helpers.php, Str.php

themes/
  default/
    layouts/       app.php (auth shell), auth.php (login shell)
    views/         one folder per feature + partials/ + icons/
    assets/        theme CSS and JS

resources/
  config/          app, database, asterisk, mail, schema.php
  routes.php       Every URL → controller mapping
  templates/provisioning/   Vendor device templates

storage/          Runtime data (NOT web-accessible)
  database/, logs/, provision/, asterisk/, cache/

scripts/          Ubuntu server installer, DB installer, config apply,
                  SSL helpers, voicemail mail relay, CDR import
docs/             asterisk-setup.md — how to wire this to a live Asterisk
```

---

## Configuration

All settings read from environment variables (or edit `resources/config/*.php`).

| Variable | Default | Purpose |
|---|---|---|
| `APP_URL` | `http://localhost:8080` | Absolute URL; used to build provisioning links |
| `APP_DEBUG` | `false` | Show stack traces |
| `APP_TIMEZONE` | `UTC` | Application log/display timezone (IANA identifier) |
| `APP_SECURE_COOKIES` | auto | Force secure session cookies; automatically enabled for an HTTPS `APP_URL` |
| `APP_THEME` | `default` | Theme folder under `themes/` |
| `DB_DRIVER` | `sqlite` | `sqlite` or `mysql` |
| `AMI_HOST` / `AMI_PORT` | `127.0.0.1` / `5038` | Asterisk Manager Interface |
| `AMI_USER` / `AMI_SECRET` | `admin` / `change-me` | AMI credentials |
| `ASTERISK_CONFIG_DIR` | `/etc/asterisk/asterisk_panel` | Where generated config is written |
| `ASTERISK_SIP_HOST` | `pbx.example.com` | Registrar/hostname handed to phones |
| `VM_SMTP_HOST` etc. | — | SMTP relay for voicemail email (see docs) |

---

## Connecting to Asterisk

Read **[docs/asterisk-setup.md](docs/asterisk-setup.md)**. The short version:

1. Enable AMI in `/etc/asterisk/manager.conf` (TCP 5038) and set `AMI_*`.
2. Add one `#include asterisk_panel/...` line to `extensions.conf`, `pjsip.conf`,
   `voicemail.conf`, `queues.conf`, `musiconhold.conf`, `confbridge.conf`.
3. Point `mailcmd` in `voicemail.conf` at `scripts/asterisk-mail.php`.
4. Hit **System → Apply config** (or let any save trigger it).

The panel degrades gracefully — every page works without a reachable Asterisk,
showing an "offline" state instead of erroring.

---

## Security notes

* Everything outside `public/` is intentionally not web-servable.
* Passwords use `password_hash()` / `password_verify()` with rehashing.
* CSRF tokens on every unsafe request; sessions are `HttpOnly` + `SameSite=Lax`.
* HTTPS deployments use secure session cookies and HSTS through the supplied Nginx config.
* Login is throttled per-IP.
* Roles: `user` (read/CDR), `admin` (own tenant), `superadmin` (everything).
* Device provisioning is keyed by MAC and fails closed with a 404.

---

## Extending

The architecture is intentionally small so it is easy to grow:

1. **Add a feature** → create `app/Controllers/FooController.php`, add routes in
   `resources/routes.php`, add views under `themes/default/views/foo/`, and add
   any tables to `resources/config/schema.php`.
2. **Render new Asterisk config** → add a `renderFoo()` method to
   `app/Services/Asterisk/ConfigGenerator.php` and a case in `generateFor()`.
3. **Add a vendor template** → drop `resources/templates/provisioning/<vendor>.cfg`
   and add a `match` arm in `ProvisioningService`.
4. **Add a role ability** → extend the matrix in `app/Core/Auth.php::can()`.

---

## License

MIT — adapt freely for your business or clients.
