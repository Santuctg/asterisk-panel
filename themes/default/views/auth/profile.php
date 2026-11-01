<?php
/**
 * User profile. Expects: $user
 */
require dirname(__DIR__) . '/partials/fields.php';
?>

<div class="page-head">
    <div>
        <h1>My Profile</h1>
        <div class="subtitle">Your account details and password</div>
    </div>
</div>

<div class="grid grid-2">
    <form method="post" action="/profile" class="card">
        <?= csrf() ?>
        <div class="card-head"><h2>Account</h2></div>
        <div class="card-body">
            <div class="field mb-3">
                <label>Username</label>
                <input type="text" value="<?= e($user['username'] ?? '') ?>" readonly>
            </div>
            <div class="field mb-3">
                <label>Role</label>
                <input type="text" value="<?= e(ucfirst((string) ($user['role'] ?? ''))) ?>" readonly>
            </div>
            <div class="field mb-3">
                <label>Tenant</label>
                <input type="text" value="<?= e($user['tenant_id'] ? (string) app()->db()->scalar('SELECT name FROM tenants WHERE id = ?', [$user['tenant_id']]) : 'None (global)') ?>" readonly>
            </div>
            <?php echo field_text('email', 'Email address', $user['email'] ?? '', ['type' => 'email']); ?>
        </div>
        <div class="card-foot">
            <span></span>
            <button type="submit" class="btn btn-primary"><?= $this->fragment('icons/check') ?> Save</button>
        </div>
    </form>

    <form method="post" action="/profile/password" class="card">
        <?= csrf() ?>
        <div class="card-head"><h2>Change password</h2></div>
        <div class="card-body">
            <div class="field mb-3">
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password"
                       autocomplete="current-password" required>
            </div>
            <div class="field mb-3">
                <label for="password">New password</label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required minlength="8">
                <span class="hint">At least 8 characters.</span>
            </div>
            <div class="field mb-3">
                <label for="password_confirmation">Confirm new password</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" required minlength="8">
            </div>
        </div>
        <div class="card-foot">
            <span></span>
            <button type="submit" class="btn btn-primary"><?= $this->fragment('icons/check') ?> Update password</button>
        </div>
    </form>
</div>
