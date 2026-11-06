<?php
/** Login screen. Expects: (layout supplies $flashes) */
require dirname(__DIR__) . '/partials/fields.php';
?>
<form method="post" action="/login" autocomplete="on">
    <?= csrf() ?>

    <div class="field mb-3">
        <label for="username">Username or email</label>
        <input type="text" id="username" name="username"
               value="<?= e(old('username')) ?>"
               autocomplete="username" autofocus required
               placeholder="e.g. admin">
    </div>

    <div class="field mb-4">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required placeholder="••••••••">
    </div>

    <button type="submit" class="btn btn-primary btn-block">Sign in</button>

    <a href="/web-phone" class="btn btn-block web-phone-login-link">
        <?= $this->fragment('icons/phone') ?> Open Web Phone
    </a>

    <p class="text-xs muted text-center mt-3 mb-0">
        Forgot your password? Contact your system administrator.
    </p>
</form>
