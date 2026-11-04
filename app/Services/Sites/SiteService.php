<?php

namespace App\Services\Sites;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SiteStatus;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SiteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);
        $data = $this->validated($data);

        return DB::transaction(function () use ($data, $actor): Site {
            $this->lockAvailableClient((int) $data['client_id']);

            // Le statut initial est imposé par le référentiel, jamais choisi
            // librement par l'appelant.
            $data['status'] = SiteStatus::APreparer->value;
            $site = Site::query()->create($data);
            $this->audit->record(AuditAction::SiteCreated, $site, [
                'site_id' => $site->getKey(),
                'client_id' => $site->client_id,
                'status' => $site->status,
            ], null, $actor);

            return $site;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Site $site, array $data, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);
        $data = $this->validated($data);

        return DB::transaction(function () use ($site, $data, $actor): Site {
            $site = Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            if ($site->isArchived()) {
                throw ValidationException::withMessages([
                    'site' => 'Un chantier archivé ne peut pas être modifié.',
                ]);
            }

            $this->lockAvailableClient((int) $data['client_id']);

            $site->fill($data);
            $changed = array_keys($site->getDirty());

            if ($changed !== []) {
                $site->save();
                $this->audit->record(AuditAction::SiteUpdated, $site, ['changed_fields' => $changed], null, $actor);
            }

            return $site;
        });
    }

    public function transition(Site $site, SiteStatus $target, ?string $reason, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);

        return DB::transaction(function () use ($site, $target, $reason, $actor): Site {
            $site = Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            if ($site->isArchived()) {
                throw ValidationException::withMessages([
                    'site' => 'Un chantier archivé ne peut pas changer de statut.',
                ]);
            }

            $current = $site->statusEnum();

            if ($current === null) {
                throw ValidationException::withMessages([
                    'site' => 'Le statut actuel du chantier n’est pas reconnu ; aucune transition n’est possible.',
                ]);
            }

            if (! in_array($target, $current->allowedTransitions(), true)) {
                throw ValidationException::withMessages([
                    'target' => sprintf(
                        'Transition refusée de « %s » vers « %s ».',
                        $current->label(),
                        $target->label(),
                    ),
                ]);
            }

            $reason = $this->validatedReason(
                $reason,
                $current === SiteStatus::EnCours && $target === SiteStatus::Annule,
            );

            $site->forceFill(['status' => $target->value])->save();
            $this->audit->record(AuditAction::SiteStatusChanged, $site, [
                'from' => $current->value,
                'to' => $target->value,
            ], $reason, $actor);

            return $site;
        });
    }

    public function reopen(Site $site, SiteStatus $target, ?string $reason, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesReopen->value);
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);

        return DB::transaction(function () use ($site, $target, $reason, $actor): Site {
            $site = Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();

            if ($site->isArchived()) {
                throw ValidationException::withMessages([
                    'site' => 'Un chantier archivé ne peut pas être réouvert.',
                ]);
            }

            $current = $site->statusEnum();

            if ($current === null || ! $current->isTerminal()) {
                throw ValidationException::withMessages([
                    'site' => 'Seul un chantier terminé ou annulé peut être réouvert.',
                ]);
            }

            if (! in_array($target, $current->reopenTargets(), true)) {
                throw ValidationException::withMessages([
                    'target' => sprintf(
                        'Réouverture refusée de « %s » vers « %s ».',
                        $current->label(),
                        $target->label(),
                    ),
                ]);
            }

            $reason = $this->validatedReason($reason, true);

            $site->forceFill(['status' => $target->value])->save();
            $this->audit->record(AuditAction::SiteReopened, $site, [
                'from' => $current->value,
                'to' => $target->value,
            ], $reason, $actor);

            return $site;
        });
    }

    public function archive(Site $site, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);

        return DB::transaction(function () use ($site, $actor): Site {
            $site = Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            if (! $site->isArchived()) {
                $archivedAt = now();
                $site->forceFill(['archived_at' => $archivedAt])->save();
                $this->audit->record(AuditAction::SiteArchived, $site, [
                    'archived_at' => $archivedAt->toIso8601String(),
                ], null, $actor);
            }

            return $site;
        });
    }

    private function lockAvailableClient(int $clientId): Client
    {
        $client = Client::query()->whereKey($clientId)->lockForUpdate()->firstOrFail();

        if ($client->isArchived()) {
            throw ValidationException::withMessages([
                'client_id' => 'Un chantier ne peut pas être créé ou rattaché à un client archivé.',
            ]);
        }

        return $client;
    }

    private function validatedReason(?string $reason, bool $required): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        if ($required && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages([
                'reason' => 'Un motif est obligatoire pour cette opération.',
            ]);
        }

        if ($reason !== null && mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => 'Le motif ne peut pas dépasser 500 caractères.',
            ]);
        }

        return $reason === '' ? null : $reason;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        foreach (['name', 'address'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        return validator($data, [
            'name' => ['required', 'string', 'max:190'],
            'address' => ['required', 'string', 'max:500'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ])->validate();
    }
}
