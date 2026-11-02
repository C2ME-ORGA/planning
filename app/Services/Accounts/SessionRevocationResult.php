<?php

namespace App\Services\Accounts;

/**
 * Résultat d'une révocation logique et de son éventuel nettoyage physique.
 *
 * La révocation logique (version utilisateur ou génération globale) est
 * toujours acquise lorsqu'un résultat est retourné : une exception est levée
 * si elle échoue. Le nettoyage physique des sessions est secondaire et peut
 * échouer sans remettre en cause l'invalidation logique.
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

    public function isPhysicalCleanupComplete(): bool
    {
        return ! $this->physicalCleanupRequired || $this->physicalError === null;
    }
}
