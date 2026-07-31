<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * A single output target in sqlc.yaml.
 *
 * The `out:` value can be either a string (all types to one dir — backward
 * compatible) or a YAML map (each type to its own dir/namespace).
 *
 * String form:
 *   targets:
 *     - namespace: "App\\Database"
 *       out:       generated
 *       queries:   queries.sql
 *
 * Map form:
 *   targets:
 *     - namespace: "App\\Database"
 *       queries:   queries.sql
 *       out:
 *         queries:    database/Repositories
 *         models:     database/Models
 *         dtos:       database/DTOs
 *         enums:      database/Enums
 *         interfaces: database/Contracts
 *         criterias:  database/Criterias
 */
readonly class Target
{
    /**
     * @param string[]       $queries
     * @param TypeOverride[] $typeOverrides   Merged: global overrides + local overrides
     */
    public function __construct(
        public string       $namespace,
        public OutputConfig $output,
        public array        $queries,
        /** Default true — set false only to disable interface generation for this target. */
        public bool         $generateInterfaces    = true,
        public array        $typeOverrides         = [],
        /** Database engine for this target — inherited from global if not specified. */
        public string       $engine                = 'mysql',
        /** Inflection language for this target — inherited from global if not specified. */
        public string       $language              = 'english',
        public bool         $preparedStatementCache = false,
        public string       $classSuffix           = 'Query',
        public ?DatabaseConfig $database           = null,
        /**
         * When true, each query method's DTOs and embed classes are placed in a
         * subdirectory named after the method (PascalCase). This guarantees that
         * two queries whose @embed annotations use the same class name but select
         * different columns never collide.
         *
         * Example with scoped_dtos: true:
         *   DTOs/GetBillingDetails/BillingReserve.php  ← namespace: DTOs\GetBillingDetails
         *   DTOs/GetBillingWithDate/BillingReserve.php ← namespace: DTOs\GetBillingWithDate
         *
         * When false (default) and a collision is detected, generation aborts
         * with a clear error message listing the conflicting queries.
         *
         * @deprecated Use dto_scope: method instead. Kept for backward compat.
         */
        public bool         $scopedDtos            = false,
        /**
         * DTO scoping mode — controls how generated DTO files are namespaced:
         *
         *   'none'   (default) — flat: all DTOs in App\DTOs\GetActiveRow
         *   'class'  (new)     — grouped by @class: App\DTOs\CmsConfig\GetActiveRow
         *   'method' (full)    — grouped by @class + @name: App\DTOs\CmsConfig\GetActive\GetActiveRow
         *
         * 'scoped_dtos: true' is a backward-compat alias for dto_scope: method.
         */
        public string       $dtoScope              = 'none',
        /**
         * Per-target CTE file paths — merged with global ctes at generation time.
         * @var string[]
         */
        public array        $ctePaths              = [],
    ) {}

    /**
     * Backward-compat accessor — returns the default dir (string form) or the
     * first declared dir (map form). Used wherever the old $target->out was used
     * for display/logging.
     */
    public function out(): string
    {
        return $this->output->defaultDir();
    }

    public static function fromArray(
        array  $data,
        array  $globalOverrides   = [],
        string $globalEngine      = 'mysql',
        string $globalLanguage    = 'english',
        string $globalClassSuffix = 'Query',
    ): self {
        $rawQueries = $data['queries'] ?? [];
        $queries    = is_array($rawQueries)
            ? array_values(array_filter(array_map('strval', $rawQueries)))
            : [(string) $rawQueries];

        // Local overrides merged on top of global
        $localOverrides = [];
        foreach ($data['type_overrides'] ?? [] as $entry) {
            if (!is_array($entry)) continue;
            try {
                $localOverrides[] = TypeOverride::fromArray($entry);
            } catch (\InvalidArgumentException) {}
        }

        $rawGi              = $data['generate_interfaces'] ?? null;
        $generateInterfaces = $rawGi === null
            ? true
            : filter_var($rawGi, FILTER_VALIDATE_BOOLEAN);

        $namespace = (string) ($data['namespace'] ?? 'App\\Database');

        // `out:` can be a string or a map
        $rawOut = $data['out'] ?? 'generated';
        $output = OutputConfig::fromRaw($rawOut, $namespace);

        return new self(
            namespace:              $namespace,
            output:                 $output,
            queries:                $queries,
            generateInterfaces:     $generateInterfaces,
            typeOverrides:          array_merge($globalOverrides, $localOverrides),
            engine:                 strtolower((string) ($data['engine']   ?? $globalEngine)),
            language:               strtolower((string) ($data['language'] ?? $globalLanguage)),
            preparedStatementCache: filter_var($data['prepared_statement_cache'] ?? false, FILTER_VALIDATE_BOOLEAN),
            classSuffix:            (string) ($data['class_suffix'] ?? $globalClassSuffix),
            database:               isset($data['database']) && is_array($data['database'])
                                        ? DatabaseConfig::fromArray($data['database'])
                                        : null,
            scopedDtos:             filter_var($data['scoped_dtos'] ?? false, FILTER_VALIDATE_BOOLEAN),
            dtoScope:               (function() use ($data): string {
                // dto_scope takes priority; scoped_dtos: true is alias for 'method'
                $explicit = $data['dto_scope'] ?? null;
                if ($explicit !== null) {
                    $v = strtolower(trim((string) $explicit));
                    if (!in_array($v, ['none', 'class', 'method'], true)) {
                        throw new \InvalidArgumentException(
                            "dto_scope must be 'none', 'class', or 'method'. Got: '{$v}'"
                        );
                    }
                    return $v;
                }
                // Fall back to scoped_dtos alias
                return filter_var($data['scoped_dtos'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ? 'method'
                    : 'none';
            })(),
            ctePaths:               is_array($data['ctes'] ?? null)
                                        ? array_values(array_filter(array_map('strval', $data['ctes'])))
                                        : (is_string($data['ctes'] ?? null) && $data['ctes'] !== ''
                                            ? [(string) $data['ctes']]
                                            : []),
        );
    }
}
