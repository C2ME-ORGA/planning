<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Services\Access\ApprovalRules;
use Illuminate\Validation\ValidationException;
use Tests\Support\TimesheetFixtures;

beforeEach(function (): void {
    $this->rules = app(ApprovalRules::class);
    $this->bureau = TimesheetFixtures::user(Role::BureauDirection);
    $this->owner = TimesheetFixtures::user(Role::Ouvrier);
});

it('records an active Bureau self-approval with a normalized reason', function (): void {
    $event = $this->rules->recordSelfApproval(
        $this->bureau,
        (int) $this->bureau->getKey(),
        $this->owner,
        '  Auto-validation motivée  ',
    );

    expect($event->action)->toBe(AuditAction::SelfApprovalRecorded)
        ->and($event->reason)->toBe('Auto-validation motivée')
        ->and($event->actor_id)->toBe($this->bureau->getKey());
});

it('refuses a self-approval from an inactive account', function (): void {
    $this->bureau->forceFill(['is_active' => false])->save();

    expect(fn (): AuditEvent => $this->rules->recordSelfApproval(
        $this->bureau,
        (int) $this->bureau->getKey(),
        $this->owner,
        'Auto-validation motivée',
    ))->toThrow(RuntimeException::class, 'inactif');

    expect(AuditEvent::query()
        ->where('action', AuditAction::SelfApprovalRecorded->value)
        ->count())->toBe(0);
});

it('refuses a self-approval whose owner is another user', function (): void {
    expect(fn (): AuditEvent => $this->rules->recordSelfApproval(
        $this->bureau,
        (int) $this->owner->getKey(),
        $this->owner,
        'Auto-validation motivée',
    ))->toThrow(RuntimeException::class, 'auto-validation');

    expect(AuditEvent::query()
        ->where('action', AuditAction::SelfApprovalRecorded->value)
        ->count())->toBe(0);
});

it('refuses self-approval to a chef, a conducteur and the administrator', function (): void {
    foreach ([Role::ChefEquipe, Role::Conducteur, Role::Admin] as $role) {
        $actor = TimesheetFixtures::user($role);

        expect(fn (): AuditEvent => $this->rules->recordSelfApproval(
            $actor,
            (int) $actor->getKey(),
            $this->owner,
            'Auto-validation motivée',
        ))->toThrow(RuntimeException::class, 'Bureau');
    }

    expect(AuditEvent::query()
        ->where('action', AuditAction::SelfApprovalRecorded->value)
        ->count())->toBe(0);
});

it('keeps a self-approval reason validation structured', function (string $reason, string $expectedMessage): void {
    $thrown = null;

    try {
        $this->rules->recordSelfApproval(
            $this->bureau,
            (int) $this->bureau->getKey(),
            $this->owner,
            $reason,
        );
    } catch (ValidationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ValidationException::class);

    if (! $thrown instanceof ValidationException) {
        return;
    }

    expect($thrown->status)->toBe(422)
        ->and($thrown->errors())->toHaveKey('reason')
        ->and($thrown->errors()['reason'][0] ?? '')->toContain($expectedMessage);

    expect(AuditEvent::query()
        ->where('action', AuditAction::SelfApprovalRecorded->value)
        ->count())->toBe(0);
})->with([
    'too short' => ['123456789', '10 caractères'],
    'blank' => ['   ', 'motif est obligatoire'],
    'too long' => [str_repeat('a', 501), '500 caractères'],
]);
