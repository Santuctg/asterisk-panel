<?php
/**
 * Minimal shell for the login screen. Expects: $content, $flashes
 */
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e(config('app.name', 'Asterisk Panel')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📞</text></svg>">
</head>
<body class="auth-body">
<div class="auth-wrap">
    <div class="auth-brand">
        <div class="brand-mark lg">P</div>
        <h1><?= e(config('app.name', 'Asterisk Panel')) ?></h1>
        <p>Sign in to manage your phone system.</p>
    </div>

    <?php if (!empty($flashes)): ?>
        <div class="flash-stack">
            <?php foreach ($flashes as $f): ?>
                <div class="flash flash-<?= e($f['type']) ?>" data-flash>
                    <span class="flash-msg"><?= e($f['message']) ?></span>
                    <button class="flash-close" aria-label="Dismiss">×</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="auth-card">
        <?= $content ?>
    </div>

    <div class="auth-footer">
        &copy; <?= date('Y') ?> <?= e(config('app.name', 'Asterisk Panel')) ?>
    </div>
</div>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
</body>
</html>
