<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * A single output target — allows generating multiple namespaces/directories
 * from the same schema in one sqlc.yaml.
 *
 * Example:
 *   targets:
 *     - namespace: "App\\Database\\Read"
 *       out:       generated/read
 *       queries:
 *         - queries/read/users.sql
 *     - namespace: "App\\Database\\Write"
 *       out:       generated/write
 *       queries:
 *         - queries/write/users.sql
 */
readonly class Target
{
    /**
     * @param string[]       $queries
     * @param TypeOverride[] $typeOverrides
     */
    public function __construct(
        public string $namespace,
        public string $out,
        public array  $queries,
        public bool   $generateInterfaces = false,
        public array  $typeOverrides      = [],
    ) {}

    /**
     * @param array<string, mixed> $data        Parsed YAML target block
     * @param TypeOverride[]       $globalOverrides  Merged from the root type_overrides
     */
    public static function fromArray(array $data, array $globalOverrides = []): self
    {
        $rawQueries = $data['queries'] ?? [];
        $queries    = is_array($rawQueries)
            ? array_values(array_filter(array_map('strval', $rawQueries)))
            : [(string) $rawQueries];

        // Local overrides are merged on top of global overrides
        $localOverrides = [];
        foreach ($data['type_overrides'] ?? [] as $entry) {
            if (!is_array($entry)) continue;
            try {
                $localOverrides[] = TypeOverride::fromArray($entry);
            } catch (\InvalidArgumentException) {}
        }

        return new self(
            namespace:          (string) ($data['namespace'] ?? 'App\\Database'),
            out:                rtrim((string) ($data['out'] ?? 'generated'), '/'),
            queries:            $queries,
            generateInterfaces: filter_var($data['generate_interfaces'] ?? false, FILTER_VALIDATE_BOOLEAN),
            typeOverrides:      array_merge($globalOverrides, $localOverrides),
        );
    }
}
