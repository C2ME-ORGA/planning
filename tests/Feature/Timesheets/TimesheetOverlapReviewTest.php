<?php

use App\Enums\AuditAction;
use App\Enums\OverlapReviewDecision;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\PermissionException;
use App\Models\Timesheet;
use App\Models\TimesheetOverlapReview;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Timesheets\LocalDateTimeResolver;
use App\Services\Timesheets\TimesheetControlService;
use App\Services\Timesheets\TimesheetOverlapReviewService;
use App\Services\Timesheets\TimesheetSegmentService;
use App\Services\Timesheets\TimesheetSubmissionService;
use App\Services\Timesheets\TimesheetValidationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

function pc132OverlapInstant(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, LocalDateTimeResolver::TIMEZONE);
}

/**
 * Relevé avec une piste d'audit compatible avec l'état demandé.
 */
function pc132ReviewableTimesheet(
    Employee $employee,
    User $owner,
    User $controller,
    TimesheetStatus $status = TimesheetStatus::PretePourValidation,
    string $week = '2026-11-02',
): Timesheet {
    $timesheet = TimesheetFixtures::timesheet($employee, week: $week, status: $status);

    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetSubmitted, $owner);

    if ($status === TimesheetStatus::PretePourValidation) {
        TimesheetFixtures::audit(
            $timesheet,
            AuditAction::TimesheetReadyForValidation,
            $controller,
            'Contrôle satisfaisant',
        );
    }

    return $timesheet;
}

/**
 * @return array{0: TimesheetSegment, 1: TimesheetSegment}
 */
function pc132AddOverlap(Timesheet $timesheet, string $week = '2026-11-02'): array
{
    $monday = Timesheet::weekFor($week)['start']->toDateString();

    return [
        TimesheetFixtures::segment($timesheet, start: "{$monday} 08:00:00", end: "{$monday} 12:00:00"),
        TimesheetFixtures::segment($timesheet, start: "{$monday} 11:00:00", end: "{$monday} 13:00:00"),
    ];
}

beforeEach(function (): void {
    $this->reviews = app(TimesheetOverlapReviewService::class);
    $this->validation = app(TimesheetValidationService::class);
    $this->controls = app(TimesheetControlService::class);
    $this->segments = app(TimesheetSegmentService::class);
    $this->submissions = app(TimesheetSubmissionService::class);

    $this->ownerUser = TimesheetFixtures::user(Role::Ouvrier);
    $this->owner = TimesheetFixtures::employee($this->ownerUser);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->secondBureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->chefUser = TimesheetFixtures::user(Role::ChefEquipe);
    $this->chef = TimesheetFixtures::employee($this->chefUser);

    $team = TimesheetFixtures::team();
    TimesheetFixtures::assign($this->owner, $team);
    TimesheetFixtures::lead($this->chef, $team);
});

it('uses one canonical review per overlapping pair regardless of detection order', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $overlaps = $this->reviews->overlapsFor($timesheet);

    expect($overlaps)->toHaveCount(1)
        ->and($overlaps[0]['first_segment_id'])->toBe(min($first->id, $second->id))
        ->and($overlaps[0]['second_segment_id'])->toBe(max($first->id, $second->id))
        ->and($overlaps[0]['pair_key'])->toBe(TimesheetOverlapReviewService::pairKey($second->id, $first->id));

    $this->reviews->review(
        $timesheet,
        $second->id,
        $first->id,
        OverlapReviewDecision::Accepted,
        'Déplacement justifié par le chantier.',
        $this->bureau,
    );

    $review = TimesheetOverlapReview::query()->sole();

    expect($review->first_segment_id)->toBe(min($first->id, $second->id))
        ->and($review->second_segment_id)->toBe(max($first->id, $second->id));
});

it('detects an overlap between two distinct timesheets with the batched query', function (): void {
    $first = TimesheetFixtures::timesheet($this->owner, week: '2026-10-26');
    $second = TimesheetFixtures::timesheet($this->owner, week: '2026-11-02');

    $firstSegment = TimesheetFixtures::segment(
        $first,
        start: '2026-11-01 22:00:00',
        end: '2026-11-02 02:00:00',
    );
    $secondSegment = TimesheetFixtures::segment(
        $second,
        start: '2026-11-02 01:00:00',
        end: '2026-11-02 03:00:00',
    );

    $overlaps = $this->reviews->overlapsFor($first);
    $mirror = $this->reviews->overlapsFor($second);

    expect($overlaps)->toHaveCount(1)
        ->and($overlaps[0]['pair_key'])->toBe(TimesheetOverlapReviewService::pairKey(
            (int) $firstSegment->getKey(),
            (int) $secondSegment->getKey(),
        ))
        ->and($overlaps[0]['fingerprint'])->not->toBeEmpty()
        ->and($mirror)->toHaveCount(1)
        ->and($mirror[0]['pair_key'])->toBe($overlaps[0]['pair_key'])
        ->and($mirror[0]['fingerprint'])->toBe($overlaps[0]['fingerprint']);
});

it('loads all overlap pairs in a constant number of queries', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner, week: '2026-11-02');

    TimesheetFixtures::segment($timesheet, start: '2026-11-02 08:00:00', end: '2026-11-02 12:00:00');
    TimesheetFixtures::segment($timesheet, start: '2026-11-02 09:00:00', end: '2026-11-02 13:00:00');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $small = $this->reviews->overlapsFor($timesheet);
    $smallQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    TimesheetFixtures::segment($timesheet, start: '2026-11-02 10:00:00', end: '2026-11-02 14:00:00');
    TimesheetFixtures::segment($timesheet, start: '2026-11-02 11:00:00', end: '2026-11-02 15:00:00');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $large = $this->reviews->overlapsFor($timesheet);
    $largeQueryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($small)->toHaveCount(1)
        ->and($large)->toHaveCount(6)
        ->and($smallQueryCount)->toBe($largeQueryCount)
        ->and($largeQueryCount)->toBe(3);
});

it('keeps two distinct overlaps independent during validation', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);
    $monday = Timesheet::weekFor('2026-11-02')['start']->toDateString();
    $third = TimesheetFixtures::segment(
        $timesheet,
        start: "{$monday} 12:30:00",
        end: "{$monday} 14:00:00",
    );

    expect($this->reviews->overlapsFor($timesheet))->toHaveCount(2);

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Premier chevauchement justifié.',
        $this->bureau,
    );

    expect(fn (): Timesheet => $this->validation->validate($timesheet, $this->secondBureau))
        ->toThrow(ValidationException::class, 'non examiné');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);

    $this->reviews->review(
        $timesheet,
        $second->id,
        $third->id,
        OverlapReviewDecision::Accepted,
        'Second chevauchement justifié.',
        $this->bureau,
    );

    expect($this->validation->validate($timesheet, $this->secondBureau)->status)
        ->toBe(TimesheetStatus::Validee);
});

it('does not require a review for adjacent segments', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    $monday = Timesheet::weekFor('2026-11-02')['start']->toDateString();

    TimesheetFixtures::segment($timesheet, start: "{$monday} 08:00:00", end: "{$monday} 12:00:00");
    TimesheetFixtures::segment($timesheet, start: "{$monday} 12:00:00", end: "{$monday} 13:00:00");

    expect($this->reviews->overlapsFor($timesheet))->toBe([])
        ->and($this->validation->validate($timesheet, $this->secondBureau)->status)
        ->toBe(TimesheetStatus::Validee);
});

it('requires the dedicated overlap review permission and a Bureau role', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $worker = TimesheetFixtures::user(Role::Ouvrier);

    expect(fn (): TimesheetOverlapReview => $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié.',
        $worker,
    ))->toThrow(AuthorizationException::class);

    // Un rôle non Bureau conserve la permission de validation via une
    // dérogation historique : elle ne doit jamais ouvrir la revue.
    $chef = TimesheetFixtures::user(Role::ChefEquipe);
    PermissionException::query()->create([
        'user_id' => $chef->getKey(),
        'permission' => Permission::TimesheetValidate,
        'reason' => 'Dérogation historique de test.',
        'granted_at' => now()->subDay(),
        'expires_at' => now()->addDay(),
    ]);
    $chef->refresh();

    expect($chef->hasPermission(Permission::TimesheetValidate))->toBeTrue()
        ->and($chef->hasPermission(Permission::TimesheetOverlapReview))->toBeFalse();

    expect(fn (): TimesheetOverlapReview => $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié.',
        $chef,
    ))->toThrow(AuthorizationException::class);

    expect(TimesheetOverlapReview::query()->count())->toBe(0)
        ->and(AuditEvent::query()->whereIn('action', [
            AuditAction::TimesheetOverlapReviewAccepted->value,
            AuditAction::TimesheetOverlapReviewRejected->value,
        ])->count())->toBe(0);
});

it('refuses an overlap review outside the control and ready states', function (): void {
    $weeks = ['2026-11-02', '2026-11-09', '2026-11-16'];
    $statuses = [TimesheetStatus::Brouillon, TimesheetStatus::ACorriger, TimesheetStatus::Validee];

    foreach ($statuses as $index => $status) {
        $week = $weeks[$index];
        $timesheet = TimesheetFixtures::timesheet($this->owner, week: $week, status: $status);
        [$first, $second] = pc132AddOverlap($timesheet, $week);

        expect(fn (): TimesheetOverlapReview => $this->reviews->review(
            $timesheet,
            $first->id,
            $second->id,
            OverlapReviewDecision::Accepted,
            'Chevauchement justifié.',
            $this->bureau,
        ))->toThrow(AuthorizationException::class);
    }

    expect(TimesheetOverlapReview::query()->count())->toBe(0);
});

it('records an accepted decision with its normalized reason, cycle and fingerprint', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $review = $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        '  Déplacement justifié par le chantier.  ',
        $this->bureau,
    );

    $event = AuditEvent::query()
        ->where('action', AuditAction::TimesheetOverlapReviewAccepted->value)
        ->firstOrFail();

    expect($review->decision)->toBe(OverlapReviewDecision::Accepted)
        ->and($review->reason)->toBe('Déplacement justifié par le chantier.')
        ->and($review->cycle_sequence)->toBe(0)
        ->and($event->actor_id)->toBe($this->bureau->getKey())
        ->and($event->reason)->toBe('Déplacement justifié par le chantier.')
        ->and($event->changes['decision'] ?? null)->toBe('accepted')
        ->and($event->changes['pair_key'] ?? null)->toBe($review->pair_key)
        ->and($event->changes['fingerprint'] ?? null)->toBe($review->fingerprint)
        ->and($event->changes['cycle_sequence'] ?? null)->toBe(0);
});

it('records a rejected decision as a distinct audit event', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $review = $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Rejected,
        'Horaires incompatibles avec le chantier.',
        $this->bureau,
    );

    expect($review->decision)->toBe(OverlapReviewDecision::Rejected)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetOverlapReviewRejected->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->where('actor_id', $this->bureau->getKey())
            ->exists())->toBeTrue()
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetOverlapReviewAccepted->value)
            ->count())->toBe(0);
});

it('rolls back the overlap review when the audit fails', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    config(['planning.audit.key' => 'trop-court']);

    expect(fn () => $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Déplacement justifié par le chantier.',
        $this->bureau,
    ))->toThrow(RuntimeException::class, 'PLANNING_AUDIT_KEY');

    expect(TimesheetOverlapReview::query()->count())->toBe(0)
        ->and(AuditEvent::query()
            ->whereIn('action', [
                AuditAction::TimesheetOverlapReviewAccepted->value,
                AuditAction::TimesheetOverlapReviewRejected->value,
            ])
            ->count())->toBe(0);
});

it('refuses every invalid review reason without side effects', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    foreach (['', '123456789', str_repeat('a', 501)] as $reason) {
        expect(fn (): TimesheetOverlapReview => $this->reviews->review(
            $timesheet,
            $first->id,
            $second->id,
            OverlapReviewDecision::Accepted,
            $reason,
            $this->bureau,
        ))->toThrow(ValidationException::class);
    }

    expect(TimesheetOverlapReview::query()->count())->toBe(0)
        ->and(AuditEvent::query()->whereIn('action', [
            AuditAction::TimesheetOverlapReviewAccepted->value,
            AuditAction::TimesheetOverlapReviewRejected->value,
        ])->count())->toBe(0);
});

it('blocks validation while an overlap is not reviewed', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    pc132AddOverlap($timesheet);

    expect(fn (): Timesheet => $this->validation->validate($timesheet, $this->secondBureau))
        ->toThrow(ValidationException::class, 'non examiné');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('blocks validation while an overlap is rejected', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Rejected,
        'Horaires incompatibles avec le chantier.',
        $this->bureau,
    );

    expect(fn (): Timesheet => $this->validation->validate($timesheet, $this->secondBureau))
        ->toThrow(ValidationException::class, 'rejeté');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('allows validation after every overlap is accepted', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié par le chantier.',
        $this->bureau,
    );

    expect($this->validation->validate($timesheet, $this->secondBureau)->status)
        ->toBe(TimesheetStatus::Validee);
});

it('keeps an accepted review valid when the timesheet becomes ready for validation', function (): void {
    $timesheet = pc132ReviewableTimesheet(
        $this->owner,
        $this->ownerUser,
        $this->chefUser,
        TimesheetStatus::AControler,
    );
    [$first, $second] = pc132AddOverlap($timesheet);

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié par le chantier.',
        $this->bureau,
    );

    $this->controls->markReadyForValidation(
        $timesheet->fresh() ?? $timesheet,
        'Contrôle satisfaisant.',
        $this->chefUser,
    );

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and($this->validation->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau)->status)
        ->toBe(TimesheetStatus::Validee);
});

it('blocks validation when a segment changed after the review', function (): void {
    $timesheet = pc132ReviewableTimesheet($this->owner, $this->ownerUser, $this->chefUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié par le chantier.',
        $this->bureau,
    );

    // Modification hors modèle (import ou correction directe en base) : elle
    // doit invalider l'empreinte sans passer par le service de saisie.
    DB::table('timesheet_segments')->where('id', $second->getKey())->update([
        'start_at' => pc132OverlapInstant('2026-11-02 11:30:00')->utc()->format('Y-m-d H:i:s'),
        'end_at' => pc132OverlapInstant('2026-11-02 13:30:00')->utc()->format('Y-m-d H:i:s'),
    ]);

    expect(fn (): Timesheet => $this->validation->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau))
        ->toThrow(ValidationException::class, 'modifié');

    expect($timesheet->fresh()?->status)->toBe(TimesheetStatus::PretePourValidation)
        ->and(AuditEvent::query()->where('action', AuditAction::TimesheetValidated->value)->count())->toBe(0);
});

it('still requires an overlap review before validating a direct Bureau transmission', function (): void {
    $timesheet = TimesheetFixtures::timesheet($this->owner);
    TimesheetFixtures::audit($timesheet, AuditAction::TimesheetCreated, $this->ownerUser);
    [$first, $second] = pc132AddOverlap($timesheet);

    $this->controls->sendToValidation(
        $timesheet,
        'Correction effectuée par le Bureau.',
        $this->bureau,
    );

    expect(fn (): Timesheet => $this->validation->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau))
        ->toThrow(ValidationException::class, 'non examiné');

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié par le chantier.',
        $this->bureau,
    );

    expect($this->validation->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau)->status)
        ->toBe(TimesheetStatus::Validee);
});

it('requires a new review after a correction cycle even when segments are unchanged', function (): void {
    $timesheet = pc132ReviewableTimesheet(
        $this->owner,
        $this->ownerUser,
        $this->chefUser,
        TimesheetStatus::AControler,
    );
    [$first, $second] = pc132AddOverlap($timesheet);

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié par le chantier.',
        $this->bureau,
    );

    $this->controls->requestCorrection(
        $timesheet->fresh() ?? $timesheet,
        'Correction demandée après contrôle.',
        $this->chefUser,
    );
    $this->controls->resumeCorrection($timesheet->fresh() ?? $timesheet, $this->ownerUser);
    $this->submissions->submit($timesheet->fresh() ?? $timesheet, $this->ownerUser);
    $this->controls->markReadyForValidation(
        $timesheet->fresh() ?? $timesheet,
        'Contrôle satisfaisant après correction.',
        $this->chefUser,
    );

    expect(fn (): Timesheet => $this->validation->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau))
        ->toThrow(ValidationException::class, 'nouveau cycle');

    $this->reviews->review(
        $timesheet->fresh() ?? $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement confirmé après correction.',
        $this->bureau,
    );

    expect($this->validation->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau)->status)
        ->toBe(TimesheetStatus::Validee)
        ->and(TimesheetOverlapReview::query()->count())->toBe(1)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetOverlapReviewAccepted->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(2);
});

it('does not require a new review when the correction removed the overlap', function (): void {
    $timesheet = pc132ReviewableTimesheet(
        $this->owner,
        $this->ownerUser,
        $this->chefUser,
        TimesheetStatus::AControler,
    );
    [$first, $second] = pc132AddOverlap($timesheet);

    $this->reviews->review(
        $timesheet,
        $first->id,
        $second->id,
        OverlapReviewDecision::Accepted,
        'Chevauchement justifié par le chantier.',
        $this->bureau,
    );

    $this->controls->requestCorrection(
        $timesheet->fresh() ?? $timesheet,
        'Correction demandée après contrôle.',
        $this->chefUser,
    );
    $this->controls->resumeCorrection($timesheet->fresh() ?? $timesheet, $this->ownerUser);

    $this->segments->update(
        $second->fresh() ?? $second,
        [
            'category' => TimeCategory::TravailEffectif,
            'start_at' => pc132OverlapInstant('2026-11-03 08:00:00'),
            'end_at' => pc132OverlapInstant('2026-11-03 12:00:00'),
        ],
        $this->ownerUser,
    );

    $this->submissions->submit($timesheet->fresh() ?? $timesheet, $this->ownerUser);
    $this->controls->markReadyForValidation(
        $timesheet->fresh() ?? $timesheet,
        'Contrôle satisfaisant après correction.',
        $this->chefUser,
    );

    expect($this->reviews->overlapsFor($timesheet->fresh() ?? $timesheet))->toBe([])
        ->and($this->validation->validate($timesheet->fresh() ?? $timesheet, $this->secondBureau)->status)
        ->toBe(TimesheetStatus::Validee)
        ->and(AuditEvent::query()
            ->where('action', AuditAction::TimesheetOverlapReviewAccepted->value)
            ->where('subject_id', (string) $timesheet->getKey())
            ->count())->toBe(1);
});
