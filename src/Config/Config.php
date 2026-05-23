<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * Represents the parsed sqlc.yaml configuration.
 *
 * Both `schema` and `queries` accept a scalar string or a YAML list:
 *
 *   schema: database/schema.sql              # single file (legacy)
 *   schema:                                   # multiple files
 *     - database/schema/users.sql
 *     - database/schema/orders.sql
 *
 *   queries: database/queries/users.sql      # single file (legacy)
 *   queries:                                  # multiple files
 *     - database/queries/users.sql
 *     - database/queries/roles.sql
 */
class Config
{
    /**
     * @param string[]       $schemas       Always string[] — normalised from scalar or list
     * @param string[]       $queries       Always string[] — normalised from scalar or list
     * @param TypeOverride[] $typeOverrides
     */
    public function __construct(
        public readonly string $version,
        /** First schema file — kept for single-file backward compatibility. */
        public readonly string $schema,
        public readonly array  $queries,
        public readonly string $namespace,
        public readonly string $out,
        public readonly string $engine,
        public readonly array  $typeOverrides = [],
        /** When true, a *Interface file is generated alongside each Query class. */
        public readonly bool   $generateInterfaces = false,
        /** All schema files — always string[]. */
        public readonly array  $schemas = [],
    ) {}

    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        $raw  = file_get_contents($path) ?: '';
        $data = self::parseYaml($raw);
        $php  = $data['php'] ?? [];

        // schema: scalar or list
        $rawSchema = $data['schema'] ?? 'schema.sql';
        $schemas   = is_array($rawSchema)
            ? array_values(array_filter(array_map('strval', $rawSchema)))
            : [(string) $rawSchema];

        // queries: scalar or list
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
            version:            (string) ($data['version'] ?? '1'),
            schema:             $schemas[0] ?? 'schema.sql',
            queries:            $queries,
            namespace:          (string) ($php['namespace'] ?? 'App\\Database'),
            out:                (string) ($php['out']       ?? 'generated'),
            engine:             (string) ($php['engine']    ?? 'mysql'),
            typeOverrides:      $overrides,
            generateInterfaces: filter_var($php['generate_interfaces'] ?? false, FILTER_VALIDATE_BOOLEAN),
            schemas:            $schemas,
        );
    }

    // -------------------------------------------------------------------------
    // Minimal YAML parser
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

            $j = $i;
            while ($j < $total && trim($lines[$j]) === '') $j++;

            if ($j >= $total || strlen($lines[$j]) - strlen(ltrim($lines[$j])) === 0) {
                $result[$key] = '';
                continue;
            }

            $nextTrimmed = ltrim($lines[$j]);

            if (str_starts_with($nextTrimmed, '-')) {
                [$items, $i] = self::parseList($lines, $i, $total);
                $result[$key] = $items;
            } else {
                [$map, $i] = self::parseNestedMap($lines, $i, $total);
                $result[$key] = $map;
            }
        }

        return $result;
    }

    /** @return array{0: array<mixed>, 1: int} */
    private static function parseList(array $lines, int $i, int $total): array
    {
        $items       = [];
        $currentItem = null;
        $listIndent  = null;
        $isMapList   = null;

        while ($i < $total) {
            $line    = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) { $i++; continue; }

            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent === 0) break;

            if ($listIndent === null) $listIndent = $indent;

            if ($indent <= $listIndent && str_starts_with(ltrim($line), '-')) {
                if ($currentItem !== null) $items[] = $currentItem;

                $afterDash = trim((string) preg_replace('/^\s*-\s*/', '', $line));

                if ($isMapList === null) {
                    $isMapList = str_contains($afterDash, ':');
                }

                if ($isMapList) {
                    $currentItem = [];
                    if (preg_match('/^(\w+)\s*:\s*(.+)$/', $afterDash, $m)) {
                        $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
                    }
                } else {
                    $currentItem = self::parseScalar($afterDash);
                }

                $i++;
                continue;
            }

            if ($isMapList && is_array($currentItem)
                && preg_match('/^(\w+)\s*:\s*(.+)$/', $trimmed, $m)) {
                $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
            }

            $i++;
        }

        if ($currentItem !== null) $items[] = $currentItem;

        return [$items, $i];
    }

    /** @return array{0: array<string,string>, 1: int} */
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
