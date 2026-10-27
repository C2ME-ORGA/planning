<?php

namespace App\Support\DemoData;

use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use RuntimeException;

/**
 * Contrôle de sécurité du jeu de données de démonstration.
 *
 * Le mécanisme est volontairement fermé par défaut : APP_ENV=local ne suffit
 * pas si la connexion pointe vers un hôte distant, une base non autorisée ou
 * un fichier SQLite arbitraire. Aucun accès à la base n'est effectué par cette
 * classe ; elle ne lit que la configuration résolue.
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
     *     resolved_database: string|null,
     *     safe: bool,
     *     reasons: array<int, string>
     * }
     */
    public function inspect(): array
    {
        $environment = app()->environment();
        $connection = (string) config('database.default');
        $configuration = config("database.connections.{$connection}");
        /** @var array<string, mixed> $configuration */
        $configuration = is_array($configuration) ? $configuration : [];

        $reasons = [];

        // Appliquer le même parseur que Laravel évite de valider une cible
        // différente de celle que le connecteur ouvrira réellement.
        if (trim((string) ($configuration['url'] ?? '')) !== '') {
            try {
                $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
            } catch (InvalidArgumentException) {
                $reasons[] = 'URL de connexion illisible';
            }
        }

        $driver = strtolower((string) ($configuration['driver'] ?? ''));
        $host = (string) ($configuration['host'] ?? '');
        $database = trim((string) ($configuration['database'] ?? ''));
        $resolvedDatabase = null;

        $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));

        if (! in_array($environment, ['local', 'testing'], true)) {
            $reasons[] = "environnement « {$environment} » non autorisé (local ou testing attendu)";
        }

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $reasons[] = "pilote « {$driver} » non autorisé";
        }

        if ($driver === 'sqlite') {
            [$resolvedDatabase, $sqliteReasons] = $this->inspectSqliteTarget($database);
            $reasons = [...$reasons, ...$sqliteReasons];
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
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
        }

        return [
            'environment' => $environment,
            'connection' => $connection,
            'driver' => $driver,
            'host' => $host,
            'database' => $database,
            'resolved_database' => $resolvedDatabase,
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
        $database = $state['database'];

        if ($state['driver'] === 'sqlite' && $state['resolved_database'] !== null) {
            $database = $state['resolved_database'];
        }

        return sprintf(
            'environnement=%s, connexion=%s, pilote=%s, hôte=%s, base=%s',
            $state['environment'],
            $state['connection'],
            $state['driver'],
            $state['host'] !== '' ? $state['host'] : '—',
            $database !== '' ? $database : '—',
        );
    }

    /**
     * Valide un fichier SQLite et renvoie son chemin canonique.
     *
     * Seuls des chemins absolus, sans segment « .. », dont le fichier ou le
     * répertoire parent est résoluble, sont acceptés. Les bases en mémoire, les
     * URI « file: » et les liens symboliques sont refusés : ils échappent à la
     * comparaison avec la liste blanche. Aucun fichier n'est créé ici.
     *
     * @return array{0: string|null, 1: array<int, string>}
     */
    private function inspectSqliteTarget(string $database): array
    {
        if ($database === '') {
            return [null, ['chemin SQLite absent']];
        }

        $reasons = [];

        if (str_contains($database, "\0")) {
            $reasons[] = 'chemin SQLite invalide (caractère NUL)';
        }

        if ($database === ':memory:'
            || str_starts_with($database, 'file:')
            || str_contains($database, '?mode=memory')
            || str_contains($database, '&mode=memory')
        ) {
            $reasons[] = "base SQLite en mémoire ou URI refusée : « {$database} »";
        }

        if (! $this->isAbsolutePath($database)) {
            $reasons[] = "chemin SQLite relatif non autorisé : « {$database} »";
        }

        if (preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $database) === 1) {
            $reasons[] = "segment « .. » interdit dans le chemin SQLite : « {$database} »";
        }

        if (preg_match('#[\\\\/]$#', $database) === 1) {
            $reasons[] = "chemin SQLite invalide (répertoire au lieu d’un fichier) : « {$database} »";
        }

        if ($reasons !== []) {
            return [null, $reasons];
        }

        if (is_link($database)) {
            return [null, ["lien symbolique SQLite refusé : « {$database} »"]];
        }

        if (file_exists($database)) {
            if (! is_file($database)) {
                return [null, ["cible SQLite incorrecte (fichier attendu) : « {$database} »"]];
            }

            $canonical = realpath($database);

            if ($canonical === false) {
                return [null, ["chemin SQLite non résoluble : « {$database} »"]];
            }
        } else {
            $parent = realpath(dirname($database));
            $basename = $this->safeBasename($database);

            if ($parent === false || ! is_dir($parent) || $basename === null) {
                return [null, ["répertoire parent SQLite introuvable : « {$database} »"]];
            }

            $canonical = $parent.DIRECTORY_SEPARATOR.$basename;
        }

        foreach ($this->allowedSqlitePaths() as $allowedPath) {
            $allowedCanonical = $this->canonicalizeAllowlistEntry($allowedPath);

            if ($allowedCanonical !== null && $this->pathsEqual($canonical, $allowedCanonical)) {
                return [$canonical, []];
            }
        }

        return [$canonical, ["chemin SQLite non autorisé : « {$canonical} »"]];
    }

    /**
     * Canonicalise une entrée de la liste blanche sans résoudre son dernier
     * segment : un fichier autorisé remplacé par un lien symbolique ne doit pas
     * pouvoir rediriger l'écriture vers une autre cible.
     */
    private function canonicalizeAllowlistEntry(string $allowedPath): ?string
    {
        $allowedPath = trim($allowedPath);
        $basename = $this->safeBasename($allowedPath);

        if ($basename === null
            || ! $this->isAbsolutePath($allowedPath)
            || str_contains($allowedPath, "\0")
            || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $allowedPath) === 1
            || preg_match('#[\\\\/]$#', $allowedPath) === 1
        ) {
            return null;
        }

        $parent = realpath(dirname($allowedPath));

        if ($parent === false || ! is_dir($parent)) {
            return null;
        }

        return $parent.DIRECTORY_SEPARATOR.$basename;
    }

    private function safeBasename(string $path): ?string
    {
        $basename = basename($path);

        if ($basename === '' || $basename === '.' || $basename === '..') {
            return null;
        }

        return $basename;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
                || str_starts_with($path, '\\\\');
        }

        return str_starts_with($path, '/');
    }

    private function pathsEqual(string $first, string $second): bool
    {
        $first = str_replace('\\', '/', $first);
        $second = str_replace('\\', '/', $second);

        if (PHP_OS_FAMILY === 'Windows') {
            return strcasecmp($first, $second) === 0;
        }

        return $first === $second;
    }

    /**
     * @return array<int, string>
     */
    private function allowedSqlitePaths(): array
    {
        $configured = config('planning.demo.allowed_sqlite_paths', []);

        if (! is_array($configured)) {
            return [];
        }

        $paths = [];

        foreach ($configured as $path) {
            if (is_string($path) && trim($path) !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
