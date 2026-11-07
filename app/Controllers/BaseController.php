<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Base controller: shared helpers for rendering views, redirecting with
 * flash messages, and resolving the active tenant scope.
 */
abstract class BaseController
{
    protected App $app;

    public function __construct()
    {
        $this->app = App::getInstance();
        try {
            $tenantId = (int) (\tenant_id() ?? 0);
            if ($tenantId > 0) {
                $timezone = $this->app->db()->scalar('SELECT timezone FROM tenants WHERE id = ?', [$tenantId]);
                date_default_timezone_set(\normalize_timezone(is_string($timezone) ? $timezone : null, (string) config('app.timezone', 'UTC')));
            }
        } catch (\Throwable) {
            // Bootstrap, migrations and isolated tests may construct a controller before tenant tables exist.
        }
    }

    protected function db(): \App\Core\Database
    {
        return $this->app->db();
    }

    /** Render a themed view inside the default layout. */
    protected function view(string $template, array $data = [], string $layout = 'layouts/app'): Response
    {
        $data['auth']    ??= $this->app->auth();
        $data['flashes'] ??= Flash::pull();

        return Response::html(View::getInstance()->render($template, $data, $layout));
    }

    protected function redirect(string $to, ?string $type = null, ?string $message = null): Response
    {
        if ($type && $message) {
            Flash::add($type, $message);
        }
        return Response::redirect($to);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /** Active tenant id for this request (from session or user record). */
    protected function tenantId(): ?int
    {
        return tenant_id();
    }

    protected function requireTenant(): int
    {
        $tid = $this->tenantId();
        if (!$tid) {
            Flash::warning('Select a tenant first.');
            return 0;
        }
        return $tid;
    }

    protected function user(): array
    {
        return $this->app->auth()->user() ?? [];
    }

    /** Persist old input so forms can repopulate after a validation failure. */
    protected function stashOld(Request $request): void
    {
        $_SESSION['__old'] = $request->all();
    }

    protected function clearOld(): void
    {
        unset($_SESSION['__old']);
    }

    protected function dialplanNumberAvailable(int $tenantId, string $number, string $table, ?int $id = null): bool
    {
        return (new \App\Services\DialplanNumberRegistry())->available($tenantId, $number, $table, $id);
    }

    /** Record an audit-log entry for the current user + action. */
    protected function audit(string $action, ?string $entity = null, ?int $entityId = null, array $detail = []): void
    {
        $user = $this->app->auth()->user() ?? [];
        $tenantId = !empty($user['tenant_id']) ? (int) $user['tenant_id'] : ($this->tenantId() ?: null);
        $this->db()->insert('audit_log', [
            'tenant_id'  => $tenantId,
            'user_id'   => $this->app->auth()->id(),
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'detail'    => $detail ? json_encode($detail, JSON_UNESCAPED_SLASHES) : null,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
            'created_at'=> date('Y-m-d H:i:s'),
        ]);
    }

    /** Regenerate Asterisk config for a feature and reload the daemon. */
    protected function applyToAsterisk(string $feature): bool
    {
        try {
            $gen = new \App\Services\Asterisk\ConfigGenerator();
            $gen->generateFor($feature);

            $mgr = asterisk();
            if (!$mgr->isOnline()) {
                throw new \RuntimeException('Asterisk AMI is unavailable.');
            }
            $mgr->reloadManaged();
            return true;
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Asterisk apply failed', ['feature' => $feature, 'error' => $e->getMessage()]);
            Flash::warning('Changes were saved, but could not be applied to Asterisk. Check System Status and apply the configuration again.');
            return false;
        }
    }
}
