<?php

namespace App\Enums;

/**
 * États métier d'un relevé d'heures.
 *
 * Le référentiel du Lot 5 ajoute le workflow dans un incrément ultérieur ; cet
 * enum fixe uniquement les valeurs stockées et leurs libellés.
 */
enum TimesheetStatus: string
{
    case Brouillon = 'brouillon';
    case AControler = 'a_controler';
    case ACorriger = 'a_corriger';
    case PretePourValidation = 'prete_pour_validation';
    case Validee = 'validee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::AControler => 'À contrôler',
            self::ACorriger => 'À corriger',
            self::PretePourValidation => 'Prête pour validation',
            self::Validee => 'Validée',
        };
    }

    /**
     * Transitions autorisées par la matrice du Lot 5.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Brouillon => [self::AControler],
            self::AControler => [self::ACorriger, self::PretePourValidation],
            self::ACorriger => [self::Brouillon],
            self::PretePourValidation => [self::Validee],
            self::Validee => [self::Brouillon],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Seul un brouillon est éditable comme brouillon. Les autres états sont
     * verrouillés pour l'édition des segments.
     */
    public function isEditable(): bool
    {
        return $this === self::Brouillon;
    }

    public function isLocked(): bool
    {
        return ! $this->isEditable();
    }

    /**
     * Le motif est obligatoire pour les retours en correction, la préparation
     * à la validation et la réouverture d'un relevé validé.
     */
    public function requiresReasonTo(self $target): bool
    {
        if ($this === self::AControler) {
            return in_array($target, [self::ACorriger, self::PretePourValidation], true);
        }

        return $this === self::Validee && $target === self::Brouillon;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
