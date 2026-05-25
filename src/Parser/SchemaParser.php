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
 *
 * Handles:
 *   - Backtick and double-quote quoted identifiers: `table_name`, `col_name`
 *   - ENUM columns with quoted values
 *   - PRIMARY KEY / AUTO_INCREMENT constraints
 *   - DEFAULT values including single-quoted strings with escaped quotes
 *   - Nested parentheses (DECIMAL(10,2), ENUM('a','b'))
 *   - Multi-line schemas
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

        // Find each CREATE TABLE — support backtick, double-quote, or unquoted table names
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\(/si';

        if (!preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            return $tables;
        }

        foreach ($matches[0] as $i => $match) {
            $tableName  = $matches[1][$i][0];
            $parenStart = $match[1] + strlen($match[0]) - 1; // position of '('

            $columnBlock = $this->extractBalancedParens($sql, $parenStart);
            if ($columnBlock === null) continue;

            $columns  = $this->parseColumns($columnBlock);
            $tables[] = new TableDefinition($tableName, $columns);
        }

        return $tables;
    }

    // -------------------------------------------------------------------------

    /**
     * Given the position of an opening '(' in $sql, extract the content
     * between it and its matching closing ')', respecting nested parens
     * and string literals so that DEFAULT 'value(with paren)' is handled.
     */
    private function extractBalancedParens(string $sql, int $openPos): ?string
    {
        $depth  = 0;
        $start  = $openPos + 1;
        $len    = strlen($sql);
        $inStr  = false;
        $strCh  = '';

        for ($i = $openPos; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($inStr) {
                if ($ch === '\\') { $i++; continue; }     // escaped char
                if ($ch === $strCh) { $inStr = false; }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inStr = true;
                $strCh = $ch;
                continue;
            }

            if ($ch === '(') $depth++;
            elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $start, $i - $start);
                }
            }
        }

        return null;
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
     * Split column definitions respecting parentheses AND string literals.
     * e.g. ENUM('a','b'), DEFAULT 'value,with,commas'
     *
     * @return string[]
     */
    private function splitColumnLines(string $block): array
    {
        $lines   = [];
        $current = '';
        $depth   = 0;
        $inStr   = false;
        $strCh   = '';
        $len     = strlen($block);

        for ($i = 0; $i < $len; $i++) {
            $ch = $block[$i];

            if ($inStr) {
                if ($ch === '\\') {
                    $current .= $ch . ($block[$i + 1] ?? '');
                    $i++;
                    continue;
                }
                if ($ch === $strCh) {
                    $inStr = false;
                }
                $current .= $ch;
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inStr = true;
                $strCh = $ch;
                $current .= $ch;
                continue;
            }

            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth--;
            elseif ($ch === ',' && $depth === 0) {
                $lines[]  = $current;
                $current  = '';
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
        // Strip leading/trailing whitespace
        $line = trim($line);

        // Match: [`"]?col_name[`"]? TYPE[(args)] [modifiers...]
        // Supports backtick and double-quote quoted identifiers
        $pattern = '/^[`"]?(\w+)[`"]?\s+(\w+(?:\s*\([^)]*\))?)\s*(.*)/si';

        if (!preg_match($pattern, $line, $m)) {
            return null;
        }

        $name    = $m[1];
        $rawType = trim($m[2]);
        $rest    = trim($m[3]);

        // Nullable: false when NOT NULL, PRIMARY KEY (implicit NOT NULL), or AUTO_INCREMENT
        $upper         = strtoupper($line);
        $hasNotNull    = str_contains($upper, 'NOT NULL');
        $hasPrimaryKey = str_contains($upper, 'PRIMARY KEY');
        $autoIncrement = (bool) preg_match('/AUTO_INCREMENT/i', $rest);
        $nullable      = !$hasNotNull && !$hasPrimaryKey && !$autoIncrement;

        // DEFAULT value — handles quoted strings including escaped apostrophes
        $default = $this->extractDefault($rest);

        // Base SQL type (strip display width / args)
        $sqlType = strtoupper(trim(preg_replace('/\s*\(.*\)/s', '', $rawType) ?? $rawType));

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
     * Extract the DEFAULT value from the column modifier string.
     * Handles: DEFAULT 123, DEFAULT 'string', DEFAULT 'it''s ok', DEFAULT NULL
     */
    private function extractDefault(string $rest): ?string
    {
        // Unquoted default (number, keyword like NULL/CURRENT_TIMESTAMP)
        if (preg_match('/DEFAULT\s+(NULL|CURRENT_TIMESTAMP|[\d.]+)/i', $rest, $dm)) {
            return strtoupper($dm[1]) === 'NULL' ? null : $dm[1];
        }

        // Quoted string default — match 'value' including escaped '' or \'
        if (preg_match("/DEFAULT\s+'((?:[^'\\\\]|\\\\.|'')*)'/i", $rest, $dm)) {
            return str_replace("''", "'", $dm[1]);
        }

        return null;
    }

    /**
     * Extract string values from ENUM('a', 'b', 'c').
     *
     * @return string[]
     */
    private function parseEnumValues(string $rawType): array
    {
        if (!preg_match('/\((.+)\)/s', $rawType, $m)) {
            return [];
        }

        $inner  = $m[1];
        $values = [];

        preg_match_all("/'([^']*)'/", $inner, $matches);

        foreach ($matches[1] as $v) {
            if ($v !== '') {
                $values[] = $v;
            }
        }

        return $values;
    }
}
