<?php

namespace App\Enums;

/**
 * Référentiel officiel des statuts de chantier et transitions autorisées.
 *
 * La colonne `sites.status` reste une chaîne courte pour préserver les données
 * historiques ; les valeurs autorisées pour toute nouvelle écriture sont celles
 * de cet enum. Les libellés historiques connus sont normalisés par migration.
 */
enum SiteStatus: string
{
    case APreparer = 'a_preparer';
    case Planifie = 'planifie';
    case EnCours = 'en_cours';
    case Suspendu = 'suspendu';
    case EnReception = 'en_reception';
    case Termine = 'termine';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::APreparer => 'À préparer',
            self::Planifie => 'Planifié',
            self::EnCours => 'En cours',
            self::Suspendu => 'Suspendu',
            self::EnReception => 'En réception',
            self::Termine => 'Terminé',
            self::Annule => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::APreparer => 'gray',
            self::Planifie => 'info',
            self::EnCours => 'primary',
            self::Suspendu => 'warning',
            self::EnReception => 'info',
            self::Termine => 'success',
            self::Annule => 'danger',
        };
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

    /**
     * Transitions ordinaires. La réouverture d'un statut terminal est une
     * action distincte, protégée par `sites.reopen`.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::APreparer => [self::Planifie, self::Annule],
            self::Planifie => [self::EnCours, self::Suspendu, self::Annule],
            self::EnCours => [self::Suspendu, self::EnReception, self::Annule],
            self::Suspendu => [self::Planifie, self::EnCours, self::Annule],
            self::EnReception => [self::EnCours, self::Termine],
            self::Termine, self::Annule => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Termine, self::Annule], true);
    }

    /**
     * Statuts opérationnels proposés lors d'une réouverture exceptionnelle.
     *
     * Un chantier terminé peut repartir en réception, en cours ou être
     * replanifié. Un chantier annulé repart de la préparation, de la
     * planification ou directement en cours selon la situation réelle.
     *
     * @return array<int, self>
     */
    public function reopenTargets(): array
    {
        return match ($this) {
            self::Termine => [self::EnReception, self::EnCours, self::Planifie],
            self::Annule => [self::APreparer, self::Planifie, self::EnCours],
            default => [],
        };
    }
}
