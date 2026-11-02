<?php

namespace App\Services\Audit;

use App\Models\AuditEvent;

/**
 * Résultat de la vérification de la chaîne d'audit.
 */
final class AuditVerification
{
    public function __construct(
        public readonly bool $isValid,
        public readonly int $checked,
        public readonly ?int $brokenAt = null,
        public readonly ?string $message = null,
    ) {}

    public static function valid(int $checked): self
    {
        return new self(true, $checked);
    }

    public static function configurationError(string $message): self
    {
        return new self(false, 0, null, $message);
    }

    public static function broken(int $checked, AuditEvent $event, string $message): self
    {
        return new self(false, $checked, $event->id, $message);
    }
}
