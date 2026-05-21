<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * Represents the parsed sqlc.yaml configuration.
 *
 * `queries` accepts both a scalar string and a YAML list of strings:
 *
 *   queries: database/queries/users.sql          # single file (legacy)
 *
 *   queries:                                     # multiple files
 *     - database/queries/users.sql
 *     - database/queries/roles.sql
 */
class Config
{
    /**
     * @param string[]       $queries
     * @param TypeOverride[] $typeOverrides
     */
    public function __construct(
        public readonly string $version,
        public readonly string $schema,
        public readonly array  $queries,       // always string[]
        public readonly string $namespace,
        public readonly string $out,
        public readonly string $engine,
        public readonly array  $typeOverrides = [],
    ) {}

    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        $raw  = file_get_contents($path) ?: '';
        $data = self::parseYaml($raw);
        $php  = $data['php'] ?? [];

        // queries: accepts string (single) or string[] (list)
        $rawQueries = $data['queries'] ?? 'queries.sql';
        $queries    = is_array($rawQueries)
            ? array_values(array_filter(array_map('strval', $rawQueries)))
            : [(string) $rawQueries];

        $overrides = [];
        foreach ($data['type_overrides'] ?? [] as $entry) {
            if (!is_array($entry)) continue;
            try {
                $overrides[] = TypeOverride::fromArray($entry);
            } catch (\InvalidArgumentException $e) {
                fwrite(STDERR, "Warning: skipping type_override — " . $e->getMessage() . "\n");
            }
        }

        return new self(
            version:       (string) ($data['version'] ?? '1'),
            schema:        (string) ($data['schema']  ?? 'schema.sql'),
            queries:       $queries,
            namespace:     (string) ($php['namespace'] ?? 'App\\Database'),
            out:           (string) ($php['out']       ?? 'generated'),
            engine:        (string) ($php['engine']    ?? 'mysql'),
            typeOverrides: $overrides,
        );
    }

    // -------------------------------------------------------------------------
    // Minimal YAML parser
    //
    // Supports:
    //   version: "1"                      top-level scalars
    //   schema:  path/to/schema.sql
    //   queries: path/to/queries.sql      scalar (single file)
    //   queries:                          list of scalars (multiple files)
    //     - path/to/users.sql
    //     - path/to/roles.sql
    //   php:                              nested map
    //     namespace: "App\\Db"
    //   type_overrides:                   list of maps
    //     - column: "users.active"
    //       php_type: "bool"
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function parseYaml(string $content): array
    {
        $result = [];
        $lines  = explode("\n", $content);
        $i      = 0;
        $total  = count($lines);

        while ($i < $total) {
            $line    = $lines[$i];
            $trimmed = trim($line);
            $i++;

            if ($trimmed === '' || str_starts_with($trimmed, '#')) continue;

            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent !== 0 || !preg_match('/^(\w+)\s*:\s*(.*)$/', $trimmed, $m)) {
                continue;
            }

            $key   = $m[1];
            $value = self::parseScalar(trim($m[2]));

            if ($value !== '') {
                $result[$key] = $value;
                continue;
            }

            // Block value — peek at next non-empty line to decide the type
            $j = $i;
            while ($j < $total && trim($lines[$j]) === '') $j++;

            if ($j >= $total || strlen($lines[$j]) - strlen(ltrim($lines[$j])) === 0) {
                // No indented block follows — treat as empty string
                $result[$key] = '';
                continue;
            }

            $nextTrimmed = ltrim($lines[$j]);

            if (str_starts_with($nextTrimmed, '-')) {
                // Could be a list of scalars OR a list of maps — detect by content
                [$items, $i] = self::parseList($lines, $i, $total);
                $result[$key] = $items;
            } else {
                // Nested map
                [$map, $i] = self::parseNestedMap($lines, $i, $total);
                $result[$key] = $map;
            }
        }

        return $result;
    }

    /**
     * Parse a YAML list starting at line $i.
     * Detects whether items are scalars (`- value`) or maps (`- key: value`).
     *
     * Returns a flat string[] for scalar lists, array[] for map lists.
     *
     * @return array{0: array<mixed>, 1: int}
     */
    private static function parseList(array $lines, int $i, int $total): array
    {
        $items       = [];
        $currentItem = null;   // null = scalar mode, array = map mode
        $listIndent  = null;
        $isMapList   = null;   // determined on first item

        while ($i < $total) {
            $line    = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) { $i++; continue; }

            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent === 0) break;  // back to top level

            if ($listIndent === null) $listIndent = $indent;

            if ($indent <= $listIndent && str_starts_with(ltrim($line), '-')) {
                // Flush previous item
                if ($currentItem !== null) $items[] = $currentItem;

                $afterDash = trim((string) preg_replace('/^\s*-\s*/', '', $line));

                if ($isMapList === null) {
                    // Determine list type from the first item's content
                    $isMapList = str_contains($afterDash, ':');
                }

                if ($isMapList) {
                    // Map item — may have inline first key
                    $currentItem = [];
                    if (preg_match('/^(\w+)\s*:\s*(.+)$/', $afterDash, $m)) {
                        $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
                    }
                } else {
                    // Scalar item — the value is the text after the dash
                    $currentItem = self::parseScalar($afterDash);
                }

                $i++;
                continue;
            }

            // Additional key: value lines inside a map item
            if ($isMapList && is_array($currentItem)
                && preg_match('/^(\w+)\s*:\s*(.+)$/', $trimmed, $m)) {
                $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
            }

            $i++;
        }

        if ($currentItem !== null) $items[] = $currentItem;

        return [$items, $i];
    }

    /**
     * Parse a nested YAML map (e.g. the `php:` block).
     *
     * @return array{0: array<string,string>, 1: int}
     */
    private static function parseNestedMap(array $lines, int $i, int $total): array
    {
        $map       = [];
        $mapIndent = null;

        while ($i < $total) {
            $line    = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) { $i++; continue; }

            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent === 0) break;

            if ($mapIndent === null) $mapIndent = $indent;
            if ($indent < $mapIndent) break;

            if (preg_match('/^(\w+)\s*:\s*(.+)$/', $trimmed, $m)) {
                $map[$m[1]] = self::parseScalar(trim($m[2]));
            }

            $i++;
        }

        return [$map, $i];
    }

    private static function parseScalar(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^"([^"]*)"/', $raw, $m)) return $m[1];
        if (preg_match("/^'([^']*)'/", $raw, $m)) return $m[1];

        $raw = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;

        return trim($raw);
    }
}
