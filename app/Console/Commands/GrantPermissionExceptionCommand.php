<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCliActor;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Access\PermissionExceptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class GrantPermissionExceptionCommand extends Command
{
    use ResolvesCliActor;

    protected $signature = 'planning:permission-exception:grant
        {email : Compte bénéficiaire}
        {permission : Capacité éligible (ex. planning.propose)}
        {--actor= : Compte Bureau / Direction qui autorise}
        {--reason= : Justification obligatoire}
        {--expires-at= : Date d’expiration AAAA-MM-JJ}
        {--confirm : Confirme l’exception}';

    protected $description = 'Accorde une exception de permission ciblée, justifiée et révocable.';

    public function handle(PermissionExceptionService $exceptions): int
    {
        if (! $this->option('confirm')) {
            $this->error('Ajoutez --confirm pour accorder l’exception.');

            return self::FAILURE;
        }

        $actor = $this->resolveActorWith(Permission::PermissionExceptionsManage);

        if ($actor === null) {
            return self::FAILURE;
        }

        $target = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if (! $target instanceof User) {
            $this->error('Compte bénéficiaire introuvable.');

            return self::FAILURE;
        }

        $permission = Permission::tryFrom((string) $this->argument('permission'));

        if ($permission === null) {
            $this->error('Capacité inconnue.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        $expiresAt = trim((string) $this->option('expires-at'));

        if ($expiresAt === '') {
            $this->error('Option --expires-at obligatoire (AAAA-MM-JJ).');

            return self::FAILURE;
        }

        try {
            $exception = $exceptions->grant(
                $target,
                $permission,
                $reason,
                Carbon::parse($expiresAt)->endOfDay(),
                $actor,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Exception #%d accordée à %s pour %s jusqu’au %s.',
            $exception->getKey(),
            $target->email,
            $permission->value,
            $exception->expires_at->format('d/m/Y'),
        ));

        return self::SUCCESS;
    }
}
