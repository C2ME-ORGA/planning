<?php

use App\Enums\PlanningHalfDay;
use App\Filament\Resources\PlanningAssignments\Pages\CreatePlanningAssignment;
use App\Filament\Resources\PlanningAssignments\Pages\EditPlanningAssignment;
use App\Filament\Resources\PlanningAssignments\Pages\ListPlanningAssignments;
use App\Models\PlanningAssignment;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\PlanningFixtures;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('exposes the planning screens to Bureau/Direction only', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $admin = User::factory()->admin()->withMultiFactorAuthentication()->create();

    $this->actingAs($bureau)->get('/admin/planning-assignments')->assertOk()->assertSee('Planning');
    $this->actingAs($bureau)->get('/admin/planning-assignments/create')->assertOk();

    $this->actingAs($admin)->get('/admin/planning-assignments')->assertForbidden();
    $this->actingAs($admin)->get('/admin/planning-assignments/create')->assertForbidden();
});

it('creates a planning assignment through Filament', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);

    $this->actingAs($bureau);

    Livewire::test(CreatePlanningAssignment::class)
        ->fillForm([
            'site_id' => $site->id,
            'team_id' => $team->id,
            'work_date' => '2026-11-02',
            'half_day' => PlanningHalfDay::Morning->value,
            'excluded_employee_ids' => [$employee->id],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $assignment = PlanningAssignment::query()->firstOrFail();

    expect($assignment->site_id)->toBe($site->id)
        ->and($assignment->team_id)->toBe($team->id)
        ->and($assignment->work_date->toDateString())->toBe('2026-11-02')
        ->and($assignment->half_day)->toBe(PlanningHalfDay::Morning)
        ->and($assignment->excludedEmployeeIds())->toBe([$employee->id]);
});

it('rejects nonexistent site or team references from the Filament form', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();

    $this->actingAs($bureau);

    Livewire::test(CreatePlanningAssignment::class)
        ->fillForm([
            'site_id' => 999999,
            'team_id' => 999999,
            'work_date' => '2026-11-02',
            'half_day' => PlanningHalfDay::Morning->value,
        ])
        ->call('create')
        ->assertHasErrors(['data.site_id', 'data.team_id']);

    expect(PlanningAssignment::query()->count())->toBe(0);
});

it('renders the consultation page for an active assignment with its edit action', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);

    $assignment = app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $this->actingAs($bureau)
        ->get('/admin/planning-assignments/'.$assignment->id)
        ->assertOk()
        ->assertSee($site->name)
        ->assertSee('Modifier')
        ->assertSee('Historique');
});

it('warns about overlaps after a Filament creation', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);
    $firstSite = PlanningFixtures::site();
    $secondSite = PlanningFixtures::site();

    app(PlanningAssignmentService::class)->create(
        $firstSite,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $this->actingAs($bureau);

    Livewire::test(CreatePlanningAssignment::class)
        ->fillForm([
            'site_id' => $secondSite->id,
            'team_id' => $team->id,
            'work_date' => '2026-11-02',
            'half_day' => PlanningHalfDay::Morning->value,
        ])
        ->call('create')
        ->assertHasNoErrors()
        ->assertNotified('Enregistré avec chevauchement');

    expect(PlanningAssignment::query()->count())->toBe(2);
});

it('reports overlaps from the list without modifying the existing assignments', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);
    $firstSite = PlanningFixtures::site();
    $secondSite = PlanningFixtures::site();
    $service = app(PlanningAssignmentService::class);

    $first = $service->create($firstSite, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $second = $service->create($secondSite, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    $this->actingAs($bureau);

    Livewire::test(ListPlanningAssignments::class)
        ->callTableAction('checkConflicts', $second)
        ->assertNotified('Chevauchements détectés');

    expect($first->fresh()->site_id)->toBe($firstSite->id)
        ->and($first->fresh()->team_id)->toBe($team->id)
        ->and(PlanningAssignment::query()->count())->toBe(2);
});

it('edits a planning assignment and restores its exclusions in the form', function (): void {
    $bureau = User::factory()->bureauDirection()->withMultiFactorAuthentication()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);

    $assignment = app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [$employee->id],
        $bureau,
    );

    $this->actingAs($bureau);

    Livewire::test(EditPlanningAssignment::class, ['record' => $assignment->id])
        ->assertFormSet([
            'excluded_employee_ids' => [$employee->id],
        ])
        ->fillForm([
            'half_day' => PlanningHalfDay::Afternoon->value,
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect($assignment->fresh()->half_day)->toBe(PlanningHalfDay::Afternoon)
        ->and($assignment->fresh()->excludedEmployeeIds())->toBe([$employee->id]);
});
