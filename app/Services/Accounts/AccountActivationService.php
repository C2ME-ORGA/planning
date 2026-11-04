<?php

namespace App\Services\Accounts;

use App\Enums\AuditAction;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidActivationException;
use App\Models\AccountActivation;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
     *
     * L'activation et son audit sont écrits atomiquement. La notification est
     * enregistrée via `DB::afterCommit()` : elle n'est envoyée qu'après le
     * commit de la transaction racine, y compris lorsque `send()` est appelé
     * depuis une transaction englobante. Un rollback annule donc l'envoi.
     */
    public function send(User $user, ?User $actor = null): string
    {
        if (! $user->isActive()) {
            throw new BusinessRuleException('Un compte désactivé ne peut pas recevoir de lien d’activation.');
        }

        if ($user->activated_at !== null) {
            throw new BusinessRuleException('Ce compte est déjà activé : utilisez la réinitialisation de mot de passe.');
        }

        $token = Str::random(64);

        DB::transaction(function () use ($user, $token, $actor): void {
            $user->activations()->whereNull('consumed_at')->delete();

            $user->activations()->create([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'created_by_id' => $actor?->getKey(),
            ]);

            $this->audit->record(
                AuditAction::ActivationSent,
                $user,
                ['expires_in_minutes' => $this->ttlMinutes()],
                null,
                $actor,
            );

            DB::afterCommit(function () use ($user, $token): void {
                $this->notifyAfterCommit($user, $token);
            });
        });

        return $token;
    }

    /**
     * Envoie la notification après commit et journalise un échec de transport
     * sans jamais exposer le jeton.
     */
    private function notifyAfterCommit(User $user, string $token): void
    {
        try {
            $user->notify(new AccountActivationNotification($token));
        } catch (Throwable $exception) {
            Log::error('Échec de l’envoi du lien d’activation après commit.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
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
