<?php

declare(strict_types=1);

namespace SqlcPhp\Config;

/**
 * Subset-YAML parser for sqlc.yaml configuration files.
 *
 * This parser is the fallback implementation used via the Symfony\Yaml shim
 * when symfony/yaml is not installed. When running in production (installed
 * via Composer), symfony/yaml is used directly and this class is bypassed.
 *
 * Supports:
 *   - Top-level scalar keys: version, engine, language, class_suffix
 *   - Block sequences (lists) at any nesting level
 *   - Block mappings (maps) at any nesting level
 *   - Inline flow maps: { name: id, type: INT }
 *   - ${ENV_VAR} expansion is handled separately by DatabaseConfig
 *   - Single-quoted and double-quoted string scalars
 *   - Inline comments (# ...)
 *
 * @internal  Used only as a fallback shim — prefer symfony/yaml in production.
 */
final class YamlParser
{
    /** @return array<string, mixed> */
    public static function parse(string $content): array
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

    // -------------------------------------------------------------------------

    /**
     * Parse an inline YAML map: "name: id, type: INT, nullable: true"
     * (without the surrounding braces, which are stripped by the caller)
     *
     * @return array<string, string>
     */
    private static function parseInlineMap(string $inner): array
    {
        $map   = [];
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

            if ($indent <= $minIndent) break;

            if ($listIndent === null) $listIndent = $indent;

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
                            [$nestedItems, $i] = self::parseList($lines, $i, $total, $indent);
                            $currentItem[$key] = $nestedItems;
                        } elseif ($nextIndent > $indent) {
                            [$nestedMap, $i] = self::parseNestedMap($lines, $i, $total);
                            $currentItem[$key] = $nestedMap;
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

    /** @return array{0: array<string, mixed>, 1: int} */
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
                        [$items, $i] = self::parseList($lines, $i, $total, $indent);
                        $map[$key]   = $items;
                    } elseif ($nextIndent > $indent) {
                        [$subMap, $i] = self::parseNestedMap($lines, $i, $total);
                        $map[$key]    = $subMap;
                    } else {
                        $map[$key] = '';
                    }
                } else {
                    $map[$key] = '';
                }
            } else {
                $i++;
            }
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
