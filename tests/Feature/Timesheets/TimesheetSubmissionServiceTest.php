<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Services\Timesheets\TimesheetSegmentService;
use App\Services\Timesheets\TimesheetSubmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

beforeEach(function (): void {
    $this->service = app(TimesheetSubmissionService::class);
    $this->segments = app(TimesheetSegmentService::class);

    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->timesheet = TimesheetFixtures::timesheet($this->owner, week: '2026-11-02');
});

it('submits a draft with a segment and records an attributed event', function (): void {
    TimesheetFixtures::segment($this->timesheet);

    $submitted = $this->service->submit($this->timesheet, $this->ownerUser);

    expect($submitted->status)->toBe(TimesheetStatus::AControler);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetSubmitted->value)
        ->where('subject_id', (string) $this->timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->ownerUser->getKey())
        ->and($event->changes['segments_count'] ?? null)->toBe(1)
        ->and($event->context['warnings'] ?? [])->toBe([]);
});

it('refuses a draft without any segment', function (): void {
    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->ownerUser))
        ->toThrow(ValidationException::class);

    expect($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSubmitted->value)->count())->toBe(0);
});

it('refuses submission when a segment falls outside the week', function (): void {
    TimesheetFixtures::segment(
        $this->timesheet,
        start: '2026-11-01 23:00:00',
        end: '2026-11-02 01:00:00',
    );

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->ownerUser))
        ->toThrow(ValidationException::class);

    expect($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSubmitted->value)->count())->toBe(0);
});

it('refuses submission when a segment crosses the end of the week', function (): void {
    TimesheetFixtures::segment(
        $this->timesheet,
        start: '2026-11-08 22:00:00',
        end: '2026-11-09 01:00:00',
    );

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->ownerUser))
        ->toThrow(ValidationException::class);

    expect($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSubmitted->value)->count())->toBe(0);
});

it('refuses submission when a segment has non-zero seconds', function (): void {
    DB::table('timesheet_segments')->insert([
        'timesheet_id' => $this->timesheet->getKey(),
        'category' => TimeCategory::TravailEffectif->value,
        'start_at' => '2026-11-04 09:00:30',
        'end_at' => '2026-11-04 11:00:30',
        'start_offset_minutes' => 60,
        'end_offset_minutes' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->ownerUser))
        ->toThrow(ValidationException::class);

    expect($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSubmitted->value)->count())->toBe(0)
        ->and(TimesheetSegment::query()->where('timesheet_id', $this->timesheet->getKey())->count())->toBe(1);
});

it('submits a segment aligned to the whole minute', function (): void {
    TimesheetFixtures::segment(
        $this->timesheet,
        start: '2026-11-04 08:00:00',
        end: '2026-11-04 12:00:00',
    );

    $submitted = $this->service->submit($this->timesheet, $this->ownerUser);

    expect($submitted->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetSubmitted->value)
            ->where('subject_id', (string) $this->timesheet->getKey())
            ->where('actor_id', $this->ownerUser->getKey())
            ->exists())->toBeTrue();
});

it('submits a valid segment crossing midnight inside the week', function (): void {
    TimesheetFixtures::segment(
        $this->timesheet,
        start: '2026-11-02 22:00:00',
        end: '2026-11-03 02:00:00',
    );

    $submitted = $this->service->submit($this->timesheet, $this->ownerUser);

    expect($submitted->status)->toBe(TimesheetStatus::AControler);
});

it('never lets the Bureau or a chef submit on behalf of the employee', function (): void {
    TimesheetFixtures::segment($this->timesheet);

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);
});

it('refuses submission when the employee has no linked account', function (): void {
    $unlinked = TimesheetFixtures::employee();
    $timesheet = TimesheetFixtures::timesheet($unlinked, week: '2026-11-02');
    TimesheetFixtures::segment($timesheet);

    expect(fn (): Timesheet => $this->service->submit($timesheet, $this->bureau))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->submit($timesheet, $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);
});

it('refuses submission by an inactive account', function (): void {
    TimesheetFixtures::segment($this->timesheet);

    $this->ownerUser->forceFill(['is_active' => false])->save();

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->ownerUser))
        ->toThrow(AuthorizationException::class);
});

it('is idempotent on an already submitted timesheet', function (): void {
    TimesheetFixtures::segment($this->timesheet);

    $this->service->submit($this->timesheet, $this->ownerUser);
    $again = $this->service->submit($this->timesheet->fresh() ?? $this->timesheet, $this->ownerUser);

    expect($again->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSubmitted->value)->count())->toBe(1);
});

it('does not treat a submission retry by another account as idempotent success', function (): void {
    TimesheetFixtures::segment($this->timesheet);
    $this->service->submit($this->timesheet, $this->ownerUser);

    expect(fn (): Timesheet => $this->service->submit($this->timesheet->fresh() ?? $this->timesheet, $this->otherUser))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetSubmitted->value)->count())->toBe(1);
});

it('refuses any segment write after a successful submission', function (): void {
    TimesheetFixtures::segment($this->timesheet);
    $this->service->submit($this->timesheet, $this->ownerUser);

    expect(fn (): TimesheetSegment => $this->segments->add(
        $this->timesheet,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => CarbonImmutable::parse('2026-11-04 08:00', 'Europe/Paris'),
            'end_at' => CarbonImmutable::parse('2026-11-04 12:00', 'Europe/Paris'),
        ],
        $this->ownerUser,
    ))->toThrow(AuthorizationException::class);
});

it('refuses submission after the last segment is deleted', function (): void {
    $segment = TimesheetFixtures::segment($this->timesheet);
    $this->segments->delete($segment, $this->ownerUser);

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->ownerUser))
        ->toThrow(ValidationException::class);

    expect($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);
});

it('records a non-blocking duration warning in the audit context', function (): void {
    TimesheetFixtures::segment(
        $this->timesheet,
        start: '2026-11-02 08:00:00',
        end: '2026-11-02 21:00:00',
    );

    $this->service->submit($this->timesheet, $this->ownerUser);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetSubmitted->value)
        ->firstOrFail();

    $types = collect($event->context['warnings'] ?? [])->pluck('type')->all();

    expect($types)->toContain('segment_over_12_hours')
        ->and($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler);
});

it('records a non-blocking overlap warning in the audit context', function (): void {
    TimesheetFixtures::segment($this->timesheet, start: '2026-11-02 08:00:00', end: '2026-11-02 12:00:00');
    TimesheetFixtures::segment($this->timesheet, start: '2026-11-02 11:00:00', end: '2026-11-02 13:00:00');

    $this->service->submit($this->timesheet, $this->ownerUser);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetSubmitted->value)
        ->firstOrFail();

    $types = collect($event->context['warnings'] ?? [])->pluck('type')->all();

    expect($types)->toContain('segment_overlap')
        ->and($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler);
});

it('refuses submission from a state other than draft', function (): void {
    $corrected = TimesheetFixtures::timesheet($this->owner, week: '2026-11-09', status: TimesheetStatus::ACorriger);
    TimesheetFixtures::segment($corrected, start: '2026-11-09 08:00:00', end: '2026-11-09 12:00:00');

    expect(fn (): Timesheet => $this->service->submit($corrected, $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    expect($corrected->fresh()?->status)->toBe(TimesheetStatus::ACorriger);
});

it('rolls back the submission when the audit fails', function (): void {
    TimesheetFixtures::segment($this->timesheet);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->submit($this->timesheet, $this->ownerUser))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($this->timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetSubmitted->value)->count())->toBe(0);
});
