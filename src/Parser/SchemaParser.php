<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

/**
 * Represents a single column parsed from a CREATE TABLE statement.
 */
class ColumnDefinition
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $sqlType,
        public readonly bool    $nullable,
        public readonly bool    $autoIncrement,
        public readonly ?string $default,
        /** Non-empty only when sqlType === 'ENUM', contains the raw quoted values. */
        public readonly array   $enumValues = [],
    ) {}

    /** Returns true when this column is a MySQL ENUM. */
    public function isEnum(): bool
    {
        return $this->sqlType === 'ENUM' && !empty($this->enumValues);
    }
}

/**
 * Represents a parsed table with all its columns.
 */
class TableDefinition
{
    /** @param ColumnDefinition[] $columns */
    public function __construct(
        public readonly string $name,
        public readonly array  $columns,
    ) {}
}

/**
 * Parses MySQL CREATE TABLE SQL statements into structured TableDefinition objects.
 */
class SchemaParser
{
    /**
     * Parse all CREATE TABLE statements in the given SQL string.
     *
     * @return TableDefinition[]
     */
    public function parse(string $sql): array
    {
        $tables = [];

        // Remove single-line comments
        $sql = preg_replace('/--[^\n]*/', '', $sql);
        // Remove multi-line comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql ?? '');

        // Find each CREATE TABLE by locating the opening paren, then
        // scanning for the balanced closing paren manually.
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\(/si';

        if (!preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return $tables;
        }

        foreach ($matches[0] as $i => $match) {
            $tableName   = $matches[1][$i][0];
            $parenStart  = $match[1] + strlen($match[0]) - 1; // position of '('

            $columnBlock = $this->extractBalancedParens($sql, $parenStart);
            if ($columnBlock === null) continue;

            $columns  = $this->parseColumns($columnBlock);
            $tables[] = new TableDefinition($tableName, $columns);
        }

        return $tables;
    }

    /**
     * Given the position of an opening '(' in $sql, extract the content
     * between it and its matching closing ')', handling nesting correctly.
     */
    private function extractBalancedParens(string $sql, int $openPos): ?string
    {
        $depth  = 0;
        $start  = $openPos + 1;
        $len    = strlen($sql);

        for ($i = $openPos; $i < $len; $i++) {
            if ($sql[$i] === '(') $depth++;
            elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $start, $i - $start);
                }
            }
        }

        return null; // unbalanced
    }

    /** @return ColumnDefinition[] */
    private function parseColumns(string $block): array
    {
        $columns = [];
        $lines = $this->splitColumnLines($block);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip table constraints
            if (preg_match('/^\s*(PRIMARY\s+KEY|UNIQUE|KEY|INDEX|CONSTRAINT|FOREIGN)/i', $line)) {
                continue;
            }

            $col = $this->parseColumnLine($line);
            if ($col !== null) {
                $columns[] = $col;
            }
        }

        return $columns;
    }

    /**
     * Split column definitions respecting parentheses (e.g. DECIMAL(10,2)).
     *
     * @return string[]
     */
    private function splitColumnLines(string $block): array
    {
        $lines = [];
        $current = '';
        $depth = 0;

        for ($i = 0; $i < strlen($block); $i++) {
            $ch = $block[$i];
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth--;
            elseif ($ch === ',' && $depth === 0) {
                $lines[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        if (trim($current) !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function parseColumnLine(string $line): ?ColumnDefinition
    {
        // Match: `col_name` TYPE[(args)] [modifiers...]
        // We capture the full type token including its parenthesised args.
        $pattern = '/^[`"]?(\w+)[`"]?\s+(\w+(?:\([^)]*\))?)\s*(.*)/i';

        if (!preg_match($pattern, $line, $m)) {
            return null;
        }

        $name    = $m[1];
        $rawType = $m[2]; // e.g. "ENUM('a','b')" or "VARCHAR(45)"
        $rest    = $m[3];

        $nullable      = !str_contains(strtoupper($rest . ' ' . $rawType . ' ' . $line), 'NOT NULL');
        $autoIncrement = (bool) preg_match('/AUTO_INCREMENT/i', $rest);

        $default = null;
        if (preg_match("/DEFAULT\s+'?([^',\s]+)'?/i", $rest, $dm)) {
            $default = $dm[1];
        }

        // Extract base SQL type (strip display width / args)
        $sqlType = strtoupper(trim(preg_replace('/\(.*\)/s', '', $rawType) ?? $rawType));

        // For ENUM columns, parse the quoted values
        $enumValues = [];
        if ($sqlType === 'ENUM') {
            $enumValues = $this->parseEnumValues($rawType);
        }

        return new ColumnDefinition(
            name:          $name,
            sqlType:       $sqlType,
            nullable:      $nullable,
            autoIncrement: $autoIncrement,
            default:       $default,
            enumValues:    $enumValues,
        );
    }

    /**
     * Extract string values from ENUM('a', 'b', 'c').
     *
     * @return string[]
     */
    private function parseEnumValues(string $rawType): array
    {
        // Extract everything inside the parentheses
        if (!preg_match('/\((.+)\)/s', $rawType, $m)) {
            return [];
        }

        $inner  = $m[1];
        $values = [];

        // Match each 'value' token (single-quoted strings)
        preg_match_all("/'([^']*)'/", $inner, $matches);

        foreach ($matches[1] as $v) {
            if ($v !== '') {
                $values[] = $v;
            }
        }

        return $values;
    }
}
