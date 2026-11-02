<?php

namespace App\Services\Accounts;

use App\Enums\SessionCleanupState;

/**
 * Résultat d'une révocation logique et de son éventuel nettoyage physique.
 *
 * La révocation logique est toujours acquise lorsqu'un résultat est retourné.
 * Le nettoyage physique est distingué explicitement : effectué, requis mais
 * échoué, ou non applicable au pilote de session configuré.
 */
final readonly class SessionRevocationResult
{
    public function __construct(
        public string $scope,
        public int $generation,
        public int $deletedSessions,
        public string $sessionDriver,
        public bool $physicalCleanupRequired,
        public ?string $physicalError,
    ) {}

    public function physicalCleanupState(): SessionCleanupState
    {
        if (! $this->physicalCleanupRequired) {
            return SessionCleanupState::NotApplicable;
        }

        return $this->physicalError === null
            ? SessionCleanupState::Completed
            : SessionCleanupState::Failed;
    }

    public function isPhysicalCleanupComplete(): bool
    {
        return $this->physicalCleanupState() === SessionCleanupState::Completed;
    }

    public function hasPhysicalCleanupFailure(): bool
    {
        return $this->physicalCleanupState() === SessionCleanupState::Failed;
    }
}
