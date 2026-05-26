<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * Represents the parsed sqlc.yaml configuration.
 *
 * Canonical format (v2):
 *
 *   version: "2"
 *
 *   schema:                              # one or many schema files (required)
 *     - database/schema/users.sql
 *     - database/schema/orders.sql
 *
 *   engine:   mysql                      # global engine default (optional, default: mysql)
 *   language: english                    # inflection language (optional, default: english)
 *
 *   type_overrides:                      # global overrides applied to all targets
 *     - db_type: "TIMESTAMP"
 *       php_type: "\\DateTimeImmutable"
 *       nullable: true
 *
 *   targets:                             # required — one or more output targets
 *     - namespace: "App\\Database"
 *       out:       generated
 *       queries:                         # one or many query files
 *         - queries/users.sql
 *       engine:   mysql                  # overrides global engine for this target
 *       language: spanish                # overrides global language for this target
 *       generate_interfaces: false       # default: true — set false to disable
 *       type_overrides:                  # merged on top of global overrides
 *         - column: "users.active"
 *           php_type: "bool"
 */
class Config
{
    /**
     * @param string[]       $schemas       Always string[] — normalised from scalar or list
     * @param TypeOverride[] $typeOverrides Global type overrides
     * @param Target[]       $targets       Output targets (always non-empty after validation)
     */
    public function __construct(
        public readonly string $version,
        /** All schema files — always string[]. */
        public readonly array  $schemas,
        /** Global type overrides — applied to all targets unless overridden locally. */
        public readonly array  $typeOverrides = [],
        /** Global engine default — can be overridden per target. */
        public readonly string $engine        = 'mysql',
        /** Global language for inflection — can be overridden per target. */
        public readonly string $language      = 'english',
        /** Output targets — always at least one. */
        public readonly array  $targets       = [],
    ) {}

    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        $raw  = file_get_contents($path) ?: '';
        $data = self::parseYaml($raw);

        // schema: scalar or list (required)
        $rawSchema = $data['schema'] ?? null;
        if ($rawSchema === null) {
            throw new \RuntimeException(
                "Config '{$path}': missing required 'schema' field."
            );
        }
        $schemas = is_array($rawSchema)
            ? array_values(array_filter(array_map('strval', $rawSchema)))
            : [(string) $rawSchema];

        // Global engine and language
        $globalEngine   = strtolower((string) ($data['engine']   ?? 'mysql'));
        $globalLanguage = strtolower((string) ($data['language'] ?? 'english'));

        // Global type_overrides
        $globalOverrides = [];
        foreach ($data['type_overrides'] ?? [] as $entry) {
            if (!is_array($entry)) continue;
            try {
                $globalOverrides[] = TypeOverride::fromArray($entry);
            } catch (\InvalidArgumentException $e) {
                fwrite(STDERR, "Warning: skipping type_override — " . $e->getMessage() . "\n");
            }
        }

        // targets: (required)
        $rawTargets = $data['targets'] ?? null;
        if (empty($rawTargets)) {
            throw new \RuntimeException(
                "Config '{$path}': missing required 'targets' field. " .
                "Define at least one target under 'targets:'."
            );
        }

        $targets = [];
        foreach ($rawTargets as $entry) {
            if (!is_array($entry)) continue;
            $targets[] = Target::fromArray(
                $entry,
                $globalOverrides,
                $globalEngine,
                $globalLanguage,
            );
        }

        if (empty($targets)) {
            throw new \RuntimeException(
                "Config '{$path}': 'targets' is defined but contains no valid entries."
            );
        }

        return new self(
            version:       (string) ($data['version'] ?? '2'),
            schemas:       $schemas,
            typeOverrides: $globalOverrides,
            engine:        $globalEngine,
            language:      $globalLanguage,
            targets:       $targets,
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
                    } elseif (preg_match('/^(\w+)\s*:\s*$/', $afterDash, $m)) {
                        // key with empty value — will be filled by subsequent lines
                        $currentItem[$m[1]] = '';
                    }
                } else {
                    $currentItem = self::parseScalar($afterDash);
                }

                $i++;
                continue;
            }

            if ($isMapList && is_array($currentItem)) {
                // Check if this line is a key: value pair
                if (preg_match('/^(\w+)\s*:\s*(.+)$/', $trimmed, $m)) {
                    $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
                } elseif (preg_match('/^(\w+)\s*:\s*$/', $trimmed, $m)) {
                    // Key with no inline value — check next lines for a nested list
                    $key = $m[1];
                    $i++;
                    // Peek ahead
                    $j = $i;
                    while ($j < $total && trim($lines[$j]) === '') $j++;

                    if ($j < $total && strlen($lines[$j]) - strlen(ltrim($lines[$j])) > $indent
                        && str_starts_with(ltrim($lines[$j]), '-')) {
                        // Nested list
                        [$nestedItems, $i] = self::parseList($lines, $i, $total);
                        $currentItem[$key] = $nestedItems;
                    } else {
                        $currentItem[$key] = '';
                    }
                    continue;
                }
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

        if (preg_match('/^"([^"]*)"/', $raw, $m)) {
            return str_replace(['\\\\', '\\"', '\\n', '\\t'], ['\\', '"', "\n", "\t"], $m[1]);
        }
        if (preg_match("/^'([^']*)'/", $raw, $m)) return $m[1];

        $raw = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;

        return trim($raw);
    }
}
