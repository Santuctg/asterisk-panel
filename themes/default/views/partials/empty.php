<?php /** Empty-state block. Expects: $title, $message, $icon (default 'info'), optional $action_html */ ?>
<div class="empty">
    <div class="empty-icon">
        <?= $this->fragment('icons/' . ($icon ?? 'info')) ?>
    </div>
    <h3><?= e($title ?? 'Nothing here yet') ?></h3>
    <p class="mb-0"><?= e($message ?? '') ?></p>
    <?php if (!empty($action_html)): ?>
        <div class="mt-4"><?= $action_html ?></div>
    <?php endif; ?>
</div>
