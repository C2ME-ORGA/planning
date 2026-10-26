<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Exceptions\InvalidActivationException;
use App\Models\AccountActivation;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AccountActivationService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Émet un nouveau lien d'activation et invalide les précédents.
     *
     * Le jeton en clair n'est jamais stocké ni journalisé. Il est uniquement
     * transmis par e-mail à l'adresse du compte.
     */
    public function send(User $user, ?User $actor = null): string
    {
        if (! $user->isActive()) {
            throw new RuntimeException('Un compte désactivé ne peut pas recevoir de lien d’activation.');
        }

        if ($user->activated_at !== null) {
            throw new RuntimeException('Ce compte est déjà activé : utilisez la réinitialisation de mot de passe.');
        }

        $token = Str::random(64);

        DB::transaction(function () use ($user, $token, $actor): void {
            $user->activations()->whereNull('consumed_at')->delete();

            $user->activations()->create([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'created_by_id' => $actor?->getKey(),
            ]);

            $user->notify(new AccountActivationNotification($token));
        });

        $this->audit->record(
            AuditAction::ActivationSent,
            $user,
            ['expires_in_minutes' => $this->ttlMinutes()],
            null,
            $actor,
        );

        return $token;
    }

    public function findUsable(string $plainToken): ?AccountActivation
    {
        if (strlen($plainToken) !== 64) {
            return null;
        }

        $activation = AccountActivation::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        return $activation?->isUsable() ? $activation : null;
    }

    /**
     * @throws InvalidActivationException
     */
    public function assertUsable(string $plainToken): AccountActivation
    {
        $activation = $this->findUsable($plainToken);

        if (! $activation instanceof AccountActivation) {
            throw new InvalidActivationException('Ce lien d’activation est invalide ou expiré.');
        }

        return $activation;
    }

    public function ttlMinutes(): int
    {
        return (int) config('planning.accounts.activation_ttl_minutes', 1440);
    }
}
