<?php

use App\Enums\TimeCategory;
use App\Enums\TimesheetStatus;

it('exposes the five allowed categories with labels and options', function (): void {
    expect(TimeCategory::cases())->toHaveCount(5)
        ->and(array_map(static fn (TimeCategory $category): string => $category->value, TimeCategory::cases()))
        ->toBe(['travail_effectif', 'trajet', 'pause', 'formation', 'autre'])
        ->and(TimeCategory::options())->toBe([
            'travail_effectif' => 'Travail effectif',
            'trajet' => 'Trajet',
            'pause' => 'Pause',
            'formation' => 'Formation',
            'autre' => 'Autre',
        ]);
});

it('exposes the five workflow states with labels and options', function (): void {
    expect(TimesheetStatus::cases())->toHaveCount(5)
        ->and(array_map(static fn (TimesheetStatus $status): string => $status->value, TimesheetStatus::cases()))
        ->toBe(['brouillon', 'a_controler', 'a_corriger', 'prete_pour_validation', 'validee'])
        ->and(TimesheetStatus::options())->toBe([
            'brouillon' => 'Brouillon',
            'a_controler' => 'À contrôler',
            'a_corriger' => 'À corriger',
            'prete_pour_validation' => 'Prête pour validation',
            'validee' => 'Validée',
        ]);
});
