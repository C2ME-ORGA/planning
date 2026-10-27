<?php

namespace Database\Seeders;

use App\Enums\SiteStatus;
use App\Models\Client;
use App\Models\Site;
use App\Rules\ValidPhoneNumber;
use App\Support\DemoData\DemoDatasetEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Jeu de démonstration fictif pour la revue visuelle du lot PC1-28.
 *
 * Ce seeder est volontairement séparé de DatabaseSeeder. Il écrit directement
 * via Eloquent, sans passer par ClientService ni SiteService : aucune opération
 * métier ni aucun événement d'audit n'est simulé. Il est additif, idempotent et
 * identifie ses lignes par le suffixe « (démo) ».
 *
 * Les statuts utilisés sont ceux du référentiel officiel `SiteStatus` et les
 * clients disposent des champs structurés arbitrés (adresse, contact, e-mail
 * obligatoire). Les clients de démonstration déjà présents sont complétés sans
 * écraser les valeurs déjà saisies.
 */
class DemoClientsAndSitesSeeder extends Seeder
{
    use WithoutModelEvents;

    public const MARKER = '(démo)';

    public int $clientsCreated = 0;

    public int $clientsExisting = 0;

    public int $sitesCreated = 0;

    public int $sitesExisting = 0;

    public function __construct(private readonly DemoDatasetEnvironment $environment) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    private function clientRules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'address' => ['required', 'string', 'max:500'],
            'street' => ['required', 'string', 'max:255'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40', new ValidPhoneNumber],
            'email' => ['required', 'email', 'max:254'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function siteRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'address' => ['required', 'string', 'max:500'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'status' => ['required', Rule::enum(SiteStatus::class)],
        ];
    }

    public function run(): void
    {
        $this->environment->assertSafe();

        DB::transaction(function (): void {
            foreach (self::dataset() as $entry) {
                $clientAttributes = [
                    'company_name' => $entry['company_name'],
                    'contact_name' => $entry['contact_name'],
                    'address' => $entry['address'],
                    'street' => $entry['street'],
                    'address_complement' => $entry['address_complement'],
                    'postal_code' => $entry['postal_code'],
                    'city' => $entry['city'],
                    'country' => $entry['country'],
                    'phone' => $entry['phone'],
                    'email' => $entry['email'],
                ];

                validator($clientAttributes, $this->clientRules())->validate();

                $client = Client::query()->firstOrCreate(
                    ['company_name' => $clientAttributes['company_name']],
                    $clientAttributes,
                );

                if ($client->wasRecentlyCreated) {
                    $this->clientsCreated++;
                } else {
                    $this->clientsExisting++;
                    $this->completeExistingClient($client, $clientAttributes);
                }

                foreach ($entry['sites'] as $site) {
                    $siteAttributes = [
                        'name' => $site['name'],
                        'address' => $site['address'],
                        'client_id' => $client->getKey(),
                        'status' => $site['status'],
                        'archived_at' => array_key_exists('archived_months_ago', $site)
                            ? CarbonImmutable::now()->subMonths($site['archived_months_ago'])->startOfDay()
                            : null,
                    ];

                    validator($siteAttributes, $this->siteRules())->validate();

                    $record = Site::query()->firstOrCreate(
                        ['client_id' => $client->getKey(), 'name' => $siteAttributes['name']],
                        $siteAttributes,
                    );

                    $record->wasRecentlyCreated
                        ? $this->sitesCreated++
                        : $this->sitesExisting++;
                }
            }
        });
    }

    public static function clientCount(): int
    {
        return count(self::dataset());
    }

    public static function siteCount(): int
    {
        $count = 0;

        foreach (self::dataset() as $entry) {
            $count += count($entry['sites']);
        }

        return $count;
    }

    /**
     * Complète uniquement les champs structurés absents d'un client de
     * démonstration existant, sans écraser une valeur déjà saisie.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function completeExistingClient(Client $client, array $attributes): void
    {
        $missing = [];

        foreach ([
            'contact_name',
            'street',
            'address_complement',
            'postal_code',
            'city',
            'country',
            'phone',
            'email',
        ] as $key) {
            if (blank($client->getAttribute($key)) && filled($attributes[$key] ?? null)) {
                $missing[$key] = $attributes[$key];
            }
        }

        if ($missing !== []) {
            $client->forceFill($missing)->save();
        }
    }

    /**
     * @return array<int, array{
     *     company_name: string,
     *     contact_name: string|null,
     *     address: string,
     *     street: string,
     *     address_complement: string|null,
     *     postal_code: string,
     *     city: string,
     *     country: string,
     *     phone: string|null,
     *     email: string,
     *     sites: array<int, array{
     *         name: string,
     *         address: string,
     *         status: string,
     *         archived_months_ago?: int
     *     }>
     * }>
     */
    private static function dataset(): array
    {
        return [
            [
                'company_name' => 'Atelier du Vieux Canal (démo)',
                'contact_name' => 'Claire Meyer',
                'address' => '12 quai des Tanneurs, 67000 Strasbourg',
                'street' => '12 quai des Tanneurs',
                'address_complement' => null,
                'postal_code' => '67000',
                'city' => 'Strasbourg',
                'country' => 'France',
                'phone' => '+33 3 88 00 00 01',
                'email' => 'contact@atelier-vieux-canal.example.test',
                'sites' => [
                    [
                        'name' => 'Rénovation de l’atelier historique — tranche 1 (démo)',
                        'address' => '12 quai des Tanneurs, 67000 Strasbourg',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Extension du local d’exposition (démo)',
                        'address' => '14 quai des Tanneurs, 67000 Strasbourg',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Reprise de la toiture et des menuiseries (démo)',
                        'address' => '12 quai des Tanneurs, 67000 Strasbourg',
                        'status' => SiteStatus::Termine->value,
                        'archived_months_ago' => 2,
                    ],
                ],
            ],
            [
                'company_name' => 'Boulangerie des Trois Ponts (démo)',
                'contact_name' => 'Sophie Klein',
                'address' => '3 place du Marché, 68000 Colmar',
                'street' => '3 place du Marché',
                'address_complement' => null,
                'postal_code' => '68000',
                'city' => 'Colmar',
                'country' => 'France',
                'phone' => null,
                'email' => 'contact@boulangerie-trois-ponts.example.test',
                'sites' => [
                    [
                        'name' => 'Réaménagement du fournil (démo)',
                        'address' => '3 place du Marché, 68000 Colmar',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Mise aux normes de la vitrine et de l’accès PMR (démo)',
                        'address' => '3 place du Marché, 68000 Colmar',
                        'status' => SiteStatus::EnReception->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Groupe Immobilier Horizon & Associés (démo)',
                'contact_name' => 'Nicolas Bernard',
                'address' => 'Immeuble Le Concorde, 48 avenue de la République, 69003 Lyon',
                'street' => '48 avenue de la République',
                'address_complement' => 'Immeuble Le Concorde',
                'postal_code' => '69003',
                'city' => 'Lyon',
                'country' => 'France',
                'phone' => '+33 4 72 00 00 03',
                'email' => 'contact@horizon-associes.example.test',
                'sites' => [
                    [
                        'name' => 'Construction de la résidence Les Terrasses du Parc — bâtiment A (démo)',
                        'address' => '48 avenue de la République, Immeuble Le Concorde, 69003 Lyon',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Construction de la résidence Les Terrasses du Parc — bâtiment B (démo)',
                        'address' => '48 avenue de la République, Immeuble Le Concorde, 69003 Lyon',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Aménagement des espaces extérieurs et du parking souterrain (démo)',
                        'address' => '48 avenue de la République, Immeuble Le Concorde, entrée B, 3e étage, bureau 312, 69003 Lyon — accès par le parking visiteurs au 12 rue de la Villette',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Réhabilitation du hall d’entrée et des parties communes (démo)',
                        'address' => '17 rue de la Villette, 69003 Lyon',
                        'status' => SiteStatus::Suspendu->value,
                    ],
                    [
                        'name' => 'Réfection complète de la façade principale et des balcons donnant sur le boulevard, avec reprise de l’isolation thermique par l’extérieur (démo)',
                        'address' => '48 avenue de la République, 69003 Lyon',
                        'status' => SiteStatus::Termine->value,
                        'archived_months_ago' => 6,
                    ],
                ],
            ],
            [
                'company_name' => 'SCI Les Peupliers (démo)',
                'contact_name' => null,
                'address' => '1 rue A, 44100 Nantes',
                'street' => '1 rue A',
                'address_complement' => null,
                'postal_code' => '44100',
                'city' => 'Nantes',
                'country' => 'France',
                'phone' => '0251000004',
                'email' => 'gestion@sci-les-peupliers.example.test',
                'sites' => [
                    [
                        'name' => 'Ravalement de la façade sud (démo)',
                        'address' => '1 rue A, 44100 Nantes',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Remplacement des portes de garage (démo)',
                        'address' => '1 rue A, 44100 Nantes',
                        'status' => SiteStatus::Termine->value,
                        'archived_months_ago' => 9,
                    ],
                ],
            ],
            [
                'company_name' => 'Mairie de Saint-Rémy-sur-Creuse (démo)',
                'contact_name' => 'Service technique',
                'address' => 'Place de la Mairie, 2 rue de l’Église, 86220 Saint-Rémy-sur-Creuse',
                'street' => '2 rue de l’Église',
                'address_complement' => 'Place de la Mairie',
                'postal_code' => '86220',
                'city' => 'Saint-Rémy-sur-Creuse',
                'country' => 'France',
                'phone' => '+33 5 49 00 00 05',
                'email' => 'services-techniques@mairie-saint-remy.example.test',
                'sites' => [
                    [
                        'name' => 'Réhabilitation de la salle des fêtes municipale (démo)',
                        'address' => 'Place de la Mairie, 86220 Saint-Rémy-sur-Creuse',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Mise en accessibilité de l’école communale (démo)',
                        'address' => '4 rue des Écoles, 86220 Saint-Rémy-sur-Creuse',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Entretien annuel des bâtiments communaux — lot toiture (démo)',
                        'address' => 'Place de la Mairie, 86220 Saint-Rémy-sur-Creuse',
                        'status' => SiteStatus::EnReception->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Études et Conseils Rhénans (démo)',
                'contact_name' => 'Amélie Schmitt',
                'address' => '7 rue des Arquebusiers, 67000 Strasbourg',
                'street' => '7 rue des Arquebusiers',
                'address_complement' => null,
                'postal_code' => '67000',
                'city' => 'Strasbourg',
                'country' => 'France',
                'phone' => null,
                'email' => 'bonjour@etudes-rhenanes.example.test',
                'sites' => [],
            ],
            [
                'company_name' => 'Camping du Lac Bleu (démo)',
                'contact_name' => 'Accueil du camping',
                'address' => 'Lieu-dit Les Rives du Lac, 88400 Gérardmer',
                'street' => 'Lieu-dit Les Rives du Lac',
                'address_complement' => null,
                'postal_code' => '88400',
                'city' => 'Gérardmer',
                'country' => 'France',
                'phone' => '+33 3 29 00 00 07',
                'email' => 'accueil@camping-lac-bleu.example.test',
                'sites' => [
                    [
                        'name' => 'Création de dix emplacements de camping-car (démo)',
                        'address' => 'Lieu-dit Les Rives du Lac, 88400 Gérardmer',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Réfection des sanitaires du secteur nord (démo)',
                        'address' => 'Lieu-dit Les Rives du Lac, 88400 Gérardmer',
                        'status' => SiteStatus::Termine->value,
                        'archived_months_ago' => 12,
                    ],
                ],
            ],
            [
                'company_name' => 'Société Coopérative Artisanale de Construction, de Rénovation, d’Aménagement et d’Entretien des Bâtiments Publics, Privés et Industriels du Grand Est et des Régions Limitrophes (démo)',
                'contact_name' => 'Direction des achats',
                'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, 67000 Strasbourg',
                'street' => '4 rue de la Coopérative',
                'address_complement' => 'Zone d’activités du Port du Rhin',
                'postal_code' => '67000',
                'city' => 'Strasbourg',
                'country' => 'France',
                'phone' => '+33 3 88 00 00 08',
                'email' => 'coordination@sca-batiments-grand-est.example.test',
                'sites' => [
                    [
                        'name' => 'Extension du site de production — hall logistique (démo)',
                        'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, 67000 Strasbourg',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Création d’un quai de chargement poids lourds (démo)',
                        'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, 67000 Strasbourg',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Mise en conformité électrique des ateliers (démo)',
                        'address' => '6 rue de la Coopérative, 67000 Strasbourg',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Construction d’un local social et vestiaires (démo)',
                        'address' => '6 rue de la Coopérative, 67000 Strasbourg',
                        'status' => SiteStatus::Suspendu->value,
                    ],
                    [
                        'name' => 'Désamiantage et démolition de l’ancien bâtiment administratif, puis reconstruction d’un accueil unique regroupant les services techniques, la direction et la salle de réunion (démo)',
                        'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, bâtiment C, 67000 Strasbourg — accès poids lourds par la porte 4, après le pont ferroviaire, prendre la première rue à droite puis suivre les panneaux « logistique » jusqu’au parking visiteurs situé derrière l’atelier de maintenance ; contacter le responsable de site avant toute livraison',
                        'status' => SiteStatus::EnReception->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Garage Muller & Fils (démo)',
                'contact_name' => 'M. Muller',
                'address' => '26 route de Colmar, 67600 Sélestat',
                'street' => '26 route de Colmar',
                'address_complement' => null,
                'postal_code' => '67600',
                'city' => 'Sélestat',
                'country' => 'France',
                'phone' => '+33 3 88 00 00 09',
                'email' => 'atelier@garage-muller.example.test',
                'sites' => [
                    [
                        'name' => 'Construction d’un atelier de carrosserie (démo)',
                        'address' => '26 route de Colmar, 67600 Sélestat',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Réfection du hall de vente (démo)',
                        'address' => '26 route de Colmar, 67600 Sélestat',
                        'status' => SiteStatus::Termine->value,
                        'archived_months_ago' => 4,
                    ],
                ],
            ],
            [
                'company_name' => 'Lycée Professionnel Gustave Eiffel (démo)',
                'contact_name' => 'Intendance',
                'address' => '15 boulevard des Métiers, 68000 Colmar',
                'street' => '15 boulevard des Métiers',
                'address_complement' => null,
                'postal_code' => '68000',
                'city' => 'Colmar',
                'country' => 'France',
                'phone' => '+33 3 89 00 00 10',
                'email' => 'intendance@lycee-eiffel.example.test',
                'sites' => [
                    [
                        'name' => 'Rénovation énergétique du bâtiment principal (démo)',
                        'address' => '15 boulevard des Métiers, 68000 Colmar',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Réaménagement du restaurant scolaire (démo)',
                        'address' => '15 boulevard des Métiers, 68000 Colmar',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Mise aux normes de sécurité incendie (démo)',
                        'address' => '15 boulevard des Métiers, 68000 Colmar',
                        'status' => SiteStatus::EnReception->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Résidence Les Jardins de l’Orangerie (démo)',
                'contact_name' => 'Syndic de copropriété',
                'address' => '32 allée des Marronniers, 67000 Strasbourg',
                'street' => '32 allée des Marronniers',
                'address_complement' => null,
                'postal_code' => '67000',
                'city' => 'Strasbourg',
                'country' => 'France',
                'phone' => null,
                'email' => 'syndic@orangerie.example.test',
                'sites' => [
                    [
                        'name' => 'Réhabilitation des deux cages d’escalier (démo)',
                        'address' => '32 allée des Marronniers, 67000 Strasbourg',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Remplacement de la chaudière collective (démo)',
                        'address' => '32 allée des Marronniers, 67000 Strasbourg',
                        'status' => SiteStatus::Termine->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Transports Schmitt Logistique (démo)',
                'contact_name' => 'Service exploitation',
                'address' => '9 rue des Chariots, 68100 Mulhouse',
                'street' => '9 rue des Chariots',
                'address_complement' => null,
                'postal_code' => '68100',
                'city' => 'Mulhouse',
                'country' => 'France',
                'phone' => '+33 3 89 00 00 12',
                'email' => 'exploitation@transports-schmitt.example.test',
                'sites' => [
                    [
                        'name' => 'Construction d’un entrepôt frigorifique (démo)',
                        'address' => '9 rue des Chariots, 68100 Mulhouse',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Extension de la cour de manœuvre (démo)',
                        'address' => '9 rue des Chariots, 68100 Mulhouse',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Installation de panneaux photovoltaïques sur le toit de l’atelier (démo)',
                        'address' => '9 rue des Chariots, 68100 Mulhouse',
                        'status' => SiteStatus::EnReception->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Pharmacie de la Place Centrale (démo)',
                'contact_name' => 'Dr. Weber',
                'address' => '2 place de l’Hôtel de Ville, 68100 Mulhouse',
                'street' => '2 place de l’Hôtel de Ville',
                'address_complement' => null,
                'postal_code' => '68100',
                'city' => 'Mulhouse',
                'country' => 'France',
                'phone' => '+33 3 89 00 00 13',
                'email' => 'contact@pharmacie-place-centrale.example.test',
                'sites' => [
                    [
                        'name' => 'Rénovation de l’officine et de la réserve (démo)',
                        'address' => '2 place de l’Hôtel de Ville, 68100 Mulhouse',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Création d’un espace de confidentialité (démo)',
                        'address' => '2 place de l’Hôtel de Ville, 68100 Mulhouse',
                        'status' => SiteStatus::Termine->value,
                        'archived_months_ago' => 7,
                    ],
                ],
            ],
            [
                'company_name' => 'Ville de Mulhouse — Direction du Patrimoine Bâti (démo)',
                'contact_name' => 'Direction du patrimoine bâti',
                'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                'street' => '1 place de la Réunion',
                'address_complement' => 'Hôtel de Ville',
                'postal_code' => '68100',
                'city' => 'Mulhouse',
                'country' => 'France',
                'phone' => '+33 3 89 00 00 14',
                'email' => 'patrimoine@ville-mulhouse.example.test',
                'sites' => [
                    [
                        'name' => 'Restauration des vitraux de la salle du conseil (démo)',
                        'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Réfection de la cour d’honneur (démo)',
                        'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Mise en sécurité des accès du public (démo)',
                        'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                        'status' => SiteStatus::EnReception->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Hôtel Le Clos des Vignes (démo)',
                'contact_name' => 'Réception',
                'address' => '18 route des Vins, 68420 Eguisheim',
                'street' => '18 route des Vins',
                'address_complement' => null,
                'postal_code' => '68420',
                'city' => 'Eguisheim',
                'country' => 'France',
                'phone' => '+33 3 89 00 00 15',
                'email' => 'reception@clos-des-vignes.example.test',
                'sites' => [
                    [
                        'name' => 'Création de six chambres supplémentaires (démo)',
                        'address' => '18 route des Vins, 68420 Eguisheim',
                        'status' => SiteStatus::EnCours->value,
                    ],
                    [
                        'name' => 'Rénovation de la salle de restauration (démo)',
                        'address' => '18 route des Vins, 68420 Eguisheim',
                        'status' => SiteStatus::APreparer->value,
                    ],
                    [
                        'name' => 'Réfection de la terrasse et du jardin (démo)',
                        'address' => '18 route des Vins, 68420 Eguisheim',
                        'status' => SiteStatus::Termine->value,
                    ],
                ],
            ],
            [
                'company_name' => 'Association Sportive du Bassin Hospitalier (démo)',
                'contact_name' => 'Secrétariat',
                'address' => 'Complexe sportif Pierre de Coubertin, 5 rue des Stades, 68100 Mulhouse',
                'street' => '5 rue des Stades',
                'address_complement' => 'Complexe sportif Pierre de Coubertin',
                'postal_code' => '68100',
                'city' => 'Mulhouse',
                'country' => 'France',
                'phone' => null,
                'email' => 'secretariat@as-bassin-hospitalier.example.test',
                'sites' => [],
            ],
        ];
    }
}
