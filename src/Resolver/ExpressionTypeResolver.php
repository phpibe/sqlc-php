<?php

declare(strict_types=1);

namespace SqlcPhp\Resolver;

use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\TypeMapper\TypeMapperInterface;

/**
 * Resolves the PHP type and output alias of a SQL expression
 * that does not map directly to a single schema column.
 *
 * Supported expressions:
 *   Aggregates : COUNT, SUM, AVG, MIN, MAX
 *   Scalars    : COALESCE, IFNULL, CONCAT, CAST, LENGTH, UPPER, LOWER, TRIM
 *   Control    : CASE WHEN … END
 *   Fallback   : mixed
 *
 * Naming convention when no AS alias is provided (mirrors sqlc/Go behaviour):
 *   COUNT(*)        → count
 *   SUM(amount)     → sumAmount
 *   AVG(price)      → avgPrice
 *   MIN(created_at) → minCreatedAt
 *   MAX(id)         → maxId
 *   COALESCE(x, y)  → coalesceX   (first argument, camelCase)
 *   CONCAT(…)       → concat
 *   unknown expr    → col_1, col_2 … (positional, tracked by caller)
 */
class ExpressionTypeResolver
{
    public function __construct(
        private readonly SchemaCatalog   $catalog,
        private readonly TypeMapperInterface $typeMapper,
    ) {}

    /**
     * Resolve an expression string (without its alias) to a PHP type and
     * a generated alias name.
     *
     * @param  string        $expr         Raw SQL expression, e.g. "COUNT(*)"
     * @param  array<string,string> $tableAliases  alias → real table name map
     * @return array{phpType: string, alias: string}
     */
    public function resolve(string $expr, array $tableAliases): array
    {
        $trimmed = trim($expr);
        $upper   = strtoupper($trimmed);

        // ── Aggregates ────────────────────────────────────────────────────────

        if ($this->matchesFunction($upper, 'COUNT', $inner)) {
            return [
                'phpType' => 'int',          // COUNT never returns NULL
                'alias'   => 'count',
            ];
        }

        if ($this->matchesFunction($upper, 'SUM', $inner)) {
            $innerType = $this->resolveInnerType($inner, $tableAliases);
            $base      = ltrim(in_array($innerType, ['int', 'float', '?int', '?float'])
                ? $innerType
                : 'int', '?');
            return [
                'phpType' => "?{$base}",     // SUM() on empty set → NULL
                'alias'   => $this->prefixAlias('sum', $inner),
            ];
        }

        if ($this->matchesFunction($upper, 'AVG', $inner)) {
            return [
                'phpType' => '?float',       // AVG always float, nullable
                'alias'   => $this->prefixAlias('avg', $inner),
            ];
        }

        if ($this->matchesFunction($upper, 'MIN', $inner)) {
            $innerType = $this->resolveInnerType($inner, $tableAliases);
            $base      = ltrim($innerType, '?');
            return [
                'phpType' => "?{$base}",     // MIN on empty set → NULL
                'alias'   => $this->prefixAlias('min', $inner),
            ];
        }

        if ($this->matchesFunction($upper, 'MAX', $inner)) {
            $innerType = $this->resolveInnerType($inner, $tableAliases);
            $base      = ltrim($innerType, '?');
            return [
                'phpType' => "?{$base}",     // MAX on empty set → NULL
                'alias'   => $this->prefixAlias('max', $inner),
            ];
        }

        // ── Scalar functions ──────────────────────────────────────────────────

        if ($this->matchesFunction($upper, 'COALESCE', $inner)) {
            // COALESCE(col, default) → type of col, NOT nullable
            $firstArg  = $this->firstArg($inner);
            $innerType = $this->resolveInnerType($firstArg, $tableAliases);
            $base      = ltrim($innerType, '?');
            return [
                'phpType' => $base,
                'alias'   => $this->prefixAlias('coalesce', $firstArg),
            ];
        }

        if ($this->matchesFunction($upper, 'IFNULL', $inner)) {
            $firstArg  = $this->firstArg($inner);
            $innerType = $this->resolveInnerType($firstArg, $tableAliases);
            $base      = ltrim($innerType, '?');
            return [
                'phpType' => $base,
                'alias'   => $this->prefixAlias('ifnull', $firstArg),
            ];
        }

        if ($this->matchesFunction($upper, 'NULLIF', $inner)) {
            $firstArg  = $this->firstArg($inner);
            $innerType = $this->resolveInnerType($firstArg, $tableAliases);
            $base      = ltrim($innerType, '?');
            return [
                'phpType' => "?{$base}",     // NULLIF can return NULL
                'alias'   => $this->prefixAlias('nullif', $firstArg),
            ];
        }

        if ($this->matchesFunction($upper, 'CAST', $inner)) {
            // CAST(col AS UNSIGNED) → parse the target type
            $phpType = $this->resolveCastType($inner);
            $firstArg = $this->firstArg($inner);
            return [
                'phpType' => $phpType,
                'alias'   => $this->prefixAlias('cast', $firstArg),
            ];
        }

        if ($this->matchesFunction($upper, 'CONCAT', $inner)
            || $this->matchesFunction($upper, 'CONCAT_WS', $inner)
            || $this->matchesFunction($upper, 'GROUP_CONCAT', $inner)) {
            return [
                'phpType' => '?string',
                'alias'   => 'concat',
            ];
        }

        if ($this->matchesFunction($upper, 'IF', $inner)) {
            // IF(cond, true_val, false_val) → type of true_val, nullable
            $args = $this->splitArgs($inner);
            $trueArg = $args[1] ?? '';
            $innerType = $this->resolveInnerType(trim($trueArg), $tableAliases);
            $base = ltrim($innerType, '?');
            return [
                'phpType' => "?{$base}",
                'alias'   => 'if',
            ];
        }

        // String scalar functions → string, not nullable
        foreach (['UPPER', 'LOWER', 'TRIM', 'LTRIM', 'RTRIM', 'SUBSTRING',
                  'SUBSTR', 'REPLACE', 'REVERSE', 'LEFT', 'RIGHT',
                  'LPAD', 'RPAD', 'FORMAT', 'DATE_FORMAT'] as $fn) {
            if ($this->matchesFunction($upper, $fn, $inner)) {
                return [
                    'phpType' => 'string',
                    'alias'   => strtolower($fn),
                ];
            }
        }

        // Numeric scalar functions → int or float
        foreach (['LENGTH', 'CHAR_LENGTH', 'BIT_LENGTH'] as $fn) {
            if ($this->matchesFunction($upper, $fn, $inner)) {
                return ['phpType' => 'int', 'alias' => strtolower($fn)];
            }
        }

        foreach (['ROUND', 'FLOOR', 'CEIL', 'CEILING', 'ABS', 'MOD',
                  'POWER', 'SQRT', 'LOG', 'EXP'] as $fn) {
            if ($this->matchesFunction($upper, $fn, $inner)) {
                return ['phpType' => 'float', 'alias' => strtolower($fn)];
            }
        }

        // Date functions → string (we don't introduce DateTime here;
        // users can use a type_override on the alias if needed)
        foreach (['NOW', 'CURDATE', 'CURTIME', 'SYSDATE',
                  'DATE', 'TIME', 'YEAR', 'MONTH', 'DAY',
                  'HOUR', 'MINUTE', 'SECOND', 'DATEDIFF',
                  'DATE_ADD', 'DATE_SUB', 'TIMESTAMPDIFF'] as $fn) {
            if ($this->matchesFunction($upper, $fn, $inner)) {
                return ['phpType' => '?string', 'alias' => strtolower($fn)];
            }
        }

        // ── CASE WHEN ─────────────────────────────────────────────────────────

        if (str_starts_with($upper, 'CASE')) {
            return [
                'phpType' => '?string',  // conservative: nullable string
                'alias'   => 'case',
            ];
        }

        // ── Fallback ──────────────────────────────────────────────────────────

        return [
            'phpType' => 'mixed',
            'alias'   => null,           // caller assigns positional name col_N
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if $upper matches FUNCNAME( inner... ) and capture $inner.
     */
    private function matchesFunction(string $upper, string $funcName, mixed &$inner): bool
    {
        $prefix = $funcName . '(';
        if (!str_starts_with($upper, $prefix)) return false;
        if (!str_ends_with(rtrim($upper), ')')) return false;

        // Extract content between the outer parens
        $start = strlen($prefix) - 1; // position of '('
        $inner = $this->extractParenContent($upper, $start);

        return $inner !== null;
    }

    /**
     * Extract content between balanced parentheses starting at $pos.
     */
    private function extractParenContent(string $str, int $pos): ?string
    {
        $depth = 0;
        $start = $pos + 1;
        $len   = strlen($str);

        for ($i = $pos; $i < $len; $i++) {
            if ($str[$i] === '(') $depth++;
            elseif ($str[$i] === ')') {
                $depth--;
                if ($depth === 0) return substr($str, $start, $i - $start);
            }
        }

        return null;
    }

    /**
     * Resolves the PHP type of a simple inner expression:
     *   - "*"         → int (for COUNT)
     *   - "table.col" → type from schema
     *   - "col"       → type from schema (search all alias tables)
     *   - literal     → string
     */
    private function resolveInnerType(string $inner, array $tableAliases): string
    {
        $inner = trim($inner);

        if ($inner === '*' || $inner === '') return 'int';

        // table.col or alias.col
        if (preg_match('/^[`"]?(\w+)[`"]?\.[`"]?(\w+)[`"]?$/', $inner, $m)) {
            $realTable = $tableAliases[$m[1]] ?? $m[1];
            $col       = $this->findColumn($realTable, $m[2]);
            if ($col) return $this->typeMapper->toPhpType(
                $col->sqlType, $col->nullable, $realTable, $col->name
            );
        }

        // bare col name
        if (preg_match('/^[`"]?(\w+)[`"]?$/', $inner, $m)) {
            foreach (array_unique(array_values($tableAliases)) as $tbl) {
                $col = $this->findColumn($tbl, $m[1]);
                if ($col) return $this->typeMapper->toPhpType(
                    $col->sqlType, $col->nullable, $tbl, $col->name
                );
            }
        }

        return 'string';
    }

    /**
     * Resolves CAST(expr AS type) → PHP type.
     */
    private function resolveCastType(string $inner): string
    {
        // CAST(col AS UNSIGNED) → find "AS <type>"
        if (preg_match('/\bAS\s+(\w+)/i', $inner, $m)) {
            return match(strtoupper($m[1])) {
                'SIGNED', 'UNSIGNED', 'INTEGER', 'INT' => 'int',
                'DECIMAL', 'FLOAT', 'DOUBLE'            => 'float',
                'BINARY', 'CHAR', 'DATE', 'DATETIME',
                'TIME', 'JSON'                          => 'string',
                default                                 => 'string',
            };
        }

        return 'mixed';
    }

    /**
     * Split function arguments respecting nested parentheses.
     *
     * @return string[]
     */
    private function splitArgs(string $inner): array
    {
        $args    = [];
        $current = '';
        $depth   = 0;

        for ($i = 0; $i < strlen($inner); $i++) {
            $ch = $inner[$i];
            if ($ch === '(') { $depth++; $current .= $ch; }
            elseif ($ch === ')') { $depth--; $current .= $ch; }
            elseif ($ch === ',' && $depth === 0) {
                $args[]  = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }

        if (trim($current) !== '') $args[] = trim($current);

        return $args;
    }

    /**
     * Extract the first argument of a function's inner content.
     */
    private function firstArg(string $inner): string
    {
        return $this->splitArgs($inner)[0] ?? $inner;
    }

    /**
     * Build a camelCase alias: "sum" + "order_total" → "sumOrderTotal"
     */
    private function prefixAlias(string $prefix, string $expr): string
    {
        $expr  = trim($expr);
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $expr) ?? $expr;
        $parts = array_filter(explode('_', strtolower($clean)));

        $camel = $prefix . implode('', array_map('ucfirst', array_values($parts)));

        // Remove trailing underscores / double underscores
        return rtrim(preg_replace('/_+/', '_', $camel) ?? $camel, '_');
    }

    /**
     * Find a column definition in the catalog.
     */
    private function findColumn(string $tableName, string $columnName): ?\SqlcPhp\Parser\ColumnDefinition
    {
        $table = $this->catalog->getTable($tableName);
        if ($table === null) return null;

        foreach ($table->columns as $col) {
            if (strtolower($col->name) === strtolower($columnName)) return $col;
        }

        return null;
    }
}
