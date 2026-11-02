<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCliActor;
use App\Enums\Permission;
use App\Models\PermissionException;
use App\Services\Access\PermissionExceptionService;
use Illuminate\Console\Command;
use Throwable;

class RevokePermissionExceptionCommand extends Command
{
    use ResolvesCliActor;

    protected $signature = 'planning:permission-exception:revoke
        {id : Identifiant de l’exception}
        {--actor= : Compte Bureau / Direction qui autorise}
        {--reason= : Justification obligatoire}
        {--confirm : Confirme la révocation}';

    protected $description = 'Révoque une exception de permission avec motif et trace d’audit.';

    public function handle(PermissionExceptionService $exceptions): int
    {
        if (! $this->option('confirm')) {
            $this->error('Ajoutez --confirm pour révoquer l’exception.');

            return self::FAILURE;
        }

        $actor = $this->resolveActorWith(Permission::PermissionExceptionsManage);

        if ($actor === null) {
            return self::FAILURE;
        }

        $exception = PermissionException::query()->find((int) $this->argument('id'));

        if (! $exception instanceof PermissionException) {
            $this->error('Exception introuvable.');

            return self::FAILURE;
        }

        try {
            $exceptions->revoke($exception, trim((string) $this->option('reason')), $actor);
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        $this->info("Exception #{$exception->getKey()} révoquée.");

        return self::SUCCESS;
    }
}
