<?php

namespace App\\Http\\Middleware;

use App\\Models\\User;
use Closure;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Symfony\\Component\\HttpFoundation\\Response;

/**
 * Coupe immédiatement l'accès d'un compte désactivé, même si sa session
 * existait déjà. La révocation en base reste la mesure principale ; ce filtre
 * protège contre un pilote de session ou un cache encore chaud.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard()->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $loginPath = $request->is('admin') || $request->is('admin/*')
                ? '/admin/login'
                : route('login');

            return redirect($loginPath)
                ->withErrors(['email' => 'Ces identifiants ne correspondent pas à nos enregistrements.']);
        }

        return $next($request);
    }
}
