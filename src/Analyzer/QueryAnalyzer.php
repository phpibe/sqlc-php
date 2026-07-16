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

        // For @with paginated, auto-inject LIMIT :limit OFFSET :offset into the SQL.
        // This was previously tied to :many-paginated; now it is a standalone flag.
        if ($query->paginated) {
            $rewrittenSql = $this->injectPagination($rewrittenSql, $query->name);
        }

        // 3. Resolve parameters against the rewritten SQL
        $rawParams = $this->paramResolver->resolve($rewrittenSql, $query->paramAnnotations);

        // @optional marking will happen in markPartialAndOptional() below
        $params = $rawParams;

        // Apply @nullable — force nullable=true on explicitly listed params
        if (!empty($query->nullableParams)) {
            $params = $this->applyNullableParams($params, $query->nullableParams);
        }

        // 4. Resolve result columns — treat :paginated like :many for column resolution
        $resultColumns        = [];
        $returnsModelDirectly = false;
        $modelClass           = null;

        $isSelectQuery = $query->returns->value !== ':exec';
        if ($isSelectQuery) {
            $rawColumns = $this->columnResolver->resolve($rewrittenSql);

            // UNION ALL: resolve each branch independently and merge types.
            // Rules: if types differ → mixed; if one branch is nullable → nullable.
            if ($query->isUnion) {
                $rawColumns = $this->mergeUnionBranchTypes($rewrittenSql, $rawColumns);
            }

            $resultColumns = $this->applyNillable($rawColumns, $query->nillableColumns);
            $resultColumns = $this->applyTypeOverrides($resultColumns, $query->typeOverrides);

            // @nillable, @embed, @column, or virtual table → always generate a custom DTO.
            // Exception: @embed is allowed to co-exist with detectDirectModel when
            // all non-embedded columns come from a single table (table.* pattern).
            // In that case, detectDirectModel filters out __ columns and may still
            // return true — but we override it back to false because @embed means
            // the result has nested objects and needs a DTO, not the plain model.
            $isVirtual = $this->catalog !== null
                && $query->fromTable !== null
                && ($this->catalog->getTable($query->fromTable)?->virtual ?? false);
            $hasCustomizations = !empty($query->nillableColumns)
                || !empty($query->columnAliases)
                || $isVirtual;
            if (!$hasCustomizations) {
                [$returnsModelDirectly, $modelClass] = $this->detectDirectModel($query, $resultColumns);
                // @embed forces DTO mode even when base columns are from a single table —
                // the result object has nested embedded properties that the plain model lacks.
                if ($returnsModelDirectly && !empty($query->embeds)) {
                    $returnsModelDirectly = false;
                    $modelClass           = null;
                }
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

        // Validate @counted / @with count: valid on :many-paginated and :cursor
        // @with paginated makes :many behave like :many-paginated for validation purposes
        $isManyPaginated = $query->returns === ReturnType::ManyPaginated || $query->paginated;

        if ($query->counted && !$isManyPaginated && $query->returns !== ReturnType::Cursor) {
            if ($query->returns === ReturnType::Paginator) {
                throw new \RuntimeException(
                    "Query '{$query->name}': :paginator and @with count cannot be combined. " .
                    ":paginator already includes an internal COUNT query. " .
                    "Use :many with @with paginated, count for a separate count method."
                );
            }
            throw new \RuntimeException(
                "Query '{$query->name}': @with count is only valid on :many + @with paginated, and :cursor queries. " .
                "Got: {$query->returns->value}"
            );
        }

        // Validate @with exists: valid on :many, :many-paginated, and :cursor
        if ($query->exists
            && $query->returns !== ReturnType::Many
            && !$isManyPaginated
            && $query->returns !== ReturnType::Cursor
        ) {
            throw new \RuntimeException(
                "Query '{$query->name}': @with exists is only valid on :many, :many (+ @with paginated), " .
                "and :cursor queries. Got: {$query->returns->value}"
            );
        }

        // Validate @searchable: valid on :many, :many-paginated, :paginated, and :cursor
        // but NOT on UNION queries (WHERE would only apply to the last branch)
        if ($query->searchable) {
            if ($query->isUnion) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @searchable cannot be used with UNION queries. " .
                    "Appending a dynamic WHERE clause to a UNION applies only to the last " .
                    "SELECT branch, which would produce incorrect results. " .
                    "Use a subquery instead: SELECT * FROM (UNION query) AS t WHERE ..."
                );
            }
            if ($query->returns !== ReturnType::Many
                && !$isManyPaginated
                && $query->returns !== ReturnType::Paginator
                && $query->returns !== ReturnType::Cursor
            ) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @searchable is only valid on :many, :many-paginated, " .
                    ":paginated, and :cursor queries. Got: {$query->returns->value}"
                );
            }
        }

        // Validate @partial: only valid on :exec, not on UNION
        if ($query->partial) {
            if ($query->isUnion) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @partial cannot be used with UNION queries."
                );
            }
            if ($query->returns !== ReturnType::Exec) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @partial is only valid on :exec queries (UPDATE statements). " .
                    "Got: {$query->returns->value}"
                );
            }
        }

        // Validate @returning: only valid on :one INSERT, not on UNION
        if ($query->returning) {
            if ($query->isUnion) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @returning cannot be used with UNION queries."
                );
            }
        }

        // Validate :paginated: cannot combine with @counted
        if ($query->returns === ReturnType::Paginator && $query->counted) {
            throw new \RuntimeException(
                "Query '{$query->name}': :paginated and @counted cannot be combined. " .
                "Use :paginated for a single PaginatedResult object, " .
                "or :many-paginated with @counted for two separate methods."
            );
        }

        // Validate @returning: only valid on :one INSERT queries
        if ($query->returning) {
            if ($query->returns !== ReturnType::One) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @returning is only valid on :one queries. " .
                    "Got: {$query->returns->value}"
                );
            }
        }

        // Validate :cursor + @cursor
        if (!empty($query->cursorColumns)) {
            if ($query->returns !== ReturnType::Cursor) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @cursor requires ':cursor' as the return type. " .
                    "Got: {$query->returns->value}"
                );
            }
            if ($query->isUnion) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @cursor cannot be used with UNION queries. " .
                    "Cursor pagination requires a single SELECT with a stable ORDER BY."
                );
            }
        }
        if ($query->returns === ReturnType::Cursor && empty($query->cursorColumns)) {
            throw new \RuntimeException(
                "Query '{$query->name}': :cursor requires @cursor to declare the cursor columns. " .
                "Example: -- @cursor created_at DESC, id DESC"
            );
        }
        if ($query->counted && $query->returns === ReturnType::Cursor && $query->isUnion) {
            throw new \RuntimeException(
                "Query '{$query->name}': @counted cannot be combined with :cursor on UNION queries."
            );
        }
        if ($query->returning) {
            $sqlUpper = strtoupper(trim($query->sql));
            if (!str_starts_with($sqlUpper, 'INSERT')) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @returning is only valid on INSERT statements."
                );
            }
            if (str_contains($sqlUpper, 'ON DUPLICATE KEY')) {
                throw new \RuntimeException(
                    "Query '{$query->name}': @returning cannot be used with ON DUPLICATE KEY UPDATE. " .
                    "lastInsertId() is unreliable when an UPDATE occurs instead of an INSERT."
                );
            }
            // Verify the table has a detectable primary key
            if ($query->fromTable !== null) {
                $pk = $this->catalog->primaryKey($query->fromTable);
                if ($pk === null) {
                    throw new \RuntimeException(
                        "Query '{$query->name}': @returning requires a detectable primary key on " .
                        "table '{$query->fromTable}'. Add PRIMARY KEY or AUTO_INCREMENT to the schema."
                    );
                }
            }
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
            comment:              $query->comment,
            nillableColumns:      $query->nillableColumns,
            embeds:               $query->embeds,
            dtoClassName:         $query->dtoClassName,
            columnAliases:        $query->columnAliases,
            counted:              $query->counted,
            searchable:           $query->searchable,
            paginated:            $query->paginated,
            exists:               $query->exists,
            partial:              $query->partial,
            returning:            $query->returning,
            isUnion:              $query->isUnion,
            typeOverrides:        $query->typeOverrides,
            cursorColumns:        $query->cursorColumns,
            jsonColumns:          $query->jsonColumns,
            filterColumns:        $query->filterColumns,
            usedCtes:             $query->usedCtes,
            nullableParams:       $query->nullableParams,
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
    /**
     * Force nullable=true on params explicitly listed via @nullable.
     *
     * The phpType is updated to have a leading '?' if not already present.
     * The PDO param type stays unchanged — PDO::PARAM_STR handles null correctly.
     *
     * @param  array<string, QueryParam> $params
     * @param  string[]                  $nullableNames
     * @return array<string, QueryParam>
     */
    private function applyNullableParams(array $params, array $nullableNames): array
    {
        $nameSet = array_flip($nullableNames);

        return array_map(function (QueryParam $p) use ($nameSet): QueryParam {
            if (!isset($nameSet[$p->name])) return $p;

            $phpType = $p->phpType;
            if (!str_starts_with($phpType, '?')) {
                $phpType = '?' . $phpType;
            }

            return new QueryParam(
                name:     $p->name,
                sqlType:  $p->sqlType,
                nullable: true,
                pdoParam: $p->pdoParam,
                phpType:  $phpType,
                optional: $p->optional,
                inList:   $p->inList,
            );
        }, $params);
    }

    /**
     * Resolve each UNION branch independently and merge the column types.
     *
     * Merge rules (applied per column position):
     *   - Same base type + both non-nullable  → use that type, non-nullable
     *   - Same base type + one nullable       → use that type, nullable
     *   - Different base types                → mixed
     *   - Branch count differs from first     → keep first-branch columns (safe fallback)
     *
     * After merging, @type overrides can still correct any column individually.
     *
     * @param  ResolvedColumn[] $firstBranchColumns Already resolved from the full SQL
     * @return ResolvedColumn[]
     */
    private function mergeUnionBranchTypes(string $sql, array $firstBranchColumns): array
    {
        $branches = $this->splitUnionBranches($sql);

        // With < 2 branches we can't compare — return first-branch result as-is
        if (count($branches) < 2) return $firstBranchColumns;

        // Resolve each branch independently
        /** @var ResolvedColumn[][] $perBranch */
        $perBranch = [];
        foreach ($branches as $branch) {
            try {
                $cols = $this->columnResolver->resolve($branch);
                if (!empty($cols)) {
                    $perBranch[] = $cols;
                }
            } catch (\Throwable) {
                // Branch couldn't be resolved — skip it, keep first-branch result
            }
        }

        if (count($perBranch) < 2) return $firstBranchColumns;

        $base    = $perBranch[0];
        $merged  = [];

        foreach ($base as $i => $baseCol) {
            $phpType = ltrim($baseCol->phpType, '?');
            $nullable = $baseCol->nullable;
            $consistent = true;

            foreach (array_slice($perBranch, 1) as $branch) {
                $other = $branch[$i] ?? null;
                if ($other === null) {
                    $consistent = false;
                    break;
                }

                $otherBase = ltrim($other->phpType, '?');

                if ($otherBase !== $phpType) {
                    // Type conflict → widen to mixed
                    $phpType    = 'mixed';
                    $nullable   = false;
                    $consistent = false;
                    break;
                }

                // Same base type — propagate nullable
                if ($other->nullable || str_starts_with($other->phpType, '?')) {
                    $nullable = true;
                }
            }

            $finalPhpType = ($nullable && $phpType !== 'mixed')
                ? '?' . $phpType
                : $phpType;

            $merged[] = new \SqlcPhp\Resolver\ResolvedColumn(
                alias:      $baseCol->alias,
                columnName: $baseCol->columnName,
                tableName:  $consistent ? $baseCol->tableName : '',
                sqlType:    $baseCol->sqlType,
                nullable:   $nullable,
                phpType:    $finalPhpType,
            );
        }

        return $merged ?: $firstBranchColumns;
    }

    /**
     * Split a UNION / UNION ALL query into its individual SELECT branches.
     *
     * Handles top-level UNION / UNION ALL keywords only (not inside subqueries).
     * Preserves each branch as a complete SELECT statement for independent analysis.
     *
     * @return string[]  One entry per branch; may be empty on parse failure.
     */
    private function splitUnionBranches(string $sql): array
    {
        $branches = [];
        $depth    = 0;
        $current  = '';
        $len      = strlen($sql);
        $inStr    = false;
        $strChar  = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if (!$inStr && ($ch === "'" || $ch === '"' || $ch === '`')) {
                $inStr   = true;
                $strChar = $ch;
                $current .= $ch;
                continue;
            }
            if ($inStr) {
                $current .= $ch;
                if ($ch === $strChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inStr = false;
                }
                continue;
            }

            if ($ch === '(') { $depth++; $current .= $ch; continue; }
            if ($ch === ')') { $depth--; $current .= $ch; continue; }

            // At depth 0: look for UNION / UNION ALL keyword
            if ($depth === 0 && preg_match('/^UNION(\s+ALL)?\s/i', substr($sql, $i), $m)) {
                $branches[] = trim($current);
                $current    = '';
                $i         += strlen($m[0]) - 1;
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $branches[] = trim($current);
        }

        return $branches;
    }

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
     * Override the PHP type of result columns explicitly annotated with @type.
     *
     * -- @type alias phpType
     *
     * This is the primary mechanism for fixing inferred types in UNION queries,
     * for constant expressions ('user' as role), or for any column whose type
     * the resolver cannot determine.
     *
     * @param  ResolvedColumn[]         $columns
     * @param  array<string, string>    $overrides  alias → phpType
     * @return ResolvedColumn[]
     */
    private function applyTypeOverrides(array $columns, array $overrides): array
    {
        if (empty($overrides)) return $columns;

        $valid = $this->validatePhpTypeNames(array_values($overrides));
        if (!empty($valid)) {
            throw new \RuntimeException(
                '@type annotation contains invalid PHP type(s): ' . implode(', ', $valid)
            );
        }

        return array_map(function (\SqlcPhp\Resolver\ResolvedColumn $col) use ($overrides): \SqlcPhp\Resolver\ResolvedColumn {
            if (!isset($overrides[$col->alias])) return $col;

            $phpType  = $overrides[$col->alias];
            $nullable = str_starts_with($phpType, '?');

            return new \SqlcPhp\Resolver\ResolvedColumn(
                alias:      $col->alias,
                columnName: $col->columnName,
                tableName:  $col->tableName,
                sqlType:    $col->sqlType,     // preserve original SQL type for reference
                nullable:   $nullable,
                phpType:    $phpType,
            );
        }, $columns);
    }

    /**
     * Returns any type names that are not valid PHP scalar / built-in types.
     * Allows: bool, int, float, string, array, mixed, null, and any class name
     * (starts with uppercase letter or backslash), all optionally prefixed with ?.
     *
     * @param  string[] $types
     * @return string[] Invalid types
     */
    private function validatePhpTypeNames(array $types): array
    {
        $scalars = ['bool', 'int', 'float', 'string', 'array', 'mixed', 'null',
                    'void', 'never', 'object', 'iterable', 'callable',
                    '\\DateTimeImmutable', 'DateTimeImmutable', '\\DateTimeInterface'];
        $invalid = [];
        foreach ($types as $t) {
            $base = ltrim($t, '?');
            // json:ClassName and json:ClassName[] are handled by ResultDtoGenerator — always valid
            if (preg_match('/^json:[A-Z][A-Za-z0-9_\\\\]*(\[\])?$/', $base)) continue;
            $ok   = in_array(strtolower($base), array_map('strtolower', $scalars), true)
                 || preg_match('/^\\\\?[A-Z][A-Za-z0-9_\\\\]*$/', $base);
            if (!$ok) $invalid[] = $t;
        }
        return $invalid;
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function detectDirectModel(QueryDefinition $query, array $resultColumns): array
    {
        if (empty($resultColumns) || $query->fromTable === null) {
            return [false, null];
        }

        // Columns whose alias contains '__' are embedded object fields (from @embed).
        // They come from joined tables and should NOT count against the "single table"
        // check — they will be grouped into nested objects by the DTO generator.
        // e.g. SELECT reserve_billing.*, reserve.id AS reserve__id
        //   → reserve__id has tableName='reserve', but it belongs to an @embed object
        $nonEmbedColumns = array_values(array_filter(
            $resultColumns,
            fn(ResolvedColumn $c) => !str_contains($c->alias, '__')
        ));

        // If all non-embed columns were filtered out, fall back to all columns
        $columnsToCheck = empty($nonEmbedColumns) ? $resultColumns : $nonEmbedColumns;

        $tables = array_unique(array_map(fn($c) => $c->tableName, $columnsToCheck));

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
