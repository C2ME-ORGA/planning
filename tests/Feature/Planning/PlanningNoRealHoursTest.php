<?php

use App\Enums\AuditAction;
use App\Enums\PlanningHalfDay;
use App\Livewire\Planning\MobilePlanning;
use App\Models\AuditEvent;
use App\Models\PlanningAssignment;
use App\Models\TeamAssignment;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentService;
use App\Services\Planning\PlanningDayBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\PlanningFixtures;

it('keeps planning structurally separate from real worked hours', function (): void {
    $columns = Schema::getColumnListing('planning_assignments');

    expect($columns)->not->toContain(
        'hours',
        'start_time',
        'end_time',
        'duration_minutes',
        'worked_minutes',
        'clock_in',
        'clock_out',
    );

    foreach (['time_entries', 'timesheets', 'timesheet_entries', 'time_segments', 'absences', 'pointages'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(class_exists(Timesheet::class))->toBeFalse()
        ->and(class_exists(TimeEntry::class))->toBeFalse();
});

it('does not create or prefill real hours when consulting or modifying planning', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $site = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    $employee = PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);

    $assignment = $service->create(
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $service->update(
        $assignment,
        $site,
        $team,
        '2026-11-02',
        PlanningHalfDay::Afternoon,
        [$employee->id],
        $bureau,
    );

    app(PlanningDayBoard::class)->forDate(CarbonImmutable::parse('2026-11-02'), $bureau);

    $this->actingAs($bureau);
    Livewire::test(MobilePlanning::class)->set('date', '2026-11-02');

    $planningAuditActions = AuditEvent::query()
        ->whereIn('action', [
            AuditAction::PlanningAssignmentCreated->value,
            AuditAction::PlanningAssignmentUpdated->value,
        ])
        ->count();

    expect(PlanningAssignment::query()->count())->toBe(1)
        ->and($planningAuditActions)->toBe(2)
        ->and($assignment->fresh()->excludedEmployeeIds())->toBe([$employee->id]);

    foreach (['time_entries', 'timesheets', 'timesheet_entries', 'time_segments', 'absences', 'pointages'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect($employee->fresh()->teamAssignments()->count())->toBe(1);

    // Une exclusion reste une exclusion de planning : aucune affectation
    // d'équipe ni période d'absence n'est créée ou modifiée.
    expect(TeamAssignment::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
