<?php

namespace App\Services\Employees;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Équipes opérationnelles. Une équipe n'est pas supprimée : ses affectations et
 * responsabilités historiques doivent rester exploitables.
 */
class TeamService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function create(string $name, User $actor): Team
    {
        $this->assertCanManage($actor);
        $name = $this->name($name);

        if (Team::query()->where('name', $name)->exists()) {
            throw new RuntimeException('Une équipe porte déjà ce nom.');
        }

        return DB::transaction(function () use ($name, $actor): Team {
            $team = Team::query()->create(['name' => $name]);

            $this->audit->record(
                AuditAction::TeamCreated,
                $team,
                ['team_id' => $team->getKey()],
                null,
                $actor,
            );

            return $team;
        });
    }

    public function update(Team $team, string $name, User $actor): Team
    {
        $this->assertCanManage($actor);
        $name = $this->name($name);

        return DB::transaction(function () use ($team, $name, $actor): Team {
            /** @var Team $team */
            $team = Team::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if ($team->name === $name) {
                return $team;
            }

            if (Team::query()->where('name', $name)->whereKeyNot($team->getKey())->exists()) {
                throw new RuntimeException('Une équipe porte déjà ce nom.');
            }

            $previous = $team->name;
            $team->forceFill(['name' => $name])->save();

            $this->audit->record(
                AuditAction::TeamUpdated,
                $team,
                ['name' => ['old' => $previous, 'new' => $name]],
                null,
                $actor,
            );

            return $team;
        });
    }

    private function name(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Le nom de l’équipe est obligatoire.');
        }

        if (mb_strlen($name) > 120) {
            throw new RuntimeException('Le nom de l’équipe est limité à 120 caractères.');
        }

        return $name;
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->hasPermission(Permission::TeamsManage)) {
            throw new RuntimeException('Ce compte n’est pas autorisé à gérer les équipes.');
        }
    }
}
