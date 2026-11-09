<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\TenantHost;
use App\Services\TenantSecurity;
use App\Services\WebPhoneLoginThrottle;

/**
 * Login / logout / profile flow.
 */
final class AuthController extends BaseController
{
    /** GET /login */
    public function showLogin(Request $request): Response
    {
        if (auth()->check()) {
            return Response::redirect(auth()->landingPath());
        }

        return $this->view('auth/login', [
            'layout' => 'layouts/auth',
            'flashes'=> Flash::pull(),
        ], 'layouts/auth');
    }

    /** POST /login */
    public function login(Request $request): Response
    {
        $username = $request->str('username');
        $password = (string) $request->input('password', '');

        $hostTenant = TenantHost::tenantIdForRequest();
        if ($hostTenant && (new TenantSecurity())->isBlocked($hostTenant, $request->ip())) {
            Logger::warning('Tenant login blocked', ['ip'=>$request->ip(), 'tenant_id'=>$hostTenant]);
            Flash::error('Login from this IP address is blocked for this tenant.');
            return Response::redirect('/login');
        }

        // Database-backed and host-independent so replacing the session cookie
        // or varying Host cannot create a fresh brute-force attempt window.
        $throttle = new WebPhoneLoginThrottle();
        $throttleKey = $throttle->key($request->ip(), WebPhoneLoginThrottle::PANEL);
        $retryAfter = $throttle->retryAfter($throttleKey);
        if ($retryAfter > 0) {
            Flash::error('Too many login attempts. Please wait a few minutes and try again.');
            return $this->view('auth/login', ['layout' => 'layouts/auth'], 'layouts/auth')
                ->withStatus(429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }

        if ($username === '' || $password === '') {
            Flash::error('Please enter your username and password.');
            $this->stashOld($request);
            return Response::redirect('/login');
        }

        if (!auth()->attempt($username, $password)) {
            $throttle->hit($throttleKey, $request->ip(), $hostTenant);
            Logger::warning('Authentication failed', [
                'ip' => $request->ip(),
                'username' => mb_substr($username, 0, 80),
            ]);
            Flash::error('Those credentials do not match our records.');
            $this->stashOld($request);
            return Response::redirect('/login');
        }

        $throttle->reset($throttleKey);
        $this->clearOld();

        $user = auth()->user();

        // Seed the active tenant from the user's own tenant, if any.
        if (!empty($user['tenant_id'])) {
            Session::put('active_tenant', (int) $user['tenant_id']);
        } elseif ($hostTenant) {
            Session::put('active_tenant', $hostTenant);
        }

        $this->audit('auth.login', 'user', (int) $user['id']);

        return Response::redirect(auth()->landingPath());
    }

    /** POST /logout */
    public function logout(Request $request): Response
    {
        $user = auth()->user();
        if ($user) {
            $this->audit('auth.logout', 'user', (int) $user['id']);
        }

        auth()->logout();
        Flash::success('You have been signed out.');

        return Response::redirect('/login');
    }

    /** GET /profile */
    public function profile(Request $request): Response
    {
        return $this->view('auth/profile', [
            'user' => $this->user(),
        ]);
    }

    /** POST /profile */
    public function updateProfile(Request $request): Response
    {
        $id   = auth()->id();
        $name = $request->str('email');

        $this->db()->update('users', (int) $id, ['email' => $name]);

        $this->audit('user.profile.update', 'user', (int) $id);
        Flash::success('Profile updated.');

        return Response::redirect('/profile');
    }

    /** POST /profile/password */
    public function updatePassword(Request $request): Response
    {
        $id      = auth()->id();
        $current = (string) $request->input('current_password', '');
        $new     = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');

        $user = $this->db()->one('SELECT password FROM users WHERE id = ?', [$id]);

        if (!$user || !password_verify($current, $user['password'])) {
            Flash::error('Your current password is incorrect.');
            return Response::redirect('/profile');
        }

        if (strlen($new) < 8) {
            Flash::error('The new password must be at least 8 characters.');
            return Response::redirect('/profile');
        }

        if ($new !== $confirm) {
            Flash::error('The new password confirmation does not match.');
            return Response::redirect('/profile');
        }

        $this->db()->update('users', (int) $id, [
            'password' => password_hash($new, PASSWORD_DEFAULT),
        ]);

        $this->audit('user.password.change', 'user', (int) $id);
        Flash::success('Password changed.');

        return Response::redirect('/profile');
    }
}
