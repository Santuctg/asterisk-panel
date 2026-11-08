<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\App;
use App\Core\Config;
use App\Core\Logger;

/**
 * Renders the panel's database into real Asterisk configuration files.
 *
 * Every file is written to a single managed include directory (default
 * /etc/asterisk/asterisk_panel/). A small "loader" snippet is dropped into
 * each main Asterisk conf (see docs/asterisk-setup.md) so Asterisk picks up
 * everything we generate without touching distro-owned files:
 *
 *     ; extensions.conf
 *     #include asterisk_panel/dialplan.conf
 *
 * One tenant == one Asterisk "context". Extensions, IVRs, ring groups,
 * parking lots, queues and conferences all get a per-tenant prefix so
 * multiple businesses can share a single Asterisk safely.
 */
final class ConfigGenerator
{
    private string $dir;
    private \App\Core\Database $db;

    public function __construct()
    {
        $this->dir  = rtrim((string) Config::get('asterisk.config_dir', '/etc/asterisk/asterisk_panel'), '/');
        $this->db   = App::getInstance()->db();
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /** Write every managed file, then return the list written. */
    public function generateAll(): array
    {
        $written = [];

        $written['pjsip_port.conf']       = $this->renderPjsipPort();
        $written['pjsip_endpoints.conf']  = $this->renderPjsipEndpoints();
        $written['pjsip_trunks.conf']     = $this->renderPjsipTrunks();
        $written['dialplan.conf']         = $this->renderDialplan();
        $written['voicemail.conf']        = $this->renderVoicemail();
        $written['queues.conf']           = $this->renderQueues();
        $written['parking.conf']          = $this->renderParking();
        $written['musiconhold.conf']      = $this->renderMusicOnHold();
        $written['confbridge.conf']       = $this->renderConfBridge();

        foreach ($written as $name => $content) {
            $this->write($name, $content);
        }

        return array_keys($written);
    }

    /** Generate only the files affected by a given feature (faster reload). */
    public function generateFor(string $feature): array
    {
        if ($feature === 'tenant') {
            return $this->generateAll();
        }
        $files = match ($feature) {
            'sip_port' => ['pjsip_port.conf' => $this->renderPjsipPort()],
            'settings' => [
                'pjsip_port.conf' => $this->renderPjsipPort(),
                'dialplan.conf'   => $this->renderDialplan(),
            ],
            'extension', 'voicemail' => [
                'pjsip_endpoints.conf' => $this->renderPjsipEndpoints(),
                'voicemail.conf'       => $this->renderVoicemail(),
                'dialplan.conf'        => $this->renderDialplan(),
            ],
            'trunk' => ['pjsip_trunks.conf' => $this->renderPjsipTrunks(), 'dialplan.conf' => $this->renderDialplan()],
            'ringgroup', 'ivr', 'route', 'timecondition', 'announcement' => ['dialplan.conf' => $this->renderDialplan()],
            'parking'   => ['parking.conf' => $this->renderParking(), 'dialplan.conf' => $this->renderDialplan()],
            'queue'     => ['queues.conf' => $this->renderQueues(), 'dialplan.conf' => $this->renderDialplan()],
            'moh'       => ['musiconhold.conf' => $this->renderMusicOnHold()],
            'conference'=> ['confbridge.conf' => $this->renderConfBridge(), 'dialplan.conf' => $this->renderDialplan()],
            'device'    => [], // provisioning is served live, no conf file
            default     => null,
        };

        if ($files === null) {
            return $this->generateAll();
        }

        foreach ($files as $name => $content) {
            $this->write($name, $content);
        }

        return array_keys($files);
    }

    private function write(string $name, string $content): void
    {
        $path = $this->dir . '/' . $name;

        // Atomic-ish write to avoid Asterisk reading a half-written file.
        $tmp   = $path . '.' . uniqid('', true) . '.tmp';
        $ok    = false;

        if (is_dir($this->dir) || @mkdir($this->dir, 0775, true)) {
            if (@file_put_contents($tmp, $content) !== false) {
                $ok = @rename($tmp, $path);
            }
        }

        if ($ok) {
            return;
        }

        // Running on a dev box without write access to the conf dir:
        // mirror into storage/asterisk so the operator can inspect/diff.
        @unlink($tmp);
        $fallback = BASE_PATH . '/storage/asterisk';
        @mkdir($fallback, 0775, true);
        @file_put_contents($fallback . '/' . $name, $content);
        Logger::warning('Wrote config to fallback location (no access to ' . $this->dir . ')', ['file' => $name]);
    }

    // -----------------------------------------------------------------
    // PJSIP
    // -----------------------------------------------------------------

    public function renderPjsipPort(): string
    {
        return 'bind = 0.0.0.0:' . SipPort::configured() . "\n";
    }

    public function renderPjsipEndpoints(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n";
        $out .= ";; Local extensions (PJSIP endpoints, auth + AOR).\n\n";

        $out .= "[global]\n";
        $out .= "type = global\n";
        $out .= "disable_multi_domain = no\n";
        $out .= "endpoint_identifier_order = ip,username,auth_username\n\n";

        // Each authorized panel operator gets an isolated, listen-only browser
        // endpoint. It cannot originate calls through the tenant dialplan.
        $monitorCredentials = new MonitorCredential();
        $monitorTenants = $this->db->all('SELECT id, context FROM tenants WHERE enabled = 1 ORDER BY id');
        $monitorUsers = $this->db->all('SELECT id, tenant_id, role FROM users WHERE active = 1 ORDER BY id');
        foreach ($monitorTenants as $tenant) {
            foreach ($monitorUsers as $user) {
                if ((string) $user['role'] !== 'superadmin' && (int) ($user['tenant_id'] ?? 0) !== (int) $tenant['id']) continue;
                $monitor = $monitorCredentials->forActor((int) $tenant['id'], (string) $tenant['context'], (int) $user['id']);
                $name = $monitor['username'];
                $out .= "; Active Calls monitor for panel user {$user['id']}\n";
                $out .= "[{$name}]\ntype = endpoint\ntransport = panel-transport-ws\n";
                $out .= "context = panel-listen-only\nauth = {$name}\naors = {$name}\n";
                $out .= "webrtc = yes\ndtls_auto_generate_cert = yes\ndirect_media = no\n";
                $out .= "force_rport = yes\nrewrite_contact = yes\nrtp_symmetric = yes\n";
                $out .= "allow = !all,opus,ulaw,alaw\nidentify_by = username,auth_username\n\n";
                $out .= "[{$name}]\ntype = auth\nauth_type = userpass\nusername = {$name}\n";
                $out .= "password = {$this->esc($monitor['password'])}\n\n";
                $out .= "[{$name}]\ntype = aor\nmax_contacts = 1\nremove_existing = yes\n\n";
            }
        }

        $extensions = $this->db->all(
            'SELECT e.*, t.context AS ctx, t.domain AS tenant_domain FROM extensions e
             JOIN tenants t ON t.id = e.tenant_id
             WHERE e.enabled = 1 ORDER BY t.id, e.extension'
        );

        foreach ($extensions as $ext) {
            $realm = $this->tenantRealm((string) ($ext['tenant_domain'] ?? ''), (string) $ext['ctx']);
            $extension = $this->dialToken((string) $ext['extension']);
            $name = $extension . '@' . $realm;
            $webBase = $this->endpointName($ext['ctx'], $ext['extension']);
            $ctx  = $this->contextName((string) $ext['ctx']);

            $out .= "; ---- {$extension} ({$this->esc((string) $ext['name'])}) context={$ctx} ----\n";
            $out .= "[{$name}](endpoint_basic)\n";
            $out .= "context = {$ctx}\n";
            $out .= "auth = {$name}\n";
            $out .= "aors = {$name}\n";
            $out .= "identify_by = username,auth_username\n";
            if (!empty($ext['outbound_route_id'])) {
                $out .= "set_var = PANEL_OUTBOUND_ROUTE_ID=" . (int) $ext['outbound_route_id'] . "\n";
            }
            // Always emit a callerid so the display name comes from the
            // caller's configured name, never the called extension's number.
            $cidName = $this->esc((string) ($ext['cid_name'] ?: $ext['name'] ?: $ext['extension']));
            $cidNum  = $this->callerNumber((string) (($ext['cid_number'] ?? '') !== '' ? $ext['cid_number'] : $extension));
            $out .= "callerid = \"{$cidName}\" <{$cidNum}>\n";
            $out .= "\n";

            $out .= "[{$name}]\n";
            $out .= "type = auth\n";
            $out .= "auth_type = userpass\n";
            // FusionPBX-style multi-domain identity: the user remains "201"
            // while the endpoint/AOR is qualified as "201@tenant-domain".
            $out .= "username = {$extension}\n";
            $out .= "password = {$this->esc($ext['secret'])}\n";
            $out .= "realm = {$realm}\n\n";

            $out .= "[{$name}]\n";
            $out .= "type = aor\n";
            $out .= "max_contacts = " . max(1, (int) $ext['max_registrations']) . "\n";
            $out .= "remove_existing = yes\n";
            // OPTIONS keepalive gives each phone a live latency (RTT) reading.
            $out .= "qualify_frequency = 60\n";
            $out .= "qualify_timeout = 3\n\n";

            // A separate identity is used for normal browser calling. Keeping
            // it distinct preserves the listen-only endpoint's restrictions.
            $phone = $webBase . '-phone-web';
            $out .= "[{$phone}]\n";
            $out .= "type = endpoint\ntransport = panel-transport-ws\n";
            $out .= "context = {$ctx}\nauth = {$phone}\naors = {$phone}\n";
            if (!empty($ext['outbound_route_id'])) {
                $out .= "set_var = PANEL_OUTBOUND_ROUTE_ID=" . (int) $ext['outbound_route_id'] . "\n";
            }
            $out .= "callerid = \"{$cidName}\" <{$cidNum}>\n";
            $out .= "webrtc = yes\ndtls_auto_generate_cert = yes\ndirect_media = no\n";
            $out .= "force_rport = yes\nrewrite_contact = yes\nrtp_symmetric = yes\n";
            $out .= "allow = !all,opus,ulaw,alaw\nidentify_by = username,auth_username\n\n";
            $out .= "[{$phone}]\ntype = auth\nauth_type = userpass\n";
            $out .= "username = {$phone}\npassword = {$this->esc($ext['secret'])}\n\n";
            $out .= "[{$phone}]\ntype = aor\nmax_contacts = 1\nremove_existing = yes\n\n";
        }

        return $out;
    }

    public function renderPjsipTrunks(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n";
        $out .= ";; SIP trunks / gateways.\n\n";

        $trunks = $this->db->all(
            'SELECT g.*, t.context AS ctx FROM trunks g
             JOIN tenants t ON t.id = g.tenant_id WHERE g.enabled = 1 ORDER BY g.name'
        );

        $hostUseCounts = [];
        foreach ($trunks as $trunk) {
            $matchHost = strtolower(rtrim($this->sipValue((string) $trunk['host']), '.'));
            if ($matchHost !== '') {
                $hostUseCounts[$matchHost] = ($hostUseCounts[$matchHost] ?? 0) + 1;
            }
        }

        foreach ($trunks as $t) {
            $trunkName = $this->esc((string) $t['name']);
            $host = $this->sipValue((string) $t['host']);
            $username = $this->sipValue((string) $t['username']);
            $out .= "; ---- {$trunkName} ({$host}) ----\n";

            // Registration (outbound auth) block.
            $out .= "[trunk-{$t['id']}](registration)\n";
            $out .= "outbound_auth = trunk-{$t['id']}-auth\n";
            $out .= "client_uri = sip:{$username}@{$host}\n";
            $out .= "server_uri = sip:{$host}\n";
            if ($username !== '') {
                // Registrars that strip the opaque line parameter still route
                // calls to this Contact user, giving the shared DID dispatcher
                // a stable, tenant-specific registration identity.
                $out .= "contact_user = {$username}\n";
            }
            // The registrar returns this opaque line token on inbound calls,
            // binding the request to this exact endpoint before IP matching.
            // This is essential when multiple tenants use the same provider.
            $out .= "line = yes\n";
            $out .= "endpoint = trunk-{$t['id']}\n";
            if (!empty($t['register'])) {
                $out .= "retry_interval = " . max(1, (int) $t['register']) . "\n";
            }
            $out .= "\n";

            $out .= "[trunk-{$t['id']}-auth]\n";
            $out .= "type = auth\n";
            $out .= "auth_type = userpass\n";
            $out .= "username = {$username}\n";
            $out .= "password = {$this->esc($t['secret'])}\n\n";

            // AoR pointing at the provider.
            $out .= "[trunk-{$t['id']}-aor]\n";
            $out .= "type = aor\n";
            $out .= "contact = sip:{$host}\n";
            // Qualify the provider so RTT/latency and reachability are visible.
            $out .= "qualify_frequency = 60\n";
            $out .= "qualify_timeout = 3\n\n";

            // The endpoint itself.
            $out .= "[trunk-{$t['id']}](trunk-basic)\n";
            $fromDomain = $this->sipValue((string) ($t['from_domain'] ?: $host));
            $out .= "from_domain = {$fromDomain}\n";
            $out .= "context = {$this->contextName((string) $t['ctx'])}-inbound\n";
            $out .= "trust_id_inbound = yes\n";
            $out .= "aors = trunk-{$t['id']}-aor\n";
            $out .= "outbound_auth = trunk-{$t['id']}-auth\n";
            if (!empty($t['cid'])) {
                $out .= "callerid = {$this->line((string) $t['cid'])}\n";
            }
            $out .= "\n";

            // Keep IP/host matching only as a compatibility fallback when the
            // provider host is unique. Duplicate identify matches can select
            // another tenant's endpoint when an ITSP host is shared.
            $matchHost = strtolower(rtrim($host, '.'));
            if (($hostUseCounts[$matchHost] ?? 0) === 1) {
                $out .= "[trunk-{$t['id']}-identify]\n";
                $out .= "type = identify\n";
                $out .= "endpoint = trunk-{$t['id']}\n";
                $out .= "match = {$host}\n\n";
            } else {
                $out .= "; Shared provider host: using line binding with DID-dispatch fallback.\n\n";
            }
        }

        // A provider that does not return registration line parameters cannot
        // safely identify one of several tenant endpoints by IP alone. Route
        // that shared IP to a DID dispatcher instead of an arbitrary tenant.
        foreach ($hostUseCounts as $matchHost => $count) {
            if ($count < 2) continue;
            $suffix = substr(hash('sha256', $matchHost), 0, 12);
            $sharedEndpoint = 'trunk-shared-' . $suffix;
            $out .= "[{$sharedEndpoint}](trunk-basic)\n";
            $out .= "context = panel-inbound-shared-{$suffix}\n";
            $out .= "trust_id_inbound = yes\n\n";
            $out .= "[{$sharedEndpoint}-identify]\n";
            $out .= "type = identify\n";
            $out .= "endpoint = {$sharedEndpoint}\n";
            $out .= "match = {$matchHost}\n\n";
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Voicemail
    // -----------------------------------------------------------------

    public function renderVoicemail(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n\n";
        $out .= "[general]\n";
        $out .= "format = wav49|wav|mp3\n";
        $out .= "serveremail = " . $this->line((string) Config::get('asterisk.voicemail.serveremail', 'asterisk@localhost')) . "\n";
        $out .= "mailcmd = " . $this->line((string) Config::get('asterisk.voicemail.mailcmd', '/usr/bin/php ' . BASE_PATH . '/scripts/asterisk-mail.php')) . "\n";
        $out .= "attach = " . $this->line((string) Config::get('asterisk.voicemail.attach', 'yes')) . "\n";
        $out .= "maxmessage = 180\n";
        $out .= "minmessage = 3\n";
        $out .= "maxgreet = 60\n";
        $out .= "skipkey = *\n";
        $out .= "forcename = no\n";
        $out .= "sayduration = yes\n";
        $out .= "saydurationm = 1\n";
        $out .= "searchcontexts = no\n";
        $out .= "moveheard = no\n";
        $out .= "delete = no\n\n";

        // Asterisk's mailbox `tz` option references a key in [zonemessages],
        // not an IANA identifier directly. Generate a stable, safe key for
        // every IANA zone currently used by an enabled mailbox.
        $zoneRows = $this->db->all('SELECT DISTINCT timezone FROM voicemail WHERE enabled = 1');
        $zones = [];
        foreach ($zoneRows as $zoneRow) {
            $zone = \normalize_timezone((string) ($zoneRow['timezone'] ?? ''), 'UTC');
            $zones[$zone] = 'iana_' . substr(hash('sha256', $zone), 0, 16);
        }
        ksort($zones);

        $out .= "[zonemessages]\n";
        foreach ($zones as $zone => $name) {
            $out .= "{$name}={$zone}|'vm-received' Q 'digits/at' IMp\n";
        }
        $out .= "\n";

        // Mailboxes grouped by tenant context so multi-tenant stays isolated.
        $tenants = $this->db->all('SELECT id, context, name FROM tenants ORDER BY id');

        foreach ($tenants as $t) {
            $context = $this->contextName((string) $t['context']);
            $boxes = $this->db->all(
                'SELECT v.*, e.extension AS ext FROM voicemail v
                 JOIN extensions e ON e.id = v.extension_id
                 WHERE v.tenant_id = ? AND v.enabled = 1 ORDER BY ext',
                [$t['id']]
            );

            if (!$boxes) {
                continue;
            }

            $out .= "; ---- Tenant: {$this->esc((string) $t['name'])} ({$context}) ----\n";
            $out .= "[{$context}]\n";

            foreach ($boxes as $b) {
                // mailbox => password,name,email,attachment,pageremail,pager,options,timezone
                $opts = [];
                $opts[] = 'attach=' . ($b['attach'] ? 'yes' : 'no');
                $opts[] = 'saycid=' . ($b['say_cid'] ? 'yes' : 'no');
                $opts[] = 'envelope=' . ($b['envelope'] ? 'yes' : 'no');
                $opts[] = 'skipinstructions=' . ($b['skip_instructions'] ? 'yes' : 'no');
                $opts[] = 'vm-mailto=' . $this->line((string) $b['email']);
                if ($b['vm_password'] !== '') {
                    $opts[] = 'vm-secret=' . $this->line((string) $b['vm_password']);
                }
                $zone = \normalize_timezone((string) ($b['timezone'] ?? ''), 'UTC');
                $opts[] = 'tz=' . ($zones[$zone] ?? ('iana_' . substr(hash('sha256', $zone), 0, 16)));

                $out .= sprintf(
                    "%s => %s,%s,%s,%s,,,%s\n",
                    $this->dialToken((string) $b['ext']),
                    $this->line((string) $b['pin']),
                    $this->esc($b['name']),
                    $this->esc($b['email']),
                    $b['pager_email'] ? $this->line((string) $b['pager_email']) : '""',
                    implode(',', $opts)
                );
            }

            $out .= "\n";
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Dialplan
    // -----------------------------------------------------------------

    public function renderDialplan(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n";
        $out .= ";; Contexts: one per tenant, plus shared [globals]-style helpers.\n\n";
        $out .= $this->recordingHelperContext();
        $out .= $this->announcementHelperContext();
        $out .= $this->dialplanSharedProviderInbound();

        $tenants = $this->db->all('SELECT * FROM tenants ORDER BY id');

        foreach ($tenants as $t) {
            $ctx  = $this->contextName((string) $t['context']);
            $tid  = $t['id'];
            $recordingMode = $this->tenantSetting((int) $tid, 'recording_mode', 'off');
            $recordInbound = in_array($recordingMode, ['all', 'inbound'], true);
            $recordOutbound = in_array($recordingMode, ['all', 'outbound'], true);
            $recordInternal = $recordingMode === 'all';
            $out .= "; ============================================================\n";
            $out .= "; TENANT: {$this->esc((string) $t['name'])}  (context {$ctx})\n";
            $out .= "; ============================================================\n\n";

            $out .= $this->dialplanInboundRoutes($tid, $ctx, $recordInbound);

            // All tenant-dialable numbers belong to one immutable context.
            // Helper contexts (IVR menus and time-condition branches) are
            // emitted afterwards so they cannot accidentally capture the
            // next ring group/extension definition.
            $out .= "[{$ctx}]\n";
            $out .= $this->dialplanTimeConditionEntries($tid, $ctx, $recordInternal);
            $out .= $this->dialplanIvrEntries($tid, $ctx, $recordInternal);
            $realm = $this->tenantRealm((string) ($t['domain'] ?? ''), (string) $ctx);
            $out .= $this->dialplanRingGroups($tid, $ctx, $recordInternal, $realm);
            $out .= $this->dialplanQueueEntries($tid, $ctx, $recordInternal);
            $out .= $this->dialplanConferences($tid, $ctx, $recordInternal);
            $out .= $this->dialplanParking($tid, $ctx, $recordInternal);
            $out .= $this->dialplanExtensions($tid, $ctx, $recordInternal, $realm);
            $out .= "include => {$ctx}-outbound\n\n";

            $out .= $this->dialplanTimeConditionContexts($tid, $ctx);
            $out .= $this->dialplanIvrContexts($tid, $ctx);
            $out .= $this->dialplanAnnouncementContexts($tid, $ctx);
            $out .= $this->dialplanOutboundRoutes($tid, $ctx, $recordOutbound);
        }

        // Browser identities may register but cannot dial arbitrary numbers.
        $out .= "[panel-listen-only]\n";
        $out .= "exten => _X.,1,Hangup()\n\n";

        return $out;
    }

    private function dialplanInboundRoutes(int $tid, string $ctx, bool $record = false): string
    {
        $routes = $this->db->all(
            'SELECT * FROM inbound_routes WHERE tenant_id = ? AND enabled = 1 ORDER BY priority, id',
            [$tid]
        );

        $out = "[{$ctx}-inbound]\n";
        if (!$routes) {
            $out .= "; (no inbound routes configured)\n\n";
            return $out;
        }

        foreach ($routes as $r) {
            $pattern = $this->didToPattern($r['did_pattern']);
            $routeName = $this->esc((string) $r['name']);
            $out .= "; {$routeName}\n";
            $out .= "exten => {$pattern},1,NoOp(Inbound: {$routeName})\n";
            $out .= " same => n,Set(__PANEL_TENANT_ID={$tid})\n";
            $out .= " same => n,Set(__PANEL_INBOUND_CALLER=\${CALLERID(num)})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Inbound Route: ' . (string) $r['name'])})\n";
            if ($this->shouldRecord((int) ($r['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('inbound', $ctx);
            }
            $out .= $this->announcementGosub((int) ($r['announcement_id'] ?? 0), $tid);
            $out .= " same => n,Gosub({$this->destinationGosub($r['dest_type'], $r['dest_id'], $ctx)} )\n";
            $out .= " same => n,Hangup()\n\n";
        }

        return $out;
    }

    /** DID fallback for ITSPs that strip PJSIP registration line parameters. */
    private function dialplanSharedProviderInbound(): string
    {
        $trunks = $this->db->all(
            'SELECT g.host, g.username, g.tenant_id, t.context AS ctx FROM trunks g
             JOIN tenants t ON t.id = g.tenant_id WHERE g.enabled = 1 AND t.enabled = 1'
        );
        $groups = [];
        foreach ($trunks as $trunk) {
            $host = strtolower(rtrim($this->sipValue((string) $trunk['host']), '.'));
            if ($host === '') continue;
            $groups[$host][] = $trunk;
        }

        $out = '';
        foreach ($groups as $host => $members) {
            if (count($members) < 2) continue;
            $suffix = substr(hash('sha256', $host), 0, 12);
            $out .= "[panel-inbound-shared-{$suffix}]\n";
            $tenantContexts = [];
            foreach ($members as $member) {
                $tenantContexts[(int) $member['tenant_id']] = $this->contextName((string) $member['ctx']);
            }
            $placeholders = implode(',', array_fill(0, count($tenantContexts), '?'));
            $routes = $this->db->all(
                "SELECT tenant_id, did_pattern FROM inbound_routes
                 WHERE enabled = 1 AND tenant_id IN ({$placeholders}) ORDER BY priority, id",
                array_keys($tenantContexts)
            );
            $patterns = [];
            $catchAllTenants = [];
            foreach ($routes as $route) {
                $pattern = $this->didToPattern((string) $route['did_pattern']);
                // A catch-all cannot prove tenant ownership on a shared host.
                if ($pattern === '_X.') {
                    $catchAllTenants[(int) $route['tenant_id']] = true;
                    continue;
                }
                $patterns[$pattern][] = [
                    'tenant_id' => (int) $route['tenant_id'],
                    'target' => '${EXTEN}',
                    'source' => 'DID',
                ];
            }
            // A Contact user is tied to one outbound registration. It can
            // therefore select that tenant's catch-all route without allowing
            // another tenant's generic _X. route to capture the call.
            foreach ($members as $member) {
                $tenantId = (int) $member['tenant_id'];
                $contactUser = trim((string) ($member['username'] ?? ''));
                if ($contactUser === '' || empty($catchAllTenants[$tenantId])) continue;
                $pattern = $this->didToPattern($contactUser);
                if (isset($patterns[$pattern])) {
                    $existingTenants = array_unique(array_column($patterns[$pattern], 'tenant_id'));
                    // An explicit DID for this same tenant is more precise than
                    // its registration-user fallback. A different tenant must
                    // remain ambiguous and fail closed below.
                    if (count($existingTenants) === 1 && (int) $existingTenants[0] === $tenantId) continue;
                }
                $patterns[$pattern][] = [
                    'tenant_id' => $tenantId,
                    // Preserve a numeric registration DID so the tenant's
                    // catch-all _X. route matches (it requires 2+ digits).
                    // Non-numeric account IDs use a safe two-digit surrogate.
                    'target' => preg_match('/^[0-9]{2,}$/', $contactUser) ? '${EXTEN}' : '00',
                    'source' => 'registration',
                ];
            }
            foreach ($patterns as $pattern => $matchingRoutes) {
                $targets = [];
                foreach ($matchingRoutes as $matchingRoute) {
                    $targets[$matchingRoute['tenant_id'] . '|' . $matchingRoute['target']] = $matchingRoute;
                }
                if (count($targets) !== 1) {
                    $out .= "exten => {$pattern},1,NoOp(Ambiguous shared-provider DID; refusing cross-tenant routing)\n";
                    $out .= " same => n,Hangup(21)\n";
                    continue;
                }
                $route = array_values($targets)[0];
                $ctx = $tenantContexts[(int) $route['tenant_id']];
                $out .= "exten => {$pattern},1,NoOp(Shared-provider {$route['source']} dispatch to {$ctx})\n";
                $out .= " same => n,Set(__PANEL_INBOUND_CALLER=\${CALLERID(num)})\n";
                $out .= " same => n,Goto({$ctx}-inbound,{$route['target']},1)\n";
            }
            $out .= "exten => _X.,1,NoOp(Unmatched shared-provider DID; refusing default-tenant fallback)\n";
            $out .= " same => n,Hangup(21)\n\n";
        }
        return $out;
    }

    private function dialplanOutboundRoutes(int $tid, string $ctx, bool $record = false): string
    {
        $routes = $this->db->all(
            'SELECT * FROM outbound_routes WHERE tenant_id = ? AND enabled = 1 ORDER BY priority, id',
            [$tid]
        );

        $out  = "[{$ctx}-outbound]\n";
        // The access gate must accept both ordinary digit strings and E.164
        // numbers beginning with + before dispatching to the assigned route.
        foreach (['_X.', '_+X.'] as $gatePattern) {
            $out .= "exten => {$gatePattern},1,NoOp(Outbound access check)\n";
            foreach ($routes as $r) {
                $out .= " same => n,GotoIf(\$[\"\${PANEL_OUTBOUND_ROUTE_ID}\"=\"{$r['id']}\"]?route-{$r['id']})\n";
            }
            $out .= " same => n,NoOp(Outbound calling denied: no enabled route assigned)\n";
            $out .= " same => n,Hangup(21)\n";
            foreach ($routes as $r) {
                $out .= " same => n(route-{$r['id']}),Goto({$ctx}-outbound-{$r['id']},\${EXTEN},1)\n";
            }
        }
        $out .= "\n";

        foreach ($routes as $r) {
            $prefix  = $this->outboundPrefix((string) ($r['prefix'] ?? ''));
            $patterns = $this->outboundRoutePatterns((string) $r['match_pattern']);
            $trunk   = $this->trunkEndpoint((int) $r['trunk_id'], $tid);
            $dialed  = $prefix !== '' ? '${EXTEN:' . strlen((string) $prefix) . '}' : '${EXTEN}';
            $condition = null;
            $schedules = $this->db->all(
                'SELECT name, days_of_week, start_time, end_time FROM outbound_route_schedules
                 WHERE tenant_id = ? AND outbound_route_id = ? ORDER BY ordering, id',
                [$tid, (int) $r['id']]
            );
            if (!empty($r['time_condition_id'])) {
                $condition = $this->db->one(
                    'SELECT * FROM time_conditions WHERE id = ? AND tenant_id = ? AND enabled = 1',
                    [(int) $r['time_condition_id'], $tid]
                );
            }

            $out .= "[{$ctx}-outbound-{$r['id']}]\n";
            $routeName = $this->esc((string) $r['name']);
            $out .= "; {$routeName}\n";
            $steps = '';
            if ($schedules) {
                foreach ($schedules as $schedule) {
                    $start = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $schedule['start_time']) ? $schedule['start_time'] : '00:00';
                    $end = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $schedule['end_time']) ? $schedule['end_time'] : '23:59';
                    $days = preg_match('/^[a-z*&\-]+$/', (string) $schedule['days_of_week']) ? $schedule['days_of_week'] : '*';
                    $steps .= " same => n,GotoIfTime({$start}-{$end},{$days},*,*,{$this->tenantTimezone($tid)}?time-allowed)\n";
                }
                $steps .= " same => n,NoOp(Outbound blocked: no route calling schedule matched)\n";
                $steps .= " same => n,Hangup(21)\n";
                $steps .= " same => n(time-allowed),NoOp(Outbound calling schedule matched)\n";
            } elseif (!empty($r['time_condition_id'])) {
                if ($condition) {
                    $steps .= " same => n,GotoIfTime(" . $this->timeMatch($condition, $this->tenantTimezone($tid)) . "?time-allowed)\n";
                    $steps .= " same => n,NoOp(Outbound blocked by time condition: {$this->esc($condition['name'])})\n";
                    $steps .= " same => n,Hangup(21)\n";
                    $steps .= " same => n(time-allowed),NoOp(Outbound time condition matched)\n";
                } else {
                    $steps .= " same => n,NoOp(Outbound blocked: assigned time condition is unavailable)\n";
                    $steps .= " same => n,Hangup(21)\n";
                }
            }
            if ($this->shouldRecord((int) ($r['record'] ?? 0), $record)) {
                $steps .= $this->recordingGosub('outbound', $ctx);
            }
            $callerId = $r['cid_override'] ? $this->callerNumber((string) $r['cid_override']) : '${CALLERID(num)}';
            $steps .= " same => n,Set(CALLERID(num)={$callerId})\n";
            $steps .= " same => n,Dial(PJSIP/{$dialed}@{$trunk},30,Tt)\n";
            $steps .= " same => n,Hangup()\n\n";
            foreach ($patterns as $pattern) {
                $out .= "exten => {$this->outboundPattern((string) $prefix, $pattern)},1,NoOp(Outbound: {$routeName})\n";
                $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Outbound Route: ' . (string) $r['name'])})\n";
                $out .= $steps;
            }
        }

        return $out;
    }

    private function dialplanExtensions(int $tid, string $ctx, bool $record, string $realm): string
    {
        $exts = $this->db->all(
            'SELECT * FROM extensions WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );

        $out = '';

        foreach ($exts as $e) {
            $extension = $this->dialToken((string) $e['extension']);
            $ep    = $extension . '@' . $realm;
            $label = $this->esc($e['name']);

            $out .= "; {$extension} {$label}\n";
            $out .= "exten => {$extension},1,NoOp(Ext {$extension} {$label})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Extension: ' . $extension)})\n";
            if ($this->shouldRecord((int) ($e['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Dial(PJSIP/{$ep}," . (int) $e['ring_timeout'] . ',Tt' . ($e['follow_me'] ? 'm' : '') . ")\n";

            if (!empty($e['vm_on_busy']) || !empty($e['vm_on_noanswer'])) {
                $out .= " same => n(voicemail),Set(__PANEL_CALL_STAGE={$this->stageLabel('Voicemail: ' . $extension)})\n";
                $out .= " same => n,Voicemail({$extension}@{$ctx}," .
                    (!empty($e['vm_on_busy']) ? 'u' : 'b') . ")\n";
            }
            $out .= " same => n,Hangup()\n\n";
        }

        return $out;
    }

    private function dialplanRingGroups(int $tid, string $ctx, bool $record, string $realm): string
    {
        $groups = $this->db->all(
            'SELECT * FROM ring_groups WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );

        $out = '';
        foreach ($groups as $g) {
            $extension = $this->dialToken((string) $g['extension']);
            $members = $this->memberEndpoints((int) $g['id'], $realm);
            $strategy = $this->ringStrategy($g['strategy']);

            $out .= "; Ring group {$extension} {$this->esc($g['name'])}\n";
            $out .= "exten => {$extension},1,NoOp(Ring group: {$this->esc($g['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Ring Group: ' . (string) $g['name'])})\n";
            if ($this->shouldRecord((int) ($g['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= $this->announcementGosub((int) ($g['announcement_id'] ?? 0), $tid);
            // The shared announcement helper temporarily labels the call as
            // Announcement. Restore this feature before member ringing.
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Ring Group: ' . (string) $g['name'])})\n";

            if ($members === '') {
                $out .= " same => n,Playback(pbx-invalid)\n";
                $out .= " same => n,Hangup()\n\n";
                continue;
            }

            $out .= " same => n,Set(__RINGSTRATEGY={$strategy})\n";
            $dialOptions = 'TtH';
            $out .= " same => n,Dial({$members}," . (int) $g['timeout'] . ",{$dialOptions})\n";
            $out .= " same => n,Hangup()\n\n";
        }

        return $out;
    }

    private function dialplanQueueEntries(int $tid, string $ctx, bool $record = false): string
    {
        $queues = $this->db->all(
            'SELECT * FROM queues WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );

        $out = '';
        foreach ($queues as $q) {
            $extension = $this->dialToken((string) $q['extension']);
            $out .= "; Queue {$extension} {$this->esc($q['name'])}\n";
            $out .= "exten => {$extension},1,NoOp(Queue: {$this->esc($q['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Queue: ' . (string) $q['name'])})\n";
            if ($this->shouldRecord((int) ($q['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= $this->announcementGosub((int) ($q['announcement_id'] ?? 0), $tid);
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Queue: ' . (string) $q['name'])})\n";
            $out .= " same => n,Queue({$extension},tT,,," . (int) $q['max_wait'] . ")\n";
            $out .= " same => n,Hangup()\n\n";
        }
        return $out;
    }

    private function dialplanIvrEntries(int $tid, string $ctx, bool $record = false): string
    {
        $ivrs = $this->db->all(
            'SELECT * FROM ivr_menus WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );

        $out = '';
        foreach ($ivrs as $ivr) {
            $extension = $this->dialToken((string) $ivr['extension']);
            $out .= "; IVR {$extension} {$this->esc($ivr['name'])}\n";
            $out .= "exten => {$extension},1,NoOp(IVR: {$this->esc($ivr['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('IVR: ' . (string) $ivr['name'])})\n";
            if ($this->shouldRecord((int) ($ivr['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Answer()\n";
            $out .= " same => n,Set(TIMEOUT(digit)=" . (int) $ivr['timeout'] . ")\n";
            $out .= " same => n,Set(TIMEOUT(response)=" . (int) $ivr['response_timeout'] . ")\n";
            $out .= " same => n,Goto({$ctx}-ivr-{$extension},s,1)\n\n";
        }

        return $out;
    }

    private function dialplanIvrContexts(int $tid, string $ctx): string
    {
        $ivrs = $this->db->all(
            'SELECT * FROM ivr_menus WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );

        $out = '';
        foreach ($ivrs as $ivr) {
            $extension = $this->dialToken((string) $ivr['extension']);
            $greeting = !empty($ivr['greeting'])
                ? $this->soundPath((string) $ivr['greeting'])
                : 'silence/1';
            $maxRetries = max(1, (int) ($ivr['max_retries'] ?? 3));
            $out .= "[{$ctx}-ivr-{$extension}]\n";
            $out .= "exten => s,1,Set(__IVR_MENU={$extension})\n";
            $out .= " same => n,Set(IVR_RETRIES=0)\n";
            $out .= " same => n(menu),Set(__PANEL_CALL_STAGE={$this->stageLabel('IVR: ' . (string) $ivr['name'])})\n";
            $out .= " same => n,Background({$greeting})\n";
            $out .= " same => n,WaitExten()\n";
            $out .= " same => n,Goto(t,1)\n\n";

            $keys = json_decode((string) $ivr['keys'], true) ?: [];
            foreach ($keys as $key => $dest) {
                $key = $this->dialToken((string) $key);
                $out .= "exten => {$key},1,Gosub({$this->destinationGosub($dest['type'], $dest['id'], $ctx)})\n";
                $out .= " same => n,Hangup()\n";
            }

            // Timeout / invalid fallback.
            if (!empty($ivr['timeout_action'])) {
                $out .= "exten => t,1,Gosub({$this->destinationGosub($ivr['timeout_action'], $ivr['timeout_dest_id'], $ctx)})\n";
                $out .= " same => n,Hangup()\n";
            } else {
                $out .= "exten => t,1,Goto(s,menu)\n";
            }
            $out .= "exten => i,1,Playback(pbx-invalid)\n";
            $out .= " same => n,Set(IVR_RETRIES=$[\${IVR_RETRIES} + 1])\n";
            $out .= " same => n,GotoIf($[\${IVR_RETRIES} >= {$maxRetries}]?exhausted:retry)\n";
            $out .= " same => n(retry),Goto(s,menu)\n";
            if (!empty($ivr['timeout_action'])) {
                $out .= " same => n(exhausted),Gosub({$this->destinationGosub($ivr['timeout_action'], $ivr['timeout_dest_id'], $ctx)})\n";
                $out .= " same => n,Hangup()\n\n";
            } else {
                $out .= " same => n(exhausted),Hangup()\n\n";
            }
        }

        return $out;
    }

    /** Routable announcements: play the prompt completely, then continue. */
    private function dialplanAnnouncementContexts(int $tid, string $ctx): string
    {
        $rows = $this->db->all(
            'SELECT * FROM announcements WHERE tenant_id = ? AND enabled = 1 ORDER BY id',
            [$tid]
        );
        $out = '';
        foreach ($rows as $row) {
            $path = (string) ($row['sound_path'] ?? '');
            if (!preg_match('#^[A-Za-z0-9_./-]+$#', $path)) { continue; }
            $type = (string) ($row['dest_type'] ?? 'hangup');
            $id = $row['dest_id'] ?? 0;
            $out .= "[{$ctx}-announcement-{$row['id']}]\n";
            $out .= "exten => s,1,NoOp(Announcement: {$this->esc($row['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Announcement: ' . (string) $row['name'])})\n";
            $out .= " same => n,Answer()\n";
            $out .= " same => n,Playback({$path})\n";
            if ($type === 'hangup') {
                $out .= " same => n,Hangup()\n\n";
            } else {
                $out .= " same => n,Gosub({$this->destinationGosub($type, $id, $ctx)})\n";
                $out .= " same => n,Return()\n\n";
            }
        }
        return $out;
    }

    private function dialplanTimeConditionEntries(int $tid, string $ctx, bool $record = false): string
    {
        $conds = $this->db->all(
            'SELECT * FROM time_conditions WHERE tenant_id = ? AND enabled = 1 ORDER BY priority, id',
            [$tid]
        );

        $out = '';
        foreach ($conds as $c) {
            if (empty($c['extension'])) {
                continue;
            }
            $extension = $this->dialToken((string) $c['extension']);
            $out .= "; Time condition {$this->esc($c['name'])}\n";
            $out .= "exten => {$extension},1,NoOp(Time condition: {$this->esc($c['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Time Condition: ' . (string) $c['name'])})\n";
            if ($this->shouldRecord((int) ($c['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Gosub({$ctx}-timecond-{$c['id']},s,1)\n";
            $out .= " same => n,Hangup()\n\n";
        }

        return $out;
    }

    private function dialplanTimeConditionContexts(int $tid, string $ctx): string
    {
        $conds = $this->db->all(
            'SELECT * FROM time_conditions WHERE tenant_id = ? AND enabled = 1 ORDER BY priority, id',
            [$tid]
        );

        $out = '';
        foreach ($conds as $c) {
            if (empty($c['open_type']) || empty($c['closed_type'])) {
                continue;
            }
            $out .= "[{$ctx}-timecond-{$c['id']}]\n";
            $out .= "exten => s,1,NoOp(Time condition: {$this->esc($c['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Time Condition: ' . (string) $c['name'])})\n";
            $out .= " same => n,GotoIfTime(" . $this->timeMatch($c, $this->tenantTimezone($tid)) . "?open)\n";
            $out .= " same => n,Gosub({$this->destinationGosub($c['closed_type'], $c['closed_dest_id'], $ctx)})\n";
            $out .= " same => n,Return()\n";
            $out .= " same => n(open),Gosub({$this->destinationGosub($c['open_type'], $c['open_dest_id'], $ctx)})\n";
            $out .= " same => n,Return()\n\n";
        }

        return $out;
    }

    private function dialplanConferences(int $tid, string $ctx, bool $record = false): string
    {
        $rooms = $this->db->all(
            'SELECT * FROM conferences WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );

        $out = '';
        foreach ($rooms as $r) {
            $extension = $this->dialToken((string) $r['extension']);
            $profile = $this->contextName((string) ($r['profile'] ?: 'default'));
            $out .= "; Conference {$extension} {$this->esc($r['name'])}\n";
            $out .= "exten => {$extension},1,NoOp(Conference: {$this->esc($r['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Conference: ' . (string) $r['name'])})\n";
            if ($this->shouldRecord((int) ($r['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Answer()\n";
            $out .= " same => n,ConfBridge({$this->dialToken((string) $r['pin'])}@{$ctx},{$ctx}-{$profile},{$ctx}-users)\n";
            $out .= " same => n,Hangup()\n\n";
        }

        return $out;
    }

    private function dialplanParking(int $tid, string $ctx, bool $record = false): string
    {
        $lots = $this->db->all(
            'SELECT * FROM parking_lots WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );

        $out = '';
        foreach ($lots as $l) {
            $extension = $this->dialToken((string) $l['extension']);
            $out .= "; Park lot {$this->esc($l['name'])} (ext {$extension})\n";
            $out .= "exten => {$extension},1,NoOp(Park lot: {$this->esc($l['name'])})\n";
            $out .= " same => n,Set(__PANEL_CALL_STAGE={$this->stageLabel('Parking: ' . (string) $l['name'])})\n";
            if ($this->shouldRecord((int) ($l['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $range = preg_replace('/[^0-9-]/', '', $this->line((string) $l['parked_range'])) ?: '701-750';
            $returnExtension = $this->dialToken((string) $l['return_extension']);
            $out .= " same => n,Park({$range},{$ctx}-{$l['id']},{$ctx}-{$l['id']},{$returnExtension})\n";
            $out .= " same => n,Hangup()\n\n";
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Queues (call center)
    // -----------------------------------------------------------------

    public function renderQueues(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n\n";

        $tenants = $this->db->all('SELECT id, context, domain, name FROM tenants ORDER BY id');

        foreach ($tenants as $t) {
            $queues = $this->db->all(
                'SELECT * FROM queues WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
                [$t['id']]
            );

            if (!$queues) {
                continue;
            }

            foreach ($queues as $q) {
                $tid = (int) $t['id'];
                $ctx = $this->contextName((string) $t['context']);
                $realm = $this->tenantRealm((string) ($t['domain'] ?? ''), (string) $ctx);
                $extension = $this->dialToken((string) $q['extension']);
                $out .= "; {$this->esc((string) $q['name'])} ({$extension})\n";
                $out .= "[{$extension}]\n";
                $out .= "context = {$ctx}\n";
                $out .= "musicclass = " . $this->mohClassName($tid, (string) ($q['moh_class'] ?: 'default')) . "\n";
                $out .= "timeout = " . (int) $q['timeout'] . "\n";
                $out .= "maxlen = " . (int) $q['max_wait'] . "\n";
                $out .= "servicelevel = " . (int) $q['service_level'] . "\n";
                $out .= "strategy = " . $this->queueStrategy($q['strategy']) . "\n";
                $out .= "wrapuptime = " . (int) $q['wrapup'] . "\n";
                $out .= "retry = " . (int) $q['retry'] . "\n";
                $out .= "agentannounce = " . $this->soundPath((string) ($q['agent_announce'] ?: 'none')) . "\n";
                $out .= "memberdelay = " . (int) $q['member_delay'] . "\n";
                $out .= "timeoutrestart = yes\n";
                $out .= "dynamic_members = yes\n";
                $out .= "EventMemberStatus = yes\n\n";

                // Static members are the agents attached to this queue.
                $agents = $this->db->all(
                    'SELECT e.extension, e.name, a.penalty, a.paused
                     FROM queue_agents a
                     JOIN extensions e ON e.id = a.extension_id
                     WHERE a.queue_id = ? ORDER BY a.penalty, e.extension',
                    [(int) $q['id']]
                );

                foreach ($agents as $ag) {
                    $agentExtension = $this->dialToken((string) $ag['extension']);
                    $out .= "member => PJSIP/{$agentExtension}@{$realm}," . (int) $ag['penalty'] . ',' . $this->esc((string) $ag['name']) .
                        ($ag['paused'] ? ',paused' : '') . "\n";
                }
                $out .= "\n";
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Parking
    // -----------------------------------------------------------------

    public function renderParking(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n";
        $out .= ";; res_parking: one [parkinglot_<id>] section per configured lot.\n\n";

        $lots = $this->db->all('SELECT * FROM parking_lots WHERE enabled = 1 ORDER BY id');

        foreach ($lots as $l) {
            $ctx = $this->contextName((string) $this->db->scalar('SELECT context FROM tenants WHERE id = ?', [$l['tenant_id']]));
            $out .= "; {$this->esc((string) $l['name'])}\n";
            $out .= "[parkinglot-{$l['id']}]\n";
            $out .= "context = {$ctx}-{$l['id']}\n";
            $out .= "parkext = {$this->dialToken((string) $l['extension'])}\n";
            $out .= "parkextsounds = " . ($l['play_sounds'] ? 'yes' : 'no') . "\n";
            $out .= "parktime = " . (int) $l['timeout'] . "\n";
            $out .= "parkedcalltransfers = " . ($l['allow_transfer'] ? 'yes' : 'no') . "\n";
            $out .= "parkedcallreparking = " . ($l['allow_repark'] ? 'yes' : 'no') . "\n";
            $out .= "parkedcallhangup = " . ($l['allow_hangup'] ? 'yes' : 'no') . "\n";
            $out .= "parkedcallrecording = " . ($l['record'] ? 'yes' : 'no') . "\n";
            $out .= "parkingtime = " . (int) $l['timeout'] . "\n";
            $out .= "courtesytone = " . $this->soundPath((string) ($l['courtesy_tone'] ?: 'beep')) . "\n\n";
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Music on hold
    // -----------------------------------------------------------------

    public function renderMusicOnHold(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n\n";
        $out .= "[general]\n";
        $out .= "cachemclasses = yes\n\n";

        $classes = $this->db->all(
            'SELECT m.*, t.context AS tenant_context FROM moh_classes m
             JOIN tenants t ON t.id = m.tenant_id ORDER BY m.tenant_id, m.id'
        );

        foreach ($classes as $c) {
            $dir = trim((string) ($c['directory'] ?? ''));
            if ($dir === '' || !preg_match('#^/[A-Za-z0-9_./ -]+$#', $this->line($dir))) {
                $dir = rtrim((string) Config::get('asterisk.moh_path', '/var/lib/asterisk/moh'), '/')
                    . '/' . $this->mohPathPart((string) $c['tenant_context']) . '/' . $this->mohPathPart((string) $c['name']);
            }
            $dir = $this->line($dir);
            $className = $this->mohClassName((int) $c['tenant_id'], (string) $c['name']);
            $out .= "; {$this->esc((string) $c['tenant_context'])} / {$this->esc((string) $c['name'])}\n";
            $out .= "[{$className}]\n";
            $out .= "mode = files\n";
            $out .= "directory = {$dir}\n";
            if ($c['random']) {
                $out .= "random = yes\n";
            }
            if ($c['shuffle']) {
                $out .= "shuffle = yes\n";
            }
            $out .= "\n";
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // ConfBridge
    // -----------------------------------------------------------------

    public function renderConfBridge(): string
    {
        $out  = ";; Generated by Asterisk Panel - do not edit by hand.\n\n";
        $out .= "[general]\n\n";

        $rooms = $this->db->all('SELECT * FROM conferences WHERE enabled = 1 ORDER BY id');
        $ctxs  = $this->db->all('SELECT id, context FROM tenants');

        foreach ($ctxs as $ctxRow) {
            $ctx = $this->contextName((string) $ctxRow['context']);
            $tid = (int) $ctxRow['id'];

            // Asterisk's ACO parser rejects the whole file when any option is
            // unknown, so keep these names aligned with Asterisk 20.
            $out .= "[{$ctx}-default]\n";
            $out .= "type = bridge\n\n";

            $out .= "[{$ctx}-users]\n";
            $out .= "type = user\n";
            $out .= "announce_user_count = yes\n";
            $out .= "announce_join_leave = yes\n";
            $out .= "marked = no\n";
            $out .= "end_marked = no\n";
            $out .= "music_on_hold_class = " . $this->mohClassName($tid, 'default') . "\n\n";

            foreach ($rooms as $r) {
                if ((int) $r['tenant_id'] !== $tid) {
                    continue;
                }
                $out .= "; {$this->esc((string) $r['name'])}\n";
                $out .= "[{$this->dialToken((string) $r['pin'])}]\n";
                $out .= "type = bridge\n";
                $out .= "max_members = " . max(0, (int) $r['max_members']) . "\n";
                $out .= "record_conference = " . ($r['record'] ? 'yes' : 'no') . "\n\n";
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function mohClassName(int $tenantId, string $name): string
    {
        return 'tenant' . $tenantId . '_' . $this->mohPathPart($name);
    }

    private function mohPathPart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'default';
        return substr($safe, 0, 80);
    }

    private function recordingHelperContext(): string
    {
        $dir = rtrim((string) Config::get('asterisk.recording_dir', '/var/spool/asterisk/monitor/asterisk-panel'), '/');
        if (!preg_match('#^/[A-Za-z0-9_./-]+$#', $dir)) {
            $dir = '/var/spool/asterisk/monitor/asterisk-panel';
        }

        $out  = "[panel-auto-record]\n";
        $out .= "exten => s,1,NoOp(Auto recording: \${ARG1})\n";
        $out .= " same => n,GotoIf(\$[\"\${PANEL_RECORDING_STARTED}\"=\"1\"]?done)\n";
        $out .= " same => n,Set(__PANEL_RECORDING_STARTED=1)\n";
        $out .= " same => n,Set(__PANEL_RECORDING_FILE=\${ARG2}-\${ARG1}-\${STRFTIME(\${EPOCH},,%Y%m%d-%H%M%S)}-\${UNIQUEID}.wav)\n";
        $out .= " same => n,Set(CDR(userfield)=recording:\${PANEL_RECORDING_FILE})\n";
        $out .= " same => n,GotoIf(\$[\"\${ARG3}\"=\"answered\"]?answered)\n";
        $out .= " same => n,MixMonitor({$dir}/\${PANEL_RECORDING_FILE})\n";
        $out .= " same => n,Goto(done)\n";
        $out .= " same => n(answered),MixMonitor({$dir}/\${PANEL_RECORDING_FILE},b)\n";
        $out .= " same => n(done),Return()\n\n";
        return $out;
    }

    private function recordingGosub(string $direction, string $ctx): string
    {
        $tenantId = (int) $this->db->scalar('SELECT id FROM tenants WHERE context = ? LIMIT 1', [$ctx]);
        $start = $tenantId > 0 ? $this->tenantSetting($tenantId, 'recording_start', 'full') : 'full';
        $start = $start === 'answered' ? 'answered' : 'full';
        return " same => n,Gosub(panel-auto-record,s,1({$direction},{$ctx},{$start}))\n";
    }

    private function announcementHelperContext(): string
    {
        $out  = "[panel-announcement]\n";
        $out .= "exten => s,1,NoOp(Non-skippable caller announcement)\n";
        $out .= " same => n,GotoIf(\$[\"\${PANEL_ANNOUNCEMENT_PLAYED}\"=\"1\"]?done)\n";
        $out .= " same => n,Set(__PANEL_CALL_STAGE=Announcement)\n";
        $out .= " same => n,Set(__PANEL_ANNOUNCEMENT_PLAYED=1)\n";
        $out .= " same => n,Answer()\n";
        // Some SIP clients need a short answered-media interval before RTP
        // playback starts, otherwise the prompt can be clipped or silent.
        $out .= " same => n,Wait(1)\n";
        $out .= " same => n,Playback(\${ARG1})\n";
        $out .= " same => n(done),Return()\n\n";
        return $out;
    }

    private function announcementGosub(int $announcementId, int $tenantId): string
    {
        if ($announcementId <= 0) { return ''; }
        $path = $this->db->scalar(
            'SELECT sound_path FROM announcements WHERE id = ? AND tenant_id = ? AND enabled = 1',
            [$announcementId, $tenantId]
        );
        if (!is_string($path) || !preg_match('#^[A-Za-z0-9_./-]+$#', $path)) { return ''; }
        return " same => n,Gosub(panel-announcement,s,1({$path}))\n";
    }

    private function tenantSetting(int $tenantId, string $key, string $default = ''): string
    {
        $value = $this->db->scalar(
            'SELECT value FROM settings
             WHERE `key` = ? AND (tenant_id = ? OR tenant_id IS NULL)
             ORDER BY CASE WHEN tenant_id = ? THEN 0 ELSE 1 END, id DESC LIMIT 1',
            [$key, $tenantId, $tenantId]
        );
        return $value === false || $value === null ? $default : (string) $value;
    }

    /**
     * Resolve whether to record a call leg.
     *
     * itemRecord: 0 = inherit tenant default, 1 = force record, 2 = force skip.
     */
    private function shouldRecord(int $itemRecord, bool $tenantRecord): bool
    {
        if ($itemRecord === 1) {
            return true;
        }
        if ($itemRecord === 2) {
            return false;
        }
        return $tenantRecord;
    }

    /** Build a Gosub() target that routes to any supported destination. */
    private function destinationGosub(string $type, int|string|null $id, string $ctx): string
    {
        if ($type !== 'hangup' && ($id === null || $id === '')) {
            return 'app-dial,1,Hangup()';
        }
        return match ($type) {
            'extension'  => $this->extenGosub((int) $id, $ctx),
            'ringgroup'  => $this->ringGroupGosub((int) $id, $ctx),
            'ivr'        => $this->ivrGosub((int) $id, $ctx),
            'conference' => $this->conferenceGosub((int) $id, $ctx),
            'queue'      => $this->queueGosub((int) $id, $ctx),
            'parking'    => $this->parkingGosub((int) $id, $ctx),
            'timecond'   => $this->timeCondGosub((int) $id, $ctx),
            'announcement' => "{$ctx}-announcement-" . (int) $id . ',s,1',
            'external'   => 'app-dial,1,Dial(SIP/' . $this->callerNumber((string) $id) . ')',
            'hangup'     => 'app-dial,1,Hangup()',
            'voicemail'  => $this->voicemailGosub((int) $id, $ctx),
            default      => 'app-dial,1,Hangup()',
        };
    }

    private function extenGosub(int $id, string $ctx): string
    {
        $e = $this->db->one('SELECT extension FROM extensions WHERE id = ?', [$id]);
        return $e ? "{$ctx},{$this->dialToken((string) $e['extension'])},1" : "{$ctx},s,1";
    }

    private function ringGroupGosub(int $id, string $ctx): string
    {
        $g = $this->db->one('SELECT extension FROM ring_groups WHERE id = ?', [$id]);
        return $g ? "{$ctx},{$this->dialToken((string) $g['extension'])},1" : "{$ctx},s,1";
    }

    private function ivrGosub(int $id, string $ctx): string
    {
        $i = $this->db->one('SELECT extension FROM ivr_menus WHERE id = ?', [$id]);
        return $i ? "{$ctx},{$this->dialToken((string) $i['extension'])},1" : "{$ctx},s,1";
    }

    private function conferenceGosub(int $id, string $ctx): string
    {
        $c = $this->db->one('SELECT extension FROM conferences WHERE id = ?', [$id]);
        return $c ? "{$ctx},{$this->dialToken((string) $c['extension'])},1" : "{$ctx},s,1";
    }

    private function queueGosub(int $id, string $ctx): string
    {
        $q = $this->db->one('SELECT extension FROM queues WHERE id = ?', [$id]);
        return $q ? "{$ctx},{$this->dialToken((string) $q['extension'])},1" : "{$ctx},s,1";
    }

    private function parkingGosub(int $id, string $ctx): string
    {
        $p = $this->db->one('SELECT extension FROM parking_lots WHERE id = ?', [$id]);
        return $p ? "{$ctx},{$this->dialToken((string) $p['extension'])},1" : "{$ctx},s,1";
    }

    private function timeCondGosub(int $id, string $ctx): string
    {
        $exists = $this->db->scalar('SELECT COUNT(*) FROM time_conditions WHERE id = ? AND enabled = 1', [$id]);
        return $exists ? "{$ctx}-timecond-{$id},s,1" : 'app-dial,1,Hangup()';
    }

    private function voicemailGosub(int $id, string $ctx): string
    {
        $v = $this->db->one('SELECT e.extension FROM voicemail v JOIN extensions e ON e.id = v.extension_id WHERE v.id = ?', [$id]);
        return $v ? "app-voicemail,1,Voicemail({$this->dialToken((string) $v['extension'])}@{$ctx},u)" : "{$ctx},s,1";
    }

    /** Ampersand-delimited PJSIP dial string for simultaneous ring-group legs. */
    private function memberEndpoints(int $groupId, string $realm): string
    {
        $members = $this->db->all(
            'SELECT e.extension FROM ring_group_members m
             JOIN extensions e ON e.id = m.extension_id
             WHERE m.ring_group_id = ? ORDER BY m.ordering',
            [$groupId]
        );

        $eps = [];
        foreach ($members as $m) {
            $eps[] = 'PJSIP/' . $this->dialToken((string) $m['extension']) . '@' . $realm;
        }

        return implode('&', $eps);
    }

    private function trunkEndpoint(int $trunkId, int $tenantId): string
    {
        $t = $this->db->one('SELECT id FROM trunks WHERE id = ? AND tenant_id = ? AND enabled = 1', [$trunkId, $tenantId]);
        return $t ? 'trunk-' . $trunkId : 'invalid';
    }

    /** Add an optional dial prefix without corrupting Asterisk's leading _. */
    private function outboundPattern(string $prefix, string $match): string
    {
        $prefix = $this->outboundPrefix($prefix);
        $match = $this->dialPattern($match);
        if ($prefix === '') {
            return $match;
        }
        return str_starts_with($match, '_') ? '_' . $prefix . substr($match, 1) : $prefix . $match;
    }

    /** @return list<string> */
    private function outboundRoutePatterns(string $stored): array
    {
        $patterns = preg_split('/[\r\n,]+/', $stored) ?: [];
        $safe = [];
        foreach ($patterns as $pattern) {
            $pattern = $this->dialPattern(trim($pattern));
            if ($pattern !== '') $safe[$pattern] = true;
        }
        return array_keys($safe) ?: ['_X.'];
    }

    private function ringStrategy(string $s): string
    {
        return match ($s) {
            'ringall'    => 'ringall',
            'leastrecent'=> 'leastrecent',
            'random'     => 'random',
            'wrandom'    => 'wrandom',
            'seqfixed'   => 'ringall', // closest Asterisk equivalent
            default      => 'ringall',
        };
    }

    private function queueStrategy(string $s): string
    {
        return match ($s) {
            'ringall'     => 'ringall',
            'leastrecent' => 'leastrecent',
            'fewest'      => 'fewestcalls',
            'random'      => 'random',
            'rrmemory'    => 'rrmemory',
            'linear'      => 'linear',
            default       => 'ringall',
        };
    }

    /** Translate a DID pattern like "+15551234" or "_+1555XXXX" to exten pattern. */
    private function didToPattern(string $pattern): string
    {
        $pattern = $this->dialPattern($pattern);
        if ($pattern === '') {
            return '_X.';
        }
        if ($pattern[0] === '_') {
            return $pattern;
        }
        // Literal number -> exact match with _ prefix.
        return '_' . (preg_replace('/[^0-9X*+.]/', '', $pattern) ?: 'X.');
    }

    /** Build a GotoIfTime() match expression from a time condition row. */
    private function timeMatch(array $c, string $timezone = 'UTC'): string
    {
        $timezone = \is_valid_timezone($timezone) ? $timezone : 'UTC';
        if (!empty($c['time_match'])) {
            $match = preg_replace('/[^A-Za-z0-9:*&,\/_.+\-]/', '', $this->line((string) $c['time_match'])) ?? '';
            return substr_count($match, ',') >= 4 ? $match : $match . ',' . $timezone;
        }
        // Asterisk order: times,weekdays,month-days,months.
        $dows = preg_replace('/[^A-Za-z0-9*&\-]/', '', $this->line((string) ($c['days_of_week'] ?: '*'))) ?: '*';
        $dom  = preg_replace('/[^0-9*&\-]/', '', $this->line((string) ($c['day_of_month'] ?: '*'))) ?: '*';
        $mon  = preg_replace('/[^A-Za-z0-9*&\-]/', '', $this->line((string) ($c['month'] ?: '*'))) ?: '*';
        $hasRange = $c['start_hour'] !== null && $c['start_hour'] !== ''
            && $c['end_hour'] !== null && $c['end_hour'] !== '';
        $times = $hasRange
            ? sprintf('%02d:%02d-%02d:%02d', $c['start_hour'], $c['start_minute'] ?? 0, $c['end_hour'], $c['end_minute'] ?? 0)
            : '*';

        return "{$times},{$dows},{$dom},{$mon},{$timezone}";
    }

    private function tenantTimezone(int $tenantId): string
    {
        $timezone = $this->db->scalar('SELECT timezone FROM tenants WHERE id = ?', [$tenantId]);
        return \normalize_timezone(is_string($timezone) ? $timezone : null, 'UTC');
    }

    /** Prefix endpoints per tenant so two tenants can both use ext 1001. */
    private function endpointName(string $ctx, string $extension): string
    {
        // PJSIP section names cannot contain '@', so flatten ctx -> label.
        return $this->contextName($ctx) . '_' . $this->dialToken($extension);
    }

    /** SIP realm used to distinguish the same username across tenants. */
    private function tenantRealm(string $domain, string $ctx): string
    {
        $realm = strtolower(rtrim(trim($domain), '.'));
        if ($realm === '') {
            $realm = $ctx;
        }
        $realm = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $this->line($realm)) ?: $this->contextName($ctx);
        return substr($realm, 0, 253);
    }

    private function esc(string $s): string
    {
        return str_replace(['"', '\\'], ['', '\\\\'], $this->line($s));
    }

    /** Safe, compact text for an inherited AMI-visible dialplan variable. */
    private function stageLabel(string $value): string
    {
        $value = str_replace([',', '$', '(', ')', '{', '}', ';', '"', '\\'], ' ', $this->line($value));
        return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? ''), 0, 120) ?: 'Call';
    }

    /** Keep every database/config value on one generated config line. */
    private function line(string $value): string
    {
        return trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    }

    private function contextName(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_-]/', '_', $this->line($value)) ?: 'invalid', 0, 80);
    }

    private function dialToken(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9*#+_.@-]/', '', $this->line($value)) ?: 'invalid', 0, 120);
    }

    private function dialPattern(string $value): string
    {
        $pattern = preg_replace('/[^A-Za-z0-9_*#+.!\[\]+-]/', '', $this->line($value)) ?? '';
        return substr(strtr($pattern, ['x' => 'X', 'n' => 'N', 'z' => 'Z']), 0, 120);
    }

    private function outboundPrefix(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9*#+]/', '', $this->line($value)) ?? '', 0, 40);
    }

    private function callerNumber(string $value): string
    {
        return substr(preg_replace('/[^A-Za-z0-9+*#_.@-]/', '', $this->line($value)) ?: 'anonymous', 0, 120);
    }

    private function sipValue(string $value): string
    {
        return substr(preg_replace('/\s+/', '', $this->line($value)) ?? '', 0, 255);
    }

    private function soundPath(string $value): string
    {
        return substr(preg_replace('#[^A-Za-z0-9_./-]#', '', $this->line($value)) ?: 'none', 0, 255);
    }
}
