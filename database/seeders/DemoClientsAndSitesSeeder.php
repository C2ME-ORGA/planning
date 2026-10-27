<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Site;
use App\Support\DemoData\DemoDatasetEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Jeu de démonstration fictif pour la revue visuelle du lot PC1-28.
 *
 * Ce seeder est volontairement séparé de DatabaseSeeder. Il écrit directement
 * via Eloquent, sans passer par ClientService ni SiteService : aucune opération
 * métier ni aucun événement d'audit n'est simulé. Il est additif, idempotent et
 * identifie ses lignes par le suffixe « (démo) ».
 *
 * Les valeurs de statut ci-dessous sont des libellés de démonstration, pas une
 * liste officielle : la liste et les transitions restent à arbitrer
 * (docs/PC1-28-DECISIONS.md, point 1).
 */
class DemoClientsAndSitesSeeder extends Seeder
{
    use WithoutModelEvents;

    public const MARKER = '(démo)';

    public int $clientsCreated = 0;

    public int $clientsExisting = 0;

    public int $sitesCreated = 0;

    public int $sitesExisting = 0;

    /** @var array<string, array<int, string>> */
    private const CLIENT_RULES = [
        'company_name' => ['required', 'string', 'max:190'],
        'address' => ['required', 'string', 'max:500'],
        'phone' => ['nullable', 'string', 'max:40'],
        'email' => ['nullable', 'email', 'max:254'],
    ];

    /** @var array<string, array<int, string>> */
    private const SITE_RULES = [
        'name' => ['required', 'string', 'max:190'],
        'address' => ['required', 'string', 'max:500'],
        'client_id' => ['required', 'integer', 'exists:clients,id'],
        'status' => ['required', 'string', 'max:40'],
    ];

    public function __construct(private readonly DemoDatasetEnvironment $environment) {}

    public function run(): void
    {
        $this->environment->assertSafe();

        DB::transaction(function (): void {
            foreach (self::dataset() as $entry) {
                $clientAttributes = [
                    'company_name' => $entry['company_name'],
                    'address' => $entry['address'],
                    'phone' => $entry['phone'],
                    'email' => $entry['email'],
                ];

                validator($clientAttributes, self::CLIENT_RULES)->validate();

                $client = Client::query()->firstOrCreate(
                    ['company_name' => $clientAttributes['company_name']],
                    $clientAttributes,
                );

                $client->wasRecentlyCreated
                    ? $this->clientsCreated++
                    : $this->clientsExisting++;

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

                    validator($siteAttributes, self::SITE_RULES)->validate();

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
     * @return array<int, array{
     *     company_name: string,
     *     address: string,
     *     phone: string|null,
     *     email: string|null,
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
                'address' => '12 quai des Tanneurs, 67000 Strasbourg',
                'phone' => '+33 3 88 00 00 01',
                'email' => 'contact@atelier-vieux-canal.example.test',
                'sites' => [
                    [
                        'name' => 'Rénovation de l’atelier historique — tranche 1 (démo)',
                        'address' => '12 quai des Tanneurs, 67000 Strasbourg',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Extension du local d’exposition (démo)',
                        'address' => '14 quai des Tanneurs, 67000 Strasbourg',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Reprise de la toiture et des menuiseries (démo)',
                        'address' => '12 quai des Tanneurs, 67000 Strasbourg',
                        'status' => 'terminé',
                        'archived_months_ago' => 2,
                    ],
                ],
            ],
            [
                'company_name' => 'Boulangerie des Trois Ponts (démo)',
                'address' => '3 place du Marché, 68000 Colmar',
                'phone' => null,
                'email' => null,
                'sites' => [
                    [
                        'name' => 'Réaménagement du fournil (démo)',
                        'address' => '3 place du Marché, 68000 Colmar',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Mise aux normes de la vitrine et de l’accès PMR (démo)',
                        'address' => '3 place du Marché, 68000 Colmar',
                        'status' => 'réception',
                    ],
                ],
            ],
            [
                'company_name' => 'Groupe Immobilier Horizon & Associés (démo)',
                'address' => 'Immeuble Le Concorde, 48 avenue de la République, 69003 Lyon',
                'phone' => '+33 4 72 00 00 03',
                'email' => 'contact@horizon-associes.example.test',
                'sites' => [
                    [
                        'name' => 'Construction de la résidence Les Terrasses du Parc — bâtiment A (démo)',
                        'address' => '48 avenue de la République, Immeuble Le Concorde, 69003 Lyon',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Construction de la résidence Les Terrasses du Parc — bâtiment B (démo)',
                        'address' => '48 avenue de la République, Immeuble Le Concorde, 69003 Lyon',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Aménagement des espaces extérieurs et du parking souterrain (démo)',
                        'address' => '48 avenue de la République, Immeuble Le Concorde, entrée B, 3e étage, bureau 312, 69003 Lyon — accès par le parking visiteurs au 12 rue de la Villette',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Réhabilitation du hall d’entrée et des parties communes (démo)',
                        'address' => '17 rue de la Villette, 69003 Lyon',
                        'status' => 'suspendu',
                    ],
                    [
                        'name' => 'Réfection complète de la façade principale et des balcons donnant sur le boulevard, avec reprise de l’isolation thermique par l’extérieur (démo)',
                        'address' => '48 avenue de la République, 69003 Lyon',
                        'status' => 'terminé',
                        'archived_months_ago' => 6,
                    ],
                ],
            ],
            [
                'company_name' => 'SCI Les Peupliers (démo)',
                'address' => '1 rue A, 44100 Nantes',
                'phone' => '0251000004',
                'email' => null,
                'sites' => [
                    [
                        'name' => 'Ravalement de la façade sud (démo)',
                        'address' => '1 rue A, 44100 Nantes',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Remplacement des portes de garage (démo)',
                        'address' => '1 rue A, 44100 Nantes',
                        'status' => 'terminé',
                        'archived_months_ago' => 9,
                    ],
                ],
            ],
            [
                'company_name' => 'Mairie de Saint-Rémy-sur-Creuse (démo)',
                'address' => 'Place de la Mairie, 2 rue de l’Église, 86220 Saint-Rémy-sur-Creuse',
                'phone' => '+33 5 49 00 00 05',
                'email' => 'services-techniques@mairie-saint-remy.example.test',
                'sites' => [
                    [
                        'name' => 'Réhabilitation de la salle des fêtes municipale (démo)',
                        'address' => 'Place de la Mairie, 86220 Saint-Rémy-sur-Creuse',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Mise en accessibilité de l’école communale (démo)',
                        'address' => '4 rue des Écoles, 86220 Saint-Rémy-sur-Creuse',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Entretien annuel des bâtiments communaux — lot toiture (démo)',
                        'address' => 'Place de la Mairie, 86220 Saint-Rémy-sur-Creuse',
                        'status' => 'réception',
                    ],
                ],
            ],
            [
                'company_name' => 'Études et Conseils Rhénans (démo)',
                'address' => '7 rue des Arquebusiers, 67000 Strasbourg',
                'phone' => null,
                'email' => 'bonjour@etudes-rhenanes.example.test',
                'sites' => [],
            ],
            [
                'company_name' => 'Camping du Lac Bleu (démo)',
                'address' => 'Lieu-dit Les Rives du Lac, 88400 Gérardmer',
                'phone' => '+33 3 29 00 00 07',
                'email' => 'accueil@camping-lac-bleu.example.test',
                'sites' => [
                    [
                        'name' => 'Création de dix emplacements de camping-car (démo)',
                        'address' => 'Lieu-dit Les Rives du Lac, 88400 Gérardmer',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Réfection des sanitaires du secteur nord (démo)',
                        'address' => 'Lieu-dit Les Rives du Lac, 88400 Gérardmer',
                        'status' => 'terminé',
                        'archived_months_ago' => 12,
                    ],
                ],
            ],
            [
                'company_name' => 'Société Coopérative Artisanale de Construction, de Rénovation, d’Aménagement et d’Entretien des Bâtiments Publics, Privés et Industriels du Grand Est et des Régions Limitrophes (démo)',
                'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, 67000 Strasbourg',
                'phone' => '+33 3 88 00 00 08',
                'email' => 'coordination@sca-batiments-grand-est.example.test',
                'sites' => [
                    [
                        'name' => 'Extension du site de production — hall logistique (démo)',
                        'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, 67000 Strasbourg',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Création d’un quai de chargement poids lourds (démo)',
                        'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, 67000 Strasbourg',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Mise en conformité électrique des ateliers (démo)',
                        'address' => '6 rue de la Coopérative, 67000 Strasbourg',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Construction d’un local social et vestiaires (démo)',
                        'address' => '6 rue de la Coopérative, 67000 Strasbourg',
                        'status' => 'suspendu',
                    ],
                    [
                        'name' => 'Désamiantage et démolition de l’ancien bâtiment administratif, puis reconstruction d’un accueil unique regroupant les services techniques, la direction et la salle de réunion (démo)',
                        'address' => 'Zone d’activités du Port du Rhin, 4 rue de la Coopérative, bâtiment C, 67000 Strasbourg — accès poids lourds par la porte 4, après le pont ferroviaire, prendre la première rue à droite puis suivre les panneaux « logistique » jusqu’au parking visiteurs situé derrière l’atelier de maintenance ; contacter le responsable de site avant toute livraison',
                        'status' => 'réception',
                    ],
                ],
            ],
            [
                'company_name' => 'Garage Muller & Fils (démo)',
                'address' => '26 route de Colmar, 67600 Sélestat',
                'phone' => '+33 3 88 00 00 09',
                'email' => null,
                'sites' => [
                    [
                        'name' => 'Construction d’un atelier de carrosserie (démo)',
                        'address' => '26 route de Colmar, 67600 Sélestat',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Réfection du hall de vente (démo)',
                        'address' => '26 route de Colmar, 67600 Sélestat',
                        'status' => 'terminé',
                        'archived_months_ago' => 4,
                    ],
                ],
            ],
            [
                'company_name' => 'Lycée Professionnel Gustave Eiffel (démo)',
                'address' => '15 boulevard des Métiers, 68000 Colmar',
                'phone' => '+33 3 89 00 00 10',
                'email' => 'intendance@lycee-eiffel.example.test',
                'sites' => [
                    [
                        'name' => 'Rénovation énergétique du bâtiment principal (démo)',
                        'address' => '15 boulevard des Métiers, 68000 Colmar',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Réaménagement du restaurant scolaire (démo)',
                        'address' => '15 boulevard des Métiers, 68000 Colmar',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Mise aux normes de sécurité incendie (démo)',
                        'address' => '15 boulevard des Métiers, 68000 Colmar',
                        'status' => 'réception',
                    ],
                ],
            ],
            [
                'company_name' => 'Résidence Les Jardins de l’Orangerie (démo)',
                'address' => '32 allée des Marronniers, 67000 Strasbourg',
                'phone' => null,
                'email' => 'syndic@orangerie.example.test',
                'sites' => [
                    [
                        'name' => 'Réhabilitation des deux cages d’escalier (démo)',
                        'address' => '32 allée des Marronniers, 67000 Strasbourg',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Remplacement de la chaudière collective (démo)',
                        'address' => '32 allée des Marronniers, 67000 Strasbourg',
                        'status' => 'terminé',
                    ],
                ],
            ],
            [
                'company_name' => 'Transports Schmitt Logistique (démo)',
                'address' => '9 rue des Chariots, 68100 Mulhouse',
                'phone' => '+33 3 89 00 00 12',
                'email' => 'exploitation@transports-schmitt.example.test',
                'sites' => [
                    [
                        'name' => 'Construction d’un entrepôt frigorifique (démo)',
                        'address' => '9 rue des Chariots, 68100 Mulhouse',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Extension de la cour de manœuvre (démo)',
                        'address' => '9 rue des Chariots, 68100 Mulhouse',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Installation de panneaux photovoltaïques sur le toit de l’atelier (démo)',
                        'address' => '9 rue des Chariots, 68100 Mulhouse',
                        'status' => 'réception',
                    ],
                ],
            ],
            [
                'company_name' => 'Pharmacie de la Place Centrale (démo)',
                'address' => '2 place de l’Hôtel de Ville, 68100 Mulhouse',
                'phone' => '+33 3 89 00 00 13',
                'email' => null,
                'sites' => [
                    [
                        'name' => 'Rénovation de l’officine et de la réserve (démo)',
                        'address' => '2 place de l’Hôtel de Ville, 68100 Mulhouse',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Création d’un espace de confidentialité (démo)',
                        'address' => '2 place de l’Hôtel de Ville, 68100 Mulhouse',
                        'status' => 'terminé',
                        'archived_months_ago' => 7,
                    ],
                ],
            ],
            [
                'company_name' => 'Ville de Mulhouse — Direction du Patrimoine Bâti (démo)',
                'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                'phone' => '+33 3 89 00 00 14',
                'email' => 'patrimoine@ville-mulhouse.example.test',
                'sites' => [
                    [
                        'name' => 'Restauration des vitraux de la salle du conseil (démo)',
                        'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Réfection de la cour d’honneur (démo)',
                        'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Mise en sécurité des accès du public (démo)',
                        'address' => 'Hôtel de Ville, 1 place de la Réunion, 68100 Mulhouse',
                        'status' => 'réception',
                    ],
                ],
            ],
            [
                'company_name' => 'Hôtel Le Clos des Vignes (démo)',
                'address' => '18 route des Vins, 68420 Eguisheim',
                'phone' => '+33 3 89 00 00 15',
                'email' => 'reception@clos-des-vignes.example.test',
                'sites' => [
                    [
                        'name' => 'Création de six chambres supplémentaires (démo)',
                        'address' => '18 route des Vins, 68420 Eguisheim',
                        'status' => 'en cours',
                    ],
                    [
                        'name' => 'Rénovation de la salle de restauration (démo)',
                        'address' => '18 route des Vins, 68420 Eguisheim',
                        'status' => 'en préparation',
                    ],
                    [
                        'name' => 'Réfection de la terrasse et du jardin (démo)',
                        'address' => '18 route des Vins, 68420 Eguisheim',
                        'status' => 'terminé',
                    ],
                ],
            ],
            [
                'company_name' => 'Association Sportive du Bassin Hospitalier (démo)',
                'address' => 'Complexe sportif Pierre de Coubertin, 5 rue des Stades, 68100 Mulhouse',
                'phone' => null,
                'email' => 'secretariat@as-bassin-hospitalier.example.test',
                'sites' => [],
            ],
        ];
    }
}
