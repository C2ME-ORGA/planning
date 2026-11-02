<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coupe immédiatement l'accès d'un compte désactivé, même si sa session
 * existait déjà. La révocation en base reste la mesure principale ; ce filtre
 * protège contre un pilote de session ou un cache encore chaud.
 *
 * Il vérifie également la version d'authentification enregistrée dans la
 * session : une session antérieure à une révocation est refusée, y compris
 * après réactivation du compte et quel que soit le pilote de session.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && (! $user->is_active || $this->sessionIsStale($request, $user))) {
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

    private function sessionIsStale(Request $request, User $user): bool
    {
        $version = (int) $user->session_version;

        // Avant toute révocation, les sessions historiques sans marqueur
        // restent acceptées : la compatibilité est conservée au déploiement.
        if ($version === 0) {
            return false;
        }

        $recorded = $request->session()->get('auth.session_version');

        return $recorded === null || (int) $recorded !== $version;
    }
}
