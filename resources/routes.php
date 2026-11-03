<?php

declare(strict_types=1);

/**
 * Route table. Register every URL here.
 *
 * Middleware used:
 *   Auth::require('ability')  – must be logged in + have the ability
 *   Csrf::middleware          – verifies the CSRF token on unsafe methods
 *
 * Tenant scoping: controllers read the active tenant from the session
 * (set by the tenant switcher on the dashboard) and filter every query by it.
 */

use App\Controllers\{
    AuthController, DashboardController, ExtensionController, VoicemailController,
    DeviceController, RingGroupController, ParkingController, IvrController,
    RouteController, MusicOnHoldController, CallCenterController,
    ConferenceController, CdrController, TenantController, UserController,
    SystemController, SslController, ProvisionController, LiveListenController, WebPhoneController, TenantSslController
};
use App\Core\Auth;
use App\Core\Csrf;

/** Unauthenticated: login / logout / device provisioning endpoint. */
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], [Csrf::middleware(...)]);
$router->post('/logout', [AuthController::class, 'logout'], [Csrf::middleware(...)]);

// Phones poll their config here; no session auth (MAC-address keyed).
$router->get('/prov/{file}', [ProvisionController::class, 'serve']);

/** Everything below requires an authenticated session. */
$auth    = [Auth::require()];
$admin   = [Auth::require('extension.manage')];
$cc      = [Auth::require('callcenter.manage')];
$read    = [Auth::require('cdr.view')];
$csrf    = [Csrf::middleware(...)];

// Dashboard + tenant switching
$router->get('/', [DashboardController::class, 'index'], $auth);
$router->get('/dashboard', [DashboardController::class, 'index'], $auth);
$router->post('/dashboard/switch-tenant', [DashboardController::class, 'switchTenant'], array_merge($auth, $csrf));
$router->get('/live-listen', [LiveListenController::class, 'index'], [Auth::require('call.listen')]);
$router->post('/live-listen/start', [LiveListenController::class, 'start'], [Auth::require('call.listen'), Csrf::middleware(...)]);

// Browser WebRTC softphone
$router->get('/web-phone', [WebPhoneController::class, 'index'], $auth);

// Extensions
$router->get('/extensions', [ExtensionController::class, 'index'], $admin);
$router->get('/extensions/create', [ExtensionController::class, 'create'], $admin);
$router->post('/extensions', [ExtensionController::class, 'store'], array_merge($admin, $csrf));
$router->get('/extensions/{id}/edit', [ExtensionController::class, 'edit'], $admin);
$router->post('/extensions/{id}', [ExtensionController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/extensions/{id}', [ExtensionController::class, 'destroy'], array_merge($admin, $csrf));
$router->post('/extensions/{id}/regenerate-secret', [ExtensionController::class, 'regenerateSecret'], array_merge($admin, $csrf));

// Voicemail (to email) + mailboxes
$router->get('/voicemail', [VoicemailController::class, 'index'], $admin);
$router->get('/voicemail/create', [VoicemailController::class, 'create'], $admin);
$router->post('/voicemail', [VoicemailController::class, 'store'], array_merge($admin, $csrf));
$router->get('/voicemail/{id}/edit', [VoicemailController::class, 'edit'], $admin);
$router->post('/voicemail/{id}', [VoicemailController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/voicemail/{id}', [VoicemailController::class, 'destroy'], array_merge($admin, $csrf));

// Devices / provisioning
$router->get('/devices', [DeviceController::class, 'index'], $admin);
$router->get('/devices/create', [DeviceController::class, 'create'], $admin);
$router->post('/devices', [DeviceController::class, 'store'], array_merge($admin, $csrf));
$router->get('/devices/{id}/edit', [DeviceController::class, 'edit'], $admin);
$router->post('/devices/{id}', [DeviceController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/devices/{id}', [DeviceController::class, 'destroy'], array_merge($admin, $csrf));
$router->post('/devices/{id}/resync', [DeviceController::class, 'resync'], array_merge($admin, $csrf));

// Ring groups
$router->get('/ring-groups', [RingGroupController::class, 'index'], $admin);
$router->get('/ring-groups/create', [RingGroupController::class, 'create'], $admin);
$router->post('/ring-groups', [RingGroupController::class, 'store'], array_merge($admin, $csrf));
$router->get('/ring-groups/{id}/edit', [RingGroupController::class, 'edit'], $admin);
$router->post('/ring-groups/{id}', [RingGroupController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/ring-groups/{id}', [RingGroupController::class, 'destroy'], array_merge($admin, $csrf));

// Call parking
$router->get('/parking', [ParkingController::class, 'index'], $admin);
$router->post('/parking', [ParkingController::class, 'store'], array_merge($admin, $csrf));
$router->post('/parking/{id}', [ParkingController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/parking/{id}', [ParkingController::class, 'destroy'], array_merge($admin, $csrf));

// IVR menus
$router->get('/ivr', [IvrController::class, 'index'], $admin);
$router->get('/ivr/create', [IvrController::class, 'create'], $admin);
$router->post('/ivr', [IvrController::class, 'store'], array_merge($admin, $csrf));
$router->get('/ivr/{id}/edit', [IvrController::class, 'edit'], $admin);
$router->post('/ivr/{id}', [IvrController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/ivr/{id}', [IvrController::class, 'destroy'], array_merge($admin, $csrf));

// Call routing (inbound + outbound + time conditions)
$router->get('/routes', [RouteController::class, 'index'], $admin);
$router->post('/routes/inbound', [RouteController::class, 'storeInbound'], array_merge($admin, $csrf));
$router->post('/routes/inbound/{id}', [RouteController::class, 'updateInbound'], array_merge($admin, $csrf));
$router->delete('/routes/inbound/{id}', [RouteController::class, 'destroyInbound'], array_merge($admin, $csrf));
$router->post('/routes/outbound', [RouteController::class, 'storeOutbound'], array_merge($admin, $csrf));
$router->post('/routes/outbound/{id}', [RouteController::class, 'updateOutbound'], array_merge($admin, $csrf));
$router->delete('/routes/outbound/{id}', [RouteController::class, 'destroyOutbound'], array_merge($admin, $csrf));
$router->post('/routes/trunks', [RouteController::class, 'storeTrunk'], array_merge($admin, $csrf));
$router->post('/routes/trunks/{id}', [RouteController::class, 'updateTrunk'], array_merge($admin, $csrf));
$router->delete('/routes/trunks/{id}', [RouteController::class, 'destroyTrunk'], array_merge($admin, $csrf));
$router->post('/routes/time', [RouteController::class, 'storeTime'], array_merge($admin, $csrf));
$router->post('/routes/time/{id}', [RouteController::class, 'updateTime'], array_merge($admin, $csrf));
$router->delete('/routes/time/{id}', [RouteController::class, 'destroyTime'], array_merge($admin, $csrf));

// Music on hold
$router->get('/moh', [MusicOnHoldController::class, 'index'], $admin);
$router->post('/moh', [MusicOnHoldController::class, 'store'], array_merge($admin, $csrf));
$router->post('/moh/{id}', [MusicOnHoldController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/moh/{id}', [MusicOnHoldController::class, 'destroy'], array_merge($admin, $csrf));

// Call center (queues + agents)
$router->get('/call-center', [CallCenterController::class, 'index'], $cc);
$router->post('/call-center', [CallCenterController::class, 'store'], array_merge($cc, $csrf));
$router->post('/call-center/{id}', [CallCenterController::class, 'update'], array_merge($cc, $csrf));
$router->delete('/call-center/{id}', [CallCenterController::class, 'destroy'], array_merge($cc, $csrf));
$router->post('/call-center/{id}/agents', [CallCenterController::class, 'syncAgents'], array_merge($cc, $csrf));

// Conferences
$router->get('/conferences', [ConferenceController::class, 'index'], $admin);
$router->post('/conferences', [ConferenceController::class, 'store'], array_merge($admin, $csrf));
$router->post('/conferences/{id}', [ConferenceController::class, 'update'], array_merge($admin, $csrf));
$router->delete('/conferences/{id}', [ConferenceController::class, 'destroy'], array_merge($admin, $csrf));

// CDR / call history
$router->get('/cdr', [CdrController::class, 'index'], $read);
$router->get('/cdr/{id}/recording', [CdrController::class, 'recording'], $read);

// Tenants (superadmin)
$router->get('/tenants', [TenantController::class, 'index'], [Auth::require('tenant.view')]);
$router->post('/tenants', [TenantController::class, 'store'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/tenants/{id}', [TenantController::class, 'update'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->delete('/tenants/{id}', [TenantController::class, 'destroy'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->get('/tenants/{id}/ssl', [TenantSslController::class, 'index'], [Auth::require('tenant.manage')]);
$router->post('/tenants/{id}/ssl/register', [TenantSslController::class, 'register'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/tenants/{id}/ssl/verify', [TenantSslController::class, 'verify'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/tenants/{id}/ssl/issue', [TenantSslController::class, 'issue'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/tenants/{id}/ssl/reset', [TenantSslController::class, 'reset'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);

// Users (admin+)
$router->get('/users', [UserController::class, 'index'], [Auth::require('user.manage')]);
$router->post('/users', [UserController::class, 'store'], [Auth::require('user.manage'), Csrf::middleware(...)]);
$router->post('/users/{id}', [UserController::class, 'update'], [Auth::require('user.manage'), Csrf::middleware(...)]);
$router->delete('/users/{id}', [UserController::class, 'destroy'], [Auth::require('user.manage'), Csrf::middleware(...)]);

// System: status, apply config, settings, audit log
$router->get('/system/status', [SystemController::class, 'status'], $auth);
$router->post('/system/apply', [SystemController::class, 'apply'], array_merge($auth, $csrf));
$router->get('/system/settings', [SystemController::class, 'settings'], [Auth::require('tenant.view')]);
$router->post('/system/settings', [SystemController::class, 'saveSettings'], [Auth::require('tenant.view'), Csrf::middleware(...)]);
$router->get('/system/audit', [SystemController::class, 'auditLog'], [Auth::require('tenant.view')]);
$router->get('/system/audit-feed', [SystemController::class, 'auditFeed'], [Auth::require('tenant.view')]);
$router->get('/system/ssl', [SslController::class, 'index'], [Auth::require('tenant.manage')]);
$router->post('/system/ssl/register', [SslController::class, 'register'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/system/ssl/verify', [SslController::class, 'verify'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/system/ssl/issue', [SslController::class, 'issue'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/system/ssl/reset', [SslController::class, 'reset'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);

// Profile (any logged-in user)
$router->get('/profile', [AuthController::class, 'profile'], $auth);
$router->post('/profile', [AuthController::class, 'updateProfile'], array_merge($auth, $csrf));
$router->post('/profile/password', [AuthController::class, 'updatePassword'], array_merge($auth, $csrf));
