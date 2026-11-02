<?php

namespace App\Console\Commands;

use App\Services\Access\PermissionExceptionService;
use Illuminate\Console\Command;

class ReviewPermissionExceptionsCommand extends Command
{
    protected $signature = 'planning:permission-exceptions:review';

    protected $description = 'Liste les exceptions actives à revoir avant leur expiration.';

    public function handle(PermissionExceptionService $exceptions): int
    {
        $active = $exceptions->forReview();

        if ($active->isEmpty()) {
            $this->info('Aucune exception active.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Compte', 'Capacité', 'Expire le', 'Jours restants'],
            $active->map(static fn ($exception): array => [
                $exception->getKey(),
                $exception->user?->email,
                $exception->permission->value,
                $exception->expires_at->format('d/m/Y'),
                (int) now()->diffInDays($exception->expires_at, false),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
