<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\TeamAssignmentKind;
use App\Exceptions\EmployeeExitBlockedException;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\EmploymentPeriod;
use App\Models\Team;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Employees\EmployeeLifecycleService;
use App\Services\Employees\EmployeeService;
use App\Services\Employees\EmploymentPeriodService;
use App\Services\Employees\TeamAssignmentService;
use App\Services\Employees\TeamLeadershipService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('blocks an exit while assignments or responsibilities remain active after the exit date', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    $primaryTeam = Team::factory()->create(['name' => 'Équipe principale']);
    $temporaryTeam = Team::factory()->create(['name' => 'Équipe temporaire']);
    $leadershipTeam = Team::factory()->create(['name' => 'Équipe responsable']);

    $primary = app(TeamAssignmentService::class)->assign(
        $employee,
        $primaryTeam,
        TeamAssignmentKind::Primary,
        now()->subYear()->toDateString(),
        null,
        $actor,
    );
    $temporary = app(TeamAssignmentService::class)->assign(
        $employee,
        $temporaryTeam,
        TeamAssignmentKind::Temporary,
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );

    $leaderUser = User::factory()->chefEquipe()->create();
    app(EmployeeService::class)->linkAccount($employee, $leaderUser, $actor);
    $leadership = app(TeamLeadershipService::class)->assign(
        $leadershipTeam,
        $employee->fresh(),
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );

    $exitDate = now()->toDateString();
    $periodBefore = $employee->fresh()->employmentPeriods()->firstOrFail();

    try {
        app(EmployeeLifecycleService::class)->exit($employee, $exitDate, $actor);
        $this->fail('La sortie aurait dû être bloquée.');
    } catch (EmployeeExitBlockedException $exception) {
        expect($exception->blockers)->toHaveCount(3);

        $joined = implode(' | ', $exception->blockers);
        expect($joined)->toContain('Équipe principale')
            ->and($joined)->toContain('Équipe temporaire')
            ->and($joined)->toContain('Équipe responsable');
    }

    expect($periodBefore->fresh()->end_date)->toBeNull()
        ->and($primary->fresh()->end_date)->toBeNull()
        ->and($temporary->fresh()->end_date)->toBeNull()
        ->and($leadership->fresh()->end_date)->toBeNull();
});

it('accepts an exit after manual closure of compatible assignments and keeps history', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $teamA = Team::factory()->create(['name' => 'Équipe A']);
    $teamB = Team::factory()->create(['name' => 'Équipe B']);
    $teamC = Team::factory()->create(['name' => 'Équipe C']);

    $primary = app(TeamAssignmentService::class)->assign(
        $employee,
        $teamA,
        TeamAssignmentKind::Primary,
        now()->subYear()->toDateString(),
        null,
        $actor,
    );
    $temporary = app(TeamAssignmentService::class)->assign(
        $employee,
        $teamB,
        TeamAssignmentKind::Temporary,
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );
    app(EmployeeService::class)->linkAccount($employee, User::factory()->chefEquipe()->create(), $actor);
    $leadership = app(TeamLeadershipService::class)->assign(
        $teamC,
        $employee->fresh(),
        now()->subMonth()->toDateString(),
        null,
        $actor,
    );

    $exitDate = now()->toDateString();

    app(TeamAssignmentService::class)->close($primary, $exitDate, $actor);
    app(TeamAssignmentService::class)->close($temporary, $exitDate, $actor);
    app(TeamLeadershipService::class)->close($leadership, $exitDate, $actor);

    $period = app(EmployeeLifecycleService::class)->exit($employee, $exitDate, $actor);

    expect($period->end_date->toDateString())->toBe($exitDate)
        ->and($primary->fresh()->start_date->toDateString())->toBe(now()->subYear()->toDateString())
        ->and($primary->fresh()->end_date->toDateString())->toBe($exitDate)
        ->and($temporary->fresh()->end_date->toDateString())->toBe($exitDate)
        ->and($leadership->fresh()->end_date->toDateString())->toBe($exitDate)
        ->and($employee->fresh()->employmentPeriods()->count())->toBe(1);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('allows cancelling a future assignment before exiting without deleting its history', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $team = Team::factory()->create();

    $future = app(TeamAssignmentService::class)->assign(
        $employee,
        $team,
        TeamAssignmentKind::Temporary,
        now()->addDay()->toDateString(),
        now()->addMonth()->toDateString(),
        $actor,
    );

    expect(fn () => app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor))
        ->toThrow(EmployeeExitBlockedException::class);

    app(TeamAssignmentService::class)->cancel($future, 'Annulation avant la sortie du salarié.', $actor);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor);

    expect($future->fresh()->cancelled_at)->not->toBeNull()
        ->and($future->fresh()->end_date->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and(TeamAssignment::query()->whereKey($future->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::TeamAssignmentCancelled->value,
        'subject_id' => (string) $future->id,
    ]);
});

it('creates a rehire as a new period after an exit without reactivating the old account', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    $inactive = User::factory()->ouvrier()->inactive()->create();
    app(EmployeeService::class)->linkAccount($employee, $inactive, $actor);

    $periods = app(EmploymentPeriodService::class);
    $first = $periods->add($employee, '2026-01-01', null, $actor);

    app(EmployeeLifecycleService::class)->exit($employee, '2026-06-30', $actor);
    $second = $periods->add($employee, '2026-07-01', '2026-12-31', $actor);

    expect($first->fresh()->end_date->toDateString())->toBe('2026-06-30')
        ->and($second->start_date->toDateString())->toBe('2026-07-01')
        ->and($employee->fresh()->employmentPeriods()->count())->toBe(2)
        ->and($inactive->fresh()->isActive())->toBeFalse()
        ->and($inactive->fresh()->role)->toBe(Role::Ouvrier);
});

it('deactivates the linked account only when explicitly requested during exit', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $user = User::factory()->ouvrier()->create();
    app(EmployeeService::class)->linkAccount($employee, $user, $actor);

    DB::table('sessions')->insert([
        'id' => 'employee-exit-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor, true);

    expect($employee->fresh()->employmentPeriods()->first()->end_date->toDateString())
        ->toBe(now()->toDateString())
        ->and($user->fresh()->isActive())->toBeFalse()
        ->and($user->fresh()->deactivated_at)->not->toBeNull()
        ->and($user->fresh()->deactivation_reason)->toContain('sortie');

    $this->assertDatabaseMissing('sessions', ['id' => 'employee-exit-session']);
    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $user->id,
    ]);
    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
    expect(app(AuditLogger::class)->verify()->isValid)->toBeTrue();
});

it('keeps the linked account active when deactivation is not requested', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $user = User::factory()->ouvrier()->create();
    app(EmployeeService::class)->linkAccount($employee, $user, $actor);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor);

    expect($user->fresh()->isActive())->toBeTrue()
        ->and($user->fresh()->deactivated_at)->toBeNull();

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $user->id,
    ]);
});

it('exits a salarié without a linked account even when deactivation is requested', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor, true);

    $period = $employee->fresh()->employmentPeriods()->first();

    expect($period->end_date->toDateString())->toBe(now()->toDateString())
        ->and($employee->fresh()->user_id)->toBeNull();

    $event = AuditEvent::query()
        ->where('action', AuditAction::EmployeeExited->value)
        ->where('subject_id', (string) $employee->id)
        ->firstOrFail();

    expect($event->changes['account_linked'])->toBeFalse()
        ->and($event->changes['account_deactivation_requested'])->toBeTrue()
        ->and($event->changes['account_deactivated'])->toBeFalse();
});

it('rolls back the exit when the account deactivation fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    app(EmployeeService::class)->linkAccount($employee, $actor, $actor);

    DB::table('sessions')->insert([
        'id' => 'exit-deactivation-failure-session',
        'user_id' => $actor->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    $periodBefore = $employee->fresh()->employmentPeriods()->firstOrFail();
    $rememberToken = $actor->remember_token;

    expect(fn () => app(EmployeeLifecycleService::class)->exit(
        $employee,
        now()->toDateString(),
        $actor,
        true,
    ))->toThrow(RuntimeException::class, 'désactiver');

    expect($periodBefore->fresh()->end_date)->toBeNull()
        ->and($actor->fresh()->isActive())->toBeTrue()
        ->and($actor->fresh()->remember_token)->toBe($rememberToken);

    $this->assertDatabaseHas('sessions', ['id' => 'exit-deactivation-failure-session']);
    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $actor->id,
    ]);
});

it('refuses immediate account deactivation for a future exit', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $user = User::factory()->ouvrier()->create();
    app(EmployeeService::class)->linkAccount($employee, $user, $actor);

    expect(fn () => app(EmployeeLifecycleService::class)->exit(
        $employee,
        now()->addWeek()->toDateString(),
        $actor,
        true,
    ))->toThrow(RuntimeException::class, 'désactivation immédiate');

    expect($user->fresh()->isActive())->toBeTrue()
        ->and($employee->fresh()->employmentPeriods()->first()->end_date)->toBeNull();
});

it('closes the employment period only once and records a single exit event', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor);

    expect(fn () => app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor))
        ->toThrow(RuntimeException::class, 'aucune période');

    expect($employee->fresh()->employmentPeriods()->count())->toBe(1)
        ->and($employee->fresh()->employmentPeriods()->first()->end_date->toDateString())
        ->toBe(now()->toDateString())
        ->and(AuditEvent::query()
            ->where('action', AuditAction::EmployeeExited->value)
            ->where('subject_id', (string) $employee->id)
            ->count())->toBe(1);
});

it('refuses an invalid or too early exit date', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => '2026-06-01',
        'end_date' => null,
    ]);

    expect(fn () => app(EmployeeLifecycleService::class)->exit($employee, 'pas-une-date', $actor))
        ->toThrow(RuntimeException::class, 'AAAA-MM-JJ');

    expect(fn () => app(EmployeeLifecycleService::class)->exit($employee, '2026-05-31', $actor))
        ->toThrow(RuntimeException::class, 'ne peut pas précéder');

    expect($employee->fresh()->employmentPeriods()->first()->end_date)->toBeNull();
});

it('rolls back the closure when the audit write fails', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $periodId = $employee->employmentPeriods()->firstOrFail()->getKey();

    config(['planning.audit.key' => 'trop-court']);

    expect(fn () => app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect(EmploymentPeriod::query()->findOrFail($periodId)->end_date)->toBeNull();

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
});

it('rolls back closure, account and sessions when the audit fails after deactivation', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $user = User::factory()->ouvrier()->create();
    app(EmployeeService::class)->linkAccount($employee, $user, $actor);

    DB::table('sessions')->insert([
        'id' => 'exit-audit-failure-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);

    $rememberToken = $user->remember_token;
    $periodId = $employee->employmentPeriods()->firstOrFail()->getKey();

    config(['planning.audit.key' => 'trop-court']);

    expect(fn () => app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor, true))
        ->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect(EmploymentPeriod::query()->findOrFail($periodId)->end_date)->toBeNull()
        ->and($user->fresh()->isActive())->toBeTrue()
        ->and($user->fresh()->deactivated_at)->toBeNull()
        ->and($user->fresh()->remember_token)->toBe($rememberToken);

    $this->assertDatabaseHas('sessions', ['id' => 'exit-audit-failure-session']);
    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::AccountDeactivated->value,
        'subject_id' => (string) $user->id,
    ]);
    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
});

it('fails when the period is closed between the service re-read and the guarded update', function () {
    $actor = User::factory()->bureauDirection()->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);
    $periodId = $employee->employmentPeriods()->firstOrFail()->getKey();

    // Injection déterministe d'un interlacement SQL : la relecture exacte du
    // service (EmploymentPeriodService::openPeriod) est identifiée par sa
    // signature SQL — table employment_periods, condition employee_id,
    // end_date IS NULL et tri start_date DESC — et non par un simple motif
    // « première requête contenant employment_periods ». Une autre requête
    // sur la même table ne peut donc pas déclencher l'injection ; si la
    // signature de relecture change, le test échoue explicitement
    // ($injected restant faux) au lieu de viser la mauvaise requête.
    //
    // L'ordre est ensuite prouvé par la séquence observée : l'UPDATE d'injection
    // doit précéder l'UPDATE métier protégé du service. L'UPDATE d'injection
    // génère lui-même un événement QueryExecuted réentrant : $injecting
    // l'identifie et l'ignore pour ne jamais le confondre avec l'UPDATE métier.
    $injected = false;
    $injecting = false;
    $matchedSql = null;
    $injectedAffected = null;
    $observed = [];

    DB::listen(function (QueryExecuted $query) use (&$injected, &$injecting, &$matchedSql, &$injectedAffected, &$observed, $periodId): void {
        if ($injecting) {
            return;
        }

        $sql = strtolower(str_replace(['`', '"', '[', ']'], '', trim($query->sql)));

        if (str_starts_with($sql, 'update') && str_contains($sql, 'employment_periods')) {
            // Hors injection en cours, seul l'UPDATE métier du service peut
            // écrire sur la table à ce stade ; toute autre écriture avant
            // l'injection signale un ordre de requêtes inattendu.
            $observed[] = $injected ? 'business-update' : 'update-before-injection';

            return;
        }

        if ($injected
            || ! str_starts_with($sql, 'select')
            || ! str_contains($sql, 'from employment_periods')
            || ! str_contains($sql, 'employee_id')
            || ! str_contains($sql, 'end_date is null')
            || ! str_contains($sql, 'order by start_date desc')) {
            return;
        }

        $injected = true;
        $matchedSql = $query->sql;
        $injecting = true;

        try {
            $injectedAffected = DB::table('employment_periods')
                ->where('id', $periodId)
                ->whereNull('end_date')
                ->update([
                    'end_date' => now()->toDateString(),
                    'updated_at' => now(),
                ]);
        } finally {
            $injecting = false;
        }

        $observed[] = 'injected-update';
    });

    $caught = null;

    try {
        app(EmployeeLifecycleService::class)->exit($employee, now()->toDateString(), $actor);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    // L'exception provient bien de la branche `if ($affected !== 1)` :
    // message propre à cette branche et jamais une erreur SQL de la couche
    // Query Builder (QueryException est aussi un RuntimeException).
    expect($caught)->not->toBeNull()
        ->and($caught)->not->toBeInstanceOf(QueryException::class)
        ->and($caught?->getMessage())->toContain('déjà été clôturée par une autre opération');

    // L'UPDATE d'injection a bien affecté exactement une ligne, puis a été
    // suivi de l'UPDATE métier protégé — dans cet ordre.
    expect($injected)->toBeTrue()
        ->and((string) $matchedSql)->toContain('employment_periods')
        ->and((string) $matchedSql)->toContain('is null')
        ->and($injectedAffected)->toBe(1)
        ->and($observed)->toBe(['injected-update', 'business-update']);

    // Rollback complet : la transaction a restauré la période et aucun événement
    // EmployeeExited n'a été enregistré.
    expect(EmploymentPeriod::query()->findOrFail($periodId)->end_date)->toBeNull();

    $this->assertDatabaseMissing('audit_events', [
        'action' => AuditAction::EmployeeExited->value,
        'subject_id' => (string) $employee->id,
    ]);
});

it('refuses exit operations to unauthorized roles', function (Role $role) {
    $actor = User::factory()->role($role)->create();
    $employee = Employee::factory()->create();
    EmploymentPeriod::factory()->for($employee)->create([
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
    ]);

    expect(fn () => app(EmployeeLifecycleService::class)->exit(
        $employee,
        now()->toDateString(),
        $actor,
    ))->toThrow(RuntimeException::class);
})->with([
    'administrateur' => Role::Admin,
    'conducteur' => Role::Conducteur,
    'chef d’équipe' => Role::ChefEquipe,
    'ouvrier' => Role::Ouvrier,
]);
