<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Branding;
use App\Services\TenantHost;

final class BrandingController extends BaseController
{
    public function logo(Request $request): Response
    {
        if (!TenantHost::isKnownRequest()) return Response::text('Not found', 404);
        $branding = Branding::current();
        $relative = (string) $branding['logo'];
        $scope = $branding['logo_tenant_id'] === null ? 'global' : 'tenant-' . (int) $branding['logo_tenant_id'];
        if (!str_starts_with($relative, 'branding/' . $scope . '/')) return Response::text('Not found', 404);
        $path = Branding::absoluteLogoPath($relative);
        if (!$path) return Response::text('Not found', 404);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return Response::text('Not found', 404);
        }
        return (new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, private',
        ]));
    }
}
