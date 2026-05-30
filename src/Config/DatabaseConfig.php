<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * Database connection configuration used by --generate-schema.
 *
 * Example sqlc.yaml:
 *
 *   database:
 *     dsn:      "mysql:host=localhost;dbname=myapp;charset=utf8mb4"
 *     username: "${DB_USER}"
 *     password: "${DB_PASS}"
 *     exclude_tables:
 *       - migrations
 *       - failed_jobs
 *       - sessions
 *
 * Values of the form ${ENV_VAR} are expanded from the environment at runtime
 * so that credentials are never stored in the committed YAML file.
 *
 * Can be declared globally (applies to all targets) or per-target (overrides
 * the global connection for that target's schema extraction).
 */
readonly class DatabaseConfig
{
    /**
     * @param string[] $excludeTables  Table names to skip when generating the schema.
     * @param string[] $includeTables  When non-empty, only these tables are extracted.
     *                                 Mutually exclusive with excludeTables.
     */
    public function __construct(
        public string $dsn,
        public string $username      = '',
        public string $password      = '',
        /** @var string[] */
        public array  $excludeTables = [],
        /** @var string[] */
        public array  $includeTables = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dsn:           (string) ($data['dsn']      ?? ''),
            username:      (string) ($data['username'] ?? ''),
            password:      (string) ($data['password'] ?? ''),
            excludeTables: self::toStringArray($data['exclude_tables'] ?? []),
            includeTables: self::toStringArray($data['include_tables'] ?? []),
        );
    }

    /**
     * DSN with ${ENV_VAR} placeholders expanded from the environment.
     */
    public function resolvedDsn(): string
    {
        return self::expand($this->dsn);
    }

    /**
     * Username with ${ENV_VAR} placeholders expanded.
     */
    public function resolvedUsername(): string
    {
        return self::expand($this->username);
    }

    /**
     * Password with ${ENV_VAR} placeholders expanded.
     */
    public function resolvedPassword(): string
    {
        return self::expand($this->password);
    }

    /**
     * Returns true if the given table name should be included in the schema.
     */
    public function shouldInclude(string $tableName): bool
    {
        // include_tables acts as a whitelist — if non-empty, only those tables pass
        if (!empty($this->includeTables)) {
            return in_array($tableName, $this->includeTables, true);
        }

        // exclude_tables acts as a blacklist
        return !in_array($tableName, $this->excludeTables, true);
    }

    // -------------------------------------------------------------------------

    /**
     * Expand ${ENV_VAR} placeholders using getenv() / $_ENV / $_SERVER.
     * Unknown variables are left as-is so the caller can detect missing config.
     */
    private static function expand(string $value): string
    {
        return preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)\}/i',
            static function (array $m): string {
                $val = getenv($m[1]);
                if ($val === false) {
                    $val = $_ENV[$m[1]]    ?? null;
                    $val = $val ?? ($_SERVER[$m[1]] ?? null);
                }
                return $val !== null ? (string) $val : $m[0]; // leave unexpanded if missing
            },
            $value
        ) ?? $value;
    }

    /** @return string[] */
    private static function toStringArray(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        return array_values(array_filter(array_map('strval', $raw)));
    }
}
