<?php

use App\Enums\PlanningHalfDay;
use App\Enums\Role;
use App\Enums\SiteStatus;
use App\Filament\Resources\PlanningAssignments\Pages\EditPlanningAssignment;
use App\Models\PlanningAssignment;
use App\Models\Site;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentService;
use App\Services\Sites\SiteService;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\PlanningFixtures;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/**
 * @return array{0: User, 1: Site, 2: PlanningAssignment}
 */
function archivedSiteWithAssignment(): array
{
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

    app(SiteService::class)->archive($site, $bureau);

    return [$bureau, $site->fresh(), $assignment->fresh()];
}

it('keeps archived site assignments consultable with their history', function (): void {
    [$bureau, $site, $assignment] = archivedSiteWithAssignment();

    $this->actingAs($bureau)
        ->get('/admin/planning-assignments/'.$assignment->id)
        ->assertOk()
        ->assertSee($site->name)
        ->assertSee('Chantier archivé')
        ->assertSee('Historique')
        ->assertSee('Affectation de planning créée');

    $this->actingAs($bureau)
        ->get('/admin/planning-assignments')
        ->assertOk()
        ->assertSee($site->name);
});

it('forbids writing on an assignment whose site is archived', function (): void {
    [$bureau, $site, $assignment] = archivedSiteWithAssignment();
    $otherSite = PlanningFixtures::site();

    $this->actingAs($bureau)
        ->get('/admin/planning-assignments/'.$assignment->id.'/edit')
        ->assertForbidden();

    $this->actingAs($bureau);

    Livewire::test(EditPlanningAssignment::class, ['record' => $assignment->id])
        ->assertForbidden();
});

it('rejects service updates on an archived site assignment', function (): void {
    [$bureau, $site, $assignment] = archivedSiteWithAssignment();
    $otherSite = PlanningFixtures::site();

    expect(fn () => app(PlanningAssignmentService::class)->update(
        $assignment,
        $otherSite,
        $assignment->team,
        '2026-11-03',
        PlanningHalfDay::Afternoon,
        [],
        $bureau,
    ))->toThrow(ValidationException::class);

    expect($assignment->fresh()->site_id)->toBe($site->id)
        ->and($assignment->fresh()->work_date->toDateString())->toBe('2026-11-02')
        ->and(PlanningAssignment::query()->count())->toBe(1);
});

it('renders archived sites in read only mode to authorized users only', function (): void {
    [$bureau, $site] = archivedSiteWithAssignment();

    $this->actingAs($bureau)
        ->get('/admin/sites/'.$site->id)
        ->assertOk()
        ->assertSee($site->name)
        ->assertSee('Archivé');

    foreach ([Role::Admin, Role::Ouvrier] as $role) {
        $user = User::factory()->role($role)->withMultiFactorAuthentication()->create();

        $this->actingAs($user)
            ->get('/admin/sites/'.$site->id)
            ->assertForbidden();
    }
});

it('refuses direct creation for an archived site and preserves historical assignments', function (): void {
    [$bureau, $site, $assignment] = archivedSiteWithAssignment();
    $team = $assignment->team;

    $this->actingAs($bureau)
        ->get('/admin/sites/'.$site->id.'/edit')
        ->assertForbidden();

    expect(fn () => app(PlanningAssignmentService::class)->create(
        $site,
        $team,
        '2026-11-04',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    ))->toThrow(ValidationException::class);

    expect(PlanningAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue()
        ->and($site->isArchived())->toBeTrue();
});

it('keeps an archived site visible in the site list without reopening it', function (): void {
    [$bureau, $site] = archivedSiteWithAssignment();

    $this->actingAs($bureau)
        ->get('/admin/sites?tableFilters[archived_at][value]=true')
        ->assertOk()
        ->assertSee($site->name);

    expect($site->fresh()->isArchived())->toBeTrue()
        ->and($site->fresh()->status)->toBe(SiteStatus::APreparer->value)
        ->and($bureau->can('update', $site))->toBeFalse()
        ->and($bureau->can('archive', $site))->toBeFalse();
});
