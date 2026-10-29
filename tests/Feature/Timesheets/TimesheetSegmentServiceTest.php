<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Services\Timesheets\TimesheetSegmentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

/**
 * @return array<string, mixed>
 */
function timesheetSegmentData(
    string $start,
    string $end,
    TimeCategory $category = TimeCategory::TravailEffectif,
): array {
    return [
        'category' => $category,
        'start_at' => CarbonImmutable::parse($start, 'Europe/Paris'),
        'end_at' => CarbonImmutable::parse($end, 'Europe/Paris'),
    ];
}

beforeEach(function (): void {
    $this->service = app(TimesheetSegmentService::class);

    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->timesheet = TimesheetFixtures::timesheet($this->owner, week: '2026-11-02');
});

it('adds a segment in a draft and derives the offsets from the instant', function (): void {
    $segment = $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-02 08:00', '2026-11-02 12:00'),
        $this->ownerUser,
    );

    expect($segment->timesheet_id)->toBe($this->timesheet->getKey())
        ->and($segment->start_at?->toIso8601String())->toBe('2026-11-02T07:00:00+00:00')
        ->and($segment->end_at?->toIso8601String())->toBe('2026-11-02T11:00:00+00:00')
        ->and($segment->start_offset_minutes)->toBe(60)
        ->and($segment->end_offset_minutes)->toBe(60);

    expect(AuditEvent::query()
        ->where('action', AuditAction::TimesheetUpdated->value)
        ->where('subject_id', (string) $this->timesheet->getKey())
        ->where('actor_id', $this->ownerUser->getKey())
        ->where('changes->operation', 'segment_added')
        ->exists())->toBeTrue();
});

it('lets the Bureau write a segment in a draft', function (): void {
    $segment = $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-02 08:00', '2026-11-02 12:00'),
        $this->bureau,
    );

    expect($segment->exists)->toBeTrue();
});

it('accepts a segment that crosses midnight inside the week', function (): void {
    $segment = $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-02 22:00', '2026-11-03 02:00'),
        $this->ownerUser,
    );

    expect($segment->exists)->toBeTrue();
});

it('accepts a segment ending on sunday at the last minute', function (): void {
    $segment = $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-08 22:00', '2026-11-08 23:59'),
        $this->ownerUser,
    );

    expect($segment->exists)->toBeTrue();
});

it('refuses a segment that starts before the week', function (): void {
    expect(fn (): TimesheetSegment => $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-01 23:00', '2026-11-02 01:00'),
        $this->ownerUser,
    ))->toThrow(ValidationException::class);

    expect(TimesheetSegment::query()->count())->toBe(0);
});

it('refuses a segment that crosses the end of the week', function (): void {
    expect(fn (): TimesheetSegment => $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-08 22:00', '2026-11-09 01:00'),
        $this->ownerUser,
    ))->toThrow(ValidationException::class);

    expect(TimesheetSegment::query()->count())->toBe(0);
});

it('refuses seconds, an inverted range and an unknown category', function (): void {
    $withSeconds = timesheetSegmentData('2026-11-02 08:00', '2026-11-02 12:00');
    $withSeconds['start_at'] = CarbonImmutable::parse('2026-11-02 08:00:30', 'Europe/Paris');

    expect(fn (): TimesheetSegment => $this->service->add($this->timesheet, $withSeconds, $this->ownerUser))
        ->toThrow(ValidationException::class);

    expect(fn (): TimesheetSegment => $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-02 12:00', '2026-11-02 08:00'),
        $this->ownerUser,
    ))->toThrow(ValidationException::class);

    $invalid = timesheetSegmentData('2026-11-02 08:00', '2026-11-02 12:00');
    $invalid['category'] = 'inconnue';

    expect(fn (): TimesheetSegment => $this->service->add($this->timesheet, $invalid, $this->ownerUser))
        ->toThrow(ValidationException::class);

    expect(TimesheetSegment::query()->count())->toBe(0);
});

it('refuses an unauthorized actor and a non-draft timesheet', function (): void {
    expect(fn (): TimesheetSegment => $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-02 08:00', '2026-11-02 12:00'),
        $this->otherUser,
    ))->toThrow(AuthorizationException::class);

    $locked = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09', status: TimesheetStatus::AControler);

    expect(fn (): TimesheetSegment => $this->service->add(
        $locked,
        timesheetSegmentData('2026-11-09 08:00', '2026-11-09 12:00'),
        $this->ownerUser,
    ))->toThrow(AuthorizationException::class);

    expect(TimesheetSegment::query()->count())->toBe(0);
});

it('revalidates the status of a stale timesheet under lock', function (): void {
    Timesheet::query()
        ->whereKey($this->timesheet->getKey())
        ->update(['status' => TimesheetStatus::AControler->value]);

    expect($this->timesheet->status)->toBe(TimesheetStatus::Brouillon);

    expect(fn (): TimesheetSegment => $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-02 08:00', '2026-11-02 12:00'),
        $this->ownerUser,
    ))->toThrow(AuthorizationException::class);

    expect(TimesheetSegment::query()->count())->toBe(0);
});

it('updates a segment and records the changed fields', function (): void {
    $segment = TimesheetFixtures::segment(
        $this->timesheet,
        start: '2026-11-02 08:00:00',
        end: '2026-11-02 12:00:00',
    );

    $updated = $this->service->update(
        $segment,
        timesheetSegmentData('2026-11-03 09:00', '2026-11-03 11:30', TimeCategory::Trajet),
        $this->ownerUser,
    );

    expect($updated->category)->toBe(TimeCategory::Trajet)
        ->and($updated->start_at?->toIso8601String())->toBe('2026-11-03T08:00:00+00:00')
        ->and($updated->end_at?->toIso8601String())->toBe('2026-11-03T10:30:00+00:00');

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetUpdated->value)
        ->where('subject_id', (string) $this->timesheet->getKey())
        ->where('changes->operation', 'segment_updated')
        ->firstOrFail();

    $changed = $event->changes['changed_fields'] ?? [];

    expect($event->actor_id)->toBe($this->ownerUser->getKey())
        ->and($event->changes['segment_id'] ?? null)->toBe($segment->getKey())
        ->and($changed)->toContain('category')
        ->and($changed)->toContain('start_at')
        ->and($changed)->toContain('end_at');
});

it('refuses to update or delete a segment of another employee', function (): void {
    $foreign = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09');
    $segment = TimesheetFixtures::segment($foreign);

    expect(fn (): TimesheetSegment => $this->service->update(
        $segment,
        timesheetSegmentData('2026-11-09 08:00', '2026-11-09 12:00'),
        $this->otherUser,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => $this->service->delete($segment, $this->otherUser))
        ->toThrow(AuthorizationException::class);
});

it('deletes a segment in a draft and keeps a trace of the deleted values', function (): void {
    $segment = TimesheetFixtures::segment($this->timesheet);

    $this->service->delete($segment, $this->ownerUser);

    expect(TimesheetSegment::query()->whereKey($segment->getKey())->exists())->toBeFalse();

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetUpdated->value)
        ->where('subject_id', (string) $this->timesheet->getKey())
        ->where('changes->operation', 'segment_deleted')
        ->firstOrFail();

    expect($event->changes['segment_id'] ?? null)->toBe($segment->getKey())
        ->and($event->changes)->toHaveKeys(['category', 'start_at', 'end_at']);
});

it('refuses a direct model deletion outside the draft state', function (): void {
    $locked = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09', status: TimesheetStatus::Validee);
    $segment = TimesheetFixtures::segment($locked);

    expect(fn () => $segment->delete())->toThrow(LogicException::class);

    expect(TimesheetSegment::query()->whereKey($segment->getKey())->exists())->toBeTrue();
});

it('rolls back the segment write when the audit fails', function (): void {
    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): TimesheetSegment => $this->service->add(
        $this->timesheet,
        timesheetSegmentData('2026-11-02 08:00', '2026-11-02 12:00'),
        $this->ownerUser,
    ))->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect(TimesheetSegment::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});
