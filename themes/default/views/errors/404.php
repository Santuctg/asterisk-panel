<?php
/** 404 error page. Expects: $path */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 · Not found</title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="auth-body">
    <div class="auth-wrap" style="text-align:center">
        <div class="card">
            <div class="card-body" style="padding:44px 28px">
                <div class="empty-icon" style="margin:0 auto 16px">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" width="26" height="26">
                        <circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>
                    </svg>
                </div>
                <h1 style="margin-bottom:6px">404</h1>
                <p class="muted">The page <code><?= e($path ?? '') ?></code> could not be found.</p>
                <a class="btn btn-primary" href="/dashboard">Back to dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>
