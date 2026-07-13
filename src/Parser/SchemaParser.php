<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

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
     * Parses both CREATE TABLE and CREATE [OR REPLACE] VIEW statements.
     * Views are stored as TableDefinition with virtual=true and columns
     * inferred from the SELECT column list.
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

        // ── Parse CREATE TABLE statements ────────────────────────────────────
        $tablePattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`\"]?(\w+)[`\"]?\s*\(/si';

        if (preg_match_all($tablePattern, $sql, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $tableName  = $matches[1][$i][0];
                $parenStart = $match[1] + strlen($match[0]) - 1;

                $columnBlock = $this->extractBalancedParens($sql, $parenStart);
                if ($columnBlock === null) continue;

                $columns  = $this->parseColumns($columnBlock);
                $tables[] = new TableDefinition($tableName, $columns);
            }
        }

        // ── Parse CREATE [OR REPLACE] VIEW statements ────────────────────────
        $viewPattern = '/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+[`\"]?(\w+)[`\"]?\s+AS\s+(SELECT\b.+?)(?=;|\Z)/si';

        if (preg_match_all($viewPattern, $sql, $vMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($vMatches[1] as $i => $nameMatch) {
                $viewName  = $nameMatch[0];
                $selectSql = trim($vMatches[2][$i][0]);
                $columns   = $this->parseViewColumns($selectSql);
                $tables[]  = new TableDefinition($viewName, $columns, virtual: true);
            }
        }

        return $tables;
    }

    // -------------------------------------------------------------------------

    /**
     * Given the position of an opening '(' in $sql, extract the content
     * between it and its matching closing ')', respecting nested parens
     * and string literals so that DEFAULT 'value(with paren)' is handled.
     */
    // -------------------------------------------------------------------------

    /**
     * Infer column definitions from a VIEW's SELECT clause.
     *
     * Extracts the outer SELECT list (between SELECT and the first top-level FROM),
     * respecting nested parentheses so subqueries like
     *   (SELECT COUNT(*) FROM orders ...) AS order_count
     * are treated as a single item.
     *
     * @return ColumnDefinition[]
     */
    private function parseViewColumns(string $selectSql): array
    {
        // Find the outer SELECT list — everything between SELECT and the first
        // top-level FROM keyword (depth=0 means not inside any parentheses).
        $selectList = $this->extractOuterSelectList($selectSql);

        $columns = [];
        $items   = $this->splitSelectList($selectList);

        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '' || strtoupper($item) === '*') continue;

            // Extract alias: `... AS alias` or `... AS \`alias\``
            if (preg_match('/\bAS\s+[`"]?(\w+)[`"]?\s*$/i', $item, $m)) {
                $colName = $m[1];
            } else {
                // No alias — use the last bare identifier (e.g. table.col_name → col_name)
                if (preg_match('/[`"]?(\w+)[`"]?\s*$/', $item, $m)) {
                    $colName = $m[1];
                } else {
                    continue;
                }
            }

            $columns[] = new ColumnDefinition(
                name:          $colName,
                sqlType:       'VARCHAR',
                nullable:      true,
                autoIncrement: false,
                default:       null,
            );
        }

        return $columns;
    }

    /**
     * Extract the SELECT list: everything between SELECT and the first
     * top-level FROM (not inside parentheses).
     */
    private function extractOuterSelectList(string $selectSql): string
    {
        // Skip the leading SELECT keyword
        $pos = stripos($selectSql, 'SELECT');
        if ($pos === false) return $selectSql;
        $pos += strlen('SELECT');

        $depth   = 0;
        $start   = $pos;
        $len     = strlen($selectSql);
        $inStr   = false;
        $strChar = '';

        for ($i = $pos; $i < $len; $i++) {
            $ch = $selectSql[$i];

            // Track string literals to avoid treating FROM inside strings as keywords
            if (!$inStr && ($ch === "'" || $ch === '"')) {
                $inStr   = true;
                $strChar = $ch;
                continue;
            }
            if ($inStr) {
                if ($ch === $strChar && ($i === 0 || $selectSql[$i - 1] !== '\\')) {
                    $inStr = false;
                }
                continue;
            }

            if ($ch === '(') { $depth++; continue; }
            if ($ch === ')') { $depth--; continue; }

            // Top-level FROM keyword ends the SELECT list
            if ($depth === 0 && substr_compare($selectSql, 'FROM', $i, 4, true) === 0) {
                // Make sure it's a word boundary (preceded by whitespace or comma)
                if ($i === 0 || in_array($selectSql[$i - 1], [' ', "\t", "\n", "\r", ','])) {
                    return substr($selectSql, $start, $i - $start);
                }
            }
        }

        // No top-level FROM found — return everything after SELECT
        return substr($selectSql, $start);
    }

    /**
     * Split a SELECT list on top-level commas (not inside parentheses).
     *
     * @return string[]
     */
    private function splitSelectList(string $list): array
    {
        $items  = [];
        $depth  = 0;
        $current = '';

        for ($i = 0, $len = strlen($list); $i < $len; $i++) {
            $ch = $list[$i];
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')') {
                $depth--;
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $items[]  = $current;
                $current  = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $items[] = $current;
        }

        return $items;
    }

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
            isPrimaryKey:  $hasPrimaryKey,
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
