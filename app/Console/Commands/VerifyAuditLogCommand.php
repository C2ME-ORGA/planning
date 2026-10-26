<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

class VerifyAuditLogCommand extends Command
{
    protected $signature = 'planning:audit:verify';

    protected $description = 'Vérifie la chaîne d’intégrité du journal d’audit.';

    public function handle(AuditLogger $audit): int
    {
        $result = $audit->verify();

        if (! $result->isValid) {
            $this->error($result->brokenAt === null ? 'Configuration d’audit invalide.' : 'Journal d’audit altéré.');
            $this->line("Événements vérifiés : {$result->checked}");

            if ($result->brokenAt !== null) {
                $this->line("Premier événement incohérent : #{$result->brokenAt}");
            }

            $this->line((string) $result->message);

            return self::FAILURE;
        }

        $this->info("Journal d’audit intègre : {$result->checked} événement(s) vérifié(s).");
        $this->line('Clé d’audit courante : '.config('planning.audit.key_id'));

        return self::SUCCESS;
    }
}
