<?php

use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Services\Timesheets\TimesheetSegmentService;
use App\Services\Timesheets\TimesheetService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\TimesheetFixtures;

/**
 * @return array<string, mixed>
 */
function pc132InactiveSegmentData(): array
{
    return [
        'category' => TimeCategory::TravailEffectif,
        'start_at' => CarbonImmutable::parse('2026-11-03 08:00', 'Europe/Paris'),
        'end_at' => CarbonImmutable::parse('2026-11-03 12:00', 'Europe/Paris'),
    ];
}

beforeEach(function (): void {
    $this->segments = app(TimesheetSegmentService::class);
    $this->user = TimesheetFixtures::user(Role::Ouvrier);
    $this->employee = TimesheetFixtures::employee($this->user);
    $this->user->forceFill(['is_active' => false])->save();
});

it('refuses to open a new timesheet for an inactive owner without side effects', function (): void {
    expect(fn (): Timesheet => app(TimesheetService::class)
        ->openFor($this->employee, '2026-11-02', $this->user))
        ->toThrow(AuthorizationException::class);

    expect(Timesheet::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('refuses segment creation by an inactive owner without side effects', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->employee);

    expect(fn (): TimesheetSegment => $this->segments->add(
        $timesheet,
        pc132InactiveSegmentData(),
        $this->user,
    ))->toThrow(AuthorizationException::class);

    expect(TimesheetSegment::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('refuses segment modification by an inactive owner without side effects', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->employee);
    $segment = TimesheetFixtures::segment($timesheet);

    expect(fn (): TimesheetSegment => $this->segments->update(
        $segment,
        pc132InactiveSegmentData(),
        $this->user,
    ))->toThrow(AuthorizationException::class);

    $unchanged = $segment->fresh();

    expect($unchanged?->category)->toBe(TimeCategory::TravailEffectif)
        ->and($unchanged?->start_at?->toIso8601String())->toBe('2026-11-02T07:00:00+00:00')
        ->and($unchanged?->end_at?->toIso8601String())->toBe('2026-11-02T11:00:00+00:00')
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('refuses segment deletion by an inactive owner without side effects', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->employee);
    $segment = TimesheetFixtures::segment($timesheet);

    expect(fn () => $this->segments->delete($segment, $this->user))
        ->toThrow(AuthorizationException::class);

    expect(TimesheetSegment::query()->whereKey($segment->getKey())->exists())->toBeTrue()
        ->and(AuditEvent::query()->count())->toBe(0);
});
