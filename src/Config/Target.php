<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * A single output target in sqlc.yaml.
 *
 * Targets are the central configuration unit — each target describes
 * one full generation pass: which queries to read, where to write, and
 * which namespace and engine to use.
 *
 * Example sqlc.yaml:
 *
 *   targets:
 *     - namespace: "App\\Database"
 *       out:       generated
 *       queries:
 *         - queries/users.sql
 *         - queries/orders.sql
 *
 *     - namespace: "App\\Database\\Admin"
 *       out:       generated/admin
 *       queries:   queries/admin.sql
 *       generate_interfaces: false   # override the default (true)
 *       language: spanish
 *       type_overrides:
 *         - column: "users.active"
 *           php_type: "bool"
 */
readonly class Target
{
    /**
     * @param string[]       $queries
     * @param TypeOverride[] $typeOverrides   Merged: global overrides + local overrides
     */
    public function __construct(
        public string $namespace,
        public string $out,
        public array  $queries,
        /** Default true — set false only to disable interface generation for this target. */
        public bool   $generateInterfaces = true,
        public array  $typeOverrides      = [],
        /** Database engine for this target — inherited from global if not specified. */
        public string $engine             = 'mysql',
        /** Inflection language for this target — inherited from global if not specified. */
        public string $language           = 'english',
        /**
         * When true, generated Query classes reuse PDOStatement objects across calls
         * via a private $stmts cache (using __FUNCTION__ as key).
         * Avoids re-preparing the same SQL on every invocation — useful in loops.
         * Default: false.
         */
        public bool   $preparedStatementCache = false,
        /**
         * Suffix appended to the generated Query/Repository class name.
         * Default: 'Query'  → UserQuery, OrderQuery, …
         * Example: 'Repository' → UserRepository, OrderRepository, …
         * Can also be set globally in sqlc.yaml under class_suffix:
         * and overridden per target.
         */
        public string $classSuffix = 'Query',
        /**
         * Optional database connection for --generate-schema.
         * If null, the global database config from Config is used.
         */
        public ?DatabaseConfig $database = null,
    ) {}

    /**
     * @param array<string, mixed> $data            Parsed YAML target block
     * @param TypeOverride[]       $globalOverrides  Merged from root type_overrides
     * @param string               $globalEngine     Global engine default
     * @param string               $globalLanguage   Global language default
     */
    public static function fromArray(
        array  $data,
        array  $globalOverrides  = [],
        string $globalEngine     = 'mysql',
        string $globalLanguage   = 'english',
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

        // generate_interfaces defaults to true — only false when explicitly set
        $rawGi = $data['generate_interfaces'] ?? null;
        $generateInterfaces = $rawGi === null
            ? true
            : filter_var($rawGi, FILTER_VALIDATE_BOOLEAN);

        return new self(
            namespace:             (string) ($data['namespace'] ?? 'App\\Database'),
            out:                   rtrim((string) ($data['out'] ?? 'generated'), '/'),
            queries:               $queries,
            generateInterfaces:    $generateInterfaces,
            typeOverrides:         array_merge($globalOverrides, $localOverrides),
            engine:                strtolower((string) ($data['engine']   ?? $globalEngine)),
            language:              strtolower((string) ($data['language'] ?? $globalLanguage)),
            preparedStatementCache: filter_var($data['prepared_statement_cache'] ?? false, FILTER_VALIDATE_BOOLEAN),
            classSuffix:           (string) ($data['class_suffix'] ?? $globalClassSuffix),
            database:              isset($data['database']) && is_array($data['database'])
                                       ? DatabaseConfig::fromArray($data['database'])
                                       : null,
        );
    }
}
