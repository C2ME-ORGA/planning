<?php

use App\Enums\PlanningHalfDay;
use App\Models\AuditEvent;
use App\Models\PlanningAssignment;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Planning\PlanningAssignmentHistory;
use App\Services\Planning\PlanningAssignmentService;
use App\Services\Planning\PlanningDayBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\PlanningFixtures;

it('distinguishes team and employee overlaps in the day consultation', function (): void {
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

    $first = $service->create($siteA, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $second = $service->create($siteB, $otherTeam, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $third = $service->create($siteC, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    $board = app(PlanningDayBoard::class)->forDate(CarbonImmutable::parse('2026-11-02'), $bureau);

    $byId = collect($board['morning'])->keyBy(fn (array $entry): int => (int) $entry['assignment']->id);

    expect($board['team_warnings'])->toBeGreaterThan(0)
        ->and($board['employee_warnings'])->toBeGreaterThan(0)
        ->and(collect($byId[$third->id]['warnings'])->pluck('type')->all())->toContain('team')
        ->and(collect($byId[$second->id]['warnings'])->pluck('type')->all())->toContain('employee')
        ->and(PlanningAssignment::query()->count())->toBe(3)
        ->and($first->fresh()->site_id)->toBe($siteA->id)
        ->and($first->fresh()->team_id)->toBe($team->id);
});

it('updates conflict detection after a modification and keeps history consultation side-effect free', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $siteA = PlanningFixtures::site();
    $siteB = PlanningFixtures::site();
    $team = PlanningFixtures::team();
    PlanningFixtures::employee($team);
    $service = app(PlanningAssignmentService::class);
    $board = app(PlanningDayBoard::class);

    $first = $service->create($siteA, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);
    $second = $service->create($siteB, $team, '2026-11-02', PlanningHalfDay::Morning, [], $bureau);

    expect($board->forDate(CarbonImmutable::parse('2026-11-02'), $bureau)['team_warnings'])->toBeGreaterThan(0);

    $service->update(
        $second,
        $siteB,
        $team,
        '2026-11-02',
        PlanningHalfDay::Afternoon,
        [],
        $bureau,
    );

    $afterUpdate = $board->forDate(CarbonImmutable::parse('2026-11-02'), $bureau);

    expect($afterUpdate['morning'])->toHaveCount(1)
        ->and($afterUpdate['afternoon'])->toHaveCount(1)
        ->and($afterUpdate['team_warnings'])->toBe(0)
        ->and($afterUpdate['employee_warnings'])->toBe(0);

    $auditCount = AuditEvent::query()->count();

    app(PlanningAssignmentHistory::class)->timeline($first, $bureau);
    app(PlanningAssignmentHistory::class)->timeline($second->fresh(), $bureau);

    expect(AuditEvent::query()->count())->toBe($auditCount)
        ->and($board->forDate(CarbonImmutable::parse('2026-11-02'), $bureau)['team_warnings'])->toBe(0)
        ->and(PlanningAssignment::query()->count())->toBe(2);
});

it('loads day consultation data in a number of queries independent from the assignment count', function (): void {
    $bureau = User::factory()->bureauDirection()->create();

    $baseTeam = PlanningFixtures::team();
    PlanningFixtures::employee($baseTeam);
    $baseSite = PlanningFixtures::site();
    app(PlanningAssignmentService::class)->create(
        $baseSite,
        $baseTeam,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    $otherTeam = PlanningFixtures::team();
    PlanningFixtures::employee($otherTeam);
    app(PlanningAssignmentService::class)->create(
        PlanningFixtures::site(),
        $otherTeam,
        '2026-11-02',
        PlanningHalfDay::Morning,
        [],
        $bureau,
    );

    DB::enableQueryLog();
    DB::flushQueryLog();
    app(PlanningDayBoard::class)->forDate(CarbonImmutable::parse('2026-11-02'), $bureau);
    $baseline = count(DB::getQueryLog());
    DB::disableQueryLog();

    for ($index = 0; $index < 5; $index++) {
        $team = PlanningFixtures::team();
        PlanningFixtures::employee($team);
        app(PlanningAssignmentService::class)->create(
            PlanningFixtures::site(),
            $team,
            '2026-11-02',
            PlanningHalfDay::Morning,
            [],
            $bureau,
        );
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    app(PlanningDayBoard::class)->forDate(CarbonImmutable::parse('2026-11-02'), $bureau);
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($expanded)->toBeLessThanOrEqual($baseline + 2);
});
