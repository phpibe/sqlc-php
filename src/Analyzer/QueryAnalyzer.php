<?php

declare(strict_types=1);

namespace SqlcPhp\Analyzer;

use SqlcPhp\Parser\QueryDefinition;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Resolver\ResolvedColumn;
use SqlcPhp\Resolver\QueryParam;
use SqlcPhp\Rewriter\SqlRewriter;

/**
 * Enriches parsed QueryDefinitions with resolved parameters and result columns.
 *
 * Pipeline per query:
 *   1. SqlRewriter  — rewrites optional param conditions in the SQL
 *   2. ParamResolver — resolves :param names to typed QueryParam objects
 *   3. ColumnResolver — resolves SELECT columns to typed ResolvedColumn objects
 *   4. detectDirectModel — decides whether the return type is a table model or a custom DTO
 */
class QueryAnalyzer
{
    public function __construct(
        private readonly ParamResolver                    $paramResolver,
        private readonly ColumnResolver                  $columnResolver,
        private readonly QueryParser                     $queryParser,
        private readonly SqlRewriter                     $rewriter  = new SqlRewriter(),
        private readonly ?\SqlcPhp\Catalog\SchemaCatalog $catalog   = null,
    ) {}

    /**
     * @param  QueryDefinition[] $queries
     * @return QueryDefinition[]
     */
    public function analyze(array $queries): array
    {
        return array_map(fn($q) => $this->analyzeOne($q), $queries);
    }

    private function analyzeOne(QueryDefinition $query): QueryDefinition
    {
        // 1. Validate @optional params are in WHERE context (not SELECT/JOIN)
        $this->assertOptionalInWhereContext($query->sql, $query->optionalParams, $query->name);

        // 2. Rewrite SQL for optional parameters (validates unsafe constructs first)
        $rewrittenSql = $this->rewriter->rewrite($query->sql, $query->optionalParams, $query->name);

        // 2. For :many-paginated, append LIMIT / OFFSET to the SQL
        if ($query->returns->value === ':many-paginated') {
            $rewrittenSql = $this->injectPagination($rewrittenSql, $query->name);
        }

        // 3. Resolve parameters against the rewritten SQL
        $rawParams = $this->paramResolver->resolve($rewrittenSql, $query->paramAnnotations);

        // @optional marking will happen in markPartialAndOptional() below
        $params = $rawParams;

        // 4. Resolve result columns, applying @nillable overrides
        // Treat :many-paginated like :many for column resolution
        $resultColumns        = [];
        $returnsModelDirectly = false;
        $modelClass           = null;

        $isSelectQuery = $query->returns->value !== ':exec';
        if ($isSelectQuery) {
            $rawColumns    = $this->columnResolver->resolve($rewrittenSql);
            $resultColumns = $this->applyNillable($rawColumns, $query->nillableColumns);

            // @nillable or @embed on a direct-model query forces a custom DTO
            // @nillable, @embed, @column, or virtual table → always generate a custom DTO
            $isVirtual = $this->catalog !== null
                && $query->fromTable !== null
                && ($this->catalog->getTable($query->fromTable)?->virtual ?? false);
            $hasCustomizations = !empty($query->nillableColumns)
                || !empty($query->embeds)
                || !empty($query->columnAliases)
                || $isVirtual;
            if (!$hasCustomizations) {
                [$returnsModelDirectly, $modelClass] = $this->detectDirectModel($query, $resultColumns);
            }
        }

        // Apply @column renames — rename column aliases after resolution
        if (!empty($query->columnAliases)) {
            $resultColumns = array_map(function (ResolvedColumn $col) use ($query): ResolvedColumn {
                $newAlias = $query->columnAliases[$col->alias] ?? null;
                if ($newAlias === null) return $col;

                return new ResolvedColumn(
                    alias:      $newAlias,
                    columnName: $col->columnName,
                    tableName:  $col->tableName,
                    sqlType:    $col->sqlType,
                    nullable:   $col->nullable,
                    phpType:    $col->phpType,
                );
            }, $resultColumns);
        }

        // Validate @counted: only valid on :many-paginated
        if ($query->counted && $query->returns !== ReturnType::ManyPaginated) {
            throw new \RuntimeException(
                "Query '{$query->name}': @counted is only valid on :many-paginated queries. " .
                "Got: {$query->returns->value}"
            );
        }

        // Validate @searchable: only valid on :many and :many-paginated
        if ($query->searchable
            && $query->returns !== ReturnType::Many
            && $query->returns !== ReturnType::ManyPaginated
        ) {
            throw new \RuntimeException(
                "Query '{$query->name}': @searchable is only valid on :many and :many-paginated queries. " .
                "Got: {$query->returns->value}"
            );
        }

        // Validate @partial: only valid on :exec
        if ($query->partial && $query->returns !== ReturnType::Exec) {
            throw new \RuntimeException(
                "Query '{$query->name}': @partial is only valid on :exec queries (UPDATE statements). " .
                "Got: {$query->returns->value}"
            );
        }

        // Detect which params are "partial" — appear in COALESCE(:param, col) in the SET clause
        $partialParams = $query->partial
            ? $this->detectPartialParams($query->sql, $query->name)
            : [];

        return new QueryDefinition(
            name:                 $query->name,
            group:                $query->group,
            returns:              $query->returns,
            sql:                  $rewrittenSql,
            fromTable:            $query->fromTable,
            params:               $this->markPartialAndOptional($params, $partialParams, $query->optionalParams),
            resultColumns:        $resultColumns,
            paramAnnotations:     $query->paramAnnotations,
            optionalParams:       $query->optionalParams,
            returnsModelDirectly: $returnsModelDirectly,
            modelClass:           $modelClass,
            deprecated:           $query->deprecated,
            nillableColumns:      $query->nillableColumns,
            embeds:               $query->embeds,
            dtoClassName:         $query->dtoClassName,
            columnAliases:        $query->columnAliases,
            counted:              $query->counted,
            searchable:           $query->searchable,
            partial:              $query->partial,
        );
    }

    /**
     * Validate that every @optional param appears in a WHERE-clause context,
     * not in SELECT or other positions where the rewrite would produce invalid SQL.
     *
     * @param  string[] $optionalParams
     * @throws \RuntimeException
     */
    private function assertOptionalInWhereContext(string $sql, array $optionalParams, string $queryName): void
    {
        if (empty($optionalParams)) return;

        $upperSql = strtoupper($sql);
        $wherePos = strpos($upperSql, 'WHERE');

        // No WHERE clause at all — optional params can't be in the right place
        if ($wherePos === false) {
            foreach ($optionalParams as $param) {
                throw new \RuntimeException(
                    "Query '{$queryName}': @optional '{$param}' cannot be used on a query " .
                    "without a WHERE clause. The param has nowhere safe to be rewritten."
                );
            }
            return;
        }

        $beforeWhere = substr($sql, 0, $wherePos);

        foreach ($optionalParams as $param) {
            if (preg_match('/:' . preg_quote($param, '/') . '\b/i', $beforeWhere)) {
                throw new \RuntimeException(
                    "Query '{$queryName}': @optional '{$param}' appears before the WHERE " .
                    "clause (e.g. in SELECT or JOIN). @optional only rewrites WHERE conditions."
                );
            }
        }
    }

    /**
     * Appends LIMIT :limit OFFSET :offset to the SQL after stripping any
     * trailing semicolon.
     *
     * Throws when:
     *   - The SQL already contains a LIMIT clause (would produce duplicate LIMIT).
     *   - The query already uses a param named :limit or :offset (name collision).
     */
    private function injectPagination(string $sql, string $queryName = ''): string
    {
        $prefix = $queryName !== '' ? "Query '{$queryName}'" : 'Query';

        // Guard 1: existing LIMIT clause (but not inside a named param like :limit)
        if (preg_match('/(?<![:\w])LIMIT\b/i', $sql)) {
            throw new \RuntimeException(
                "{$prefix}: cannot use :many-paginated on a query that already contains " .
                "a LIMIT clause. Remove the manual LIMIT or use :many instead."
            );
        }

        // Guard 2: param name collision with auto-injected :limit / :offset
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $paramMatches);
        $paramNames = array_map(fn(string $p) => ltrim($p, ':'), $paramMatches[0] ?? []);

        foreach (['limit', 'offset'] as $reserved) {
            if (in_array($reserved, $paramNames, true)) {
                throw new \RuntimeException(
                    "{$prefix}: cannot use :many-paginated because the query already " .
                    "has a parameter named ':{$reserved}'. Rename it to avoid collision " .
                    "with the auto-injected pagination parameters."
                );
            }
        }

        return rtrim(trim($sql), ';') . "\nLIMIT :limit OFFSET :offset";
    }

    /**
     * Force columns named in $nillableColumns to be nullable.
     *
     * @param  ResolvedColumn[] $columns
     * @param  string[]         $nillable  Column aliases to force nullable
     * @return ResolvedColumn[]
     */
    private function applyNillable(array $columns, array $nillable): array
    {
        if (empty($nillable)) return $columns;

        return array_map(function (\SqlcPhp\Resolver\ResolvedColumn $col) use ($nillable): \SqlcPhp\Resolver\ResolvedColumn {
            if (!in_array($col->alias, $nillable, true)) return $col;

            // Force nullable — strip existing ? and re-add
            $base    = ltrim($col->phpType, '?');
            $newType = "?{$base}";

            return new \SqlcPhp\Resolver\ResolvedColumn(
                alias:      $col->alias,
                columnName: $col->columnName,
                tableName:  $col->tableName,
                sqlType:    $col->sqlType,
                nullable:   true,
                phpType:    $newType,
            );
        }, $columns);
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function detectDirectModel(QueryDefinition $query, array $resultColumns): array
    {
        if (empty($resultColumns) || $query->fromTable === null) {
            return [false, null];
        }

        $tables = array_unique(array_map(fn($c) => $c->tableName, $resultColumns));

        if (count($tables) > 1 || ($tables[0] ?? '') === '') {
            return [false, null];
        }

        $singleTable = $tables[0];

        if (strtolower($singleTable) !== strtolower($query->fromTable)) {
            return [false, null];
        }

        $modelClass = $this->queryParser->toPascalCase(
            $this->queryParser->toSingular($singleTable)
        );

        return [true, $modelClass];
    }

    // ─────────────────────────────────────────────────────────────
    // @partial helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Detect which parameter names appear inside COALESCE(:param, ...) in the
     * SET clause of an UPDATE. Those are the "partial" params — optional at
     * runtime because passing null leaves the column unchanged.
     *
     * Strategy: split SQL on the first WHERE keyword (case-insensitive).
     * Everything before WHERE is the SET region. Scan for COALESCE(:name, ...).
     *
     * @return string[]  param names (without leading colon)
     */
    private function detectPartialParams(string $sql, string $queryName): array
    {
        // Normalise whitespace for easier matching
        $upper = strtoupper($sql);

        // Must be an UPDATE
        if (!str_starts_with(trim($upper), 'UPDATE')) {
            throw new \RuntimeException(
                "Query '{$queryName}': @partial is only valid on UPDATE queries."
            );
        }

        // Split into SET region (before WHERE) and WHERE region (after WHERE)
        $wherePos = strripos($sql, ' WHERE ');
        $setRegion = $wherePos !== false ? substr($sql, 0, $wherePos) : $sql;

        // Find all COALESCE(:paramName, ...) occurrences in the SET region
        $partial = [];
        if (preg_match_all('/\bCOALESCE\s*\(\s*:([a-zA-Z_][a-zA-Z0-9_]*)\s*,/i', $setRegion, $m)) {
            foreach ($m[1] as $name) {
                $partial[] = $name;
            }
        }

        if (empty($partial)) {
            fwrite(STDERR,
                "sqlc-php: @partial on '{$queryName}' found no COALESCE(:param, col) patterns " .
                "in the SET clause. Use COALESCE(:field, field) to mark updatable fields.\n"
            );
        }

        return array_unique($partial);
    }

    /**
     * Apply @optional and @partial flags to the resolved param list.
     * - @optional params: optional = true
     * - @partial params:  optional = true, phpType forced to nullable
     * Required params keep their types unchanged.
     *
     * @param QueryParam[]  $params
     * @param string[]      $partialNames
     * @param string[]      $optionalNames
     * @return QueryParam[]
     */
    private function markPartialAndOptional(
        array $params,
        array $partialNames,
        array $optionalNames,
    ): array {
        return array_map(function (QueryParam $p) use ($partialNames, $optionalNames): QueryParam {
            $isPartial  = in_array($p->name, $partialNames, true);
            $isOptional = in_array($p->name, $optionalNames, true);

            if (!$isPartial && !$isOptional) {
                return $p;
            }

            // Force phpType to be nullable (strip existing ? then re-add)
            $base = ltrim($p->phpType, '?');
            // DateTimeImmutable already has backslash prefix — keep it
            $nullableType = '?' . $base;

            return new QueryParam(
                name:     $p->name,
                sqlType:  $p->sqlType,
                nullable: true,
                pdoParam: $p->pdoParam,
                phpType:  $nullableType,
                optional: true,
                inList:   $p->inList,
            );
        }, $params);
    }
}
