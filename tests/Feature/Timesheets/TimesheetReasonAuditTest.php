<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Support\TimesheetReason;
use Illuminate\Support\Facades\Gate;
use Tests\Support\TimesheetFixtures;

it('uses the normalized reason for both authorization and audit', function (): void {
    $bureau = TimesheetFixtures::user(Role::BureauDirection);
    $employee = TimesheetFixtures::employee();
    $timesheet = TimesheetFixtures::timesheet($employee, status: TimesheetStatus::Validee);

    $reason = TimesheetReason::required('   Réouverture demandée   ');

    expect(Gate::forUser($bureau)->allows('reopen', [$timesheet, $reason]))->toBeTrue();

    app(AuditLogger::class)->record(AuditAction::TimesheetReopened, $timesheet, [], $reason, $bureau);

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetReopened->value)
        ->where('subject_id', (string) $timesheet->getKey())
        ->firstOrFail();

    expect($event->reason)->toBe('Réouverture demandée')
        ->and($event->actor_id)->toBe($bureau->getKey());
});
