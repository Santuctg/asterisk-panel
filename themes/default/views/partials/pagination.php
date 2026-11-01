<?php
/**
 * Pagination controls.
 * Expects: $page, $pages, and an optional $base (query string prefix).
 */
$page  = (int) ($page ?? 1);
$pages = (int) ($pages ?? 1);
$base  = $base ?? '';

if ($pages <= 1) {
    return;
}

$build = static function (int $p) use ($base): string {
    $sep = str_contains($base, '?') ? '&' : '?';
    return $base . $sep . 'page=' . $p;
};

$window = 2;
$start  = max(1, $page - $window);
$end    = min($pages, $page + $window);
?>
<nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1): ?>
        <a href="<?= e($build($page - 1)) ?>">&laquo; Prev</a>
    <?php endif; ?>

    <?php if ($start > 1): ?>
        <a href="<?= e($build(1)) ?>">1</a>
        <?php if ($start > 2): ?><span class="disabled">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="current"><?= $i ?></span>
        <?php else: ?>
            <a href="<?= e($build($i)) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($end < $pages): ?>
        <?php if ($end < $pages - 1): ?><span class="disabled">…</span><?php endif; ?>
        <a href="<?= e($build($pages)) ?>"><?= $pages ?></a>
    <?php endif; ?>

    <?php if ($page < $pages): ?>
        <a href="<?= e($build($page + 1)) ?>">Next &raquo;</a>
    <?php endif; ?>
</nav>
