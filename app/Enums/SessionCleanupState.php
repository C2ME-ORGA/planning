<?php

namespace App\Enums;

enum SessionCleanupState: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Nettoyage physique effectué',
            self::Failed => 'Nettoyage physique requis mais échoué',
            self::NotApplicable => 'Nettoyage physique non applicable',
        };
    }
}
