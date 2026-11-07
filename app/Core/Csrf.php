<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF token issue + verification.
 */
final class Csrf
{
    private const KEY = '__csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function verify(Request $request): bool
    {
        $sent = (string) ($request->input('_token') ?? $request->header('X-CSRF-Token') ?? '');
        $want = (string) ($_SESSION[self::KEY] ?? '');

        return $sent !== '' && $want !== '' && hash_equals($want, $sent);
    }

    /** Middleware: reject unsafe requests lacking a valid token. */
    public static function middleware(Request $request, callable $next): mixed
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !self::verify($request)) {
            if ($request->wantsJson()) {
                return Response::json(['error' => 'Invalid CSRF token'], 419);
            }
            Flash::add('error', 'Your session expired. Please try again.');
            return Response::redirect(self::safeReturnPath($request));
        }

        return $next($request);
    }

    private static function safeReturnPath(Request $request): string
    {
        $referer = trim((string) $request->header('Referer', ''));
        if ($referer === '' || preg_match('/\\\\|[\x00-\x1F\x7F]/', $referer)) return '/dashboard';
        if (str_starts_with($referer, '/') && !str_starts_with($referer, '//')) return $referer;

        $parts = parse_url($referer);
        if (!is_array($parts) || empty($parts['host'])) return '/dashboard';
        $requestHost = strtolower(preg_replace('/:\d+$/', '', (string) $request->header('Host', '')) ?? '');
        if ($requestHost === '' || !hash_equals($requestHost, strtolower((string) $parts['host']))) return '/dashboard';
        $path = (string) ($parts['path'] ?? '/dashboard');
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) return '/dashboard';
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
