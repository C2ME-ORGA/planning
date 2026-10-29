<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Timesheet;
use App\Services\Timesheets\TimesheetControlService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

beforeEach(function (): void {
    $this->service = app(TimesheetControlService::class);

    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->chef = TimesheetFixtures::employee($this->chefUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->otherUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->outOfPerimeterChef = TimesheetFixtures::user(Role::ChefEquipe);

    $this->team = TimesheetFixtures::team();
    TimesheetFixtures::assign($this->owner, $this->team);
    TimesheetFixtures::lead($this->chef, $this->team);
});

it('returns a submitted timesheet for correction with a normalized reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $result = $this->service->requestCorrection($timesheet, '  Correction demandée  ', $this->chefUser);

    expect($result->status)->toBe(TimesheetStatus::ACorriger);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetReturnedForCorrection->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->chefUser->getKey())
        ->and($event->reason)->toBe('Correction demandée')
        ->and($event->changes['from'] ?? null)->toBe(TimesheetStatus::AControler->value)
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::ACorriger->value);
});

it('lets the Bureau return a timesheet for correction', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect($this->service->requestCorrection($timesheet, 'Correction demandée', $this->bureau)->status)
        ->toBe(TimesheetStatus::ACorriger);
});

it('refuses correction by the owner or a controller outside the perimeter', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    // L'autorisation prime sur la validation du motif : un acteur non habilité
    // reçoit un refus d'autorisation même avec un motif invalide.
    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, '   ', $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->outOfPerimeterChef))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(0);
});

it('refuses a missing, blank or oversized correction reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    foreach (['', '   ', str_repeat('a', 501)] as $reason) {
        expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, $reason, $this->chefUser))
            ->toThrow(ValidationException::class);
    }

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(0);
});

it('refuses correction from an incompatible status', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::Brouillon);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::Brouillon);
});

it('does not create a second correction event on a repeated request', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $this->service->requestCorrection($timesheet, 'Correction demandée', $this->chefUser);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet->fresh() ?? $timesheet, 'Correction demandée', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(1);
});

it('rolls back the correction when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->requestCorrection($timesheet, 'Correction demandée', $this->chefUser))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReturnedForCorrection->value)->count())->toBe(0);
});

it('lets the owner resume a correction and records the transition', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    $result = $this->service->resumeCorrection($timesheet, $this->ownerUser);

    expect($result->status)->toBe(TimesheetStatus::Brouillon);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetCorrectionResumed->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->ownerUser->getKey())
        ->and($event->reason)->toBeNull()
        ->and($event->changes['from'] ?? null)->toBe(TimesheetStatus::ACorriger->value)
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::Brouillon->value);
});

it('lets the Bureau resume a correction and refuses other actors', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet, $this->otherUser))
        ->toThrow(AuthorizationException::class);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet, $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect($this->service->resumeCorrection($timesheet, $this->bureau)->status)
        ->toBe(TimesheetStatus::Brouillon);
});

it('does not create a second resume event when the timesheet is already a draft', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    $this->service->resumeCorrection($timesheet, $this->ownerUser);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet->fresh() ?? $timesheet, $this->ownerUser))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetCorrectionResumed->value)->count())->toBe(1);
});

it('rolls back the resume when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::ACorriger);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->resumeCorrection($timesheet, $this->ownerUser))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::ACorriger)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetCorrectionResumed->value)->count())->toBe(0);
});

it('marks a controlled timesheet ready for validation with a normalized reason', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $result = $this->service->markReadyForValidation($timesheet, '  Contrôle satisfaisant  ', $this->chefUser);

    expect($result->status)->toBe(TimesheetStatus::PretePourValidation);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetReadyForValidation->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->actor_id)->toBe($this->chefUser->getKey())
        ->and($event->reason)->toBe('Contrôle satisfaisant')
        ->and($event->changes['to'] ?? null)->toBe(TimesheetStatus::PretePourValidation->value);
});

it('refuses a ready-for-validation request without a valid reason or permission', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet, '', $this->chefUser))
        ->toThrow(ValidationException::class);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet, 'Contrôle satisfaisant', $this->outOfPerimeterChef))
        ->toThrow(AuthorizationException::class);

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(0);
});

it('does not create a second ready-for-validation event on a repeated request', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    $this->service->markReadyForValidation($timesheet, 'Contrôle satisfaisant', $this->chefUser);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet->fresh() ?? $timesheet, 'Contrôle satisfaisant', $this->chefUser))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(1);
});

it('rolls back the ready-for-validation transition when the audit fails', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, status: TimesheetStatus::AControler);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn (): Timesheet => $this->service->markReadyForValidation($timesheet, 'Contrôle satisfaisant', $this->chefUser))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::AControler)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetReadyForValidation->value)->count())->toBe(0);
});
