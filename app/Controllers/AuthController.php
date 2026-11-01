<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Login / logout / profile flow.
 */
final class AuthController extends BaseController
{
    /** GET /login */
    public function showLogin(Request $request): Response
    {
        if (auth()->check()) {
            return Response::redirect('/dashboard');
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

        // Basic throttling to slow brute-force attempts.
        $throttleKey = 'login:' . md5($request->ip());
        $throttle    = Session::throttle($throttleKey);

        if ($throttle['attempts'] >= 5 && (time() - $throttle['last']) < 300) {
            Flash::error('Too many login attempts. Please wait a few minutes and try again.');
            return Response::redirect('/login');
        }

        if ($username === '' || $password === '') {
            Flash::error('Please enter your username and password.');
            $this->stashOld($request);
            return Response::redirect('/login');
        }

        if (!auth()->attempt($username, $password)) {
            Session::throttleHit($throttleKey);
            Flash::error('Those credentials do not match our records.');
            $this->stashOld($request);
            return Response::redirect('/login');
        }

        Session::throttleReset($throttleKey);
        $this->clearOld();

        $user = auth()->user();

        // Seed the active tenant from the user's own tenant, if any.
        if (!empty($user['tenant_id'])) {
            Session::put('active_tenant', (int) $user['tenant_id']);
        }

        $this->audit('auth.login', 'user', (int) $user['id']);

        return Response::redirect('/dashboard');
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
