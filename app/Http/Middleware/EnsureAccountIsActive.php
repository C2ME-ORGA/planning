<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Accounts\AuthenticationGeneration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle serveur du cycle de vie de l'authentification.
 *
 * Fail-closed : une session doit porter une version utilisateur et une
 * génération globale valides. Une session sans marqueur, avec un marqueur
 * invalide ou issue d'une génération antérieure est détruite immédiatement.
 *
 * La génération globale est lue avant la résolution de l'utilisateur : si une
 * révocation globale intervient pendant la requête (y compris pendant une
 * authentification remember-me), la différence est détectée et la session est
 * refusée.
 */
class EnsureAccountIsActive
{
    public function __construct(
        private readonly AuthenticationGeneration $generation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $generationAtStart = $this->generation->current();

        // Rendue disponible au listener de connexion pour que le marqueur
        // enregistré soit celui du début de requête, jamais une génération
        // apparue pendant l'authentification.
        $request->attributes->set('auth.global_generation_at_start', $generationAtStart);

        $user = Auth::user();

        $rememberInvalidated = (bool) $request->attributes->get('auth.remember_invalidated', false);
        $generationChanged = $this->generation->current() !== $generationAtStart;

        if ($user instanceof User && (
            ! $user->is_active
            || $rememberInvalidated
            || $generationChanged
            || $this->sessionIsStale($request, $user, $generationAtStart)
        )) {
            if ($user->is_active) {
                // Session obsolète d'un compte encore actif : le jeton
                // remember-me a déjà été invalidé par la révocation. On ne
                // renouvelle pas celui des autres appareils.
                Auth::guard()->logoutCurrentDevice();
            } else {
                // Compte désactivé : rotation défensive du jeton persistant.
                Auth::guard()->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect($this->loginPath($request))
                ->withErrors(['email' => 'Ces identifiants ne correspondent pas à nos enregistrements.']);
        }

        return $next($request);
    }

    private function sessionIsStale(Request $request, User $user, int $generationAtStart): bool
    {
        $version = $request->session()->get('auth.session_version');
        $generation = $request->session()->get('auth.global_generation');

        if (! is_numeric($version) || ! is_numeric($generation)) {
            return true;
        }

        return (int) $version !== (int) $user->session_version
            || (int) $generation !== $generationAtStart;
    }

    /**
     * Les requêtes Livewire du panneau Filament passent par `/livewire/update`.
     * Le référent permet de renvoyer l'utilisateur vers le bon écran de
     * connexion sans jamais construire d'URL depuis une valeur non fiable.
     */
    private function loginPath(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');

        if ($request->is('admin') || $request->is('admin/*') || str_contains($referer, '/admin')) {
            return '/admin/login';
        }

        return route('login');
    }
}
