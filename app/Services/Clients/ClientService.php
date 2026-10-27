<?php

namespace App\Services\Clients;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\SiteStatus;
use App\Models\Client;
use App\Models\User;
use App\Rules\ValidPhoneNumber;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClientService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
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

    /** @param array<string, mixed> $data */
    public function update(Client $client, array $data, User $actor): Client
    {
        Gate::forUser($actor)->authorize(Permission::ClientsManage->value);
        $data = $this->validated($data);

        return DB::transaction(function () use ($client, $data, $actor): Client {
            $client = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            if ($client->isArchived()) {
                throw ValidationException::withMessages([
                    'client' => 'Un client archivé ne peut pas être modifié. Restaurez-le d’abord.',
                ]);
            }

            $changed = [];
            foreach (array_keys($data) as $key) {
                if ($client->getAttribute($key) !== $data[$key]) {
                    $changed[] = $key;
                }
            }
            if ($changed !== []) {
                $client->fill($data)->save();
                $this->audit->record(AuditAction::ClientUpdated, $client, ['changed_fields' => $changed], null, $actor);
            }

            return $client;
        });
    }

    public function archive(Client $client, User $actor): Client
    {
        Gate::forUser($actor)->authorize(Permission::ClientsManage->value);

        return DB::transaction(function () use ($client, $actor): Client {
            // Le verrou sérialise l'archivage avec la création d'un chantier,
            // qui verrouille également le client avant d'écrire.
            $client = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            if ($client->isArchived()) {
                return $client;
            }

            $blocking = $client->sites()
                ->whereNotIn('status', [SiteStatus::Termine->value, SiteStatus::Annule->value])
                ->orderBy('name')
                ->get(['id', 'name']);

            if ($blocking->isNotEmpty()) {
                $listed = $blocking->take(5)->pluck('name')->implode(', ');
                $remaining = $blocking->count() - 5;

                throw ValidationException::withMessages([
                    'client' => sprintf(
                        'Archivage refusé : %d chantier(s) de ce client ne sont ni terminés ni annulés (%s%s).',
                        $blocking->count(),
                        $listed,
                        $remaining > 0 ? sprintf(', et %d autre(s)', $remaining) : '',
                    ),
                ]);
            }

            $archivedAt = now();
            $client->forceFill(['archived_at' => $archivedAt])->save();
            $this->audit->record(AuditAction::ClientArchived, $client, [
                'archived_at' => $archivedAt->toIso8601String(),
            ], null, $actor);

            return $client;
        });
    }

    public function restore(Client $client, User $actor): Client
    {
        Gate::forUser($actor)->authorize(Permission::ClientsRestore->value);

        return DB::transaction(function () use ($client, $actor): Client {
            $client = Client::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            if (! $client->isArchived()) {
                return $client;
            }

            $client->forceFill(['archived_at' => null])->save();
            $this->audit->record(AuditAction::ClientRestored, $client, ['archived_at' => null], null, $actor);

            return $client;
        });
    }

    /**
     * Doublons potentiels, non bloquants, visibles par un acteur autorisé à
     * consulter les clients. Le client courant est exclu lors d'une modification.
     *
     * @return Collection<int, Client>
     */
    public function duplicatesFor(Client $client, User $actor): Collection
    {
        Gate::forUser($actor)->authorize(Permission::ClientsView->value);

        return $this->findDuplicates(
            (string) $client->company_name,
            (string) $client->email,
            $client->getKey(),
        );
    }

    /**
     * @return Collection<int, Client>
     */
    private function findDuplicates(string $companyName, string $email, ?int $exceptClientId): Collection
    {
        $companyName = mb_strtolower(trim($companyName));
        $email = mb_strtolower(trim($email));

        $query = Client::query();

        if ($exceptClientId !== null) {
            $query->whereKeyNot($exceptClientId);
        }

        $query->where(function (Builder $query) use ($companyName, $email): void {
            if ($companyName !== '') {
                $query->whereRaw('LOWER(TRIM(company_name)) = ?', [$companyName]);
            }

            if ($email !== '') {
                $companyName === ''
                    ? $query->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                    : $query->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
            }
        });

        return $query->orderBy('company_name')->limit(10)->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $data = $this->normalized($data);

        return validator($data, [
            'company_name' => ['required', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'street' => ['required', 'string', 'max:255'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z \-]{0,19}$/'],
            'city' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40', new ValidPhoneNumber],
            'email' => ['required', 'string', 'email', 'max:254'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data): array
    {
        $nullable = ['contact_name', 'address_complement', 'phone', 'email'];

        foreach (['company_name', 'contact_name', 'street', 'address_complement', 'postal_code', 'city', 'country', 'phone', 'email'] as $key) {
            if (! array_key_exists($key, $data) || ! is_string($data[$key])) {
                continue;
            }

            $data[$key] = trim($data[$key]);

            if (in_array($key, $nullable, true) && $data[$key] === '') {
                $data[$key] = null;
            }
        }

        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }

        return $data;
    }
}
