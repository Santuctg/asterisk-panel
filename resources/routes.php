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
    RouteController, MusicOnHoldController, AnnouncementController, CallCenterController,
    ConferenceController, CdrController, TenantController, UserController, RoleController, GlobalAdminController, Fail2banController,
    SystemController, SslController, ProvisionController, LiveListenController, WebPhoneController, TenantSslController, TenantSecurityController
};
use App\Core\Auth;
use App\Core\Csrf;

/** Unauthenticated: login / logout / device provisioning endpoint. */
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], [Csrf::middleware(...)]);
$router->post('/logout', [AuthController::class, 'logout'], [Csrf::middleware(...)]);
$router->get('/web-phone', [WebPhoneController::class, 'index']);
$router->post('/web-phone/connect', [WebPhoneController::class, 'connect'], [Csrf::middleware(...)]);

// Phones poll their config here; no session auth (MAC-address keyed).
$router->get('/prov/{file}', [ProvisionController::class, 'serve']);

/** Everything below requires an authenticated session. */
$auth    = [Auth::require()];
$csrf    = [Csrf::middleware(...)];

// Dashboard + tenant switching
$router->get('/', [DashboardController::class, 'index'], $auth);
$router->get('/dashboard', [DashboardController::class, 'index'], $auth);
$router->get('/dashboard/live-calls', [DashboardController::class, 'liveCalls'], $auth);
$router->post('/dashboard/switch-tenant', [DashboardController::class, 'switchTenant'], array_merge($auth, $csrf));
$router->get('/live-listen', [LiveListenController::class, 'index'], [Auth::require('call.listen')]);
$router->get('/live-listen/calls', [LiveListenController::class, 'calls'], [Auth::require('call.listen')]);
$router->post('/live-listen/start', [LiveListenController::class, 'start'], [Auth::require('call.listen'), Csrf::middleware(...)]);

// Extensions
$router->get('/extensions', [ExtensionController::class, 'index'], [Auth::require('extension.view')]);
$router->get('/extensions/create', [ExtensionController::class, 'create'], [Auth::require('extension.manage')]);
$router->post('/extensions', [ExtensionController::class, 'store'], [Auth::require('extension.manage'), Csrf::middleware(...)]);
$router->get('/extensions/{id}/edit', [ExtensionController::class, 'edit'], [Auth::require('extension.manage')]);
$router->post('/extensions/{id}', [ExtensionController::class, 'update'], [Auth::require('extension.manage'), Csrf::middleware(...)]);
$router->delete('/extensions/{id}', [ExtensionController::class, 'destroy'], [Auth::require('extension.manage'), Csrf::middleware(...)]);
$router->post('/extensions/{id}/regenerate-secret', [ExtensionController::class, 'regenerateSecret'], [Auth::require('extension.manage'), Csrf::middleware(...)]);

// Voicemail (to email) + mailboxes
$router->get('/voicemail', [VoicemailController::class, 'index'], [Auth::require('voicemail.view')]);
$router->get('/voicemail/create', [VoicemailController::class, 'create'], [Auth::require('voicemail.manage')]);
$router->post('/voicemail', [VoicemailController::class, 'store'], [Auth::require('voicemail.manage'), Csrf::middleware(...)]);
$router->get('/voicemail/{id}/edit', [VoicemailController::class, 'edit'], [Auth::require('voicemail.manage')]);
$router->post('/voicemail/{id}', [VoicemailController::class, 'update'], [Auth::require('voicemail.manage'), Csrf::middleware(...)]);
$router->delete('/voicemail/{id}', [VoicemailController::class, 'destroy'], [Auth::require('voicemail.manage'), Csrf::middleware(...)]);

// Devices / provisioning
$router->get('/devices', [DeviceController::class, 'index'], [Auth::require('device.view')]);
$router->get('/devices/create', [DeviceController::class, 'create'], [Auth::require('device.manage')]);
$router->post('/devices', [DeviceController::class, 'store'], [Auth::require('device.manage'), Csrf::middleware(...)]);
$router->get('/devices/{id}/edit', [DeviceController::class, 'edit'], [Auth::require('device.manage')]);
$router->post('/devices/{id}', [DeviceController::class, 'update'], [Auth::require('device.manage'), Csrf::middleware(...)]);
$router->delete('/devices/{id}', [DeviceController::class, 'destroy'], [Auth::require('device.manage'), Csrf::middleware(...)]);
$router->post('/devices/{id}/resync', [DeviceController::class, 'resync'], [Auth::require('device.manage'), Csrf::middleware(...)]);

// Ring groups
$router->get('/ring-groups', [RingGroupController::class, 'index'], [Auth::require('ringgroup.view')]);
$router->get('/ring-groups/create', [RingGroupController::class, 'create'], [Auth::require('ringgroup.manage')]);
$router->post('/ring-groups', [RingGroupController::class, 'store'], [Auth::require('ringgroup.manage'), Csrf::middleware(...)]);
$router->get('/ring-groups/{id}/edit', [RingGroupController::class, 'edit'], [Auth::require('ringgroup.manage')]);
$router->post('/ring-groups/{id}', [RingGroupController::class, 'update'], [Auth::require('ringgroup.manage'), Csrf::middleware(...)]);
$router->delete('/ring-groups/{id}', [RingGroupController::class, 'destroy'], [Auth::require('ringgroup.manage'), Csrf::middleware(...)]);

// Call parking
$router->get('/parking', [ParkingController::class, 'index'], [Auth::require('parking.view')]);
$router->post('/parking', [ParkingController::class, 'store'], [Auth::require('parking.manage'), Csrf::middleware(...)]);
$router->post('/parking/{id}', [ParkingController::class, 'update'], [Auth::require('parking.manage'), Csrf::middleware(...)]);
$router->delete('/parking/{id}', [ParkingController::class, 'destroy'], [Auth::require('parking.manage'), Csrf::middleware(...)]);

// IVR menus
$router->get('/ivr', [IvrController::class, 'index'], [Auth::require('ivr.view')]);
$router->get('/ivr/create', [IvrController::class, 'create'], [Auth::require('ivr.manage')]);
$router->post('/ivr', [IvrController::class, 'store'], [Auth::require('ivr.manage'), Csrf::middleware(...)]);
$router->get('/ivr/{id}/edit', [IvrController::class, 'edit'], [Auth::require('ivr.manage')]);
$router->post('/ivr/{id}', [IvrController::class, 'update'], [Auth::require('ivr.manage'), Csrf::middleware(...)]);
$router->delete('/ivr/{id}', [IvrController::class, 'destroy'], [Auth::require('ivr.manage'), Csrf::middleware(...)]);

// Call routing (inbound + outbound + time conditions)
$router->get('/routes', [RouteController::class, 'index'], [Auth::require('route.view')]);
$router->post('/routes/inbound', [RouteController::class, 'storeInbound'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->post('/routes/inbound/{id}', [RouteController::class, 'updateInbound'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->delete('/routes/inbound/{id}', [RouteController::class, 'destroyInbound'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->post('/routes/outbound', [RouteController::class, 'storeOutbound'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->post('/routes/outbound/{id}', [RouteController::class, 'updateOutbound'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->delete('/routes/outbound/{id}', [RouteController::class, 'destroyOutbound'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->post('/routes/trunks', [RouteController::class, 'storeTrunk'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->post('/routes/trunks/{id}', [RouteController::class, 'updateTrunk'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->delete('/routes/trunks/{id}', [RouteController::class, 'destroyTrunk'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->post('/routes/time', [RouteController::class, 'storeTime'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->post('/routes/time/{id}', [RouteController::class, 'updateTime'], [Auth::require('route.manage'), Csrf::middleware(...)]);
$router->delete('/routes/time/{id}', [RouteController::class, 'destroyTime'], [Auth::require('route.manage'), Csrf::middleware(...)]);

// Music on hold
$router->get('/moh', [MusicOnHoldController::class, 'index'], [Auth::require('moh.view')]);
$router->post('/moh', [MusicOnHoldController::class, 'store'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->post('/moh/{id}', [MusicOnHoldController::class, 'update'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->delete('/moh/{id}', [MusicOnHoldController::class, 'destroy'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->post('/moh/{id}/tracks', [MusicOnHoldController::class, 'uploadTracks'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->post('/moh/{id}/record', [MusicOnHoldController::class, 'recordTrack'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->post('/moh/{id}/speech', [MusicOnHoldController::class, 'generateSpeech'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->get('/moh/{id}/tracks/{filename}', [MusicOnHoldController::class, 'trackAudio'], [Auth::require('moh.view')]);
$router->post('/moh/{id}/tracks/{filename}/toggle', [MusicOnHoldController::class, 'toggleTrack'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->delete('/moh/{id}/tracks/{filename}', [MusicOnHoldController::class, 'deleteTrack'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->post('/announcements', [AnnouncementController::class, 'store'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->post('/announcements/{id}', [AnnouncementController::class, 'update'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->delete('/announcements/{id}', [AnnouncementController::class, 'destroy'], [Auth::require('moh.manage'), Csrf::middleware(...) ]);
$router->get('/announcements/{id}/audio', [AnnouncementController::class, 'audio'], [Auth::require('moh.view')]);

// Call center (queues + agents)
$router->get('/call-center', [CallCenterController::class, 'index'], [Auth::require('callcenter.view')]);
$router->post('/call-center', [CallCenterController::class, 'store'], [Auth::require('callcenter.manage'), Csrf::middleware(...) ]);
$router->post('/call-center/{id}', [CallCenterController::class, 'update'], [Auth::require('callcenter.manage'), Csrf::middleware(...) ]);
$router->delete('/call-center/{id}', [CallCenterController::class, 'destroy'], [Auth::require('callcenter.manage'), Csrf::middleware(...) ]);
$router->post('/call-center/{id}/agents', [CallCenterController::class, 'syncAgents'], [Auth::require('callcenter.manage'), Csrf::middleware(...) ]);

// Conferences
$router->get('/conferences', [ConferenceController::class, 'index'], [Auth::require('conference.view')]);
$router->post('/conferences', [ConferenceController::class, 'store'], [Auth::require('conference.manage'), Csrf::middleware(...) ]);
$router->post('/conferences/{id}', [ConferenceController::class, 'update'], [Auth::require('conference.manage'), Csrf::middleware(...) ]);
$router->delete('/conferences/{id}', [ConferenceController::class, 'destroy'], [Auth::require('conference.manage'), Csrf::middleware(...) ]);

// CDR / call history
$router->get('/cdr', [CdrController::class, 'index'], [Auth::require('cdr.view')]);
$router->get('/cdr/{id}/recording', [CdrController::class, 'recording'], [Auth::require('cdr.view')]);
$router->post('/cdr/bulk-delete', [CdrController::class, 'bulkDelete'], [Auth::require('cdr.delete'), Csrf::middleware(...)]);
$router->delete('/cdr', [CdrController::class, 'clear'], [Auth::require('cdr.delete'), Csrf::middleware(...)]);
$router->delete('/cdr/{id}', [CdrController::class, 'destroy'], [Auth::require('cdr.delete'), Csrf::middleware(...)]);

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
$router->get('/users', [UserController::class, 'index'], [Auth::require('user.view')]);
$router->post('/users', [UserController::class, 'store'], [Auth::require('user.manage'), Csrf::middleware(...)]);
$router->post('/users/{id}', [UserController::class, 'update'], [Auth::require('user.manage'), Csrf::middleware(...)]);
$router->delete('/users/{id}', [UserController::class, 'destroy'], [Auth::require('user.manage'), Csrf::middleware(...)]);

// Tenant-scoped custom roles
$router->get('/roles', [RoleController::class, 'index'], [Auth::require('role.view')]);
$router->post('/roles', [RoleController::class, 'store'], [Auth::require('role.manage'), Csrf::middleware(...)]);
$router->post('/roles/{id}', [RoleController::class, 'update'], [Auth::require('role.manage'), Csrf::middleware(...)]);
$router->delete('/roles/{id}', [RoleController::class, 'destroy'], [Auth::require('role.manage'), Csrf::middleware(...)]);
$router->get('/tenant-security', [TenantSecurityController::class, 'index'], [Auth::require('tenant_security.view')]);
$router->post('/tenant-security', [TenantSecurityController::class, 'store'], [Auth::require('tenant_security.manage'), Csrf::middleware(...)]);
$router->delete('/tenant-security/{id}', [TenantSecurityController::class, 'destroy'], [Auth::require('tenant_security.manage'), Csrf::middleware(...)]);

// Global control-plane accounts and host security (superadmin only)
$router->get('/global-admins', [GlobalAdminController::class, 'index'], [Auth::require('tenant.manage')]);
$router->post('/global-admins', [GlobalAdminController::class, 'store'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/global-admins/{id}', [GlobalAdminController::class, 'update'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->delete('/global-admins/{id}', [GlobalAdminController::class, 'destroy'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->get('/fail2ban', [Fail2banController::class, 'index'], [Auth::require('security.fail2ban')]);
$router->post('/fail2ban/ban', [Fail2banController::class, 'ban'], [Auth::require('security.fail2ban'), Csrf::middleware(...)]);
$router->post('/fail2ban/unban', [Fail2banController::class, 'unban'], [Auth::require('security.fail2ban'), Csrf::middleware(...) ]);
$router->post('/fail2ban/whitelist/add', [Fail2banController::class, 'whitelistAdd'], [Auth::require('security.fail2ban'), Csrf::middleware(...) ]);
$router->post('/fail2ban/whitelist/remove', [Fail2banController::class, 'whitelistRemove'], [Auth::require('security.fail2ban'), Csrf::middleware(...) ]);

// System: status, apply config, settings, audit log
$router->get('/system/status', [SystemController::class, 'status'], [Auth::require('system_status.view')]);
$router->get('/system/update', [SystemController::class, 'update'], [Auth::require('system.update')]);
$router->get('/system/update/progress', [SystemController::class, 'updateProgress'], [Auth::require('system.update')]);
$router->post('/system/update', [SystemController::class, 'applyUpdate'], [Auth::require('system.update'), Csrf::middleware(...) ]);
$router->post('/system/apply', [SystemController::class, 'apply'], [Auth::require('system.apply'), Csrf::middleware(...)]);
$router->get('/system/settings', [SystemController::class, 'settings'], [Auth::require('system_settings.view')]);
$router->post('/system/settings', [SystemController::class, 'saveSettings'], [Auth::require('system_settings.manage'), Csrf::middleware(...)]);
$router->get('/system/audit', [SystemController::class, 'auditLog'], [Auth::require('audit.view')]);
$router->get('/system/audit-feed', [SystemController::class, 'auditFeed'], [Auth::require('audit.view')]);
$router->get('/system/ssl', [SslController::class, 'index'], [Auth::require('tenant.manage')]);
$router->post('/system/ssl/register', [SslController::class, 'register'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/system/ssl/verify', [SslController::class, 'verify'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/system/ssl/issue', [SslController::class, 'issue'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);
$router->post('/system/ssl/reset', [SslController::class, 'reset'], [Auth::require('tenant.manage'), Csrf::middleware(...)]);

// Profile (any logged-in user)
$router->get('/profile', [AuthController::class, 'profile'], $auth);
$router->post('/profile', [AuthController::class, 'updateProfile'], array_merge($auth, $csrf));
$router->post('/profile/password', [AuthController::class, 'updatePassword'], array_merge($auth, $csrf));
