<?php

declare(strict_types=1);

namespace SqlcPhp\Resolver;

use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\TypeMapper\TypeMapperInterface;

/**
 * Resolves named SQL parameters (`:paramName`) to typed QueryParam objects
 * by matching them to schema columns via several heuristic strategies.
 *
 * Resolution order per parameter:
 *   1. Explicit annotation  -- @param userId users.id
 *   2. Direct column match  -- WHERE users.id = :userId   → users.id
 *   3. Name-based lookup    -- :userId  → strip suffix Id/id, look for column `user_id` or `id`
 *   4. Camel→snake fallback -- :firstName → first_name
 *   5. Unknown fallback     -- mixed / PDO::PARAM_STR
 */
class ParamResolver
{
    public function __construct(
        private readonly SchemaCatalog   $catalog,
        private readonly TypeMapperInterface $typeMapper,
    ) {}

    /**
     * @param  string                        $sql         Full SQL of the query
     * @param  array<string,string>          $annotations Map of paramName → "table.column" from @param annotations
     * @return QueryParam[]                               Indexed by param name
     */
    public function resolve(string $sql, array $annotations = []): array
    {
        // Detect which params appear inside IN() clauses
        $inListParams = $this->detectInListParams($sql);

        // Extract all :paramName tokens
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $m);
        $rawParams = array_unique($m[0] ?? []);

        if (empty($rawParams)) {
            return [];
        }

        // Build a map of  tableAlias → realTableName  from the FROM / JOIN clauses
        $tableAliases = $this->extractTableAliases($sql);

        $resolved = [];

        foreach ($rawParams as $token) {
            $paramName = ltrim($token, ':');
            $isInList  = isset($inListParams[$paramName]);

            // 1. Explicit annotation overrides everything
            if (isset($annotations[$paramName])) {
                [$tbl, $col] = explode('.', $annotations[$paramName], 2);
                $found = $this->findColumn($tbl, $col);
                if ($found !== null) {
                    [$realTable, $colDef] = $found;
                    $resolved[$paramName] = $this->buildParam(
                        $paramName, $colDef->sqlType, $colDef->nullable,
                        $realTable, $colDef->name, inList: $isInList
                    );
                    continue;
                }
            }

            // 2. Direct reference in IN() — look up the column before the IN
            if ($isInList) {
                $inColResult = $this->findInListColumnWithTable($sql, $paramName, $tableAliases);
                if ($inColResult !== null) {
                    [$inTable, $inCol] = $inColResult;
                    $resolved[$paramName] = $this->buildParam(
                        $paramName, $inCol->sqlType, $inCol->nullable,
                        $inTable, $inCol->name, inList: true
                    );
                    continue;
                }
            }

            // 3. Direct reference: WHERE table.col = :param  or  col = :param
            $directResult = $this->findDirectReferenceWithTable($sql, $token, $tableAliases);
            if ($directResult !== null) {
                [$directTable, $direct] = $directResult;
                $resolved[$paramName] = $this->buildParam(
                    $paramName, $direct->sqlType, $direct->nullable,
                    $directTable, $direct->name, inList: $isInList
                );
                continue;
            }

            // 4. Name-based: :userId → look for column user_id / id in any table
            $byNameResult = $this->findByNameWithTable($paramName, $tableAliases);
            if ($byNameResult !== null) {
                [$byNameTable, $byName] = $byNameResult;
                $resolved[$paramName] = $this->buildParam(
                    $paramName, $byName->sqlType, $byName->nullable,
                    $byNameTable, $byName->name, inList: $isInList
                );
                continue;
            }

            // 5. Fallback: unresolved → mixed / PARAM_STR
            $resolved[$paramName] = new QueryParam(
                name:     $paramName,
                sqlType:  'VARCHAR',
                nullable: false,
                pdoParam: 'PDO::PARAM_STR',
                phpType:  'mixed',
                inList:   $isInList,
            );
        }

        return $resolved;
    }

    /**
     * Returns a set of param names that appear inside IN() clauses.
     * Detects: col IN (:param), col NOT IN (:param), IN(:param) (no space)
     *
     * @return array<string, true>
     */
    private function detectInListParams(string $sql): array
    {
        $inList = [];
        // Match: IN ( :paramName ) with optional whitespace, including NOT IN
        if (preg_match_all('/\bIN\s*\(\s*(:([a-zA-Z_][a-zA-Z0-9_]*))\s*\)/i', $sql, $m)) {
            foreach ($m[2] as $name) {
                $inList[$name] = true;
            }
        }
        return $inList;
    }

    /**
     * For a param inside IN(:param), find the column to the left of IN.
     * Detects: `col IN (:param)`, `table.col IN (:param)`, `t.col NOT IN (:param)`
     *
     * @return array{0: string, 1: \SqlcPhp\Parser\ColumnDefinition}|null
     */
    private function findInListColumnWithTable(string $sql, string $paramName, array $aliases): ?array
    {
        $escaped = preg_quote($paramName, '/');
        // Match: [table.]col [NOT] IN ( :paramName )
        $pattern = '/([`"]?\w+[`"]?(?:\.[`"]?\w+[`"]?)?)\s+(?:NOT\s+)?IN\s*\(\s*:' . $escaped . '\s*\)/i';

        if (!preg_match($pattern, $sql, $m)) {
            return null;
        }

        $ref   = $m[1];
        $parts = explode('.', $ref);

        if (count($parts) === 2) {
            $alias     = trim($parts[0], '`"');
            $colName   = trim($parts[1], '`"');
            $tableName = $aliases[$alias] ?? $alias;
            return $this->findColumn($tableName, $colName);
        }

        $colName = trim($parts[0], '`"');
        $tables  = !empty($aliases)
            ? array_unique(array_values($aliases))
            : array_map(fn($t) => $t->name, $this->catalog->all());

        foreach ($tables as $tbl) {
            $result = $this->findColumn($tbl, $colName);
            if ($result !== null) return $result;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Strategy implementations
    // -------------------------------------------------------------------------

    /**
     * Looks for patterns like:
     *   `table.col = :param`  or  `col = :param`  or  `col > :param` etc.
     */
    private function findDirectReferenceWithTable(string $sql, string $token, array $aliases): ?array
    {
        $escaped = preg_quote($token, '/');

        // 1. table.col = :param
        $p1 = '/[`"]?(\w+)[`"]?\s*\.\s*[`"]?(\w+)[`"]?\s*[=<>!]+\s*' . $escaped . '/i';
        if (preg_match($p1, $sql, $m)) {
            $realTable = $aliases[$m[1]] ?? $m[1];
            $result    = $this->findColumn($realTable, $m[2]);
            return $result ? [$result[0], $result[1]] : null;
        }

        // 2. col = :param (SET or WHERE, no table qualifier)
        $p2 = '/[`"]?(\w+)[`"]?\s*=\s*' . $escaped . '/i';
        if (preg_match($p2, $sql, $m)) {
            $col = $m[1];
            foreach (array_unique(array_values($aliases)) as $tbl) {
                $result = $this->findColumn($tbl, $col);
                if ($result !== null) return [$result[0], $result[1]];
            }
            foreach ($this->catalog->all() as $table) {
                $result = $this->findColumn($table->name, $col);
                if ($result !== null) return [$result[0], $result[1]];
            }
        }

        // 3. col > :param / col < :param etc.
        $p3 = '/[`"]?(\w+)[`"]?\s*[<>!]+\s*' . $escaped . '/i';
        if (preg_match($p3, $sql, $m)) {
            $col = $m[1];
            foreach (array_unique(array_values($aliases)) as $tbl) {
                $result = $this->findColumn($tbl, $col);
                if ($result !== null) return [$result[0], $result[1]];
            }
        }

        return null;
    }

    private function findByNameWithTable(string $paramName, array $aliases): ?array
    {
        $snake      = $this->camelToSnake($paramName);
        $candidates = array_unique([$snake, $paramName]);

        if (str_ends_with($snake, '_id')) {
            $candidates[] = 'id';
        }

        // Strip common boolean prefixes: is_active → active, has_role → role, can_edit → edit
        foreach (['is_', 'has_', 'can_', 'was_', 'will_'] as $prefix) {
            if (str_starts_with($snake, $prefix)) {
                $candidates[] = substr($snake, strlen($prefix));
            }
        }

        $tables = !empty($aliases)
            ? array_values($aliases)
            : array_map(fn($t) => $t->name, $this->catalog->all());

        foreach ($candidates as $col) {
            foreach ($tables as $tbl) {
                $result = $this->findColumn($tbl, $col);
                if ($result !== null) return [$result[0], $result[1]];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts FROM and JOIN table references, building an alias → realName map.
     *
     * @return array<string, string>  alias → real table name
     */
    public function extractTableAliases(string $sql): array
    {
        $aliases = [];

        // FROM table [AS alias] or FROM table alias
        $fromPattern = '/FROM\s+[`"]?(\w+)[`"]?(?:\s+AS\s+[`"]?(\w+)[`"]?|\s+([a-zA-Z_]\w*)(?:\s|$|\n|,))?/i';
        if (preg_match_all($fromPattern, $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $realName = $match[1];
                $alias    = ($match[2] ?? '') ?: (($match[3] ?? '') ?: $realName);
                $aliases[$alias]    = $realName;
                $aliases[$realName] = $realName;
            }
        }

        // UPDATE table SET ...
        if (preg_match('/^\s*UPDATE\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            $aliases[$m[1]] = $m[1];
        }

        // DELETE FROM table  (already covered by FROM pattern, but keep for clarity)
        if (preg_match('/DELETE\s+FROM\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            $aliases[$m[1]] = $m[1];
        }

        // JOIN table [AS alias]
        $joinPattern = '/JOIN\s+[`"]?(\w+)[`"]?(?:\s+AS\s+[`"]?(\w+)[`"]?|\s+([a-zA-Z_]\w*)(?:\s|ON|$|\n))?/i';
        if (preg_match_all($joinPattern, $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $realName = $match[1];
                $alias    = ($match[2] ?? '') ?: (($match[3] ?? '') ?: $realName);
                $aliases[$alias]    = $realName;
                $aliases[$realName] = $realName;
            }
        }

        return $aliases;
    }

    /**
     * Finds a column definition in a given table.
     * Returns [tableName, ColumnDefinition] or null.
     *
     * @return array{0: string, 1: \SqlcPhp\Parser\ColumnDefinition}|null
     */
    private function findColumn(string $tableName, string $columnName): ?array
    {
        $table = $this->catalog->getTable($tableName);
        if ($table === null) return null;

        foreach ($table->columns as $col) {
            if (strtolower($col->name) === strtolower($columnName)) {
                return [$tableName, $col];
            }
        }

        return null;
    }

    private function buildParam(
        string  $name,
        string  $sqlType,
        bool    $nullable,
        ?string $table  = null,
        ?string $column = null,
        bool    $inList = false,
    ): QueryParam {
        return new QueryParam(
            name:     $name,
            sqlType:  $sqlType,
            nullable: $nullable,
            pdoParam: $this->typeMapper->toPdoParam($sqlType, $table, $column),
            phpType:  $this->typeMapper->toPhpType($sqlType, $nullable, $table, $column),
            inList:   $inList,
        );
    }

    private function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input) ?? $input);
    }
}
