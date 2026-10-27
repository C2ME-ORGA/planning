<?php

use App\Enums\AuditAction;
use App\Enums\SiteStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientService;
use App\Services\Sites\SiteService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config([
        'planning.audit.key' => str_repeat('a', 32),
        'planning.audit.key_id' => 'test-key',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clientData(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'C2ME Client Test',
        'contact_name' => null,
        'street' => '10 rue du Test',
        'address_complement' => null,
        'postal_code' => '85600',
        'city' => 'Montaigu-Vendée',
        'country' => 'France',
        'phone' => null,
        'email' => 'contact@c2me-client.example.test',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function siteData(int $clientId, array $overrides = []): array
{
    return array_merge([
        'name' => 'Chantier Test',
        'address' => '2 rue du Chantier, 85600 Montaigu-Vendée',
        'client_id' => $clientId,
        'status' => SiteStatus::EnCours->value,
    ], $overrides);
}

function siteWithStatus(SiteService $service, User $actor, int $clientId, SiteStatus $status, array $overrides = []): Site
{
    $site = $service->create(siteData($clientId, $overrides), $actor);
    $site->forceFill(['status' => $status->value])->save();

    return $site->fresh();
}

it('creates and updates a client with audited changes', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $service = app(ClientService::class);

    $client = $service->create(clientData(), $bureau);
    expect($client->company_name)->toBe('C2ME Client Test')
        ->and($client->country)->toBe('France')
        ->and($client->fullAddress())->toContain('10 rue du Test');

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::ClientCreated->value,
        'subject_id' => (string) $client->id,
        'actor_id' => $bureau->id,
    ]);

    $service->update($client, clientData(['phone' => '0251000000']), $bureau);
    expect($client->fresh()->phone)->toBe('0251000000');

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::ClientUpdated->value,
        'subject_id' => (string) $client->id,
        'actor_id' => $bureau->id,
    ]);
});

it('requires server-side permission to manage clients', function (): void {
    $worker = User::factory()->ouvrier()->create();

    expect(fn () => app(ClientService::class)->create(clientData(), $worker))
        ->toThrow(AuthorizationException::class);
});

it('refuses an unauthorized client update without changing the data', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $worker = User::factory()->ouvrier()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);

    expect(fn () => app(ClientService::class)->update(
        $client,
        clientData(['company_name' => 'Modification interdite']),
        $worker,
    ))->toThrow(AuthorizationException::class);

    expect($client->fresh()->company_name)->toBe('C2ME Client Test');
});

it('validates the required client data and email server-side', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $service = app(ClientService::class);

    expect(fn () => $service->create(clientData(['email' => '']), $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(clientData(['email' => 'adresse-invalide']), $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(clientData(['street' => '']), $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(clientData(['postal_code' => '']), $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(clientData(['city' => '']), $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->create(clientData(['country' => '']), $bureau))
        ->toThrow(ValidationException::class);
});

it('normalizes the client email for storage and duplicate comparison', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(
        clientData(['email' => '  Contact@C2ME-Client.Example.Test  ']),
        $bureau,
    );

    expect($client->fresh()->email)->toBe('contact@c2me-client.example.test');
});

it('accepts common French and international phone formats and rejects invalid values', function (string $phone): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(['phone' => $phone]), $bureau);

    expect($client->fresh()->phone)->toBe($phone);
})->with([
    '0251000000',
    '+33 2 51 00 00 00',
    '+33 (0)2 51 00 00 00',
    '0033 2 51 00 00 00',
    '+1 514 555 0100',
    '+41 44 668 18 00',
]);

it('rejects clearly invalid phone values', function (string $phone): void {
    $bureau = User::factory()->bureauDirection()->create();

    expect(fn () => app(ClientService::class)->create(clientData(['phone' => $phone]), $bureau))
        ->toThrow(ValidationException::class);
})->with([
    'pas un numéro',
    '+33 abc',
    '12',
]);

it('keeps the contact name optional and distinct from the company name', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $service = app(ClientService::class);

    $withoutContact = $service->create(clientData(), $bureau);
    $withContact = $service->create(
        clientData([
            'company_name' => 'Client avec contact',
            'email' => 'autre@c2me-client.example.test',
            'contact_name' => 'Camille Dupont',
            'country' => 'Belgique',
        ]),
        $bureau,
    );

    expect($withoutContact->contact_name)->toBeNull()
        ->and($withContact->contact_name)->toBe('Camille Dupont')
        ->and($withContact->company_name)->not->toBe($withContact->contact_name)
        ->and($withContact->country)->toBe('Belgique');
});

it('flags potential duplicates without blocking creation and excludes the current client', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $service = app(ClientService::class);

    $first = $service->create(clientData(), $bureau);
    $second = $service->create(
        clientData(['company_name' => '  c2me client test  ', 'email' => 'CONTACT@C2ME-CLIENT.EXAMPLE.TEST']),
        $bureau,
    );

    expect(Client::query()->count())->toBe(2)
        ->and($service->duplicatesFor($second, $bureau)->pluck('id')->all())->toBe([$first->id])
        ->and($service->duplicatesFor($first, $bureau)->pluck('id')->all())->toBe([$second->id]);

    $service->update($second, clientData([
        'company_name' => 'Autre raison sociale',
        'email' => 'autre@c2me-client.example.test',
    ]), $bureau);

    expect($service->duplicatesFor($second->fresh(), $bureau))->toBeEmpty();
});

it('requires client view permission to inspect potential duplicates', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $worker = User::factory()->ouvrier()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);

    expect(fn () => app(ClientService::class)->duplicatesFor($client, $worker))
        ->toThrow(AuthorizationException::class);
});

it('requires the selected client and validates required site fields', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $service = app(SiteService::class);
    $client = app(ClientService::class)->create(clientData(), $bureau);

    expect(fn () => $service->create(siteData($client->id, ['name' => '']), $bureau))
        ->toThrow(ValidationException::class);
});

it('forces the initial site status to a preparer', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);

    $site = app(SiteService::class)->create(
        siteData($client->id, ['status' => SiteStatus::Termine->value]),
        $bureau,
    );

    expect($site->fresh()->status)->toBe(SiteStatus::APreparer->value)
        ->and($site->fresh()->statusEnum())->toBe(SiteStatus::APreparer);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::SiteCreated->value,
        'subject_id' => (string) $site->id,
        'actor_id' => $bureau->id,
    ]);
});

it('accepts every allowed ordinary transition', function (SiteStatus $from, SiteStatus $to): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, $from);

    app(SiteService::class)->transition(
        $site,
        $to,
        $from === SiteStatus::EnCours && $to === SiteStatus::Annule ? 'Annulation justifiée.' : null,
        $bureau,
    );

    expect($site->fresh()->status)->toBe($to->value);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::SiteStatusChanged->value,
        'subject_id' => (string) $site->id,
        'actor_id' => $bureau->id,
    ]);
})->with([
    'à préparer vers planifié' => [SiteStatus::APreparer, SiteStatus::Planifie],
    'à préparer vers annulé' => [SiteStatus::APreparer, SiteStatus::Annule],
    'planifié vers en cours' => [SiteStatus::Planifie, SiteStatus::EnCours],
    'planifié vers suspendu' => [SiteStatus::Planifie, SiteStatus::Suspendu],
    'planifié vers annulé' => [SiteStatus::Planifie, SiteStatus::Annule],
    'en cours vers suspendu' => [SiteStatus::EnCours, SiteStatus::Suspendu],
    'en cours vers en réception' => [SiteStatus::EnCours, SiteStatus::EnReception],
    'en cours vers annulé' => [SiteStatus::EnCours, SiteStatus::Annule],
    'suspendu vers planifié' => [SiteStatus::Suspendu, SiteStatus::Planifie],
    'suspendu vers en cours' => [SiteStatus::Suspendu, SiteStatus::EnCours],
    'suspendu vers annulé' => [SiteStatus::Suspendu, SiteStatus::Annule],
    'en réception vers en cours' => [SiteStatus::EnReception, SiteStatus::EnCours],
    'en réception vers terminé' => [SiteStatus::EnReception, SiteStatus::Termine],
]);

it('refuses every forbidden ordinary transition', function (SiteStatus $from, SiteStatus $to): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, $from);

    expect(fn () => app(SiteService::class)->transition($site, $to, 'Motif fourni.', $bureau))
        ->toThrow(ValidationException::class);

    expect($site->fresh()->status)->toBe($from->value);
})->with([
    'à préparer vers en cours' => [SiteStatus::APreparer, SiteStatus::EnCours],
    'planifié vers terminé' => [SiteStatus::Planifie, SiteStatus::Termine],
    'en cours vers planifié' => [SiteStatus::EnCours, SiteStatus::Planifie],
    'en réception vers annulé' => [SiteStatus::EnReception, SiteStatus::Annule],
    'terminé vers planifié sans réouverture' => [SiteStatus::Termine, SiteStatus::Planifie],
    'annulé vers en cours sans réouverture' => [SiteStatus::Annule, SiteStatus::EnCours],
]);

it('requires a reason to cancel a site in progress', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::EnCours);

    expect(fn () => app(SiteService::class)->transition($site, SiteStatus::Annule, '   ', $bureau))
        ->toThrow(ValidationException::class);

    expect($site->fresh()->status)->toBe(SiteStatus::EnCours->value);
});

it('cancels a site in progress with a reason kept in the audit trail', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::EnCours);

    app(SiteService::class)->transition($site, SiteStatus::Annule, 'Arrêt demandé par le client.', $bureau);

    expect($site->fresh()->status)->toBe(SiteStatus::Annule->value);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::SiteStatusChanged->value,
        'subject_id' => (string) $site->id,
        'actor_id' => $bureau->id,
        'reason' => 'Arrêt demandé par le client.',
    ]);

    $event = AuditEvent::query()
        ->where('action', AuditAction::SiteStatusChanged->value)
        ->where('subject_id', (string) $site->id)
        ->firstOrFail();

    expect($event->changes)->toMatchArray([
        'from' => SiteStatus::EnCours->value,
        'to' => SiteStatus::Annule->value,
    ]);
});

it('refuses to reopen a terminal site without the dedicated permission', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $worker = User::factory()->ouvrier()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::Termine);

    expect(fn () => app(SiteService::class)->reopen($site, SiteStatus::EnCours, 'Reprise du chantier.', $worker))
        ->toThrow(AuthorizationException::class);

    expect($site->fresh()->status)->toBe(SiteStatus::Termine->value);
});

it('requires a reason and a compatible target to reopen a terminal site', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::Termine);

    expect(fn () => app(SiteService::class)->reopen($site, SiteStatus::EnCours, '', $bureau))
        ->toThrow(ValidationException::class);

    expect(fn () => app(SiteService::class)->reopen($site, SiteStatus::Annule, 'Motif suffisant.', $bureau))
        ->toThrow(ValidationException::class);

    expect($site->fresh()->status)->toBe(SiteStatus::Termine->value);
});

it('reopens a terminal site with an audited reason and operational target', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::Termine);

    app(SiteService::class)->reopen($site, SiteStatus::EnCours, 'Travaux complémentaires demandés.', $bureau);

    expect($site->fresh()->status)->toBe(SiteStatus::EnCours->value);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::SiteReopened->value,
        'subject_id' => (string) $site->id,
        'actor_id' => $bureau->id,
        'reason' => 'Travaux complémentaires demandés.',
    ]);

    $event = AuditEvent::query()
        ->where('action', AuditAction::SiteReopened->value)
        ->where('subject_id', (string) $site->id)
        ->firstOrFail();

    expect($event->changes)->toMatchArray([
        'from' => SiteStatus::Termine->value,
        'to' => SiteStatus::EnCours->value,
    ]);
});

it('refuses any transition when the stored status is unknown', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = app(SiteService::class)->create(siteData($client->id), $bureau);
    $site->forceFill(['status' => 'statut_historique_inconnu'])->save();

    expect(fn () => app(SiteService::class)->transition($site->fresh(), SiteStatus::Planifie, null, $bureau))
        ->toThrow(ValidationException::class);

    expect($site->fresh()->status)->toBe('statut_historique_inconnu');
});

it('refuses to reopen a site that is not terminal', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::EnCours);

    expect(fn () => app(SiteService::class)->reopen($site, SiteStatus::Planifie, 'Motif suffisant.', $bureau))
        ->toThrow(ValidationException::class);
});

it('updates an active site and records the audit trail', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = app(SiteService::class)->create(siteData($client->id), $bureau);

    app(SiteService::class)->update($site, siteData($client->id, ['name' => 'Chantier modifié']), $bureau);

    expect($site->fresh()->name)->toBe('Chantier modifié');

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::SiteUpdated->value,
        'subject_id' => (string) $site->id,
        'actor_id' => $bureau->id,
    ]);
});

it('archives a site without changing its status, deleting it or breaking its client relationship', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::EnCours);

    app(SiteService::class)->archive($site, $bureau);

    expect($site->fresh()->isArchived())->toBeTrue()
        ->and($site->fresh()->status)->toBe(SiteStatus::EnCours->value)
        ->and($site->fresh()->client->is($client))->toBeTrue()
        ->and(Site::query()->whereKey($site->id)->exists())->toBeTrue();

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::SiteArchived->value,
        'subject_id' => (string) $site->id,
        'actor_id' => $bureau->id,
    ]);
});

it('refuses updates, transitions and reopening of an archived site without changing the data', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::EnCours);
    app(SiteService::class)->archive($site, $bureau);

    expect(fn () => app(SiteService::class)->update($site, siteData($client->id, ['name' => 'Changement']), $bureau))
        ->toThrow(ValidationException::class);
    expect(fn () => app(SiteService::class)->transition($site, SiteStatus::Suspendu, null, $bureau))
        ->toThrow(ValidationException::class);

    expect($site->fresh()->name)->toBe('Chantier Test')
        ->and($site->fresh()->status)->toBe(SiteStatus::EnCours->value);
});

it('refuses to attach a site to an archived client', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    app(ClientService::class)->archive($client, $bureau);

    expect(fn () => app(SiteService::class)->create(siteData($client->id), $bureau))
        ->toThrow(ValidationException::class);

    expect(Site::query()->count())->toBe(0);
});

it('refuses to archive a client while one of its sites is active, even archived', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::EnCours);
    app(SiteService::class)->archive($site, $bureau);

    expect(fn () => app(ClientService::class)->archive($client, $bureau))
        ->toThrow(ValidationException::class);

    expect($client->fresh()->isArchived())->toBeFalse();
});

it('archives a client when all of its sites are finished or cancelled', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::Termine, ['name' => 'Terminé']);
    siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::Annule, ['name' => 'Annulé']);
    $archived = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::Termine, ['name' => 'Archivé']);
    app(SiteService::class)->archive($archived, $bureau);

    app(ClientService::class)->archive($client, $bureau);

    expect($client->fresh()->isArchived())->toBeTrue();

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::ClientArchived->value,
        'subject_id' => (string) $client->id,
        'actor_id' => $bureau->id,
    ]);
});

it('keeps client archiving and restoration independent from its sites', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = siteWithStatus(app(SiteService::class), $bureau, $client->id, SiteStatus::Termine);
    app(SiteService::class)->archive($site, $bureau);

    app(ClientService::class)->archive($client, $bureau);

    expect($client->fresh()->isArchived())->toBeTrue()
        ->and($site->fresh()->isArchived())->toBeTrue();

    app(ClientService::class)->restore($client->fresh(), $bureau);

    expect($client->fresh()->isArchived())->toBeFalse()
        ->and($site->fresh()->isArchived())->toBeTrue()
        ->and($site->fresh()->status)->toBe(SiteStatus::Termine->value);

    $this->assertDatabaseHas('audit_events', [
        'action' => AuditAction::ClientRestored->value,
        'subject_id' => (string) $client->id,
        'actor_id' => $bureau->id,
    ]);
});

it('requires the dedicated permission to restore an archived client', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $worker = User::factory()->ouvrier()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    app(ClientService::class)->archive($client, $bureau);

    expect(fn () => app(ClientService::class)->restore($client->fresh(), $worker))
        ->toThrow(AuthorizationException::class);

    expect($client->fresh()->isArchived())->toBeTrue();
});

it('refuses to update an archived client', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    app(ClientService::class)->archive($client, $bureau);

    expect(fn () => app(ClientService::class)->update($client->fresh(), clientData(['company_name' => 'Modifié']), $bureau))
        ->toThrow(ValidationException::class);

    expect($client->fresh()->company_name)->toBe('C2ME Client Test')
        ->and($client->fresh()->isArchived())->toBeTrue();
});

it('does not grant client or site management to the technical administrator', function (): void {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(ClientService::class)->create(clientData(), $admin))
        ->toThrow(AuthorizationException::class);
});

it('denies physical deletion of clients and sites in every supported path', function (): void {
    $bureau = User::factory()->bureauDirection()->create();
    $client = app(ClientService::class)->create(clientData(), $bureau);
    $site = app(SiteService::class)->create(siteData($client->id), $bureau);

    expect($bureau->can('delete', $client))->toBeFalse()
        ->and($bureau->can('delete', $site))->toBeFalse()
        ->and($bureau->can('deleteAny', $client))->toBeFalse()
        ->and($bureau->can('deleteAny', $site))->toBeFalse()
        ->and($bureau->can('forceDelete', $client))->toBeFalse()
        ->and($bureau->can('forceDelete', $site))->toBeFalse();

    expect(fn () => $client->delete())->toThrow(LogicException::class);
    expect(fn () => $site->delete())->toThrow(LogicException::class);
});
