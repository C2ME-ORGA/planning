<?php

use App\Enums\PlanningHalfDay;
use App\Enums\Role;
use App\Livewire\Planning\MobilePlanning;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentService;
use App\Services\Sites\SiteService;
use Livewire\Livewire;
use Tests\Support\PlanningFixtures;

it('renders the mobile planning consultation for every planning reader', function (Role $role): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);

    app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        now()->toDateString(),
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $actor = User::factory()->role($role)->create();

    $this->actingAs($actor)
        ->get('/planning')
        ->assertOk()
        ->assertSee('Planning prévisionnel')
        ->assertSee($site->name)
        ->assertSee($team->name)
        ->assertSee('Matin')
        ->assertSee('Après-midi')
        ->assertSee('Aucune affectation prévue');
})->with([
    'bureau' => Role::BureauDirection,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
]);

it('denies the mobile planning to roles without planning access', function (Role $role): void {
    $actor = User::factory()->role($role)->create();

    $this->actingAs($actor)->get('/planning')->assertForbidden();

    $this->actingAs($actor);

    Livewire::test(MobilePlanning::class)->assertForbidden();
})->with([
    'administrateur' => Role::Admin,
    'ouvrier' => Role::Ouvrier,
]);

it('redirects guests to the existing login page', function (): void {
    $this->get('/planning')->assertRedirect('/login');
});

it('shows half-day separation, conflicts and readable history', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);
    $otherTeam = PlanningFixtures::team();
    $siteA = PlanningFixtures::site();
    $siteB = PlanningFixtures::site();
    $siteC = PlanningFixtures::site();
    $service = app(PlanningAssignmentService::class);

    TeamAssignment::factory()->for($employee)->for($otherTeam)->temporary()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    $service->create($siteA, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $service->create($siteB, $otherTeam, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $service->create($siteC, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    $this->actingAs($bureau);

    Livewire::test(MobilePlanning::class)
        ->set('date', '2026-11-02')
        ->assertSee($siteA->name)
        ->assertSee($siteB->name)
        ->assertSee($siteC->name)
        ->assertSee('Conflit d’équipe')
        ->assertSee('Conflit de salarié')
        ->assertSee('Historique')
        ->assertSee('Affectation de planning créée')
        ->assertSee('Exclusions');
});

it('shows the archived restriction without offering any write path', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);

    app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    app(SiteService::class)->archive($site, $bureau);

    $this->actingAs($bureau);

    Livewire::test(MobilePlanning::class)
        ->set('date', '2026-11-02')
        ->assertSee('Chantier archivé')
        ->assertSee('Consultation historique uniquement')
        ->assertDontSee('Modifier')
        ->assertDontSee('Enregistrer')
        ->assertDontSee('Pointage');
});

it('navigates between days and rejects an invalid date', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);

    app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-03',
        PlanningHalfDay::Afternoon,
        [],
        $bureau,
    );

    $this->actingAs($bureau);

    Livewire::test(MobilePlanning::class)
        ->set('date', '2026-11-02')
        ->assertDontSee($site->name)
        ->call('nextDay')
        ->assertSee($site->name)
        ->assertSee('Après-midi')
        ->set('date', '2026-02-30')
        ->assertHasErrors('date')
        ->assertSet('date', now()->toDateString());
});
