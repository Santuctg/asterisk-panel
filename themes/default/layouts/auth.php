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
    <meta name="description" content="Sign in to the Asterisk PBX administration panel">
    <title>Sign in · <?= e(config('app.name', 'Asterisk Panel')) ?></title>
    <script>
    (function () {
        try {
            var theme = localStorage.getItem('panel-theme');
            var language = localStorage.getItem('panel-language') || 'en';
            if (!theme && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) theme = 'dark';
            document.documentElement.dataset.theme = theme || 'light';
            document.documentElement.lang = language === 'bn' ? 'bn' : 'en';
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📞</text></svg>">
</head>
<body class="auth-body">
<div class="auth-ui-controls">
    <select id="languageSelect" aria-label="Language">
        <option value="en">English</option>
        <option value="bn">বাংলা</option>
    </select>
    <button type="button" class="icon-btn theme-toggle" id="themeToggle" title="Switch color mode" aria-label="Switch color mode"><span class="theme-icon" aria-hidden="true">☾</span></button>
</div>
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
<script src="<?= e(asset('assets/js/i18n.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
</body>
</html>
