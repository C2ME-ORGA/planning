<?php

use App\Support\TimesheetReason;
use Illuminate\Validation\ValidationException;

it('keeps an optional absent or blank reason null', function (): void {
    expect(TimesheetReason::optional(null))->toBeNull()
        ->and(TimesheetReason::optional(''))->toBeNull()
        ->and(TimesheetReason::optional("  \t\n "))->toBeNull();
});

it('trims surrounding whitespace without altering the text', function (): void {
    expect(TimesheetReason::required('  Correction demandée  '))->toBe('Correction demandée')
        ->and(TimesheetReason::optional('  Contrôle satisfaisant '))->toBe('Contrôle satisfaisant');
});

it('refuses a required reason that is missing or blank', function (): void {
    foreach ([null, '', '   '] as $reason) {
        expect(fn (): string => TimesheetReason::required($reason))
            ->toThrow(ValidationException::class);
    }
});

it('accepts exactly 500 characters and refuses 501', function (): void {
    expect(mb_strlen(TimesheetReason::required(str_repeat('a', 500))))->toBe(500);

    expect(fn (): string => TimesheetReason::required(str_repeat('a', 501)))
        ->toThrow(ValidationException::class);
});

it('counts multibyte characters', function (): void {
    expect(mb_strlen(TimesheetReason::required(str_repeat('é', 500))))->toBe(500);

    expect(fn (): string => TimesheetReason::required(str_repeat('é', 501)))
        ->toThrow(ValidationException::class);
});

it('never invents a default reason', function (): void {
    expect(TimesheetReason::optional(null, 'reason'))->toBeNull();

    expect(fn (): string => TimesheetReason::required(null, 'reason'))
        ->toThrow(ValidationException::class);
});
