# Wiring the panel to a real Asterisk server

This panel is the control plane; Asterisk is the media plane. The panel keeps
its own database and **renders** it into Asterisk config files, then asks a
live Asterisk to reload. Nothing here touches distro-owned config files
directly — you add a single `#include` line once, and the panel owns everything
under a dedicated subdirectory.

```
┌─────────────┐   AMI (TCP 5038)   ┌──────────────────┐
│   Panel     │ ─────────────────► │    Asterisk       │
│  (PHP)      │                    │  (PJSIP, etc.)   │
│             │  writes files ───► │ /etc/asterisk/    │
└─────────────┘                    │   asterisk_panel/ │
       ▲                           └──────────────────┘
       │ HTTPS /prov/<mac>.cfg
┌──────┴──────┐
│  IP phones  │
└─────────────┘
```

---

## 1. Prerequisites

* Asterisk **20 LTS / 22 LTS / 24** with `res_pjsip` (PJSIP is the default
  channel driver in all supported releases; the old `chan_sip` is deprecated
  and removed in newer versions).
* PHP **8.2+** with `pdo_sqlite` (or `pdo_mysql`), `mbstring`, `openssl`, and `fileinfo`.
* Network reach from the panel host to the Asterisk host on **TCP 5038** (AMI).

### Automated Ubuntu 24.04 installation

For a new server where the panel and Asterisk run together, the recommended
path is the bundled installer rather than completing the manual sections below:

```bash
sudo bash scripts/install-server-ubuntu.sh \
  --domain pbx.example.com \
  --admin-email admin@example.com
```

It installs Asterisk, PHP 8.3-FPM, Nginx and required extensions; deploys the
panel under `/opt/asterisk-panel`; creates a random localhost-only AMI account;
adds every managed include; initializes SQLite and the superadmin; starts and
verifies Asterisk; and installs the restricted web SSL helper. The generated
panel password is printed once at completion.

Useful optional arguments:

```text
--admin-user USER
--admin-password PASS
--tenant "Business Name"
--install-dir /opt/asterisk-panel
--no-firewall-rules
```

The remaining sections document the equivalent manual setup and are useful for
split panel/Asterisk deployments or troubleshooting.

---

## 2. Enable the Manager Interface (AMI)

Edit `/etc/asterisk/manager.conf`:

```ini
[general]
enabled = yes
webenabled = no
bindaddr = 0.0.0.0
port = 5038

[admin]
secret = choose-a-strong-secret
read    = system,call,log,verbose,command,agent,user,config,dtmf
write   = system,call,log,verbose,command,agent,user,config,dtmf
; Restrict to the panel host where possible:
permit  = 10.0.0.0/24
```

Reload with `asterisk -rx "manager reload"`.

Then tell the panel how to connect (environment variables):

```bash
AMI_HOST=127.0.0.1
AMI_PORT=5038
AMI_USER=admin
AMI_SECRET=choose-a-strong-secret
```

---

## 3. Let Asterisk load the generated config

Create the include directory and add **one** `#include` line to each relevant
file. The panel writes into `/etc/asterisk/asterisk_panel/` by default
(`ASTERISK_CONFIG_DIR` overrides it).

**`/etc/asterisk/extensions.conf`** — add near the top:

```ini
; --- Asterisk Panel ---
#include asterisk_panel/dialplan.conf
```

**`/etc/asterisk/pjsip.conf`** — add near the top:

```ini
; --- Asterisk Panel ---
#include asterisk_panel/pjsip_base.conf
#include asterisk_panel/pjsip_endpoints.conf
#include asterisk_panel/pjsip_trunks.conf
```

Copy the supplied base transport/templates once:

```bash
sudo install -m 0644 resources/deploy/asterisk/pjsip-base.conf \
  /etc/asterisk/asterisk_panel/pjsip_base.conf
```

**`/etc/asterisk/voicemail.conf`** — add at the very top of the file:

```ini
; --- Asterisk Panel ---
#include asterisk_panel/voicemail.conf
```

**`/etc/asterisk/queues.conf`**:

```ini
#include asterisk_panel/queues.conf
```

**`/etc/asterisk/res_parking.conf`** (parking):

```ini
#include asterisk_panel/parking.conf
```

The generated file contains `res_parking` parking-lot sections.

**`/etc/asterisk/musiconhold.conf`**:

```ini
#include asterisk_panel/musiconhold.conf
```

**`/etc/asterisk/confbridge.conf`**:

```ini
#include asterisk_panel/confbridge.conf
```

Then reload everything: `asterisk -rx "core reload"`.

---

## 4. Voicemail to email

app_voicemail shells out to `mailcmd` for every new message. Point it at the
relay script, which reads the full RFC-822 message (including the audio
attachment) from STDIN and hands it to your MTA or an SMTP relay:

```ini
; voicemail.conf [general]
mailcmd = /usr/bin/php /opt/asterisk-panel/scripts/asterisk-mail.php
```

The relay tries, in order:

1. **Local MTA** — `sendmail`/`postfix`/`exim` at `SENDMAIL_PATH`
   (default `/usr/sbin/sendmail`), invoked as `sendmail -t -i`.
2. **SMTP relay** — set these to skip the local MTA:

   ```bash
   VM_SMTP_HOST=smtp.example.com
   VM_SMTP_PORT=587
   VM_SMTP_USER=pbx@example.com
   VM_SMTP_PASS=...
   VM_SMTP_ENCRYPTION=tls     # tls | ssl | none
   ```

Per-mailbox `email`, `attach` and `saycid` come from the panel's Voicemail page
and are written into `voicemail.conf`.

Restart Asterisk (`core restart`) after changing `mailcmd` — it is read at
module load.

---

## 5. Device provisioning

Phones poll `https://<panel>/prov/<mac>.cfg` (or `.xml` for Fanvil). The panel
renders a vendor template (`resources/templates/provisioning/`) with the linked
extension's credentials.

* **Yealink** — set the provisioning server to
  `https://<panel>/prov/<mac>.cfg` in the phone UI, or use DHCP option 66 /
  Yealink's RPS redirect.
* **Grandstream** — `https://<panel>/prov/<mac>.cfg` (their XML format).
* **Polycom** — `https://<panel>/prov/<mac>.cfg`.
* **Fanvil** — `https://<panel>/prov/<mac>.xml`.

This endpoint is intentionally **unauthenticated** (the MAC is the secret) and
fails closed with a 404 for unknown/disabled MACs. Because it is on your LAN or
behind TLS, put it behind HTTPS and restrict access at the firewall.

### Production HTTPS / Let's Encrypt

#### Web setup with Hosted ACME-DNS (recommended)

Sign in as a superadmin and open **System → SSL / HTTPS**. This workflow uses
the public `https://auth.acme-dns.io` service and Let's Encrypt DNS-01. Keep an
`A`/`AAAA` record for the panel hostname pointed at this server for normal web
and phone access; DNS-01 itself does not require inbound port 80.

1. Register the public panel hostname and email in the page.
2. At your normal DNS provider, create the exact CNAME shown by the panel:
   `_acme-challenge.<panel-host>` → `<unique-id>.auth.acme-dns.io`.
3. Over SSH, install the privileged component once:

   ```bash
   sudo bash scripts/install-web-ssl-helper.sh
   ```

   If PHP-FPM does not run as `www-data`, pass `--web-user USER`. If socket
   detection fails, pass `--php-socket /run/php/php8.2-fpm.sock`.
4. Click **Verify CNAME**, then **Issue certificate & enable HTTPS**.

The browser never receives the ACME-DNS username/password. They are stored as
`storage/acme/acme-dns.json` with mode `0600`, outside `public/`. The one-time
installer adds a sudo rule for only `asterisk-panel-ssl issue`; PHP cannot run
arbitrary privileged commands. Executable hooks and the Nginx template are
installed as root-owned files. Certbot saves the authentication and deploy hooks
for unattended renewal, and Nginx is reloaded after renewal.

Keep the CNAME permanently. Removing it breaks renewal. The hosted service is
then authorized to answer only the delegated ACME TXT challenge, not to modify
any other DNS records. The supplied privileged helper intentionally allows only
the selected hosted `auth.acme-dns.io` endpoint.

#### Direct server setup with HTTP-01

Before requesting a certificate, create an `A`/`AAAA` DNS record for the panel
and allow inbound TCP **80** and **443**. On Debian or Ubuntu, run from the
project root:

```bash
sudo bash scripts/setup-https.sh \
  --domain pbx.example.com \
  --email admin@example.com
```

The script:

1. Installs Nginx and Certbot when missing.
2. Detects the PHP-FPM Unix socket (or accepts `--php-socket`).
3. Uses the ACME webroot challenge to obtain the certificate.
4. Installs `resources/deploy/nginx-https.conf.template`, redirects HTTP to
   HTTPS, and enables HSTS and standard security headers.
5. Passes `APP_URL=https://pbx.example.com` and `APP_SECURE_COOKIES=1` to PHP.

For a custom deployment, use the template manually and set these variables in
the PHP-FPM environment:

```bash
APP_URL=https://pbx.example.com
APP_SECURE_COOKIES=1
APP_DEBUG=0
```

`APP_URL` is authoritative for generated provisioning links. The app also
enables secure cookies automatically whenever that URL begins with `https://`.
If another reverse proxy terminates TLS, forward `X-Forwarded-Proto: https`,
keep the external `APP_URL` set to HTTPS, and never expose PHP-FPM publicly.

Verify redirects, TLS, and automatic renewal:

```bash
curl -I http://pbx.example.com
curl -I https://pbx.example.com/login
sudo certbot renew --dry-run
systemctl status certbot.timer
```

Use a publicly trusted certificate for phone provisioning; many desk phones do
not accept private CAs. After switching an existing installation to HTTPS,
re-copy the provisioning URL from the Devices page or reprovision the phones.

---

## 6. CDR collection

The panel reads from its `cdr` table. Populate it either way:

### Option A — HTTP backend (recommended)

Create `/etc/asterisk/cdr.conf`:

```ini
[general]
enable = yes

[cdr_csv]
enable = yes
header = yes
accountcols = accountcode
```

Or stream CDRs over HTTP to the panel with `func_curl` in the dialplan, or the
`cdr_http` module pointed at an ingest endpoint you add. Adjust to taste.

### Option B — periodic CSV import

Asterisk writes `/var/log/asterisk/cdr-csv/Master.csv`. Import it on a cron:

```cron
*/5 * * * * /usr/bin/php /opt/asterisk-panel/scripts/import-cdr.php
```

The importer appends only rows it has not seen before (keyed on `uniqueid`).
Remember to set the tenant mapping — see the script header.

---

## 7. Multi-tenancy model

Each tenant gets a unique Asterisk **context** (the `context` column on
`tenants`). The panel namespaces every generated section by that context, so
two tenants can both use extension `1001` with no collision:

* PJSIP endpoint names become `<context>_<extension>` (e.g. `acme_1001`).
* Dialplan contexts are `<tenant-context>` plus derived contexts
  (`-inbound`, `-outbound`, `-ivr-<n>`, `-<timecond>`).
* Voicemail mailboxes are grouped under `[<tenant-context>]`.
* Conferences use `[<pin>]` bridge profiles scoped per tenant context.

A tenant's `context` is **immutable after creation** — renaming it would orphan
every dialplan reference. Delete and recreate instead if you must change it.

---

## 8. Security checklist

- [ ] AMI `permit` restricted to the panel host's IP.
- [ ] AMI secret is strong and not the `ClueCon` default.
- [ ] `APP_URL` uses **HTTPS** in production.
- [ ] `APP_DEBUG=0` in production.
- [ ] The `storage/` directory is **not** web-accessible (it sits outside
      `public/`, keep it that way).
- [ ] Device provisioning endpoint restricted by firewall / reverse proxy.
- [ ] Panel database file permissions restricted to the web user.

---

## 9. Troubleshooting

| Symptom | Check |
|---|---|
| Dashboard says "Asterisk is not reachable" | AMI host/port/secret, `permit` rules, firewall on 5038. |
| Phones can't register | `pjsip show endpoints`, generated `pjsip_endpoints.conf` exists, `#include` present, endpoint name matches. |
| Inbound calls do nothing | `dialplan show <tenant-context>-inbound`, DID pattern format (`_` prefix for wildcards). |
| Voicemail email never arrives | `mailcmd` set + executable, script readable by the `asterisk` user, `core restart` after editing. |
| Config changes have no effect | Confirm the `#include` lines exist and `core reload` succeeded — see `/system/status`. |
| Provisioning returns 404 | MAC unknown/disabled, or the phone requested a different filename (`.xml` vs `.cfg`). Check `storage/logs/`. |

Live diagnosis:

```bash
asterisk -rvvv
> pjsip show endpoints
> pjsip show contacts
> dialplan show default
> queue show
> core show channels
```
