<?php
/** @var array $ssl */
require dirname(__DIR__) . '/partials/fields.php';

$registered = !empty($ssl['domain']) && !empty($ssl['fulldomain']);
$installedCertificate = !empty($ssl['certificate_present']);
$verified = !empty($ssl['cname_verified']);
$active = ($ssl['status'] ?? '') === 'active';
$status = $ssl['status'] ?? 'not_configured';
$statusLabel = match ($status) {
    'registered' => 'Waiting for DNS',
    'dns_ready'  => 'DNS ready',
    'active'     => 'HTTPS active',
    'error'      => 'Action required',
    default      => 'Not configured',
};
$statusClass = match ($status) {
    'active', 'dns_ready' => 'green',
    'registered'          => 'amber',
    'error'               => 'red',
    default               => 'gray',
};
?>

<div class="page-head">
    <div>
        <h1>SSL / HTTPS</h1>
        <div class="subtitle">Let's Encrypt certificate through hosted auth.acme-dns.io</div>
    </div>
    <div class="page-actions">
        <a href="/system/status" class="btn"><?= $this->fragment('icons/pulse') ?> Status</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-head">
        <h2>Certificate status</h2>
        <span class="badge <?= e($statusClass) ?>"><span class="dot"></span><?= e($statusLabel) ?></span>
    </div>
    <div class="card-body">
        <div class="form-grid">
            <div class="field">
                <label>Domain</label>
                <div class="mono"><?= e($ssl['domain'] ?? '—') ?></div>
            </div>
            <div class="field">
                <label>Provider</label>
                <div>Hosted ACME-DNS</div>
                <span class="hint">auth.acme-dns.io · DNS-01 validation</span>
            </div>
            <div class="field">
                <label>Privileged helper</label>
                <div><?= !empty($ssl['helper_ready']) ? 'Installed' : 'Not installed' ?></div>
                <?php if (empty($ssl['helper_ready'])): ?>
                    <span class="hint">Run the one-time server command shown below before issuing.</span>
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Last certificate issue</label>
                <div><?= e($ssl['issued_at'] ?? '—') ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (!$registered && $installedCertificate): ?>
    <div class="card">
        <div class="card-head"><h2>HTTPS is already active</h2><span class="badge green"><span class="dot"></span>Installed</span></div>
        <div class="card-body">
            <p class="muted mt-0">A valid certificate for <strong><?= e($ssl['certificate_domain'] ?? $ssl['domain']) ?></strong> is already installed and served by Nginx.</p>
            <?php if (!empty($ssl['certificate_expiry'])): ?><p class="hint mb-0">Certificate expiry: <?= e($ssl['certificate_expiry']) ?></p><?php endif; ?>
            <p class="hint mb-0">Hosted ACME-DNS registration is not required for the currently active certificate.</p>
        </div>
    </div>
<?php elseif (!$registered): ?>
    <form method="post" action="/system/ssl/register" class="card">
        <?= csrf() ?>
        <div class="card-head"><h2>1. Register the domain</h2></div>
        <div class="card-body">
            <p class="muted mt-0">The panel creates restricted credentials that can update only one ACME challenge record. Your normal DNS account credentials are never requested.</p>
            <div class="form-grid">
                <?= field_text('domain', 'Public panel hostname', '', [
                    'required' => true,
                    'placeholder' => 'pbx.example.com',
                    'hint' => 'Enter a hostname only, without https:// or a path.',
                ]) ?>
                <?= field_text('email', 'Let\'s Encrypt email', '', [
                    'required' => true,
                    'type' => 'email',
                    'placeholder' => 'admin@example.com',
                    'hint' => 'Used for expiry and account notices.',
                ]) ?>
            </div>
        </div>
        <div class="card-foot">
            <button type="submit" class="btn btn-primary">Register with Hosted ACME-DNS</button>
        </div>
    </form>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-head"><h2>1. Add this DNS record</h2></div>
        <div class="card-body">
            <p class="muted mt-0">Add this record at the DNS provider that hosts <strong><?= e($ssl['domain']) ?></strong>. It is permanent and will be reused for automatic renewal.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Type</th><th>Name / Host</th><th>Target / Value</th></tr></thead>
                    <tbody><tr>
                        <td><span class="badge blue">CNAME</span></td>
                        <td><code class="mono"><?= e($ssl['cname_name']) ?></code></td>
                        <td><code class="mono"><?= e($ssl['fulldomain']) ?></code></td>
                    </tr></tbody>
                </table>
            </div>
            <p class="hint mb-0">If your DNS provider automatically appends the zone name, enter only the host portion shown before your domain.</p>
        </div>
        <div class="card-foot">
            <form method="post" action="/system/ssl/verify">
                <?= csrf() ?>
                <button type="submit" class="btn <?= $verified ? '' : 'btn-primary' ?>">
                    <?= $verified ? 'Check CNAME again' : 'Verify CNAME' ?>
                </button>
            </form>
            <?php if ($verified): ?><span class="badge green"><span class="dot"></span>Public DNS verified</span><?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-head"><h2>2. Install the secure helper once</h2></div>
        <div class="card-body">
            <p class="muted mt-0">Run this once over SSH. It installs Certbot, the DNS hook and a narrowly scoped sudo rule; the web server does not receive unrestricted root access.</p>
            <pre class="code-block"><code>sudo bash scripts/install-web-ssl-helper.sh</code></pre>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>3. Issue and activate HTTPS</h2></div>
        <div class="card-body">
            <p class="muted mt-0">Certbot will complete a DNS-01 challenge, install the Nginx HTTPS site, and save automatic renewal hooks.</p>
            <?php if (!empty($ssl['last_output'])): ?>
                <details class="mt-3" <?= $status === 'error' ? 'open' : '' ?>>
                    <summary>Last helper output</summary>
                    <pre class="code-block"><code><?= e($ssl['last_output']) ?></code></pre>
                </details>
            <?php endif; ?>
        </div>
        <div class="card-foot">
            <form method="post" action="/system/ssl/issue">
                <?= csrf() ?>
                <button type="submit" class="btn btn-primary" <?= (!$verified || empty($ssl['helper_ready'])) ? 'disabled' : '' ?>>
                    <?= $active ? 'Renew / reinstall certificate' : 'Issue certificate & enable HTTPS' ?>
                </button>
            </form>
            <form method="post" action="/system/ssl/reset">
                <?= csrf() ?>
                <button type="submit" class="btn btn-ghost" data-confirm-btn="Clear this panel's ACME-DNS registration? Existing certificates will remain installed.">Reset registration</button>
            </form>
        </div>
    </div>
<?php endif; ?>
