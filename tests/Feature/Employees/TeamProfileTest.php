<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\TeamService;

it('creates a team with a unique name and audits it', function () {
    $actor = User::factory()->bureauDirection()->create();
    $service = app(TeamService::class);

    $team = $service->create('  Équipe Gros Œuvre  ', $actor);

    expect($team->name)->toBe('Équipe Gros Œuvre')
        ->and($team->fresh()->name)->toBe('Équipe Gros Œuvre');

    expect(fn () => $service->create('Équipe Gros Œuvre', $actor))
        ->toThrow(RuntimeException::class, 'déjà ce nom');

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::TeamCreated->value,
        'subject_id' => (string) $team->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('refuses team operations to unauthorized roles and reserves reads to the Bureau', function (Role $role) {
    $actor = User::factory()->role($role)->create();
    $bureau = User::factory()->bureauDirection()->create();

    expect(fn () => app(TeamService::class)->create('Équipe A', $actor))
        ->toThrow(RuntimeException::class);

    expect(fn () => app(TeamService::class)->update(Team::factory()->create(), 'Équipe B', $actor))
        ->toThrow(RuntimeException::class);

    expect($actor->can('viewAny', Team::class))->toBeFalse()
        ->and($actor->can('create', Team::class))->toBeFalse()
        ->and($bureau->can('viewAny', Team::class))->toBeTrue()
        ->and($bureau->can('create', Team::class))->toBeTrue();
})->with([
    'administrateur' => Role::Admin,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);
