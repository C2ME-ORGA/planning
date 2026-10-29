<?php

namespace App\\Http\\Controllers\\Auth;

use App\\Http\\Controllers\\Controller;
use App\\Models\\User;
use Illuminate\\Auth\\Events\\Failed;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Validation\\ValidationException;
use Illuminate\\View\\View;

class TerrainLoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('planning.mobile');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim($credentials['email']));

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->whereNotNull('activated_at')
            ->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            event(new Failed('web', $user, ['email' => $email]));

            throw ValidationException::withMessages([
                'email' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
            ]);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('planning.mobile'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
