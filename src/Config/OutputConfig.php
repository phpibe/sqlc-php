<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * Resolves output directories and PHP namespaces for each generated file type.
 *
 * Two forms are supported in sqlc.yaml:
 *
 * # Form 1 — string: all types go to the same directory (current behaviour)
 * out: generated
 *
 * # Form 2 — map: each type gets its own directory
 * out:
 *   queries:    database/Repositories
 *   models:     database/Models
 *   dtos:       database/DTOs
 *   enums:      database/Enums
 *   interfaces: database/Contracts
 *   criterias:  database/Criterias
 *
 * Namespace derivation in map form:
 *   The namespace for each type is: baseNamespace + '\' + last path segment (as-is)
 *   e.g. namespace: "App\Database", out.queries: "database/Repositories"
 *        → namespace: "App\Database\Repositories"
 *
 * Error policy:
 *   If map form is used and a type is needed at runtime but not declared,
 *   a RuntimeException is thrown before any files are written.
 */
readonly class OutputConfig
{
    /** Known file types */
    public const TYPES = ['queries', 'models', 'dtos', 'enums', 'interfaces', 'criterias'];

    /**
     * @param string  $baseNamespace  The target's base PHP namespace
     * @param string  $defaultDir     Fallback dir when map form is not used
     * @param bool    $isMap          True when out: is a YAML map
     * @param array<string,string> $dirs  type → directory path (map form only)
     */
    public function __construct(
        private string $baseNamespace,
        private string $defaultDir,
        private bool   $isMap,
        private array  $dirs = [],
    ) {}

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Parse the `out:` value from YAML (string or map) into an OutputConfig.
     *
     * @param string|array<string,string> $raw   Parsed YAML value
     * @param string                      $baseNamespace
     */
    public static function fromRaw(mixed $raw, string $baseNamespace): self
    {
        // String form → everything in one dir (backward compat)
        if (is_string($raw)) {
            return new self(
                baseNamespace: $baseNamespace,
                defaultDir:    rtrim($raw, '/'),
                isMap:         false,
            );
        }

        // Map form
        if (is_array($raw)) {
            $dirs = [];
            foreach (self::TYPES as $type) {
                if (isset($raw[$type]) && is_string($raw[$type])) {
                    $dirs[$type] = rtrim($raw[$type], '/');
                }
            }
            // defaultDir = first declared dir (used only for display/error messages)
            $defaultDir = reset($dirs) ?: 'generated';
            return new self(
                baseNamespace: $baseNamespace,
                defaultDir:    $defaultDir,
                isMap:         true,
                dirs:          $dirs,
            );
        }

        // Fallback
        return new self(baseNamespace: $baseNamespace, defaultDir: 'generated', isMap: false);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /** True when map form is active */
    public function isMap(): bool
    {
        return $this->isMap;
    }

    /** The default directory (string form or first declared dir in map form) */
    public function defaultDir(): string
    {
        return $this->defaultDir;
    }

    /**
     * Return the output directory for a given file type.
     * Throws RuntimeException if map form is active and the type is not declared.
     */
    public function dirFor(string $type): string
    {
        if (!$this->isMap) {
            return $this->defaultDir;
        }

        if (!isset($this->dirs[$type])) {
            throw new \RuntimeException(
                "Output directory for type '{$type}' is not declared in the out: map. " .
                "Add 'out.{$type}: path/to/dir' to your sqlc.yaml target, " .
                "or use a string out: value to write all types to one directory."
            );
        }

        return $this->dirs[$type];
    }

    /**
     * Return the PHP namespace for a given file type.
     *
     * String form → always returns the base namespace.
     * Map form    → baseNamespace + '\' + last path segment of the declared dir.
     *
     * Example: base="App\Database", dir="database/Repositories"
     *          → "App\Database\Repositories"
     */
    public function namespaceFor(string $type): string
    {
        if (!$this->isMap) {
            return $this->baseNamespace;
        }

        if (!isset($this->dirs[$type])) {
            // If the type has no declared dir, fall back to base namespace.
            // dirFor() would have thrown already before we get here.
            return $this->baseNamespace;
        }

        $lastSegment = basename($this->dirs[$type]);
        return $this->baseNamespace . '\\' . $lastSegment;
    }

    /**
     * Whether the namespace for $typeA differs from the namespace for $typeB.
     * Used to decide whether `use` statements are needed.
     */
    public function namespacesdiffer(string $typeA, string $typeB): bool
    {
        return $this->namespaceFor($typeA) !== $this->namespaceFor($typeB);
    }

    /**
     * Return all declared dirs (map form) or empty array (string form).
     * @return array<string,string>
     */
    public function allDirs(): array
    {
        return $this->dirs;
    }

    /**
     * Validate that all needed types are declared (only relevant in map form).
     * $neededTypes is a list of type names that will actually be generated.
     *
     * @param string[] $neededTypes
     * @throws \RuntimeException
     */
    public function assertAllDeclared(array $neededTypes): void
    {
        if (!$this->isMap) return;

        foreach ($neededTypes as $type) {
            if (!isset($this->dirs[$type])) {
                throw new \RuntimeException(
                    "Output directory for type '{$type}' is not declared. " .
                    "Add 'out.{$type}: path/to/dir' to the target in sqlc.yaml."
                );
            }
        }
    }
}
