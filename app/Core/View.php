<?php

declare(strict_types=1);

namespace App\Core;

/**
 * View renderer: theme folders, layouts, partials and a shared `$e()` escape.
 *
 * Views live in themes/<theme>/views/... and are rendered with:
 *   View::getInstance()->render('extensions/index', ['rows' => $rows]);
 */
final class View
{
    private static ?View $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function themePath(string $sub = ''): string
    {
        $theme = Config::get('app.theme', 'default');
        $base  = dirname(__DIR__, 2) . '/themes/' . $theme;
        return $base . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        $content = $this->partial('views/' . $view, $data);

        if ($layout === null) {
            $layout = $data['__layout'] ?? 'layouts/app';
        }

        if ($layout) {
            // Layouts share common shell data (auth, flashes, csrf).
            $shell = [];
            if (function_exists('auth')) {
                $shell['auth'] = auth();
            }
            $shell['flashes'] = function_exists('flash_messages') ? flash_messages() : [];
            $shell['csrf']    = class_exists(Csrf::class) ? Csrf::token() : '';

            $content = $this->partial($layout, $data + $shell + ['content' => $content]);
        }

        return $content;
    }

    /** Render a fragment without a layout. */
    public function partial(string $name, array $data = []): string
    {
        $file = $this->themePath($name) . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$name}");
        }

        extract($data, EXTR_SKIP);
        unset($data);

        ob_start();
        include $file;

        return (string) ob_get_clean();
    }

    /** Shorthand to render a partial from inside a view (e.g. icons/, partials/). */
    public static function fragment(string $name, array $data = []): string
    {
        // Fragments live under views/ (icons/, partials/) unless already rooted.
        if (!str_starts_with($name, 'views/') && !str_starts_with($name, 'layouts/')) {
            $name = 'views/' . $name;
        }

        return self::getInstance()->partial($name, $data);
    }
}
