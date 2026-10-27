<?php

namespace App\Support\DemoData;

use RuntimeException;

/**
 * Contrôle de sécurité du jeu de données de démonstration.
 *
 * Le mécanisme est volontairement fermé par défaut : APP_ENV=local ne suffit
 * pas si la connexion pointe vers un hôte distant ou une base non autorisée.
 * Aucun accès à la base n'est effectué par cette classe ; elle ne lit que la
 * configuration résolue.
 */
final class DemoDatasetEnvironment
{
    /**
     * @return array{
     *     environment: string,
     *     connection: string,
     *     driver: string,
     *     host: string,
     *     database: string,
     *     safe: bool,
     *     reasons: array<int, string>
     * }
     */
    public function inspect(): array
    {
        $environment = app()->environment();
        $connection = (string) config('database.default');
        $configuration = config("database.connections.{$connection}");
        $configuration = is_array($configuration) ? $configuration : [];

        $driver = (string) ($configuration['driver'] ?? '');
        $host = (string) ($configuration['host'] ?? '');
        $database = (string) ($configuration['database'] ?? '');
        $url = (string) ($configuration['url'] ?? '');

        if ($url !== '') {
            $parsedHost = parse_url($url, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }

            $parsedPath = parse_url($url, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '' && $parsedPath !== '/') {
                $database = ltrim($parsedPath, '/');
            }
        }

        $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
        $database = trim($database);
        $reasons = [];

        if (! in_array($environment, ['local', 'testing'], true)) {
            $reasons[] = "environnement « {$environment} » non autorisé (local ou testing attendu)";
        }

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $reasons[] = "pilote « {$driver} » non autorisé";
        }

        if ($driver !== 'sqlite') {
            /** @var array<int, string> $allowedHosts */
            $allowedHosts = (array) config('planning.demo.allowed_hosts', []);
            /** @var array<int, string> $allowedDatabases */
            $allowedDatabases = (array) config('planning.demo.allowed_databases', []);

            if (! in_array($host, array_map('strtolower', $allowedHosts), true)) {
                $reasons[] = "hôte « {$host} » non autorisé";
            }

            if (! in_array($database, $allowedDatabases, true)) {
                $reasons[] = "base « {$database} » non autorisée";
            }
        } elseif ($database === '') {
            $reasons[] = 'chemin SQLite absent';
        }

        return [
            'environment' => $environment,
            'connection' => $connection,
            'driver' => $driver,
            'host' => $host,
            'database' => $database,
            'safe' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    public function assertSafe(): void
    {
        $state = $this->inspect();

        if (! $state['safe']) {
            throw new RuntimeException(
                'Jeu de démonstration refusé : '.implode(' ; ', $state['reasons']).'.',
            );
        }
    }

    public function describe(): string
    {
        $state = $this->inspect();

        return sprintf(
            'environnement=%s, connexion=%s, pilote=%s, hôte=%s, base=%s',
            $state['environment'],
            $state['connection'],
            $state['driver'],
            $state['host'] !== '' ? $state['host'] : '—',
            $state['database'] !== '' ? $state['database'] : '—',
        );
    }
}
