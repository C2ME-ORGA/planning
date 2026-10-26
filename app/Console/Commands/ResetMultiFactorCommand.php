<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCliActor;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Accounts\AccountAdministration;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Réinitialise la double authentification d'un compte qui a perdu son
 * appareil et ses codes de secours. L'opération est autorisée par un compte
 * Bureau / Direction, révoque les sessions et est auditée.
 */
class ResetMultiFactorCommand extends Command
{
    use ResolvesCliActor;

    protected $signature = 'planning:mfa:reset
        {email : Compte concerné}
        {--actor= : Compte Bureau / Direction qui autorise}
        {--reason= : Justification obligatoire}
        {--confirm : Confirme la réinitialisation}';

    protected $description = 'Réinitialise la double authentification d’un compte après perte d’accès.';

    public function handle(AccountAdministration $accounts): int
    {
        if (! $this->option('confirm')) {
            $this->error('Ajoutez --confirm pour réinitialiser la double authentification.');

            return self::FAILURE;
        }

        $actor = $this->resolveActorWith(Permission::AccountsUpdate);

        if ($actor === null) {
            return self::FAILURE;
        }

        $target = User::query()
            ->where('email', Str::lower(trim((string) $this->argument('email'))))
            ->first();

        if (! $target instanceof User) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }

        try {
            $accounts->resetMultiFactor($target, trim((string) $this->option('reason')), $actor);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Double authentification réinitialisée pour {$target->email}. Sessions révoquées.");

        return self::SUCCESS;
    }
}
