<?php

namespace App\Services\Clients;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClientService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data, User $actor): Client
    {
        Gate::forUser($actor)->authorize(Permission::ClientsManage->value);
        $data = $this->validated($data);

        return DB::transaction(function () use ($data, $actor): Client {
            $client = Client::query()->create($data);
            $this->audit->record(AuditAction::ClientCreated, $client, ['client_id' => $client->getKey()], null, $actor);
            return $client;
        });
    }

    public function update(Client $client, array $data, User $actor): Client
    {
        Gate::forUser($actor)->authorize(Permission::ClientsManage->value);
        $data = $this->validated($data);

        return DB::transaction(function () use ($client, $data, $actor): Client {
            $client = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $changed = [];
            foreach (array_keys($data) as $key) {
                if ($client->getAttribute($key) !== $data[$key]) $changed[] = $key;
            }
            if ($changed !== []) {
                $client->fill($data)->save();
                $this->audit->record(AuditAction::ClientUpdated, $client, ['changed_fields' => $changed], null, $actor);
            }
            return $client;
        });
    }

    private function validated(array $data): array
    {
        return validator($data, [
            'company_name' => ['required', 'string', 'max:190'],
            'address' => ['required', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:254'],
        ])->validate();
    }
}
