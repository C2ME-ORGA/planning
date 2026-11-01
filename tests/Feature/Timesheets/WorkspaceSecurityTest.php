<?php

use App\Enums\Role;
use App\Livewire\Timesheets\Bureau\BatchValidation;
use App\Livewire\Timesheets\Bureau\Journal;
use App\Livewire\Timesheets\TeamDashboard;
use App\Livewire\Timesheets\TimesheetList;
use App\Models\User;
use Livewire\Livewire;

it('redirects guests to the login page for every workspace route', function (string $url): void {
    $this->get($url)->assertRedirect('/login');
})->with([
    '/espace',
    '/espace/salarie',
    '/espace/salarie/semaine/2026-11-02',
    '/espace/equipe',
    '/espace/bureau',
    '/espace/releves',
    '/espace/bureau/journal',
]);

it('refuses the Bureau pages to roles without the Bureau perimeter', function (string $url): void {
    foreach ([Role::Admin, Role::Conducteur, Role::ChefEquipe, Role::Ouvrier] as $role) {
        $actor = User::factory()->role($role)->create();

        $this->actingAs($actor)
            ->get($url)
            ->assertForbidden();
    }
})->with([
    '/espace/bureau',
    '/espace/bureau/journal',
    '/espace/releves/file/chevauchements',
]);

it('refuses the team dashboard to roles without the control permission', function (): void {
    foreach ([Role::Admin, Role::Ouvrier] as $role) {
        $actor = User::factory()->role($role)->create();

        $this->actingAs($actor)
            ->get('/espace/equipe')
            ->assertForbidden();

        $this->actingAs($actor);

        Livewire::test(TeamDashboard::class)
            ->assertForbidden();
    }
});

it('refuses the Bureau batch and journal Livewire components to a team leader', function (): void {
    $chef = User::factory()->chefEquipe()->create();

    $this->actingAs($chef);

    Livewire::test(BatchValidation::class)->assertForbidden();
    Livewire::test(Journal::class)->assertForbidden();

    Livewire::test(TimesheetList::class)
        ->set('queue', 'pretes')
        ->call('$refresh')
        ->assertOk();

    Livewire::test(TimesheetList::class)
        ->set('queue', 'chevauchements')
        ->call('$refresh')
        ->assertForbidden();
});

it('refuses the controller queues to an employee even after a Livewire property change', function (): void {
    $worker = User::factory()->ouvrier()->create();

    $this->actingAs($worker);

    foreach (['pretes', 'a-corriger', 'validees', 'chevauchements'] as $queue) {
        Livewire::test(TimesheetList::class)
            ->set('queue', $queue)
            ->call('$refresh')
            ->assertForbidden();
    }
});
