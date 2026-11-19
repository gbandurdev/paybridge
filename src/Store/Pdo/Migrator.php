<?php

declare(strict_types=1);

namespace PayBridge\Store\Pdo;

/**
 * Applies the Store/Pdo/migrations/{driver}/*.sql files in filename order, detecting the engine
 * via PDO::ATTR_DRIVER_NAME. Not a full migration-tracking system (that's Phase 5 bridge
 * territory, via each framework's own migration runner) — just enough for a plain-PHP app or the
 * examples/plain-php walkthrough to create the three Phase 4 tables on a fresh database.
 * Idempotent: does nothing if paybridge_idempotency already exists.
 */
final class Migrator
{
    public static function migrate(\PDO $pdo): void
    {
        if (self::alreadyMigrated($pdo)) {
            return;
        }

        $driverNameAttribute = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $driverName = \is_string($driverNameAttribute) ? $driverNameAttribute : '';
        $directory = match ($driverName) {
            'sqlite' => 'sqlite',
            'pgsql' => 'pgsql',
            'mysql' => 'mysql',
            default => throw new \InvalidArgumentException(sprintf('Unsupported PDO driver "%s".', $driverName)),
        };

        $files = glob(__DIR__ . '/migrations/' . $directory . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new \RuntimeException(sprintf('Could not read migration file "%s".', $file));
            }

            foreach (self::splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }
        }
    }

    private static function alreadyMigrated(\PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM paybridge_idempotency LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $statements = array_filter(array_map(trim(...), explode(';', $sql)));

        return array_values($statements);
    }
}
