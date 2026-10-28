<?php

use App\Enums\TimesheetStatus;

it('keeps the five workflow states', function (): void {
    expect(TimesheetStatus::cases())->toHaveCount(5)
        ->and(array_map(static fn (TimesheetStatus $status): string => $status->value, TimesheetStatus::cases()))
        ->toBe(['brouillon', 'a_controler', 'a_corriger', 'prete_pour_validation', 'validee']);
});

it('allows only the six reference transitions and forbids all others', function (): void {
    $expected = [
        'brouillon' => ['a_controler'],
        'a_controler' => ['a_corriger', 'prete_pour_validation'],
        'a_corriger' => ['brouillon'],
        'prete_pour_validation' => ['validee'],
        'validee' => ['brouillon'],
    ];

    foreach (TimesheetStatus::cases() as $from) {
        $allowed = array_map(
            static fn (TimesheetStatus $status): string => $status->value,
            $from->allowedTransitions(),
        );

        expect($allowed)->toBe($expected[$from->value]);

        foreach (TimesheetStatus::cases() as $target) {
            expect($from->canTransitionTo($target))
                ->toBe(in_array($target->value, $expected[$from->value], true));
        }
    }
});

it('allows no self transition', function (): void {
    foreach (TimesheetStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

it('declares only the draft as editable', function (): void {
    foreach (TimesheetStatus::cases() as $status) {
        expect($status->isEditable())->toBe($status === TimesheetStatus::Brouillon)
            ->and($status->isLocked())->toBe($status !== TimesheetStatus::Brouillon);
    }
});

it('requires a reason only for the reference transitions', function (): void {
    $required = [
        'a_controler|a_corriger',
        'a_controler|prete_pour_validation',
        'validee|brouillon',
    ];

    foreach (TimesheetStatus::cases() as $from) {
        foreach (TimesheetStatus::cases() as $target) {
            $key = $from->value.'|'.$target->value;

            expect($from->requiresReasonTo($target))->toBe(in_array($key, $required, true));
        }
    }
});
