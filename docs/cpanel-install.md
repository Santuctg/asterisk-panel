# Installing the panel on cPanel (shared hosting)

This guide runs the **PHP web panel** on a cPanel account: login, dashboard,
tenants, extensions, routes, CDR and settings all work. The Asterisk side
(telephony daemon, AMI, config files, call recording, Fail2ban) needs a
rooted server — see [What does not work on cPanel](#what-does-not-work-on-cpanel).

> Requirements: PHP **8.2+** with `pdo_sqlite` (or `pdo_mysql`), `mbstring`,
> `openssl`, `fileinfo`. Check in cPanel → *Select PHP Version* → *Extensions*,
> or run `php -v` / `php -m` in Terminal.

## 1. Upload

Upload the whole project (zip via cPanel *File Manager*, or SFTP/git) into
your account, e.g. `~/asterisk-panel/`, so that the layout is:

```
~/asterisk-panel/
  public/        ← web root (this is what the domain must point at)
  app/
  themes/
  resources/
  scripts/
  storage/
  .env.example
```

## 2. Point the domain at `public/`

The web server must serve **only** `public/` — everything else stays
unreachable (the included `public/.htaccess` handles routing and blocks
`.env`, `.git`, PHP files other than `index.php`).

* **cPanel → Domains → Document Root**: set it to `public` (recommended,
  available on most current cPanel versions), **or**
* **cPanel → Apache Version / "Multiple PHP" is not needed** — just create a
  subdomain/vhost whose document root is `~/asterisk-panel/public`.

If you cannot change the document root, put this in
`~/asterisk-panel/.htaccess` (the directory cPanel points at by default)
**only if** `AllowOverride` permits it — otherwise move `public/` contents up
one level, keeping `app/` etc. outside the web root is then no longer
guaranteed, so prefer the document-root change:

```apache
# Only when the domain root is the project root — prefer setting the
# document root to public/ instead.
RewriteEngine On
RewriteRule ^$ public/ [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule !^public/ public/%{REQUEST_URI} [L]
```

## 3. Configure with `.env`

cPanel cannot pass shell environment variables to PHP, so the panel reads a
`.env` file (loaded by `app/bootstrap.php`; real environment variables still
win). Copy and edit:

```bash
cd ~/asterisk-panel
cp .env.example .env
```

Minimal cPanel `.env`:

```ini
APP_URL=https://yourdomain.com
APP_DEBUG=0
APP_TIMEZONE=Asia/Dhaka
DB_DRIVER=sqlite

# Point AMI at your separate Asterisk server (optional, see below)
AMI_HOST=pbx.example.com
AMI_PORT=5038
AMI_USER=admin
AMI_SECRET=...
ASTERISK_SIP_HOST=pbx.example.com
```

`storage/database/`, `storage/logs/`, `storage/cache/`,
`storage/provision/` must be writable by PHP
(`chmod -R 775 storage` from Terminal, or 755 if the PHP user is you).

## 4. Install the database

From cPanel **Terminal**:

```bash
cd ~/asterisk-panel
php scripts/install.php
```

This creates the SQLite schema and the default `admin` user (password printed
on screen; pass `--force` to regenerate). With MySQL, set `DB_DRIVER=mysql`,
`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` in `.env` first (create the
database in cPanel → *MySQL Databases*).

## 5. SSL

Use **cPanel → SSL/TLS Status → Run AutoSSL** (or *Let's Encrypt* if your
host provides it). The panel's hosted ACME-DNS helper
(`/system/ssl`) and `scripts/setup-https.sh` need root/Nginx and do not apply
here — AutoSSL covers HTTP-01 challenges for you (`.well-known` is allowed
through `.htaccess`). After the certificate issues, set `APP_URL=https://...`
in `.env` and enable *Force HTTPS Redirect* in cPanel → Domains.

## 6. Scheduled tasks (CDR import)

There is no systemd on cPanel; use **cPanel → Cron Jobs**:

```cron
*/5 * * * * /usr/local/bin/php /home/USER/asterisk-panel/scripts/import-cdr.php >> /home/USER/asterisk-panel/storage/logs/cron.log 2>&1
```

(Adjust `php` path — run `which php` in Terminal. The script only imports
rows that exist in the CSV, which is produced by a *separate* Asterisk
server; see below.)

## 7. Optional: connect to a separate Asterisk server

If you run Asterisk on a VPS, set in `.env`:

```ini
AMI_HOST=pbx.example.com
AMI_PORT=5038
AMI_USER=admin
AMI_SECRET=...
ASTERISK_CONFIG_DIR=/etc/asterisk/asterisk_panel
```

* **Status pages** work if the host allows outbound TCP to 5038
  (many shared hosts block this — test from Terminal:
  `nc -vz pbx.example.com 5038`).
* **Apply config** writes files locally under `storage/asterisk/` on cPanel
  (no `/etc/asterisk` access) and reloads over AMI only if reachable; copy
  the files to the Asterisk box manually or with your own automation.
* **Call recording downloads** work if `ASTERISK_RECORDING_DIR` points to a
  directory that exists on the cPanel account (e.g. copy recordings there
  via rsync from the PBX). On non-nginx servers PHP streams the authorized
  file directly (no `X-Accel-Redirect` needed).

## What does not work on cPanel

| Feature | Why |
|---|---|
| Asterisk install / PJSIP / dialplan apply | needs root; `/etc/asterisk` is off-limits |
| AMI "apply config" to a local Asterisk | no local daemon; outbound 5038 often blocked |
| Call recording capture (`MixMonitor`) | recording happens on the PBX, not the panel host |
| Fail2ban, firewall, Nginx snippets, certbot | root/systemd required |
| Hosted ACME-DNS SSL helper | needs the `acme-dns` helper script + DNS control |

Everything else — login, roles, tenants, extensions, ring groups, IVR,
routes, queues, voicemail settings, CDR browsing, provisioning files,
settings, audit log — runs normally.

**Recommended split:** panel on cPanel (this guide) + one small Ubuntu VPS
running Asterisk (`scripts/install-server-ubuntu.sh` from the main README
does the full rooted install). The panel connects over AMI and serves
provisioning from its own URL.

## Troubleshooting

* **500 errors** → `APP_DEBUG=1` in `.env`, reload, read the error; check
  `storage/logs/`.
* **Blank page / only `/login` works** → `mod_rewrite` disabled or
  `AllowOverride None`. Ask your host to enable `mod_rewrite` for the
  account, or use a host that allows `.htaccess` `FileInfo` overrides.
* **"Unable to open database"** → `storage/database/` not writable.
* **Wrong PHP version** → cPanel *Select PHP Version* → 8.2/8.3 and verify
  `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo` are checked.
