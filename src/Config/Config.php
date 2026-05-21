<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * Represents the parsed sqlc.yaml configuration.
 */
class Config
{
    /**
     * @param TypeOverride[] $typeOverrides
     */
    public function __construct(
        public readonly string $version,
        public readonly string $schema,
        public readonly string $queries,
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

        $overrides = [];
        foreach ($data['type_overrides'] ?? [] as $entry) {
            try {
                $overrides[] = TypeOverride::fromArray($entry);
            } catch (\InvalidArgumentException $e) {
                fwrite(STDERR, "Warning: skipping type_override — " . $e->getMessage() . "\n");
            }
        }

        return new self(
            version:       (string) ($data['version'] ?? '1'),
            schema:        (string) ($data['schema']  ?? 'schema.sql'),
            queries:       (string) ($data['queries'] ?? 'queries.sql'),
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
    //   version: "1"                          top-level scalars
    //   php:                                  nested section
    //     namespace: "App\\Db"
    //   type_overrides:                       list of maps
    //     - column: "users.metadata"
    //       php_type: "array"
    //     - db_type: "tinyint"
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

            // Top-level key: value
            if ($indent === 0 && preg_match('/^(\w+)\s*:\s*(.*)$/', $trimmed, $m)) {
                $key   = $m[1];
                $value = self::parseScalar(trim($m[2]));

                if ($value !== '') {
                    // Scalar value on same line
                    $result[$key] = $value;
                } else {
                    // Block value — peek ahead to determine type
                    // Look at next non-empty line
                    $j = $i;
                    while ($j < $total && trim($lines[$j]) === '') $j++;

                    if ($j < $total && str_starts_with(ltrim($lines[$j]), '-')) {
                        // List of maps: type_overrides: \n  - ...
                        [$items, $i] = self::parseListOfMaps($lines, $i, $total);
                        $result[$key] = $items;
                    } else {
                        // Nested map: php: \n  namespace: ...
                        [$map, $i] = self::parseNestedMap($lines, $i, $total);
                        $result[$key] = $map;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Parse a YAML list of maps starting at line $i.
     * Each item begins with a `-` at indent > 0.
     *
     * @return array{0: array<int, array<string,string>>, 1: int}
     */
    private static function parseListOfMaps(array $lines, int $i, int $total): array
    {
        $items       = [];
        $currentItem = null;
        $listIndent  = null;

        while ($i < $total) {
            $line    = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) { $i++; continue; }

            $indent = strlen($line) - strlen(ltrim($line));

            // Back to top level — stop
            if ($indent === 0) break;

            // Set list indent on first item
            if ($listIndent === null) $listIndent = $indent;

            // A new list item
            if ($indent <= $listIndent && str_starts_with(ltrim($line), '-')) {
                if ($currentItem !== null) $items[] = $currentItem;
                $currentItem = [];

                // Inline key on the same line as `-`: `- column: "users.foo"`
                $afterDash = preg_replace('/^\s*-\s*/', '', $line);
                if ($afterDash !== null && preg_match('/^(\w+)\s*:\s*(.+)$/', trim($afterDash), $m)) {
                    $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
                }
                $i++;
                continue;
            }

            // Key: value inside a list item
            if ($currentItem !== null && preg_match('/^(\w+)\s*:\s*(.+)$/', $trimmed, $m)) {
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
        $map        = [];
        $mapIndent  = null;

        while ($i < $total) {
            $line    = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) { $i++; continue; }

            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent === 0) break;  // back to top level

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

        // Strip inline comment
        $raw = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;

        return trim($raw);
    }
}
