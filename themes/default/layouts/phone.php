<?php $branding ??= \App\Services\Branding::current(); ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Standalone browser phone">
    <meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>">
    <title>Web Phone · <?= e($branding['name']) ?></title>
    <script>
    (function () {
        try {
            var theme = localStorage.getItem('webphone-theme') || 'dark';
            var language = localStorage.getItem('panel-language') || 'en';
            document.documentElement.dataset.theme = theme;
            document.documentElement.lang = language === 'bn' ? 'bn' : 'en';
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <?php if (!empty($branding['logo_url'])): ?><link rel="icon" href="<?= e($branding['logo_url']) ?>"><?php endif; ?>
</head>
<body class="phone-body">
    <main class="phone-content">
        <?php if (!empty($flashes)): ?><div class="flash-stack"><?php foreach ($flashes as $f): ?><div class="flash flash-<?= e($f['type']) ?>" data-flash><span class="flash-msg"><?= e($f['message']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
        <?= $content ?>
    </main>
    <script src="<?= e(asset('assets/js/i18n.js')) ?>"></script>
</body>
</html>
