<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

use SqlcPhp\Parser\CteDefinition;

/**
 * Registry of named CTEs available to a generation target.
 *
 * Built by merging global ctes (from the root sqlc.yaml `ctes:` key) with
 * per-target ctes (from the target's own `ctes:` key). Each CteDefinition
 * is registered by name; duplicate names across files are a hard error.
 *
 * Usage in generation:
 *   $registry = CteRegistry::build($globalCtePaths, $targetCtePaths, $baseDir);
 *   $sql      = $registry->inject(['active_users', 'recent_orders'], $querySql);
 *   // → WITH active_users AS (...), recent_orders AS (...) SELECT ...
 */
class CteRegistry
{
    /** @var array<string, CteDefinition> name → definition */
    private array $ctes = [];

    private function __construct() {}

    /**
     * Build a registry from global + per-target CTE file paths.
     *
     * @param  string[] $globalPaths  Paths declared at root `ctes:` level
     * @param  string[] $targetPaths  Paths declared inside the target's `ctes:`
     * @param  string   $baseDir      Directory of sqlc.yaml for relative path resolution
     * @throws \RuntimeException On file-not-found or duplicate CTE names
     */
    public static function build(
        array  $globalPaths,
        array  $targetPaths,
        string $baseDir,
    ): self {
        $registry = new self();
        $parser   = new \SqlcPhp\Parser\CteParser();

        foreach (array_merge($globalPaths, $targetPaths) as $rawPath) {
            $path = self::resolvePath((string) $rawPath, $baseDir);
            $defs = $parser->parseFile($path);

            foreach ($defs as $def) {
                if (isset($registry->ctes[$def->name])) {
                    $existing = $registry->ctes[$def->name];
                    throw new \RuntimeException(
                        "Duplicate CTE name '{$def->name}': " .
                        "first defined in '{$existing->sourceFile}', " .
                        "redefined in '{$def->sourceFile}'. " .
                        "Each CTE name must be unique across all loaded CTE files."
                    );
                }
                $registry->ctes[$def->name] = $def;
            }
        }

        return $registry;
    }

    /**
     * Build an empty registry (used when no ctes: are configured).
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Returns true when no CTEs are registered.
     */
    public function isEmpty(): bool
    {
        return empty($this->ctes);
    }

    /**
     * Returns all registered CTE names — useful for --diff / --verify output.
     *
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->ctes);
    }

    /**
     * Retrieve a single CTE definition by name.
     */
    public function get(string $name): ?CteDefinition
    {
        return $this->ctes[$name] ?? null;
    }

    /**
     * Inject the requested CTEs as a WITH clause in front of the query SQL.
     *
     * @param  string[] $names    CTE names declared via @use in the query
     * @param  string   $querySql The raw query SQL (SELECT / INSERT / UPDATE)
     * @return string   SQL with WITH clause prepended
     *
     * @throws \RuntimeException When a requested CTE name is not registered,
     *                           or when the query already has a WITH clause
     */
    public function inject(array $names, string $querySql): string
    {
        if (empty($names)) return $querySql;

        $trimmed = ltrim($querySql);

        // Guard: mixing inline WITH and @use is not allowed
        if (preg_match('/^WITH\b/i', $trimmed)) {
            throw new \RuntimeException(
                "A query that uses '@use' cannot also declare an inline WITH clause. " .
                "Move the inline CTE to a shared .sql file and reference it with '@use'."
            );
        }

        $clauses = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') continue;

            $def = $this->ctes[$name] ?? null;
            if ($def === null) {
                $available = implode(', ', array_keys($this->ctes)) ?: '(none)';
                throw new \RuntimeException(
                    "Query uses '@use {$name}' but no CTE named '{$name}' is registered. " .
                    "Available CTEs: {$available}. " .
                    "Declare it in a file listed under 'ctes:' in sqlc.yaml."
                );
            }

            $clauses[] = "{$name} AS (\n    " .
                str_replace("\n", "\n    ", trim($def->sql)) .
                "\n)";
        }

        $with = "WITH\n" . implode(",\n", $clauses) . "\n";

        return $with . $querySql;
    }

    // -------------------------------------------------------------------------

    private static function resolvePath(string $path, string $baseDir): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Z]:\\\\/i', $path)) {
            return $path;
        }
        return $baseDir . DIRECTORY_SEPARATOR . $path;
    }
}
