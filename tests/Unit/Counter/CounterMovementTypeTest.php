<?php

use App\Enums\CounterAdjustmentDirection;
use App\Enums\CounterMovementType;

it('lists all movement types with labels', function (): void {
    expect(CounterMovementType::options())->toBe([
        'credit' => 'Crédit',
        'usage' => 'Utilisation',
        'payment' => 'Paiement',
        'adjustment' => 'Ajustement',
    ]);
});

it('returns the correct label for each movement type', function (): void {
    expect(CounterMovementType::Credit->label())->toBe('Crédit')
        ->and(CounterMovementType::Usage->label())->toBe('Utilisation')
        ->and(CounterMovementType::Payment->label())->toBe('Paiement')
        ->and(CounterMovementType::Adjustment->label())->toBe('Ajustement');
});

it('lists all adjustment directions with labels', function (): void {
    expect(CounterAdjustmentDirection::options())->toBe([
        'credit' => 'Crédit',
        'debit' => 'Débit',
    ]);
});

it('returns the correct label for each adjustment direction', function (): void {
    expect(CounterAdjustmentDirection::Credit->label())->toBe('Crédit')
        ->and(CounterAdjustmentDirection::Debit->label())->toBe('Débit');
});
