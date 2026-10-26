<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Journalise les changements sensibles d'un compte, quel que soit le point
 * d'entrée (panneau, commande, profil, activation).
 */
class UserObserver
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function updated(User $user): void
    {
        if ($user->wasChanged('email')) {
            $this->audit->record(
                AuditAction::EmailChanged,
                $user,
                ['email' => [
                    'old' => $user->getOriginal('email'),
                    'new' => $user->email,
                ]],
            );
        }

        if ($user->wasChanged('role')) {
            $this->audit->record(
                AuditAction::RoleChanged,
                $user,
                ['role' => [
                    'old' => $this->roleValue($user->getOriginal('role')),
                    'new' => $user->role->value,
                ]],
            );
        }

        if ($user->wasChanged('password')) {
            $this->audit->record(AuditAction::PasswordChanged, $user, ['changed' => true]);
        }

        if ($user->wasChanged('app_authentication_secret')) {
            $this->audit->record(
                filled($user->app_authentication_secret) ? AuditAction::MfaEnabled : AuditAction::MfaDisabled,
                $user,
            );
        }
    }

    private function roleValue(mixed $role): ?string
    {
        return $role instanceof Role ? $role->value : (is_string($role) ? $role : null);
    }
}
