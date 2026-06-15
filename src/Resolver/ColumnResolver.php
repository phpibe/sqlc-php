<?php

declare(strict_types=1);

namespace SqlcPhp\Resolver;

use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\TypeMapper\TypeMapperInterface;

/**
 * Resolves the output columns of a SELECT statement against the schema catalog.
 *
 * Handles:
 *   SELECT *                         → all columns from the primary FROM table
 *   SELECT table.*                   → all columns from that table
 *   SELECT col                       → lookup col in all involved tables
 *   SELECT table.col                 → lookup in specific table
 *   SELECT table.col AS alias        → use alias as property name
 *   SELECT col AS alias              → use alias as property name
 *   SELECT COUNT(*) AS total         → ExpressionTypeResolver → int $total
 *   SELECT SUM(price)                → ExpressionTypeResolver → ?float $sumPrice
 *   Multiple tables via JOIN         → disambiguate by table prefix
 *   WITH name AS (subquery) SELECT … → CTE resolved as virtual tables
 */
class ColumnResolver
{
    /** Positional counter for expressions with no alias and no inferable name */
    private int $positional = 0;

    /**
     * CTE virtual tables for the current resolve() call.
     * Maps CTE name (lowercase) → ResolvedColumn[].
     * Reset at the start of each resolve() call.
     *
     * @var array<string, ResolvedColumn[]>
     */
    private array $cteColumns = [];

    public function __construct(
        private readonly SchemaCatalog          $catalog,
        private readonly TypeMapperInterface        $typeMapper,
        private readonly ParamResolver          $paramResolver,
        private readonly ExpressionTypeResolver $exprResolver,
    ) {}

    /**
     * Resolve the SELECT column list for the given SQL query.
     *
     * @return ResolvedColumn[]
     */
    public function resolve(string $sql): array
    {
        $this->positional = 0;
        $this->cteColumns = [];

        // Detect and resolve CTEs before processing the outer query.
        // Each CTE's inner subquery is resolved and registered as a virtual
        // table so that the outer SELECT can reference it by name.
        $outerSql = $this->extractAndResolveCtes($sql);

        $tableAliases = $this->paramResolver->extractTableAliases($outerSql);
        $selectList   = $this->extractSelectList($outerSql);

        if (empty($selectList)) {
            return [];
        }

        $columns = [];

        foreach ($selectList as $item) {
            $item = trim($item);
            if ($item === '') continue;

            $resolved = $this->resolveItem($item, $tableAliases);
            foreach ($resolved as $col) {
                $columns[] = $col;
            }
        }

        return $columns;
    }

    // -------------------------------------------------------------------------
    // CTE parsing
    // -------------------------------------------------------------------------

    /**
     * Detect CTE definitions at the start of the SQL (WITH name AS (...), ...).
     * For each CTE, resolve its inner subquery's columns and register them under
     * the CTE name in $this->cteColumns. Returns the SQL with the WITH clause
     * stripped — just the outer SELECT/UPDATE/DELETE.
     *
     * Handles multiple CTEs: WITH a AS (...), b AS (...) SELECT ...
     */
    private function extractAndResolveCtes(string $sql): string
    {
        $trimmed = ltrim($sql);
        if (!preg_match('/^WITH\b/i', $trimmed)) {
            return $sql; // fast path: no CTE
        }

        // Skip past the WITH keyword
        $pos = stripos($trimmed, 'WITH') + 4;
        $len = strlen($trimmed);

        while ($pos < $len) {
            // Skip whitespace between WITH/comma and the CTE name
            while ($pos < $len && ctype_space($trimmed[$pos])) $pos++;

            // Read the CTE name (identifier)
            $nameStart = $pos;
            while ($pos < $len && (ctype_alnum($trimmed[$pos]) || $trimmed[$pos] === '_')) $pos++;
            $cteName = strtolower(substr($trimmed, $nameStart, $pos - $nameStart));

            if ($cteName === '') break; // malformed — bail out

            // Skip whitespace then expect AS
            while ($pos < $len && ctype_space($trimmed[$pos])) $pos++;
            if (stripos($trimmed, 'AS', $pos) !== $pos) break;
            $pos += 2;

            // Skip whitespace then expect (
            while ($pos < $len && ctype_space($trimmed[$pos])) $pos++;
            if ($pos >= $len || $trimmed[$pos] !== '(') break;

            // Find the matching ) using paren depth — the inner subquery body
            $depth     = 0;
            $bodyStart = $pos;
            while ($pos < $len) {
                if ($trimmed[$pos] === '(') $depth++;
                elseif ($trimmed[$pos] === ')') {
                    $depth--;
                    if ($depth === 0) { $pos++; break; }
                }
                $pos++;
            }
            $body = substr($trimmed, $bodyStart + 1, ($pos - 1) - ($bodyStart + 1));

            // Resolve the inner subquery columns (strip parameters to avoid conflicts)
            try {
                // Temporarily clear cteColumns so inner CTEs don't bleed into outer
                $outerCte = $this->cteColumns;
                $this->cteColumns = [];
                $innerCols = $this->resolve($body);
                // Restore outer context and register this CTE
                $this->cteColumns = $outerCte;
                $this->cteColumns[$cteName] = $innerCols;
            } catch (\Throwable) {
                // If inner resolution fails (e.g. complex expressions), register empty
                $this->cteColumns[$cteName] = [];
            }

            // Skip whitespace; if next char is comma, there are more CTEs
            while ($pos < $len && ctype_space($trimmed[$pos])) $pos++;
            if ($pos < $len && $trimmed[$pos] === ',') {
                $pos++; // consume comma, loop to next CTE
            } else {
                break; // no more CTEs — remainder is the outer query
            }
        }

        // Return only the outer query (after all CTE definitions)
        return trim(substr($trimmed, $pos));
    }

    // -------------------------------------------------------------------------
    // SELECT list extraction
    // -------------------------------------------------------------------------

    /**
     * Extract the raw SELECT column list between SELECT and FROM.
     * For UNION queries, only the first SELECT branch is parsed — subsequent
     * branches must have the same column structure (SQL enforces this).
     *
     * @return string[]
     */
    private function extractSelectList(string $sql): array
    {
        // Strip UNION branches — keep only the first SELECT
        $firstBranch = $this->extractFirstUnionBranch($sql);

        // Match content between SELECT and FROM (handles multi-line)
        if (!preg_match('/SELECT\s+(.*?)\s+FROM\s/si', $firstBranch, $m)) {
            return [];
        }

        $raw = $m[1];
        return $this->splitSelectItems($raw);
    }

    /**
     * For UNION queries, return only the first SELECT branch (before UNION).
     * For non-UNION queries, returns the original SQL unchanged.
     *
     * The split happens at top-level UNION keywords (not inside subqueries).
     */
    private function extractFirstUnionBranch(string $sql): string
    {
        // Fast path: no UNION keyword at all
        if (!preg_match('/\bUNION\b/i', $sql)) {
            return $sql;
        }

        // Walk the SQL character by character, tracking paren depth.
        // Split at the first top-level UNION keyword.
        $len   = strlen($sql);
        $depth = 0;
        $i     = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            if ($ch === '(') {
                $depth++;
                $i++;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                $i++;
                continue;
            }

            // Check for UNION at top level (depth === 0)
            if ($depth === 0 && stripos($sql, 'UNION', $i) === $i) {
                return substr($sql, 0, $i);
            }

            $i++;
        }

        return $sql;
    }

    /**
     * Split SELECT items respecting nested parentheses (e.g. function calls).
     *
     * @return string[]
     */
    private function splitSelectItems(string $raw): array
    {
        $items   = [];
        $current = '';
        $depth   = 0;

        for ($i = 0; $i < strlen($raw); $i++) {
            $ch = $raw[$i];
            if ($ch === '(') { $depth++; $current .= $ch; }
            elseif ($ch === ')') { $depth--; $current .= $ch; }
            elseif ($ch === ',' && $depth === 0) {
                $items[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        if (trim($current) !== '') {
            $items[] = trim($current);
        }

        return $items;
    }

    /**
     * Resolve a single SELECT item, which may expand to multiple columns (e.g. *).
     *
     * @return ResolvedColumn[]
     */
    private function resolveItem(string $item, array $tableAliases): array
    {
        // ---- table.* ----
        if (preg_match('/^[`"]?(\w+)[`"]?\.\*$/', $item, $m)) {
            $realTable = $tableAliases[$m[1]] ?? $m[1];
            return $this->expandTable($realTable);
        }

        // ---- bare * ----
        if ($item === '*') {
            // Expand all FROM tables in order (primary table first, then JOINed)
            $cols = [];
            foreach (array_unique(array_values($tableAliases)) as $tbl) {
                foreach ($this->expandTable($tbl) as $col) {
                    $cols[] = $col;
                }
            }
            return $cols;
        }

        // ---- expression with optional alias: expr [AS alias] ----
        // expr can be: col, table.col, func(...) – we only type-resolve column refs
        $alias = null;
        if (preg_match('/^(.*?)\s+AS\s+[`"]?(\w+)[`"]?\s*$/i', $item, $m)) {
            $expr  = trim($m[1]);
            $alias = $m[2];
        } else {
            $expr = $item;
        }

        // table.col
        if (preg_match('/^[`"]?(\w+)[`"]?\.[`"]?(\w+)[`"]?$/', $expr, $m)) {
            $realTable  = $tableAliases[$m[1]] ?? $m[1];
            $colName    = $m[2];
            $finalAlias = $alias ?? $colName;
            return $this->resolveTableColumn($realTable, $colName, $finalAlias, $tableAliases);
        }

        // bare col
        if (preg_match('/^[`"]?(\w+)[`"]?$/', $expr, $m)) {
            $colName    = $m[1];
            $finalAlias = $alias ?? $colName;
            return $this->resolveAnyColumn($colName, $finalAlias, $tableAliases);
        }

        // Expression / function call: delegate to ExpressionTypeResolver
        ['phpType' => $phpType, 'alias' => $inferredAlias] =
            $this->exprResolver->resolve($expr, $tableAliases);

        // Alias priority: explicit AS > inferred from function > positional col_N
        if ($alias !== null) {
            $finalAlias = $alias;
        } elseif ($inferredAlias !== null) {
            $finalAlias = $inferredAlias;
        } else {
            $this->positional++;
            $finalAlias = 'col_' . $this->positional;
        }

        return [new ResolvedColumn(
            alias:      $finalAlias,
            columnName: $finalAlias,
            tableName:  '',
            sqlType:    'EXPR',
            nullable:   str_starts_with($phpType, '?'),
            phpType:    $phpType,
        )];
    }

    // -------------------------------------------------------------------------

    /**
     * Expand all columns of a table into ResolvedColumn objects.
     * Checks CTE virtual tables first, then the schema catalog.
     *
     * @return ResolvedColumn[]
     */
    private function expandTable(string $tableName): array
    {
        // Check CTE virtual tables first
        $cteKey = strtolower($tableName);
        if (isset($this->cteColumns[$cteKey])) {
            // Return CTE columns with tableName set to the CTE name
            return array_map(fn(ResolvedColumn $c) => new ResolvedColumn(
                alias:      $c->alias,
                columnName: $c->columnName,
                tableName:  $tableName,
                sqlType:    $c->sqlType,
                nullable:   $c->nullable,
                phpType:    $c->phpType,
            ), $this->cteColumns[$cteKey]);
        }

        $cols = [];
        foreach ($this->catalog->getColumns($tableName) as $col) {
            $phpType = $this->typeMapper->toPhpType($col->sqlType, $col->nullable, $tableName, $col->name);
            $cols[] = new ResolvedColumn(
                alias:      $col->name,
                columnName: $col->name,
                tableName:  $tableName,
                sqlType:    $col->sqlType,
                nullable:   $col->nullable,
                phpType:    $phpType,
            );
        }
        return $cols;
    }

    /**
     * Resolve table.col with a given output alias.
     * Checks CTE virtual tables first, then the schema catalog.
     *
     * @return ResolvedColumn[]
     */
    private function resolveTableColumn(
        string $tableName,
        string $colName,
        string $alias,
        array $tableAliases,
    ): array {
        // Check CTE virtual tables first
        $cteKey = strtolower($tableName);
        if (isset($this->cteColumns[$cteKey])) {
            foreach ($this->cteColumns[$cteKey] as $cteCol) {
                if (strtolower($cteCol->alias) === strtolower($colName)) {
                    return [new ResolvedColumn(
                        alias:      $alias,
                        columnName: $cteCol->columnName,
                        tableName:  $tableName,
                        sqlType:    $cteCol->sqlType,
                        nullable:   $cteCol->nullable,
                        phpType:    $cteCol->phpType,
                    )];
                }
            }
            // Column not found in CTE — return unknown
            return [$this->unknownColumn($alias)];
        }

        $table = $this->catalog->getTable($tableName);
        if ($table === null) {
            return [$this->unknownColumn($alias)];
        }

        foreach ($table->columns as $col) {
            if (strtolower($col->name) === strtolower($colName)) {
                $phpType = $this->typeMapper->toPhpType($col->sqlType, $col->nullable, $tableName, $col->name);
                return [new ResolvedColumn(
                    alias:      $alias,
                    columnName: $col->name,
                    tableName:  $tableName,
                    sqlType:    $col->sqlType,
                    nullable:   $col->nullable,
                    phpType:    $phpType,
                )];
            }
        }

        return [$this->unknownColumn($alias)];
    }

    /**
     * Resolve a bare column name by searching all tables in the query.
     *
     * @return ResolvedColumn[]
     */
    private function resolveAnyColumn(string $colName, string $alias, array $tableAliases): array
    {
        foreach (array_unique(array_values($tableAliases)) as $tbl) {
            $result = $this->resolveTableColumn($tbl, $colName, $alias, $tableAliases);
            if ($result[0]->sqlType !== 'UNKNOWN') {
                return $result;
            }
        }

        return [$this->unknownColumn($alias)];
    }

    private function unknownColumn(string $alias): ResolvedColumn
    {
        return new ResolvedColumn(
            alias:      $alias,
            columnName: $alias,
            tableName:  '',
            sqlType:    'UNKNOWN',
            nullable:   true,
            phpType:    'mixed',
        );
    }
}
