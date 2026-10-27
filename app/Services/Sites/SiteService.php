<?php

namespace App\Services\Sites;

use App\Enums\AuditAction;
use App\Enums\Permission;
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

    public function create(array $data, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);
        $data = $this->validated($data);

        return DB::transaction(function () use ($data, $actor): Site {
            Client::query()->whereKey($data['client_id'])->firstOrFail();
            $site = Site::query()->create($data);
            $this->audit->record(AuditAction::SiteCreated, $site, [
                'site_id' => $site->getKey(), 'client_id' => $site->client_id,
            ], null, $actor);
            return $site;
        });
    }

    public function update(Site $site, array $data, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);
        $data = $this->validated($data);

        return DB::transaction(function () use ($site, $data, $actor): Site {
            $site = Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            if ($site->isArchived()) {
                throw ValidationException::withMessages(['site' => 'Un chantier archivé ne peut pas être modifié.']);
            }
            Client::query()->whereKey($data['client_id'])->firstOrFail();
            $changed = [];
            foreach (array_keys($data) as $key) {
                if ($site->getAttribute($key) !== $data[$key]) $changed[] = $key;
            }
            if ($changed !== []) {
                $site->fill($data)->save();
                $this->audit->record(AuditAction::SiteUpdated, $site, ['changed_fields' => $changed], null, $actor);
            }
            return $site;
        });
    }

    public function archive(Site $site, User $actor): Site
    {
        Gate::forUser($actor)->authorize(Permission::SitesManage->value);

        return DB::transaction(function () use ($site, $actor): Site {
            $site = Site::query()->whereKey($site->getKey())->lockForUpdate()->firstOrFail();
            if (! $site->isArchived()) {
                $site->forceFill(['archived_at' => now()])->save();
                $this->audit->record(AuditAction::SiteArchived, $site, [
                    'archived_at' => $site->archived_at?->toIso8601String(),
                ], null, $actor);
            }
            return $site;
        });
    }

    private function validated(array $data): array
    {
        return validator($data, [
            'name' => ['required', 'string', 'max:190'],
            'address' => ['required', 'string', 'max:500'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'status' => ['required', 'string', 'max:40'],
        ])->validate();
    }
}
