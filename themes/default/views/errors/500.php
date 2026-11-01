<?php
/** 500 error page. Expects: $message (only shown when debug is on) */
$debug = (bool) config('app.debug', false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>500 · Server error</title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="auth-body">
    <div class="auth-wrap" style="text-align:center">
        <div class="card">
            <div class="card-body" style="padding:44px 28px">
                <div class="empty-icon" style="margin:0 auto 16px;background:var(--danger-100);color:var(--danger-500)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" width="26" height="26">
                        <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>
                    </svg>
                </div>
                <h1 style="margin-bottom:6px">Something went wrong</h1>
                <p class="muted">
                    An unexpected error occurred. The details have been logged.
                </p>
                <?php if ($debug && !empty($message)): ?>
                    <pre style="text-align:left;background:var(--surface-alt);padding:14px;border-radius:8px;overflow:auto;font-size:12px"><?= e($message) ?></pre>
                <?php endif; ?>
                <a class="btn btn-primary" href="/dashboard">Back to dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>
