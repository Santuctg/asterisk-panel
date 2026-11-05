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

        $extensions = $this->db->all(
            'SELECT e.*, t.context AS ctx, t.domain AS tenant_domain FROM extensions e
             JOIN tenants t ON t.id = e.tenant_id
             WHERE e.enabled = 1 ORDER BY t.id, e.extension'
        );

        foreach ($extensions as $ext) {
            $realm = $this->tenantRealm((string) ($ext['tenant_domain'] ?? ''), (string) $ext['ctx']);
            $name = $ext['extension'] . '@' . $realm;
            $webBase = $this->endpointName($ext['ctx'], $ext['extension']);
            $ctx  = $ext['ctx'];

            $out .= "; ---- {$ext['extension']} ({$ext['name']}) context={$ctx} ----\n";
            $out .= "[{$name}](endpoint_basic)\n";
            $out .= "context = {$ctx}\n";
            $out .= "auth = {$name}\n";
            $out .= "aors = {$name}\n";
            $out .= "identify_by = username,auth_username\n";
            if (!empty($ext['outbound_route_id'])) {
                $out .= "set_var = PANEL_OUTBOUND_ROUTE_ID={$ext['outbound_route_id']}\n";
            }
            // Always emit a callerid so the display name comes from the
            // caller's configured name, never the called extension's number.
            $cidName = $this->esc((string) ($ext['cid_name'] ?: $ext['name'] ?: $ext['extension']));
            $cidNum  = ($ext['cid_number'] ?? '') !== '' ? $ext['cid_number'] : $ext['extension'];
            $out .= "callerid = \"{$cidName}\" <{$cidNum}>\n";
            $out .= "\n";

            $out .= "[{$name}]\n";
            $out .= "type = auth\n";
            $out .= "auth_type = userpass\n";
            // FusionPBX-style multi-domain identity: the user remains "201"
            // while the endpoint/AOR is qualified as "201@tenant-domain".
            $out .= "username = {$ext['extension']}\n";
            $out .= "password = {$this->esc($ext['secret'])}\n";
            $out .= "realm = {$realm}\n\n";

            $out .= "[{$name}]\n";
            $out .= "type = aor\n";
            $out .= "max_contacts = {$ext['max_registrations']}\n";
            $out .= "remove_existing = yes\n";
            // OPTIONS keepalive gives each phone a live latency (RTT) reading.
            $out .= "qualify_frequency = 60\n";
            $out .= "qualify_timeout = 3\n\n";

            // Separate WebRTC identity. Never use the ordinary phone's endpoint
            // for browser registration (different transport and media settings).
            $web = $webBase . '-web';
            $out .= "[{$web}]\n";
            $out .= "type = endpoint\n";
            $out .= "transport = panel-transport-ws\n";
            $out .= "context = panel-listen-only\n";
            $out .= "auth = {$web}\n";
            $out .= "aors = {$web}\n";
            $out .= "webrtc = yes\n";
            $out .= "dtls_auto_generate_cert = yes\n";
            $out .= "direct_media = no\n";
            $out .= "allow = !all,opus,ulaw,alaw\n";
            $out .= "identify_by = username,auth_username\n\n";
            $out .= "[{$web}]\n";
            $out .= "type = auth\n";
            $out .= "auth_type = userpass\n";
            $out .= "username = {$web}\n";
            $out .= "password = {$this->esc($ext['secret'])}\n\n";
            $out .= "[{$web}]\n";
            $out .= "type = aor\n";
            $out .= "max_contacts = 1\n";
            $out .= "remove_existing = yes\n\n";

            // A separate identity is used for normal browser calling. Keeping
            // it distinct preserves the listen-only endpoint's restrictions.
            $phone = $webBase . '-phone-web';
            $out .= "[{$phone}]\n";
            $out .= "type = endpoint\ntransport = panel-transport-ws\n";
            $out .= "context = {$ctx}\nauth = {$phone}\naors = {$phone}\n";
            if (!empty($ext['outbound_route_id'])) {
                $out .= "set_var = PANEL_OUTBOUND_ROUTE_ID={$ext['outbound_route_id']}\n";
            }
            $out .= "webrtc = yes\ndtls_auto_generate_cert = yes\ndirect_media = no\n";
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

        foreach ($trunks as $t) {
            $out .= "; ---- {$t['name']} ({$t['host']}) ----\n";

            // Registration (outbound auth) block.
            $out .= "[trunk-{$t['id']}](registration)\n";
            $out .= "outbound_auth = trunk-{$t['id']}-auth\n";
            $out .= "client_uri = sip:{$t['username']}@{$t['host']}\n";
            $out .= "server_uri = sip:{$t['host']}\n";
            if (!empty($t['register'])) {
                $out .= "retry_interval = {$t['register']}\n";
            }
            $out .= "\n";

            $out .= "[trunk-{$t['id']}-auth]\n";
            $out .= "type = auth\n";
            $out .= "auth_type = userpass\n";
            $out .= "username = {$t['username']}\n";
            $out .= "password = {$this->esc($t['secret'])}\n\n";

            // AoR pointing at the provider.
            $out .= "[trunk-{$t['id']}-aor]\n";
            $out .= "type = aor\n";
            $out .= "contact = sip:{$t['host']}\n";
            // Qualify the provider so RTT/latency and reachability are visible.
            $out .= "qualify_frequency = 60\n";
            $out .= "qualify_timeout = 3\n\n";

            // The endpoint itself.
            $out .= "[trunk-{$t['id']}](trunk-basic)\n";
            $fromDomain = $t['from_domain'] ?: $t['host'];
            $out .= "from_domain = {$fromDomain}\n";
            $out .= "context = {$t['ctx']}-inbound\n";
            $out .= "aors = trunk-{$t['id']}-aor\n";
            $out .= "outbound_auth = trunk-{$t['id']}-auth\n";
            if (!empty($t['cid'])) {
                $out .= "callerid = {$t['cid']}\n";
            }
            $out .= "\n";

            // Match provider-originated INVITEs by signalling IP/host. An
            // outbound registration alone does not identify inbound traffic.
            $out .= "[trunk-{$t['id']}-identify]\n";
            $out .= "type = identify\n";
            $out .= "endpoint = trunk-{$t['id']}\n";
            $out .= "match = {$t['host']}\n\n";
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
        $out .= "serveremail = " . (Config::get('asterisk.voicemail.serveremail', 'asterisk@localhost')) . "\n";
        $out .= "mailcmd = " . (Config::get('asterisk.voicemail.mailcmd', '/usr/bin/php ' . BASE_PATH . '/scripts/asterisk-mail.php')) . "\n";
        $out .= "attach = " . (Config::get('asterisk.voicemail.attach', 'yes')) . "\n";
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
            $boxes = $this->db->all(
                'SELECT v.*, e.extension AS ext FROM voicemail v
                 JOIN extensions e ON e.id = v.extension_id
                 WHERE v.tenant_id = ? AND v.enabled = 1 ORDER BY ext',
                [$t['id']]
            );

            if (!$boxes) {
                continue;
            }

            $out .= "; ---- Tenant: {$t['name']} ({$t['context']}) ----\n";
            $out .= "[{$t['context']}]\n";

            foreach ($boxes as $b) {
                // mailbox => password,name,email,attachment,pageremail,pager,options,timezone
                $opts = [];
                $opts[] = 'attach=' . ($b['attach'] ? 'yes' : 'no');
                $opts[] = 'saycid=' . ($b['say_cid'] ? 'yes' : 'no');
                $opts[] = 'envelope=' . ($b['envelope'] ? 'yes' : 'no');
                $opts[] = 'skipinstructions=' . ($b['skip_instructions'] ? 'yes' : 'no');
                $opts[] = 'vm-mailto=' . $b['email'];
                if ($b['vm_password'] !== '') {
                    $opts[] = 'vm-secret=' . $b['vm_password'];
                }
                $zone = \normalize_timezone((string) ($b['timezone'] ?? ''), 'UTC');
                $opts[] = 'tz=' . ($zones[$zone] ?? ('iana_' . substr(hash('sha256', $zone), 0, 16)));

                $out .= sprintf(
                    "%s => %s,%s,%s,%s,,,%s\n",
                    $b['ext'],
                    $b['pin'],
                    $this->esc($b['name']),
                    $this->esc($b['email']),
                    $b['pager_email'] ?: '""',
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

        $tenants = $this->db->all('SELECT * FROM tenants ORDER BY id');

        foreach ($tenants as $t) {
            $ctx  = $t['context'];
            $tid  = $t['id'];
            $recordingMode = $this->tenantSetting((int) $tid, 'recording_mode', 'off');
            $recordInbound = in_array($recordingMode, ['all', 'inbound'], true);
            $recordOutbound = in_array($recordingMode, ['all', 'outbound'], true);
            $recordInternal = $recordingMode === 'all';
            $out .= "; ============================================================\n";
            $out .= "; TENANT: {$t['name']}  (context {$ctx})\n";
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
            $out .= "; {$r['name']}\n";
            $out .= "exten => {$pattern},1,NoOp(Inbound: {$r['name']})\n";
            if ($this->shouldRecord((int) ($r['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('inbound', $ctx);
            }
            $out .= $this->announcementGosub((int) ($r['announcement_id'] ?? 0), $tid);
            $out .= " same => n,Gosub({$this->destinationGosub($r['dest_type'], $r['dest_id'], $ctx)} )\n";
            $out .= " same => n,Hangup()\n\n";
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
        $out .= "exten => _X.,1,NoOp(Outbound access check)\n";
        foreach ($routes as $r) {
            $out .= " same => n,GotoIf(\$[\"\${PANEL_OUTBOUND_ROUTE_ID}\"=\"{$r['id']}\"]?route-{$r['id']})\n";
        }
        $out .= " same => n,NoOp(Outbound calling denied: no enabled route assigned)\n";
        $out .= " same => n,Hangup(21)\n";
        foreach ($routes as $r) {
            $out .= " same => n(route-{$r['id']}),Goto({$ctx}-outbound-{$r['id']},\${EXTEN},1)\n";
        }
        $out .= "\n";

        foreach ($routes as $r) {
            $prefix  = $r['prefix'] ?? '';
            $pattern = $this->outboundPattern((string) $prefix, (string) $r['match_pattern']);
            $trunk   = $this->trunkEndpoint((int) $r['trunk_id']);
            $dialed  = $prefix !== '' ? '${EXTEN:' . strlen((string) $prefix) . '}' : '${EXTEN}';
            $condition = null;
            if (!empty($r['time_condition_id'])) {
                $condition = $this->db->one(
                    'SELECT * FROM time_conditions WHERE id = ? AND tenant_id = ? AND enabled = 1',
                    [(int) $r['time_condition_id'], $tid]
                );
            }

            $out .= "[{$ctx}-outbound-{$r['id']}]\n";
            $out .= "; {$r['name']}\n";
            $out .= "exten => {$pattern},1,NoOp(Outbound: {$r['name']})\n";
            if (!empty($r['time_condition_id'])) {
                if ($condition) {
                    $out .= " same => n,GotoIfTime(" . $this->timeMatch($condition) . "?time-allowed)\n";
                    $out .= " same => n,NoOp(Outbound blocked by time condition: {$this->esc($condition['name'])})\n";
                    $out .= " same => n,Hangup(21)\n";
                    $out .= " same => n(time-allowed),NoOp(Outbound time condition matched)\n";
                } else {
                    $out .= " same => n,NoOp(Outbound blocked: assigned time condition is unavailable)\n";
                    $out .= " same => n,Hangup(21)\n";
                }
            }
            if ($this->shouldRecord((int) ($r['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('outbound', $ctx);
            }
            $out .= " same => n,Set(CALLERID(num)=" . ($r['cid_override'] ?: '${CALLERID(num)}') . ")\n";
            $out .= " same => n,Dial(PJSIP/{$dialed}@{$trunk},30,Tt)\n";
            $out .= " same => n,Hangup()\n\n";
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
            $ep    = $e['extension'] . '@' . $realm;
            $label = $this->esc($e['name']);

            $out .= "; {$e['extension']} {$label}\n";
            $out .= "exten => {$e['extension']},1,NoOp(Ext {$e['extension']} {$label})\n";
            if ($this->shouldRecord((int) ($e['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Dial(PJSIP/{$ep},{$e['ring_timeout']},Tt" . ($e['follow_me'] ? 'm' : '') . ")\n";

            if (!empty($e['vm_on_busy']) || !empty($e['vm_on_noanswer'])) {
                $out .= " same => n(voicemail),Voicemail({$e['extension']}@{$ctx}," .
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
            $members = $this->memberEndpoints((int) $g['id'], $realm);
            $strategy = $this->ringStrategy($g['strategy']);

            $out .= "; Ring group {$g['extension']} {$this->esc($g['name'])}\n";
            $out .= "exten => {$g['extension']},1,NoOp(Ring group: {$this->esc($g['name'])})\n";
            if ($this->shouldRecord((int) ($g['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= $this->announcementGosub((int) ($g['announcement_id'] ?? 0), $tid);

            if ($members === '') {
                $out .= " same => n,Playback(pbx-invalid)\n";
                $out .= " same => n,Hangup()\n\n";
                continue;
            }

            $out .= " same => n,Set(__RINGSTRATEGY={$strategy})\n";
            $dialOptions = 'TtH';
            $out .= " same => n,Dial({$members},{$g['timeout']},{$dialOptions})\n";
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
            $out .= "; Queue {$q['extension']} {$this->esc($q['name'])}\n";
            $out .= "exten => {$q['extension']},1,NoOp(Queue: {$this->esc($q['name'])})\n";
            if ($this->shouldRecord((int) ($q['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= $this->announcementGosub((int) ($q['announcement_id'] ?? 0), $tid);
            $out .= " same => n,Queue({$q['extension']},tT,,,{$q['max_wait']})\n";
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
            $out .= "; IVR {$ivr['extension']} {$this->esc($ivr['name'])}\n";
            $out .= "exten => {$ivr['extension']},1,NoOp(IVR: {$this->esc($ivr['name'])})\n";
            if ($this->shouldRecord((int) ($ivr['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Answer()\n";
            $out .= " same => n,Set(TIMEOUT(digit)={$ivr['timeout']})\n";
            $out .= " same => n,Set(TIMEOUT(response)={$ivr['response_timeout']})\n";

            if (!empty($ivr['greeting'])) {
                $out .= " same => n,Background({$ivr['greeting']})\n";
            } else {
                $out .= " same => n,Background(silence/1)\n";
            }

            $out .= " same => n,Goto({$ctx}-ivr-{$ivr['extension']},s,1)\n\n";
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
            $out .= "[{$ctx}-ivr-{$ivr['extension']}]\n";
            $out .= "exten => s,1,Set(__IVR_MENU={$ivr['extension']})\n";
            $out .= " same => n,Background(silence/1)\n";
            $out .= " same => n,Hangup()\n\n";

            $keys = json_decode((string) $ivr['keys'], true) ?: [];
            foreach ($keys as $key => $dest) {
                $out .= "exten => {$key},1,Gosub({$this->destinationGosub($dest['type'], $dest['id'], $ctx)})\n";
                $out .= " same => n,Hangup()\n";
            }

            // Timeout / invalid fallback.
            if (!empty($ivr['timeout_action'])) {
                $out .= "exten => t,1,Gosub({$this->destinationGosub($ivr['timeout_action'], $ivr['timeout_dest_id'], $ctx)})\n";
            } else {
                $out .= "exten => t,1,Goto({$ctx}-ivr-{$ivr['extension']},s,1)\n";
            }
            $out .= "exten => i,1,Playback(pbx-invalid)\n";
            $out .= " same => n,Goto({$ctx}-ivr-{$ivr['extension']},s,1)\n\n";
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
            $out .= "; Time condition {$this->esc($c['name'])}\n";
            $out .= "exten => {$c['extension']},1,NoOp(Time condition: {$this->esc($c['name'])})\n";
            if ($this->shouldRecord((int) ($c['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,GotoIfTime(" . $this->timeMatch($c) . "?{$ctx}-{$c['extension']}-open,1)\n";
            $out .= " same => n,Goto({$ctx}-{$c['extension']}-closed,1)\n\n";
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
            if (empty($c['extension']) || empty($c['open_type']) || empty($c['closed_type'])) {
                continue;
            }
            $out .= "[{$ctx}-{$c['extension']}-open]\n";
            $out .= "exten => 1,1,Gosub({$this->destinationGosub($c['open_type'], $c['open_dest_id'], $ctx)})\n";
            $out .= " same => n,Hangup()\n\n";

            $out .= "[{$ctx}-{$c['extension']}-closed]\n";
            $out .= "exten => 1,1,Gosub({$this->destinationGosub($c['closed_type'], $c['closed_dest_id'], $ctx)})\n";
            $out .= " same => n,Hangup()\n\n";
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
            $profile = $r['profile'] ?: 'default';
            $out .= "; Conference {$r['extension']} {$this->esc($r['name'])}\n";
            $out .= "exten => {$r['extension']},1,NoOp(Conference: {$this->esc($r['name'])})\n";
            if ($this->shouldRecord((int) ($r['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Answer()\n";
            $out .= " same => n,ConfBridge({$r['pin']}@{$ctx},{$ctx}-{$profile},{$ctx}-users)\n";
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
            $out .= "; Park lot {$this->esc($l['name'])} (ext {$l['extension']})\n";
            $out .= "exten => {$l['extension']},1,NoOp(Park lot: {$this->esc($l['name'])})\n";
            if ($this->shouldRecord((int) ($l['record'] ?? 0), $record)) {
                $out .= $this->recordingGosub('internal', $ctx);
            }
            $out .= " same => n,Park({$l['parked_range']},{$ctx}-{$l['id']},{$ctx}-{$l['id']},{$l['return_extension']})\n";
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
                $ctx = $t['context'];
                $realm = $this->tenantRealm((string) ($t['domain'] ?? ''), (string) $ctx);
                $out .= "; {$q['name']} ({$q['extension']})\n";
                $out .= "[{$q['extension']}]\n";
                $out .= "context = {$ctx}\n";
                $out .= "musicclass = " . $this->mohClassName($tid, (string) ($q['moh_class'] ?: 'default')) . "\n";
                $out .= "timeout = {$q['timeout']}\n";
                $out .= "maxlen = {$q['max_wait']}\n";
                $out .= "servicelevel = {$q['service_level']}\n";
                $out .= "strategy = " . $this->queueStrategy($q['strategy']) . "\n";
                $out .= "wrapuptime = {$q['wrapup']}\n";
                $out .= "retry = {$q['retry']}\n";
                $out .= "agentannounce = " . ($q['agent_announce'] ?: 'none') . "\n";
                $out .= "memberdelay = {$q['member_delay']}\n";
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
                    $out .= "member => PJSIP/{$ag['extension']}@{$realm},{$ag['penalty']},{$ag['name']}" .
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
            $ctx = $this->db->scalar('SELECT context FROM tenants WHERE id = ?', [$l['tenant_id']]);
            $out .= "; {$l['name']}\n";
            $out .= "[parkinglot-{$l['id']}]\n";
            $out .= "context = {$ctx}-{$l['id']}\n";
            $out .= "parkext = {$l['extension']}\n";
            $out .= "parkextsounds = " . ($l['play_sounds'] ? 'yes' : 'no') . "\n";
            $out .= "parktime = {$l['timeout']}\n";
            $out .= "parkedcalltransfers = " . ($l['allow_transfer'] ? 'yes' : 'no') . "\n";
            $out .= "parkedcallreparking = " . ($l['allow_repark'] ? 'yes' : 'no') . "\n";
            $out .= "parkedcallhangup = " . ($l['allow_hangup'] ? 'yes' : 'no') . "\n";
            $out .= "parkedcallrecording = " . ($l['record'] ? 'yes' : 'no') . "\n";
            $out .= "parkingtime = {$l['timeout']}\n";
            $out .= "courtesytone = " . ($l['courtesy_tone'] ?: 'beep') . "\n\n";
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
            if ($dir === '') {
                $dir = rtrim((string) Config::get('asterisk.moh_path', '/var/lib/asterisk/moh'), '/')
                    . '/' . $this->mohPathPart((string) $c['tenant_context']) . '/' . $this->mohPathPart((string) $c['name']);
            }
            $className = $this->mohClassName((int) $c['tenant_id'], (string) $c['name']);
            $out .= "; {$c['tenant_context']} / {$c['name']}\n";
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
            $ctx = $ctxRow['context'];
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
                $out .= "; {$r['name']}\n";
                $out .= "[{$r['pin']}]\n";
                $out .= "type = bridge\n";
                $out .= "max_members = " . ($r['max_members'] ?: '0') . "\n";
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
        $out .= " same => n,MixMonitor({$dir}/\${PANEL_RECORDING_FILE},b)\n";
        $out .= " same => n(done),Return()\n\n";
        return $out;
    }

    private function recordingGosub(string $direction, string $ctx): string
    {
        return " same => n,Gosub(panel-auto-record,s,1({$direction},{$ctx}))\n";
    }

    private function announcementHelperContext(): string
    {
        $out  = "[panel-announcement]\n";
        $out .= "exten => s,1,NoOp(Non-skippable caller announcement)\n";
        $out .= " same => n,GotoIf(\$[\"\${PANEL_ANNOUNCEMENT_PLAYED}\"=\"1\"]?done)\n";
        $out .= " same => n,Set(__PANEL_ANNOUNCEMENT_PLAYED=1)\n";
        $out .= " same => n,Answer()\n";
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
             WHERE key = ? AND (tenant_id = ? OR tenant_id IS NULL)
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
    private function destinationGosub(string $type, int|string $id, string $ctx): string
    {
        return match ($type) {
            'extension'  => $this->extenGosub((int) $id, $ctx),
            'ringgroup'  => $this->ringGroupGosub((int) $id, $ctx),
            'ivr'        => $this->ivrGosub((int) $id, $ctx),
            'conference' => $this->conferenceGosub((int) $id, $ctx),
            'queue'      => $this->queueGosub((int) $id, $ctx),
            'parking'    => $this->parkingGosub((int) $id, $ctx),
            'timecond'   => $this->timeCondGosub((int) $id, $ctx),
            'external'   => 'app-dial,1,Dial(SIP/' . $this->esc((string) $id) . ')',
            'hangup'     => 'app-dial,1,Hangup()',
            'voicemail'  => $this->voicemailGosub((int) $id, $ctx),
            default      => 'app-dial,1,Hangup()',
        };
    }

    private function extenGosub(int $id, string $ctx): string
    {
        $e = $this->db->one('SELECT extension FROM extensions WHERE id = ?', [$id]);
        return $e ? "{$ctx},{$e['extension']},1" : "{$ctx},s,1";
    }

    private function ringGroupGosub(int $id, string $ctx): string
    {
        $g = $this->db->one('SELECT extension FROM ring_groups WHERE id = ?', [$id]);
        return $g ? "{$ctx},{$g['extension']},1" : "{$ctx},s,1";
    }

    private function ivrGosub(int $id, string $ctx): string
    {
        $i = $this->db->one('SELECT extension FROM ivr_menus WHERE id = ?', [$id]);
        return $i ? "{$ctx},{$i['extension']},1" : "{$ctx},s,1";
    }

    private function conferenceGosub(int $id, string $ctx): string
    {
        $c = $this->db->one('SELECT extension FROM conferences WHERE id = ?', [$id]);
        return $c ? "{$ctx},{$c['extension']},1" : "{$ctx},s,1";
    }

    private function queueGosub(int $id, string $ctx): string
    {
        $q = $this->db->one('SELECT extension FROM queues WHERE id = ?', [$id]);
        return $q ? "{$ctx},{$q['extension']},1" : "{$ctx},s,1";
    }

    private function parkingGosub(int $id, string $ctx): string
    {
        $p = $this->db->one('SELECT extension FROM parking_lots WHERE id = ?', [$id]);
        return $p ? "{$ctx},{$p['extension']},1" : "{$ctx},s,1";
    }

    private function timeCondGosub(int $id, string $ctx): string
    {
        $c = $this->db->one('SELECT extension FROM time_conditions WHERE id = ?', [$id]);
        return $c ? "{$ctx},{$c['extension']},1" : "{$ctx},s,1";
    }

    private function voicemailGosub(int $id, string $ctx): string
    {
        $v = $this->db->one('SELECT e.extension FROM voicemail v JOIN extensions e ON e.id = v.extension_id WHERE v.id = ?', [$id]);
        return $v ? "app-voicemail,1,Voicemail({$v['extension']}@{$ctx},u)" : "{$ctx},s,1";
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
            $eps[] = 'PJSIP/' . $m['extension'] . '@' . $realm;
        }

        return implode('&', $eps);
    }

    private function trunkEndpoint(int $trunkId): string
    {
        $t = $this->db->one('SELECT id FROM trunks WHERE id = ?', [$trunkId]);
        return $t ? 'trunk-' . $trunkId : 'invalid';
    }

    /** Add an optional dial prefix without corrupting Asterisk's leading _. */
    private function outboundPattern(string $prefix, string $match): string
    {
        if ($prefix === '') {
            return $match;
        }
        return str_starts_with($match, '_') ? '_' . $prefix . substr($match, 1) : $prefix . $match;
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
        $pattern = trim($pattern);
        if ($pattern === '') {
            return '_X.';
        }
        if ($pattern[0] === '_') {
            return $pattern;
        }
        // Literal number -> exact match with _ prefix.
        return '_' . preg_replace('/[^0-9X*+.]/', '', $pattern);
    }

    /** Build a GotoIfTime() match expression from a time condition row. */
    private function timeMatch(array $c): string
    {
        if (!empty($c['time_match'])) {
            return $c['time_match'];
        }
        // Asterisk order: times,weekdays,month-days,months.
        $dows = $c['days_of_week'] ?: '*';
        $dom  = $c['day_of_month'] ?: '*';
        $mon  = $c['month'] ?: '*';
        $hasRange = $c['start_hour'] !== null && $c['start_hour'] !== ''
            && $c['end_hour'] !== null && $c['end_hour'] !== '';
        $times = $hasRange
            ? sprintf('%02d:%02d-%02d:%02d', $c['start_hour'], $c['start_minute'] ?? 0, $c['end_hour'], $c['end_minute'] ?? 0)
            : '*';

        return "{$times},{$dows},{$dom},{$mon}";
    }

    /** Prefix endpoints per tenant so two tenants can both use ext 1001. */
    private function endpointName(string $ctx, string $extension): string
    {
        // PJSIP section names cannot contain '@', so flatten ctx -> label.
        $safeCtx = preg_replace('/[^a-zA-Z0-9_]/', '_', $ctx) ?? $ctx;
        return $safeCtx . '_' . $extension;
    }

    /** SIP realm used to distinguish the same username across tenants. */
    private function tenantRealm(string $domain, string $ctx): string
    {
        $realm = strtolower(rtrim(trim($domain), '.'));
        if ($realm === '') {
            $realm = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ctx) ?? $ctx;
        }
        return $realm;
    }

    private function esc(string $s): string
    {
        return str_replace(['"', "\n", "\r", '\\'], ['', '', '', '\\\\'], $s);
    }
}
