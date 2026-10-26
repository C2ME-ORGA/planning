<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Str;

class AuditAuthenticationEvents
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->audit->record(
            AuditAction::LoginSucceeded,
            $event->user,
            [],
            null,
            $event->user,
            ['guard' => $event->guard],
        );
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
