<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Accounts\AuthenticationGeneration;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditAuthenticationEvents
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AuthenticationGeneration $generation,
    ) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        // Si un cookie remember-me a été validé juste avant une révocation,
        // son jeton n'est plus celui de la base. La session ne doit pas être
        // marquée comme authentifiée : EnsureAccountIsActive la détruira.
        if ($this->rememberTokenWasInvalidated($event, $event->user)) {
            if (app()->bound('request')) {
                request()->attributes->set('auth.remember_invalidated', true);
            }

            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->recordSessionVersion($event->user);

        $this->audit->record(
            AuditAction::LoginSucceeded,
            $event->user,
            [],
            null,
            $event->user,
            ['guard' => $event->guard],
        );
    }

    /**
     * Fige la version d'authentification dans la session au moment de la
     * connexion. Toute révocation incrémente la version du compte : les
     * sessions antérieures sont alors rejetées par EnsureAccountIsActive.
     */
    private function recordSessionVersion(User $user): void
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return;
        }

        $generation = request()->attributes->get('auth.global_generation_at_start');

        if ($generation === null) {
            $generation = $this->generation->current();
        }

        request()->session()->put('auth.session_version', (int) $user->session_version);
        request()->session()->put('auth.global_generation', (int) $generation);
    }

    /**
     * Détecte une rotation ou une suppression du jeton remember-me survenue
     * entre la validation du cookie et l'événement Login.
     */
    private function rememberTokenWasInvalidated(Login $event, User $user): bool
    {
        $guard = Auth::guard($event->guard);

        if (! $guard->viaRemember()) {
            return false;
        }

        $currentToken = User::query()
            ->whereKey($user->getKey())
            ->value('remember_token');

        return $currentToken === null
            || ! hash_equals((string) $user->getRememberToken(), (string) $currentToken);
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        $this->audit->record(
            AuditAction::LoginFailed,
            null,
            [],
            null,
            null,
            [
                'guard' => $event->guard,
                // L'adresse en clair n'est pas nécessaire : une empreinte suffit
                // à corréler les tentatives.
                'email_hash' => is_string($email) ? hash('sha256', Str::lower(trim($email))) : null,
            ],
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record(
            AuditAction::Logout,
            $event->user,
            [],
            null,
            $event->user,
            ['guard' => $event->guard],
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record(AuditAction::PasswordResetCompleted, $event->user, [], null, $event->user);
    }

    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record(AuditAction::PasswordResetRequested, $event->user);
    }
}
