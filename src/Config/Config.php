<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

use SqlcPhp\Parser\ColumnDefinition;
use SqlcPhp\Parser\TableDefinition;

/**
 * Represents the parsed sqlc.yaml configuration.
 *
 * Canonical format (v2):
 *
 *   version: "2"
 *
 *   schema:                              # one or many schema files (required)
 *     - database/schema/users.sql
 *
 *   engine:   mysql                      # global engine default (optional, default: mysql)
 *   language: english                    # inflection language (optional, default: english)
 *
 *   type_overrides:                      # global overrides applied to all targets
 *     - db_type: "TIMESTAMP"
 *       php_type: "\\DateTimeImmutable"
 *
 *   virtual_tables:                      # tables not in schema (views, mat. views, etc.)
 *     - name: user_summary
 *       columns:
 *         - { name: id,        type: INT }
 *         - { name: email,     type: VARCHAR }
 *         - { name: role_name, type: VARCHAR, nullable: true }
 *
 *   includes:                            # additional YAML fragments to merge
 *     - config/views/user_views.yaml
 *     - config/overrides/timestamps.yaml
 *
 *   targets:                             # required — one or more output targets
 *     - namespace: "App\\Database"
 *       out:       generated
 *       queries:
 *         - queries/users.sql
 *       engine:   mysql
 *       language: spanish
 *       generate_interfaces: false
 *       type_overrides:
 *         - column: "users.active"
 *           php_type: "bool"
 */
class Config
{
    /**
     * @param string[]                           $schemas
     * @param TypeOverride[]                     $typeOverrides
     * @param Target[]                           $targets
     * @param \SqlcPhp\Parser\TableDefinition[]  $virtualTables
     */
    public function __construct(
        public readonly string $version,
        public readonly array  $schemas,
        public readonly array  $typeOverrides = [],
        public readonly string $engine        = 'mysql',
        public readonly string $language      = 'english',
        public readonly array  $targets       = [],
        /**
         * Virtual tables declared via virtual_tables: or includes.
         * Registered in SchemaCatalog for type resolution;
         * no Model class is generated for them.
         *
         * @var \SqlcPhp\Parser\TableDefinition[]
         */
        public readonly array  $virtualTables = [],
    ) {}

    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        $baseDir = dirname(realpath($path));
        $raw     = file_get_contents($path) ?: '';
        $data    = self::parseYaml($raw);

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

        // Scalar globals — includes cannot override these
        $globalEngine   = strtolower((string) ($data['engine']   ?? 'mysql'));
        $globalLanguage = strtolower((string) ($data['language'] ?? 'english'));

        // Load includes first — main file values are merged on top
        $includeData = self::loadIncludes(
            is_array($data['includes'] ?? null) ? $data['includes'] : [],
            $baseDir,
            $path
        );

        // type_overrides: includes first, main file appended after
        // (evaluation is first-match-wins, so main file entries take effect last
        //  for db_type overrides, and column-specific always win)
        $globalOverrides = self::parseTypeOverrides(
            array_merge($includeData['type_overrides'], $data['type_overrides'] ?? [])
        );

        // virtual_tables: accumulated from all includes + main file
        $virtualTables = self::parseVirtualTables(
            array_merge($includeData['virtual_tables'], $data['virtual_tables'] ?? [])
        );

        // targets: includes first, main file appended after
        $rawTargets = array_merge(
            $includeData['targets'],
            is_array($data['targets'] ?? null) ? $data['targets'] : []
        );

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
            virtualTables: $virtualTables,
        );
    }

    // -------------------------------------------------------------------------
    // Includes
    // -------------------------------------------------------------------------

    /**
     * Load include files and accumulate their mergeable sections.
     *
     * Supported sections in include files: virtual_tables, type_overrides, targets.
     * Scalar fields (engine, language) in include files are silently ignored.
     *
     * @param  string[]  $includePaths  Relative or absolute paths
     * @param  string    $baseDir       Directory of the main config file
     * @param  string    $mainPath      Main config path (for error messages only)
     * @return array{virtual_tables: array, type_overrides: array, targets: array}
     */
    private static function loadIncludes(array $includePaths, string $baseDir, string $mainPath): array
    {
        $accumulated = [
            'virtual_tables' => [],
            'type_overrides' => [],
            'targets'        => [],
        ];

        foreach ($includePaths as $rawPath) {
            $includePath = self::resolvePath((string) $rawPath, $baseDir);

            if (!file_exists($includePath)) {
                throw new \RuntimeException(
                    "Config '{$mainPath}': include file not found: {$rawPath} " .
                    "(resolved to: {$includePath})"
                );
            }

            $raw  = file_get_contents($includePath) ?: '';
            $data = self::parseYaml($raw);

            foreach (['virtual_tables', 'type_overrides', 'targets'] as $section) {
                if (!empty($data[$section]) && is_array($data[$section])) {
                    $accumulated[$section] = array_merge(
                        $accumulated[$section],
                        $data[$section]
                    );
                }
            }
        }

        return $accumulated;
    }

    private static function resolvePath(string $path, string $baseDir): string
    {
        // Already absolute
        if (str_starts_with($path, '/') || preg_match('/^[A-Z]:\\\\/i', $path)) {
            return $path;
        }
        return $baseDir . DIRECTORY_SEPARATOR . $path;
    }

    // -------------------------------------------------------------------------
    // virtual_tables: parsing
    // -------------------------------------------------------------------------

    /**
     * Parse virtual table definitions.
     *
     * Column nullability defaults to FALSE (NOT NULL) — specify nullable: true
     * to mark a column as nullable. This is the inverse of schema parsing.
     *
     * @param  array<mixed>  $raw
     * @return \SqlcPhp\Parser\TableDefinition[]
     */
    private static function parseVirtualTables(array $raw): array
    {
        $tables = [];

        foreach ($raw as $entry) {
            if (!is_array($entry) || empty($entry['name'])) continue;

            $tableName = (string) $entry['name'];
            $columns   = [];

            foreach ($entry['columns'] ?? [] as $colEntry) {
                if (!is_array($colEntry)) continue;

                $colName  = (string) ($colEntry['name'] ?? '');
                $sqlType  = strtoupper((string) ($colEntry['type'] ?? 'VARCHAR'));
                // Default: NOT NULL. Only nullable when explicitly declared.
                $nullable = filter_var($colEntry['nullable'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if ($colName === '') continue;

                $columns[] = new ColumnDefinition(
                    name:          $colName,
                    sqlType:       $sqlType,
                    nullable:      $nullable,
                    autoIncrement: false,
                    default:       null,
                    enumValues:    [],
                );
            }

            $tables[] = new TableDefinition(
                name:    $tableName,
                columns: $columns,
                virtual: true,
            );
        }

        return $tables;
    }

    // -------------------------------------------------------------------------
    // type_overrides: parsing
    // -------------------------------------------------------------------------

    /**
     * @param  array<mixed>  $raw
     * @return TypeOverride[]
     */
    private static function parseTypeOverrides(array $raw): array
    {
        $overrides = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) continue;
            try {
                $overrides[] = TypeOverride::fromArray($entry);
            } catch (\InvalidArgumentException $e) {
                fwrite(STDERR, "Warning: skipping type_override — " . $e->getMessage() . "\n");
            }
        }
        return $overrides;
    }

    // -------------------------------------------------------------------------
    // Minimal YAML parser (methods follow below)
    // -------------------------------------------------------------------------

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

    /**
     * Parse an inline YAML map: "name: id, type: INT, nullable: true"
     * (without the surrounding braces, which are stripped by the caller)
     *
     * @return array<string, string>
     */
    private static function parseInlineMap(string $inner): array
    {
        $map   = [];
        // Split on commas not inside quotes
        $pairs = preg_split('/,\s*(?=\w+\s*:)/', $inner) ?: [];

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (preg_match('/^(\w+)\s*:\s*(.+)$/', $pair, $m)) {
                $map[trim($m[1])] = self::parseScalar(trim($m[2]));
            }
        }

        return $map;
    }

    /** @return array{0: array<mixed>, 1: int} */
    private static function parseList(array $lines, int $i, int $total, int $minIndent = 0): array
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

            // Stop when indent drops to or below the caller's level
            if ($indent <= $minIndent) break;

            if ($listIndent === null) $listIndent = $indent;

            // New list item at the list indent level
            if ($indent <= $listIndent && str_starts_with(ltrim($line), '-')) {
                if ($currentItem !== null) $items[] = $currentItem;

                $afterDash = trim((string) preg_replace('/^\s*-\s*/', '', $line));

                if ($isMapList === null) {
                    $isMapList = str_contains($afterDash, ':');
                }

                if ($isMapList) {
                    $currentItem = [];
                    if (preg_match('/^\{(.+)\}$/', $afterDash, $bm)) {
                        $currentItem = self::parseInlineMap($bm[1]);
                    } elseif (preg_match('/^(\w+)\s*:\s*(.+)$/', $afterDash, $m)) {
                        $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
                    } elseif (preg_match('/^(\w+)\s*:\s*$/', $afterDash, $m)) {
                        $currentItem[$m[1]] = '';
                    }
                } else {
                    $currentItem = self::parseScalar($afterDash);
                }

                $i++;
                continue;
            }

            // Continuation lines for the current map item
            if ($isMapList && is_array($currentItem) && $indent > ($listIndent ?? 0)) {
                if (preg_match('/^(\w+)\s*:\s*(.+)$/', $trimmed, $m)) {
                    $currentItem[$m[1]] = self::parseScalar(trim($m[2]));
                    $i++;
                } elseif (preg_match('/^(\w+)\s*:\s*$/', $trimmed, $m)) {
                    $key = $m[1];
                    $i++;
                    $j = $i;
                    while ($j < $total && trim($lines[$j]) === '') $j++;

                    if ($j < $total) {
                        $nextIndent  = strlen($lines[$j]) - strlen(ltrim($lines[$j]));
                        $nextTrimmed = ltrim($lines[$j]);

                        if ($nextIndent > $indent && str_starts_with($nextTrimmed, '-')) {
                            // Nested list — pass current indent as the stop level
                            [$nestedItems, $i] = self::parseList($lines, $i, $total, $indent);
                            $currentItem[$key] = $nestedItems;
                        } else {
                            $currentItem[$key] = '';
                        }
                    } else {
                        $currentItem[$key] = '';
                    }
                } else {
                    $i++;
                }
                continue;
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
