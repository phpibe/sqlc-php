<?php

declare(strict_types=1);

namespace SqlcPhp\Generator;

use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Parser\QueryDefinition;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Query\PaginatedResult;
use SqlcPhp\Query\QueryObject;
use SqlcPhp\Resolver\QueryParam;
use SqlcPhp\TypeMapper\TypeMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates one PHP Query class per @group, containing one method per query.
 */
class QueryGenerator
{
    private CriteriaGenerator $criteriaGen;
    private QueryParser       $parser;

    /**
     * @param string $namespace      Namespace for generated Query classes
     * @param string $dtosNamespace  Namespace for DTOs — PaginatedResult is generated there.
     *                               Defaults to $namespace when not provided (string form out:).
     */
    public function __construct(
        private readonly SchemaCatalog          $catalog,
        private readonly TypeMapperInterface    $typeMapper,
        private readonly ResultDtoGenerator     $resultDtoGen,
        private readonly string                 $namespace,
        private readonly bool                   $generateInterfaces     = false,
        private readonly ?InterfaceGenerator    $interfaceGen           = null,
        private readonly bool                   $preparedStatementCache = false,
        private readonly string                 $classSuffix            = 'Query',
        private readonly string                 $dtosNamespace          = '',
    ) {
        $this->criteriaGen = new CriteriaGenerator($namespace);
        $this->parser      = new QueryParser();
    }

    /**
     * Returns the namespace where PaginatedResult lives.
     * When dtosNamespace is set (map form out:), PaginatedResult is generated
     * in that namespace. Otherwise it shares the query class namespace.
     */
    private function paginatedResultNamespace(): string
    {
        return $this->dtosNamespace !== '' ? $this->dtosNamespace : $this->namespace;
    }

    /**
     * Generate the PaginatedResult class with the correct namespace for this target.
     * Called by the CLI when any :paginated query is present in the target.
     *
     * @return array{className: string, code: string}
     */
    public function generatePaginatedResult(): array
    {
        $ns = $this->paginatedResultNamespace();

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

/**
 * Result of a :paginated query — items + pagination metadata in one object.
 *
 * Returned by generated methods with \`@returns :paginated\`. The \$items array
 * contains the typed model/DTO objects for the requested page. The metadata
 * fields describe the full result set so the caller can build pagination UI
 * without a second query.
 *
 * @template T
 */
readonly class PaginatedResult
{
    /**
     * @param array<T> \$items    The rows for the current page.
     * @param int      \$total    Total number of rows matching the query (all pages).
     * @param int      \$limit    Number of rows per page (as requested).
     * @param int      \$offset   Number of rows skipped (as requested).
     * @param int      \$pages    Total number of pages: ceil(\$total / \$limit). 0 when \$total is 0.
     * @param bool     \$hasMore  True when there are rows after the current page.
     */
    public function __construct(
        public array \$items,
        public int   \$total,
        public int   \$limit,
        public int   \$offset,
        public int   \$pages,
        public bool  \$hasMore,
    ) {}

    /**
     * The current page number (1-based).
     * Returns 1 even when the result set is empty.
     */
    public function currentPage(): int
    {
        if (\$this->limit <= 0) return 1;
        return (int) floor(\$this->offset / \$this->limit) + 1;
    }

    /** True when this is the first page (offset === 0). */
    public function isFirstPage(): bool
    {
        return \$this->offset === 0;
    }

    /** True when this is the last page (no more rows after this one). */
    public function isLastPage(): bool
    {
        return !\$this->hasMore;
    }

    /** Offset for the next page, or null when there is no next page. */
    public function nextOffset(): ?int
    {
        return \$this->hasMore ? \$this->offset + \$this->limit : null;
    }

    /** Offset for the previous page, or null when on the first page. */
    public function previousOffset(): ?int
    {
        if (\$this->offset <= 0) return null;
        return max(0, \$this->offset - \$this->limit);
    }
}
PHP;

        return ['className' => 'PaginatedResult', 'code' => $code];
    }

    /**
     * @param  QueryDefinition[] $queries
     * @return array<string, array{className: string, code: string}>
     */
    public function generate(array $queries): array
    {
        $groups = [];
        foreach ($queries as $query) {
            $groups[$query->group][] = $query;
        }

        $files = [];
        foreach ($groups as $group => $groupQueries) {
            $className = $group . $this->classSuffix;
            $code = $this->renderClass($className, $groupQueries);
            $files[$className] = ['className' => $className, 'code' => $code];

            // Generate Criteria classes for @searchable queries
            foreach ($groupQueries as $query) {
                if ($query->searchable && !empty($query->resultColumns)) {
                    $criteriaResult = $this->criteriaGen->generate($query, $query->resultColumns);
                    $files[$criteriaResult['className']] = $criteriaResult;
                }
            }
        }

        return $files;
    }

    /**
     * Generate interface files for all query groups.
     * Returns empty array when interface generation is disabled.
     *
     * @param  QueryDefinition[] $queries
     * @return array<string, array{className: string, code: string}>
     */
    public function generateInterfaces(array $queries): array
    {
        if (!$this->generateInterfaces || $this->interfaceGen === null) {
            return [];
        }

        $groups = [];
        foreach ($queries as $query) {
            $groups[$query->group][] = $query;
        }

        $files = [];
        foreach ($groups as $group => $groupQueries) {
            $queryClassName = $group . $this->classSuffix;
            ['className' => $cls, 'code' => $code] =
                $this->interfaceGen->generate($queryClassName, $groupQueries, $this);
            $files[$cls] = ['className' => $cls, 'code' => $code];
        }

        return $files;
    }

    /** @param QueryDefinition[] $queries */
    private function renderClass(string $className, array $queries): string
    {
        $methods      = array_map(fn($q) => $this->renderMethod($q), $queries);
        $methodsStr   = implode("\n\n", $methods);
        $implements   = ($this->generateInterfaces && $this->interfaceGen !== null)
            ? ' implements ' . $this->interfaceGen->interfaceName($className)
            : '';

        // Prepared statement cache property — only included when the feature is on
        $stmtsProperty = $this->preparedStatementCache
            ? "\n    /** @var array<string, \\PDOStatement> */\n    private array \$stmts = [];\n"
            : '';

        // PaginatedResult import only when the class has :paginated queries
        $hasPaginate     = !empty(array_filter($queries, fn($q) => $q->returns === ReturnType::Paginated));
        $prNs            = $this->paginatedResultNamespace();
        $prFqcn          = $prNs !== $this->namespace ? $prNs . '\\PaginatedResult' : null;
        $paginatedImport = $hasPaginate
            ? "\nuse " . ($prFqcn ?? 'SqlcPhp\\Query\\PaginatedResult') . ";"
            : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$this->namespace};

use Closure;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;{$paginatedImport}
use SqlcPhp\Query\QueryObject;

/**
 * Query class for the `{$className}` group.
 * Generated by sqlc-php — do not edit manually.
 */
class {$className}{$implements}
{
    /**
     * @param PDO                 \$pdo         PDO connection.
     * @param LoggerInterface|null \$logger     Optional PSR-3 logger. When provided, every
     *                                          executed query is logged at DEBUG level with the
     *                                          SQL as message and bound values as context.
     * @param Closure|null        \$afterQuery  Optional hook called after every query with the
     *                                          QueryObject as its sole argument. Use this to
     *                                          integrate with Debugbar, metrics, custom events, etc.
     *                                          Signature: function(QueryObject \$q): void
     */
    public function __construct(
        private readonly PDO               \$pdo,
        private readonly ?LoggerInterface  \$logger     = null,
        private readonly ?Closure          \$afterQuery = null,
    ) {}{$stmtsProperty}

    /** Stores the SQL and bindings of the most recently executed method. */
    private ?QueryObject \$lastQuery = null;

    /**
     * Returns a QueryObject with the SQL and bindings of the last executed method.
     * Useful for logging, debugging, testing, and building cache keys.
     * Returns null if no method has been called yet.
     */
    public function lastQuery(): ?QueryObject
    {
        return \$this->lastQuery;
    }

    /**
     * Logs the last executed query and fires the afterQuery hook.
     * Called automatically at the end of every generated method.
     * No-op when both logger and afterQuery hook are null.
     */
    private function logLastQuery(): void
    {
        if (\$this->lastQuery === null) return;

        \$this->logger?->debug(
            sprintf('%s [%.3fms]: %s',
                \$this->lastQuery->queryName,
                \$this->lastQuery->durationMs,
                \$this->lastQuery->toString(),
            ),
            \$this->lastQuery->values(),
        );

        if (\$this->afterQuery !== null) {
            (\$this->afterQuery)(\$this->lastQuery);
        }
    }

{$methodsStr}
}
PHP;
    }

    private function renderMethod(QueryDefinition $query): string
    {
        // @returning: INSERT that fetches and returns the created row
        if ($query->returning) {
            return $this->renderReturningMethod($query);
        }

        // :paginated: returns a PaginatedResult object with items + metadata
        if ($query->returns === ReturnType::Paginated) {
            return $query->searchable
                ? $this->renderSearchablePaginateMethod($query)
                : $this->renderPaginateMethod($query);
        }

        // @searchable queries get their own render path
        if ($query->searchable) {
            $main = $query->returns === ReturnType::ManyPaginated
                ? $this->renderSearchablePaginatedMethod($query)
                : $this->renderSearchableManyMethod($query);

            if ($query->counted && $query->returns === ReturnType::ManyPaginated) {
                $main .= "\n\n" . $this->renderSearchableCountMethod($query);
            }

            return $main;
        }

        $main = match ($query->returns->value) {
            ':many'           => $this->renderManyMethod($query),
            ':many-paginated' => $this->renderManyPaginatedMethod($query),
            ':one'            => $this->renderOneMethod($query),
            ':opt'            => $this->renderOptMethod($query),
            ':exec'           => $this->renderExecMethod($query),
            ':batch'          => $this->renderBatchMethod($query),
            ':transaction'    => $this->renderTransactionMethod($query),
            default           => $this->renderManyMethod($query),
        };

        // @counted on :many-paginated — emit an additional {name}Count() method
        if ($query->counted && $query->returns === ReturnType::ManyPaginated) {
            $main .= "\n\n" . $this->renderCountMethod($query);
        }

        return $main;
    }

    // =========================================================================
    // @searchable methods
    // =========================================================================

    private function criteriaClass(QueryDefinition $query): string
    {
        return $query->group . 'Criteria';
    }

    /**
     * Helper: build the SQL assembly block for @searchable methods.
     * Handles:
     *   - static WHERE already in SQL (uses AND, not WHERE)
     *   - GROUP BY / ORDER BY present in SQL (insert criteria before them)
     *   - static ORDER BY replaced by criteria ORDER BY when criteria has one
     */
    private function buildSearchableSqlBlock(
        string $baseSql,
        string $criteriaVar,
        bool   $withLimit = false,
    ): string {
        // Normalise: remove trailing semicolon, collapse whitespace
        $normalized = rtrim(preg_replace('/\s+/', ' ', trim($baseSql)) ?? $baseSql, ';');

        // Also strip LIMIT :limit OFFSET :offset that the analyzer may have injected
        $normalized = rtrim(preg_replace('/\s+LIMIT\s+:limit\s+OFFSET\s+:offset\s*$/i', '', $normalized));

        // Detect structural keywords (case-insensitive)
        $hasWhere    = (bool) preg_match('/\bWHERE\b/i',    $normalized);
        $hasGroupBy  = (bool) preg_match('/\bGROUP\s+BY\b/i', $normalized);
        $hasOrderBy  = (bool) preg_match('/\bORDER\s+BY\b/i', $normalized);

        // Split SQL into: beforeOrder, staticOrderBy
        // If there's an ORDER BY, we need to either keep it (no criteria order) or replace it
        if ($hasOrderBy) {
            $splitPos    = (int) preg_match('/^(.*?)(\s+ORDER\s+BY\s+.*)$/is', $normalized, $sm);
            $beforeOrder = $splitPos ? trim($sm[1]) : $normalized;
            $staticOrder = $splitPos ? trim($sm[2]) : '';
        } else {
            $beforeOrder = $normalized;
            $staticOrder = '';
        }

        // The base SQL for no-criteria or WITH-criteria cases:
        // We embed the SQL as a PHP string and append the WHERE/AND and ORDER dynamically
        $escaped = str_replace("'", "\\'", $beforeOrder);

        $lines = [];
        $lines[] = "        \$__sql = '{$escaped}';";
        $lines[] = "        if ({$criteriaVar} !== null && {$criteriaVar}->hasFilters()) {";
        $lines[] = "            \$__hasWhere = " . ($hasWhere ? 'true' : 'false') . ";";
        $lines[] = "            \$__sql .= {$criteriaVar}->toFilterClause(\$__hasWhere);";
        $lines[] = "        }";

        // ORDER BY logic
        if ($staticOrder !== '') {
            $escapedOrder = str_replace("'", "\\'", $staticOrder);
            $lines[] = "        if ({$criteriaVar} !== null && {$criteriaVar}->hasOrderBy()) {";
            $lines[] = "            \$__sql .= {$criteriaVar}->toOrderClause();";
            $lines[] = "        } else {";
            $lines[] = "            \$__sql .= ' {$escapedOrder}';";
            $lines[] = "        }";
        } else {
            $lines[] = "        if ({$criteriaVar} !== null && {$criteriaVar}->hasOrderBy()) {";
            $lines[] = "            \$__sql .= {$criteriaVar}->toOrderClause();";
            $lines[] = "        }";
        }

        if ($withLimit) {
            $lines[] = "        \$__sql .= ' LIMIT :limit OFFSET :offset';";
        }

        return implode("\n", $lines);
    }

    private function renderSearchableManyMethod(QueryDefinition $query): string
    {
        $returnClass  = $this->resolveReturnClass($query);
        $criteriaClass = $this->criteriaClass($query);
        $userParams   = $this->buildParamList($query);
        $critParam    = "?{$criteriaClass} \$criteria = null";
        $allParams    = $userParams !== '' ? "{$userParams}, {$critParam}" : $critParam;

        $docblock = $this->buildDocblock($query, "@return {$returnClass}[]");
        $bindings     = $this->renderBindings($query);
        $bindingsStr  = rtrim($bindings);
        $bindBlock    = $bindingsStr !== '' ? $bindingsStr . "\n" : '';
        $bindingsExpr = $this->buildBindingsExpr($query);

        $sqlBlock = $this->buildSearchableSqlBlock($query->sql, '$criteria');

        return <<<PHP
{$docblock}
    public function {$query->name}({$allParams}): array
    {
{$sqlBlock}
        \$stmt = \$this->pdo->prepare(\$__sql);
{$bindBlock}        \$criteria?->bindAll(\$stmt);
        \$this->lastQuery = new QueryObject(\$__sql, array_merge({$bindingsExpr}, \$criteria?->getBindings() ?? []), '{$query->name}');
        \$__t0 = hrtime(true);
        \$stmt->execute();
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();

        return array_map(
            static fn(array \$row): {$returnClass} => {$returnClass}::fromRow(\$row),
            \$stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }
PHP;
    }

    private function renderSearchablePaginatedMethod(QueryDefinition $query): string
    {
        $returnClass  = $this->resolveReturnClass($query);
        $criteriaClass = $this->criteriaClass($query);
        $userParams   = $this->buildParamList($query);
        $critParam    = "?{$criteriaClass} \$criteria = null";
        $paginParams  = '?int $limit = null, int $offset = 0';
        $allParams    = $userParams !== ''
            ? "{$userParams}, {$critParam}, {$paginParams}"
            : "{$critParam}, {$paginParams}";

        $docReturn = "@return {$returnClass}[]";
        $docblock  = $this->buildDocblockWithExtra($query, $docReturn, [
            "@param ?{$criteriaClass} \$criteria   Dynamic filters and ordering. Pass null to skip.",
            "@param ?int \$limit                   Max rows. Null returns all.",
            "@param int  \$offset                  Rows to skip.",
        ]);

        $bindings     = $this->renderBindings($query);
        $bindingsStr  = rtrim($bindings);
        $bindBlock    = $bindingsStr !== '' ? $bindingsStr . "\n" : '';
        $bindingsExpr = $this->buildBindingsExpr($query);

        $sqlBlockAll  = $this->buildSearchableSqlBlock($query->sql, '$criteria', withLimit: false);
        $sqlBlockPage = $this->buildSearchableSqlBlock($query->sql, '$criteria', withLimit: true);

        return <<<PHP
{$docblock}
    public function {$query->name}({$allParams}): array
    {
        if (\$limit === null) {
{$sqlBlockAll}
            \$stmt = \$this->pdo->prepare(\$__sql);
{$bindBlock}            \$criteria?->bindAll(\$stmt);
        } else {
{$sqlBlockPage}
            \$stmt = \$this->pdo->prepare(\$__sql);
{$bindBlock}            \$criteria?->bindAll(\$stmt);
            \$stmt->bindValue(':limit',  \$limit,  PDO::PARAM_INT);
            \$stmt->bindValue(':offset', \$offset, PDO::PARAM_INT);
        }
        \$this->lastQuery = new QueryObject(\$__sql, array_merge({$bindingsExpr}, \$criteria?->getBindings() ?? []), '{$query->name}');
        \$__t0 = hrtime(true);
        \$stmt->execute();
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();

        return array_map(
            static fn(array \$row): {$returnClass} => {$returnClass}::fromRow(\$row),
            \$stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }
PHP;
    }

    private function renderSearchableCountMethod(QueryDefinition $query): string
    {
        $criteriaClass = $this->criteriaClass($query);
        $userParams   = $this->buildParamList($query);
        $critParam    = "?{$criteriaClass} \$criteria = null";
        $allParams    = $userParams !== '' ? "{$userParams}, {$critParam}" : $critParam;
        $countName    = $query->name . 'Count';

        $bindings     = $this->renderBindings($query);
        $bindingsStr  = rtrim($bindings);
        $bindBlock    = $bindingsStr !== '' ? $bindingsStr . "\n" : '';
        $bindingsExpr = $this->buildBindingsExpr($query);

        $sqlBlock = $this->buildSearchableSqlBlock($query->sql, '$criteria', withLimit: false);

        // Wrap the dynamic SQL in a COUNT subquery at runtime
        return <<<PHP
    /**
     * Returns the total number of rows matching the current criteria (without pagination).
     * @param ?{$criteriaClass} \$criteria Same criteria as the main method — pass the same instance.
     * @return int
     */
    public function {$countName}({$allParams}): int
    {
{$sqlBlock}
        \$__countSql = 'SELECT COUNT(*) AS _total FROM (' . \$__sql . ') AS _count_subquery';
        \$stmt = \$this->pdo->prepare(\$__countSql);
{$bindBlock}        \$criteria?->bindAll(\$stmt);
        \$this->lastQuery = new QueryObject(\$__countSql, array_merge({$bindingsExpr}, \$criteria?->getBindings() ?? []), '{$countName}');
        \$__t0 = hrtime(true);
        \$stmt->execute();
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ((\$row !== false ? \$row['_total'] : null) ?? 0);
    }
PHP;
    }

    /**
     * Generate the $stmt = ... prepare line.
     * With prepared_statement_cache on: uses $this->stmts[__FUNCTION__] ??= ...
     * Without: direct $this->pdo->prepare(...)
     *
     * When the same placeholder appears more than once (e.g. in UNION queries),
     * PDO named parameter binding requires each occurrence to have a unique name.
     * We rename occurrences 2, 3, … to :param__2, :param__3, etc. in the SQL
     * sent to prepare(), then bind those aliases to the same PHP value.
     */
    private function renderPrepare(QueryDefinition $query): string
    {
        $sql        = $this->expandDuplicatePlaceholders($query->sql);
        $sqlLiteral = $this->renderSqlLiteral($sql);
        if ($this->preparedStatementCache) {
            return "        \$stmt = \$this->stmts[__FUNCTION__] ??= \$this->pdo->prepare({$sqlLiteral});\n";
        }
        return "        \$stmt = \$this->pdo->prepare({$sqlLiteral});\n";
    }

    /**
     * Rename the 2nd, 3rd, … occurrence of each named placeholder in the SQL
     * to `:name__2`, `:name__3`, etc.  Returns the rewritten SQL.
     *
     * This is needed for UNION queries (and any other query) where the same
     * parameter appears in multiple branches — PDO throws when the same named
     * placeholder appears more than once in a prepared statement.
     */
    private function expandDuplicatePlaceholders(string $sql): string
    {
        $counts = [];
        return (string) preg_replace_callback(
            '/:[a-zA-Z_][a-zA-Z0-9_]*/',
            function (array $m) use (&$counts): string {
                $name = $m[0]; // e.g. ':reserveId'
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                if ($counts[$name] === 1) {
                    return $name;                           // first occurrence — unchanged
                }
                return $name . '__' . $counts[$name];      // :reserveId__2, :reserveId__3
            },
            $sql
        );
    }

    /**
     * Render extra bindValue calls for duplicate-placeholder aliases.
     * e.g. if :reserveId appears twice, this emits:
     *   $stmt->bindValue(':reserveId__2', $reserveId, PDO::PARAM_INT);
     */
    private function renderDuplicateBindings(QueryDefinition $query, string $stmtVar = '$stmt'): string
    {
        $counts = [];
        $lines  = [];
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $query->sql, $m);
        foreach ($m[0] as $raw) {
            $counts[$raw] = ($counts[$raw] ?? 0) + 1;
            if ($counts[$raw] < 2) continue;
            // Find the resolved param for this placeholder
            $paramName = ltrim($raw, ':');
            $param     = null;
            foreach ($query->params as $p) {
                if ($p->name === $paramName) { $param = $p; break; }
            }
            if ($param === null) continue;
            $alias = ":{$paramName}__{$counts[$raw]}";
            $lines[] = "        {$stmtVar}->bindValue('{$alias}', \${$paramName}, {$param->pdoParam});";
        }
        return empty($lines) ? '' : implode("\n", $lines) . "\n";
    }

    // =========================================================================
    // lastQuery() — QueryObject recording
    // =========================================================================

    /**
     * Render the statement that saves the current SQL + bindings to $this->lastQuery.
     * Injected at the end of each query method, just before execute/return.
     *
     * @param string $sqlExpr   PHP expression for the SQL (literal or variable)
     * @param string $bindings  PHP expression for the bindings array
     * @param string $name      Method name
     * @param bool   $isBatch
     * @param string $batchCountExpr  PHP expression for batch count
     */
    private function renderSaveLastQuery(
        string $sqlExpr,
        string $bindingsExpr = '[]',
        string $name = '__FUNCTION__',
        bool   $isBatch = false,
        string $batchCountExpr = '0',
    ): string {
        $isBatchStr = $isBatch ? 'true' : 'false';
        return "        \$this->lastQuery = new QueryObject({$sqlExpr}, {$bindingsExpr}, {$name}, {$isBatchStr}, {$batchCountExpr});";
    }

    /**
     * Render the timer + withDuration + logLastQuery block.
     * Wraps $stmt->execute() to measure execution time.
     *
     * Output:
     *   $__t0 = hrtime(true);
     *   $stmt->execute();
     *   $this->lastQuery = $this->lastQuery->withDuration((hrtime(true) - $__t0) / 1_000_000);
     *   $this->logLastQuery();
     */
    private function renderTimerAndExecute(): string
    {
        return <<<'PHP'
        $__t0 = hrtime(true);
        $stmt->execute();
        $this->lastQuery = $this->lastQuery->withDuration((hrtime(true) - $__t0) / 1_000_000);
        $this->logLastQuery();
PHP;
    }

    /**
     * Render the timer + withDuration + logLastQuery block for a named SQL variable.
     * Used in @searchable methods where SQL is in $__sql.
     */
    private function renderTimerAndExecuteSearchable(): string
    {
        return <<<'PHP'
        $__t0 = hrtime(true);
        $stmt->execute();
        $this->lastQuery = $this->lastQuery->withDuration((hrtime(true) - $__t0) / 1_000_000);
        $this->logLastQuery();
PHP;
    }

    /**
     * Build a PHP bindings array expression from a QueryDefinition's params.
     * Returns a PHP literal like:
     *   [':id' => [$id, PDO::PARAM_INT], ':email' => [$email, PDO::PARAM_STR]]
     */
    private function buildBindingsExpr(QueryDefinition $query): string
    {
        $isPaginated    = $query->returns->value === ':many-paginated';
        $paginationKeys = ['limit', 'offset'];

        $parts = [];
        foreach ($query->params as $param) {
            if ($param->inList) continue; // IN() bindings are positional, skip
            if ($isPaginated && in_array($param->name, $paginationKeys, true)) continue;

            $parts[] = "    ':{$param->name}' => [\${$param->name}, {$param->pdoParam}]";
            if ($param->optional) {
                $chk     = $param->name . '_chk';
                $parts[] = "    ':{$chk}' => [\${$param->name}, {$param->pdoParam}]";
            }
        }

        if (empty($parts)) return '[]';

        $inner = implode(",\n", $parts);
        return "[\n{$inner},\n        ]";
    }

    // =========================================================================
    // @paginate — PaginatedResult
    // =========================================================================

    /**
     * Builds the count SQL by wrapping the base SQL in a subquery.
     * Strips any existing ORDER BY (irrelevant for counting).
     */
    private function buildCountSql(string $baseSql): string
    {
        $normalized = rtrim(preg_replace('/\s+/', ' ', trim($baseSql)) ?? $baseSql, ';');
        // Remove trailing ORDER BY — irrelevant for COUNT
        $normalized = (string) preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $normalized);
        // Remove trailing LIMIT/OFFSET if present
        $normalized = rtrim((string) preg_replace('/\s+LIMIT\s+:limit\s+OFFSET\s+:offset\s*$/i', '', $normalized));
        return "SELECT COUNT(*) AS _total FROM ({$normalized}) AS _paginate_total";
    }

    // =========================================================================
    // :paginated — shared core + two entry points
    // =========================================================================

    /**
     * Core paginate body shared by renderPaginateMethod and renderSearchablePaginateMethod.
     *
     * @param string $countSqlExpr  PHP expression evaluating to the COUNT SQL string
     * @param string $pageSqlExpr   PHP expression evaluating to the page SQL (includes LIMIT/OFFSET)
     * @param string $countBindings Rendered bindValue() block targeting \$__countStmt
     * @param string $pageBindings  Rendered bindValue() block targeting \$__stmt
     * @param string $bindingsExpr  PHP expression for QueryObject bindings array
     * @param string $returnClass   Class name for fromRow()
     * @param string $queryName     Method name for QueryObject recording
     * @param string $criteriaCount Optional criteria->bindAll($__countStmt) block
     * @param string $criteriaPage  Optional criteria->bindAll($__stmt) block
     */
    private function renderPaginateCore(
        string $countSqlExpr,
        string $pageSqlExpr,
        string $countBindings,
        string $pageBindings,
        string $bindingsExpr,
        string $returnClass,
        string $queryName,
        string $criteriaCount = '',
        string $criteriaPage  = '',
    ): string {
        return <<<PHP
        // Count total rows (without LIMIT)
        \$__countStmt = \$this->pdo->prepare({$countSqlExpr});
{$countBindings}{$criteriaCount}        \$__countStmt->execute();
        \$__countRow = \$__countStmt->fetch(PDO::FETCH_ASSOC);
        \$__total = (int) ((\$__countRow !== false ? \$__countRow['_total'] : null) ?? 0);

        // Fetch current page
        \$__stmt = \$this->pdo->prepare({$pageSqlExpr});
        \$__stmt->bindValue(':limit',  \$limit,  PDO::PARAM_INT);
        \$__stmt->bindValue(':offset', \$offset, PDO::PARAM_INT);
{$pageBindings}{$criteriaPage}
        \$this->lastQuery = new QueryObject({$pageSqlExpr}, array_merge({$bindingsExpr}, [':limit' => [\$limit, PDO::PARAM_INT], ':offset' => [\$offset, PDO::PARAM_INT]]), '{$queryName}');
        \$__t0 = hrtime(true);
        \$__stmt->execute();
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();

        \$__items = array_map(
            static fn(array \$row): {$returnClass} => {$returnClass}::fromRow(\$row),
            \$__stmt->fetchAll(PDO::FETCH_ASSOC),
        );

        \$__pages  = \$__total > 0 && \$limit > 0 ? (int) ceil(\$__total / \$limit) : 0;
        \$__hasMore = \$offset + count(\$__items) < \$__total;

        return new PaginatedResult(
            items:   \$__items,
            total:   \$__total,
            limit:   \$limit,
            offset:  \$offset,
            pages:   \$__pages,
            hasMore: \$__hasMore,
        );
PHP;
    }

    private function renderPaginateMethod(QueryDefinition $query): string
    {
        $returnClass  = $this->resolveReturnClass($query);
        $userParams   = $this->buildParamList($query);
        $paginParams  = $userParams !== '' ? ", int \$limit = 10, int \$offset = 0" : "int \$limit = 10, int \$offset = 0";
        $signature    = "{$query->name}({$userParams}{$paginParams}): PaginatedResult";

        $countSqlLit = $this->renderSqlLiteral($this->buildCountSql($query->sql));
        $pageSql     = rtrim(preg_replace('/\\s+/', ' ', trim($query->sql)), ';') . ' LIMIT :limit OFFSET :offset';
        $pageSqlLit  = $this->renderSqlLiteral($pageSql);

        $countBindings = $this->renderBindings($query, '$__countStmt');
        $pageBindings  = $this->renderBindings($query, '$__stmt');
        $bindingsExpr  = $this->buildBindingsExpr($query);

        $body = $this->renderPaginateCore(
            $countSqlLit, $pageSqlLit,
            $countBindings, $pageBindings,
            $bindingsExpr, $returnClass, $query->name,
        );

        return <<<PHP
    /**
     * @param int \$limit  Rows per page. Defaults to 10.
     * @param int \$offset Rows to skip.
     * @return PaginatedResult<{$returnClass}>
     */
    public function {$signature}
    {
{$body}    }
PHP;
    }

    private function renderSearchablePaginateMethod(QueryDefinition $query): string
    {
        $returnClass   = $this->resolveReturnClass($query);
        $criteriaClass = $this->criteriaClass($query);
        $userParams    = $this->buildParamList($query);
        $allParams     = $userParams !== ''
            ? "{$userParams}, ?{$criteriaClass} \$criteria = null, int \$limit = 10, int \$offset = 0"
            : "?{$criteriaClass} \$criteria = null, int \$limit = 10, int \$offset = 0";

        $sqlBlock = $this->buildSearchableSqlBlock($query->sql, '$criteria', withLimit: false);

        // Dynamic SQL expressions — evaluated at runtime from $__sql
        $countSqlExpr = "'SELECT COUNT(*) AS _total FROM (' . \$__sql . ') AS _paginate_total'";
        $pageSqlExpr  = "(\$__sql . ' LIMIT :limit OFFSET :offset')";

        $countBindings = $this->renderBindings($query, '$__countStmt');
        $pageBindings  = $this->renderBindings($query, '$__stmt');
        $bindingsExpr  = $this->buildBindingsExpr($query);
        $mergedExpr    = "array_merge({$bindingsExpr}, \$criteria?->getBindings() ?? [])";

        $criteriaCountBlock = "        \$criteria?->bindAll(\$__countStmt);\n";
        $criteriaPageBlock  = "        \$criteria?->bindAll(\$__stmt);\n";

        $body = $this->renderPaginateCore(
            $countSqlExpr, $pageSqlExpr,
            $countBindings, $pageBindings,
            $mergedExpr, $returnClass, $query->name,
            $criteriaCountBlock, $criteriaPageBlock,
        );

        return <<<PHP
    /**
     * @param ?{$criteriaClass} \$criteria  Dynamic filters and ordering.
     * @param int \$limit                   Rows per page. Defaults to 10.
     * @param int \$offset                  Rows to skip.
     * @return PaginatedResult<{$returnClass}>
     */
    public function {$query->name}({$allParams}): PaginatedResult
    {
        // Build dynamic SQL from criteria filters
{$sqlBlock}

{$body}    }
PHP;
    }

    // =========================================================================
    // @returning — INSERT that fetches and returns the created row
    // =========================================================================

    private function renderReturningMethod(QueryDefinition $query): string
    {
        // Derive model class from the INSERT table (same logic as ModelGenerator)
        $tableName    = $query->fromTable ?? '';
        $returnClass  = $query->modelClass
            ?? $this->parser->toPascalCase($this->parser->toSingular($tableName));
        $signature    = $this->buildSignature($query, $returnClass);
        $docblock     = $this->buildDocblock($query, "@return {$returnClass}");
        $bindings     = $this->renderBindings($query);
        $prepare      = $this->renderPrepare($query);
        $sqlExpr      = $this->renderSqlLiteral($query->sql);
        $bindingsExpr = $this->buildBindingsExpr($query);

        // Determine the PK column for the SELECT-back query
        $pkCol     = $this->catalog->primaryKey($tableName) ?? 'id';
        $selectSql = "SELECT * FROM {$tableName} WHERE {$pkCol} = :{$pkCol}";
        $selectLit = $this->renderSqlLiteral($selectSql);

        return <<<PHP
{$docblock}
    public function {$signature}
    {
        // Execute the INSERT
{$prepare}{$bindings}
        \$this->lastQuery = new QueryObject({$sqlExpr}, {$bindingsExpr}, '{$query->name}');
        \$__t0 = hrtime(true);
        \$stmt->execute();
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();

        // Fetch the newly created row by its primary key
        \$__{$pkCol} = (int) \$this->pdo->lastInsertId();
        \$__selectStmt = \$this->pdo->prepare({$selectLit});
        \$__selectStmt->bindValue(':{$pkCol}', \$__{$pkCol}, PDO::PARAM_INT);
        \$__selectStmt->execute();
        \$__row = \$__selectStmt->fetch(PDO::FETCH_ASSOC);
        if (\$__row === false) {
            throw new RuntimeException(
                '{$query->name}: inserted row not found (id=' . \$__{$pkCol} . ')'
            );
        }

        return {$returnClass}::fromRow(\$__row);
    }
PHP;
    }

    // =========================================================================
    // :many
    // =========================================================================

    private function renderManyMethod(QueryDefinition $query): string
    {
        $returnClass  = $this->resolveReturnClass($query);
        $signature    = $this->buildSignature($query, "array");
        $docblock     = $this->buildDocblock($query, "@return {$returnClass}[]");
        $bindings     = $this->renderBindings($query);
        $prepare      = $this->hasInListParams($query)
            ? ''
            : $this->renderPrepare($query);
        $sqlExpr      = $this->renderSqlLiteral($query->sql);
        $bindingsExpr = $this->buildBindingsExpr($query);
        $saveLastQuery = $this->renderSaveLastQuery($sqlExpr, $bindingsExpr, "'{$query->name}'");
        $executeBlock = $this->hasInListParams($query) ? '' : $this->renderTimerAndExecute();

        return <<<PHP
{$docblock}
    public function {$signature}
    {
{$prepare}{$bindings}{$saveLastQuery}
{$executeBlock}
        return array_map(
            static fn(array \$row): {$returnClass} => {$returnClass}::fromRow(\$row),
            \$stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }
PHP;
    }

    // -------------------------------------------------------------------------
    // :many-paginated
    // -------------------------------------------------------------------------

    private function renderManyPaginatedMethod(QueryDefinition $query): string
    {
        $returnClass = $this->resolveReturnClass($query);

        // $limit is nullable: null → no LIMIT applied, all rows returned.
        // $offset without $limit throws at runtime (meaningless without a limit).
        $userParams  = $this->buildParamList($query);
        $paginParams = $userParams !== ''
            ? ", ?int \$limit = null, int \$offset = 0"
            : "?int \$limit = null, int \$offset = 0";

        $signature = "{$query->name}({$userParams}{$paginParams}): array";

        $docReturn = "@return {$returnClass}[]";
        $docblock  = $this->buildDocblockWithExtra($query, $docReturn, [
            "@param ?int \$limit  Maximum rows to return. Pass null (default) to return all rows.",
            "@param int  \$offset Number of rows to skip. Ignored when \$limit is null.",
        ]);

        // SQL without LIMIT/OFFSET (used when $limit is null)
        $sqlNoLimit  = preg_replace('/\s+LIMIT\s+:limit\s+OFFSET\s+:offset\s*;?\s*$/i', '', trim($query->sql));
        $sqlNoLimit  = rtrim($sqlNoLimit, ';');
        $sqlWithLimit = rtrim(trim($query->sql), ';');

        $sqlNoLimitLiteral  = $this->renderSqlLiteral($sqlNoLimit);
        $sqlWithLimitLiteral = $this->renderSqlLiteral($sqlWithLimit);

        $bindings = $this->renderBindings($query);

        // Cache keys differ to avoid caching the wrong statement when both
        // code paths are used in the same request.
        if ($this->preparedStatementCache) {
            $prepareAll  = "        \$stmt = \$this->stmts[__FUNCTION__ . '_all']  ??= \$this->pdo->prepare({$sqlNoLimitLiteral});\n";
            $preparePage = "        \$stmt = \$this->stmts[__FUNCTION__ . '_page'] ??= \$this->pdo->prepare({$sqlWithLimitLiteral});\n";
        } else {
            $prepareAll  = "        \$stmt = \$this->pdo->prepare({$sqlNoLimitLiteral});\n";
            $preparePage = "        \$stmt = \$this->pdo->prepare({$sqlWithLimitLiteral});\n";
        }

        // Build the paginated bindings block (user params + :limit + :offset)
        $bindingsStr = rtrim($bindings);
        $bindAllBlock = $bindingsStr !== '' ? $bindingsStr . "\n" : '';
        $bindPageBlock = $bindAllBlock .
            "        \$stmt->bindValue(':limit',  \$limit,  PDO::PARAM_INT);\n" .
            "        \$stmt->bindValue(':offset', \$offset, PDO::PARAM_INT);\n";

        $bindingsExpr = $this->buildBindingsExpr($query);
        // Paginated: lastQuery set inside each branch (SQL differs), timer wraps execute after
        $saveAll  = "        \$this->lastQuery = new QueryObject(\$__sql, {$bindingsExpr}, '{$query->name}');\n";
        $savePage = "        \$this->lastQuery = new QueryObject(\$__sql, array_merge({$bindingsExpr}, [':limit' => [\$limit, PDO::PARAM_INT], ':offset' => [\$offset, PDO::PARAM_INT]]), '{$query->name}');\n";

        return <<<PHP
{$docblock}
    public function {$signature}
    {
        if (\$limit === null) {
            \$__sql = {$sqlNoLimitLiteral};
{$prepareAll}{$bindAllBlock}{$saveAll}        } else {
            \$__sql = {$sqlWithLimitLiteral};
{$preparePage}{$bindPageBlock}{$savePage}        }
        \$__t0 = hrtime(true);
        \$stmt->execute();
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();

        return array_map(
            static fn(array \$row): {$returnClass} => {$returnClass}::fromRow(\$row),
            \$stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }
PHP;
    }

    // -------------------------------------------------------------------------
    // @counted — companion COUNT method for :many-paginated
    // -------------------------------------------------------------------------

    /**
     * Generates a {name}Count() method that returns the total number of rows
     * matching the query's WHERE conditions — without LIMIT/OFFSET applied.
     *
     * SQL: SELECT COUNT(*) AS _total FROM (<original_sql>) AS _count_subquery
     *
     * The method signature mirrors the main :many-paginated method but without
     * the $limit and $offset parameters, since those don't affect the total count.
     */
    private function renderCountMethod(QueryDefinition $query): string
    {
        // Signature: same user params as the main method, no $limit/$offset
        $userParams = $this->buildParamList($query);
        $countName  = $query->name . 'Count';
        $signature  = "{$countName}({$userParams}): int";

        $docblock = $this->buildDocblock($query, '@return int Total number of rows matching the filter conditions.');

        // The SQL for the count wraps the original (already @optional-rewritten) SQL.
        // For :many-paginated, the analyzer appends LIMIT :limit OFFSET :offset to the SQL.
        // We MUST strip those before wrapping — they are not bound in the count method
        // and would cause PDO HY093 (invalid parameter number).
        $innerSql = preg_replace('/\s+/', ' ', trim($query->sql)) ?? $query->sql;
        $innerSql = rtrim($innerSql, ';');
        $innerSql = preg_replace('/\s+LIMIT\s+:limit\s+OFFSET\s+:offset\s*$/i', '', $innerSql);
        $innerSql = rtrim($innerSql);
        $countSql   = "SELECT COUNT(*) AS _total FROM ({$innerSql}) AS _count_subquery";
        $sqlLiteral = $this->renderSqlLiteral($countSql);

        // Bindings: same as the main method but without :limit/:offset
        // renderBindings only emits the user-defined params — limit/offset are
        // bound separately in renderManyPaginatedMethod, so we get exactly what we need.
        $bindings = $this->renderBindings($query);

        $prepare = $this->preparedStatementCache
            ? "        \$stmt = \$this->stmts[__FUNCTION__] ??= \$this->pdo->prepare({$sqlLiteral});\n"
            : "        \$stmt = \$this->pdo->prepare({$sqlLiteral});\n";

        $bindingsExpr = $this->buildBindingsExpr($query);
        $saveLastQuery = $this->renderSaveLastQuery($sqlLiteral, $bindingsExpr, "'{$countName}'");

        return <<<PHP
{$docblock}
    public function {$signature}
    {
{$prepare}{$bindings}{$saveLastQuery}
        \$__t0 = hrtime(true);
        \$stmt->execute();
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ((\$row !== false ? \$row['_total'] : null) ?? 0);
    }
PHP;
    }

    // -------------------------------------------------------------------------
    // :one — throws if no row found
    // -------------------------------------------------------------------------

    private function renderOneMethod(QueryDefinition $query): string
    {
        $returnClass   = $this->resolveReturnClass($query);
        $signature     = $this->buildSignature($query, $returnClass);
        $docblock      = $this->buildDocblock($query, "@return {$returnClass}");
        $bindings      = $this->renderBindings($query);
        $prepare       = $this->hasInListParams($query) ? '' : $this->renderPrepare($query);
        $exception     = 'No record found for query "' . $query->name . '"';
        $sqlExpr       = $this->renderSqlLiteral($query->sql);
        $bindingsExpr  = $this->buildBindingsExpr($query);
        $saveLastQuery = $this->renderSaveLastQuery($sqlExpr, $bindingsExpr, "'{$query->name}'");
        $executeBlock  = $this->hasInListParams($query) ? '' : $this->renderTimerAndExecute();

        return <<<PHP
{$docblock}
    public function {$signature}
    {
{$prepare}{$bindings}{$saveLastQuery}
{$executeBlock}
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        if (\$row === false) {
            throw new RuntimeException('{$exception}');
        }

        return {$returnClass}::fromRow(\$row);
    }
PHP;
    }

    // -------------------------------------------------------------------------
    // :opt — returns null if no row found
    // -------------------------------------------------------------------------

    private function renderOptMethod(QueryDefinition $query): string
    {
        $returnClass   = $this->resolveReturnClass($query);
        $signature     = $this->buildSignature($query, "?{$returnClass}");
        $docblock      = $this->buildDocblock($query, "@return {$returnClass}|null");
        $bindings      = $this->renderBindings($query);
        $prepare       = $this->hasInListParams($query) ? '' : $this->renderPrepare($query);
        $sqlExpr       = $this->renderSqlLiteral($query->sql);
        $bindingsExpr  = $this->buildBindingsExpr($query);
        $saveLastQuery = $this->renderSaveLastQuery($sqlExpr, $bindingsExpr, "'{$query->name}'");
        $executeBlock  = $this->hasInListParams($query) ? '' : $this->renderTimerAndExecute();

        return <<<PHP
{$docblock}
    public function {$signature}
    {
{$prepare}{$bindings}{$saveLastQuery}
{$executeBlock}
        \$row = \$stmt->fetch(PDO::FETCH_ASSOC);
        return \$row !== false ? {$returnClass}::fromRow(\$row) : null;
    }
PHP;
    }

    // -------------------------------------------------------------------------
    // :exec
    // -------------------------------------------------------------------------

    private function renderExecMethod(QueryDefinition $query): string
    {
        $signature     = $this->buildSignature($query, 'void');
        $docblock      = $this->buildDocblock($query, 'Executes the statement.');
        $bindings      = $this->renderBindings($query);
        $prepare       = $this->hasInListParams($query) ? '' : $this->renderPrepare($query);
        $sqlExpr       = $this->renderSqlLiteral($query->sql);
        $bindingsExpr  = $this->buildBindingsExpr($query);
        $saveLastQuery = $this->renderSaveLastQuery($sqlExpr, $bindingsExpr, "'{$query->name}'");
        $executeBlock  = $this->hasInListParams($query) ? '' : $this->renderTimerAndExecute();

        return <<<PHP
{$docblock}
    public function {$signature}
    {
{$prepare}{$bindings}{$saveLastQuery}
{$executeBlock}    }
PHP;
    }

    // -------------------------------------------------------------------------
    // Public API for InterfaceGenerator
    // -------------------------------------------------------------------------

    /**
     * Returns the PHP return type string for a given query.
     * Exposed publicly so InterfaceGenerator can produce matching signatures.
     */
    public function resolveReturnTypePublic(QueryDefinition $query): string
    {
        return match ($query->returns->value) {
            ':many', ':many-paginated' => 'array',
            ':paginated'               => 'PaginatedResult',
            ':one'                     => $this->resolveReturnClass($query),
            ':opt'                     => '?' . $this->resolveReturnClass($query),
            ':exec'                    => 'void',
            default                    => 'array',
        };
    }

    /**
     * Returns the model/DTO class name for a query — the T in PaginatedResult<T>.
     * Used by InterfaceGenerator for @paginate method signatures.
     */
    public function resolveReturnClassPublic(QueryDefinition $query): string
    {
        return $this->resolveReturnClass($query);
    }

    /**
     * Returns the PHP parameter list string for a given query.
     * Exposed publicly so InterfaceGenerator can produce matching signatures.
     */
    public function buildParamListPublic(QueryDefinition $query): string
    {
        return $this->buildParamList($query);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveReturnClass(QueryDefinition $query): string
    {
        if ($query->returnsModelDirectly && $query->modelClass !== null) {
            return $query->modelClass;
        }

        if (!empty($query->resultColumns)) {
            return $this->resultDtoGen->dtoClassName($query);
        }

        return 'array';
    }

    /**
     * Build the method signature: `methodName(type $param, ...): returnType`
     */
    private function buildSignature(QueryDefinition $query, string $returnType): string
    {
        $params = $this->buildParamList($query);
        return "{$query->name}({$params}): {$returnType}";
    }

    /**
     * Build a PHP parameter list from resolved QueryParams.
     * Optional params receive a `= null` default value.
     * For :many-paginated queries, :limit and :offset are auto-injected by the
     * generator and must not appear in the user-facing parameter list.
     */
    private function buildParamList(QueryDefinition $query): string
    {
        if (empty($query->params)) return '';

        $required = [];
        $optional = [];

        // :limit and :offset are auto-injected for :many-paginated — skip them here
        $isPaginated    = $query->returns->value === ':many-paginated';
        $paginationKeys = ['limit', 'offset'];

        foreach ($query->params as $param) {
            if ($isPaginated && in_array($param->name, $paginationKeys, true)) {
                continue;
            }
            if ($param->inList) {
                $required[] = "array \${$param->name}";
            } elseif ($param->optional) {
                $type       = str_starts_with($param->phpType, '?')
                    ? $param->phpType
                    : '?' . $param->phpType;
                $optional[] = "{$type} \${$param->name} = null";
            } else {
                $required[] = "{$param->phpType} \${$param->name}";
            }
        }

        return implode(', ', array_merge($required, $optional));
    }

    /**
     * Build the docblock comment.
     */
    private function buildDocblock(QueryDefinition $query, string $returnTag): string
    {
        $lines = ['    /**'];

        if ($query->deprecated !== null) {
            $msg     = $query->deprecated !== '' ? ' ' . $query->deprecated : '';
            $lines[] = "     * @deprecated{$msg}";
        }

        foreach ($query->params as $param) {
            // Skip auto-injected pagination params for :many-paginated
            if ($query->returns->value === ':many-paginated'
                && in_array($param->name, ['limit', 'offset'], true)) {
                continue;
            }
            if ($param->inList) {
                $base     = ltrim($param->phpType, '?');
                $lines[]  = "     * @param {$base}[] \${$param->name} List of values for IN() clause — must be non-empty.";
            } elseif ($param->optional) {
                $type    = str_starts_with($param->phpType, '?')
                    ? $param->phpType
                    : '?' . $param->phpType;
                $lines[] = "     * @param {$type} \${$param->name} Pass null to skip this filter.";
            } else {
                $lines[] = "     * @param {$param->phpType} \${$param->name}";
            }
        }

        $lines[] = "     * {$returnTag}";
        $lines[] = '     */';

        return implode("\n", $lines);
    }

    /**
     * Build the docblock comment with extra @param lines appended before @return.
     * Used for :many-paginated to document limit/offset params.
     *
     * @param string[] $extraParamLines
     */
    private function buildDocblockWithExtra(QueryDefinition $query, string $returnTag, array $extraParamLines): string
    {
        $lines = ['    /**'];

        if ($query->deprecated !== null) {
            $msg     = $query->deprecated !== '' ? ' ' . $query->deprecated : '';
            $lines[] = "     * @deprecated{$msg}";
        }

        foreach ($query->params as $param) {
            if ($param->inList) {
                $base    = ltrim($param->phpType, '?');
                $lines[] = "     * @param {$base}[] \${$param->name} List of values for IN() clause — must be non-empty.";
            } elseif ($param->optional) {
                $type    = str_starts_with($param->phpType, '?')
                    ? $param->phpType
                    : '?' . $param->phpType;
                $lines[] = "     * @param {$type} \${$param->name} Pass null to skip this filter.";
            } else {
                $lines[] = "     * @param {$param->phpType} \${$param->name}";
            }
        }

        foreach ($extraParamLines as $line) {
            $lines[] = "     * {$line}";
        }

        $lines[] = "     * {$returnTag}";
        $lines[] = '     */';

        return implode("\n", $lines);
    }

    /**
     * Render the SQL setup and bindings for the method body.
     *
     * For regular queries returns simple bindValue() calls.
     * For queries with IN() params, emits runtime placeholder expansion first.
     */
    /**
     * Render the PDO bindValue() calls for all regular (non-IN-list) params.
     *
     * @param QueryDefinition $query
     * @param string          $stmtVar  The PHP variable name of the PDOStatement.
     *                                  Defaults to '$stmt'. Pass '$__countStmt' or
     *                                  '$__stmt' for the :paginated render methods.
     */
    private function renderBindings(QueryDefinition $query, string $stmtVar = '$stmt'): string
    {
        $inListParams  = array_filter($query->params, fn($p) => $p->inList);
        $regularParams = array_filter($query->params, fn($p) => !$p->inList);

        if (empty($inListParams)) {
            // Standard path: bindValue only
            if (empty($regularParams)) return $this->renderDuplicateBindings($query, $stmtVar);
            $lines = [];

            // For :many-paginated, :limit and :offset are bound separately in the
            // main render method — skip them here so they don't double-bind and
            // so the count method doesn't bind them at all.
            $isPaginated    = $query->returns->value === ':many-paginated';
            $paginationKeys = ['limit', 'offset'];

            foreach ($regularParams as $param) {
                if ($isPaginated && in_array($param->name, $paginationKeys, true)) {
                    continue;
                }
                $lines[] = "        {$stmtVar}->bindValue(':{$param->name}', \${$param->name}, {$param->pdoParam});";
                if ($param->optional) {
                    $chk = $param->name . '_chk';
                    $lines[] = "        {$stmtVar}->bindValue(':{$chk}', \${$param->name}, {$param->pdoParam});";
                }
            }

            // Extra bindings for repeated placeholders (e.g. UNION queries)
            $dupBindings = $this->renderDuplicateBindings($query, $stmtVar);

            $all = empty($lines) ? '' : implode("\n", $lines) . "\n";
            return $all . $dupBindings;
        }

        // Has IN params — expand placeholders at runtime
        $lines = [];
        $lines[] = '        // Expand IN() placeholders dynamically at runtime';
        $lines[] = '        $__sql = ' . $this->renderSqlLiteral($query->sql) . ';';

        foreach ($inListParams as $param) {
            $n    = "\${$param->name}";
            $ph   = "\$__ph_{$param->name}";
            $msg  = "Parameter \\\${$param->name} for IN() clause must not be empty.";
            $lines[] = "        if (empty({$n})) {";
            $lines[] = "            throw new \\InvalidArgumentException('{$msg}');";
            $lines[] = "        }";
            $lines[] = "        {$ph} = implode(',', array_fill(0, count({$n}), '?'));";
            $lines[] = "        \$__sql = str_replace(':{$param->name}', {$ph}, \$__sql);";
        }

        $lines[] = "        {$stmtVar} = \$this->pdo->prepare(\$__sql);";

        // Bind regular named params (excluding auto-injected limit/offset for paginated)
        $isPaginated    = $query->returns->value === ':many-paginated';
        $paginationKeys = ['limit', 'offset'];

        foreach ($regularParams as $param) {
            if ($isPaginated && in_array($param->name, $paginationKeys, true)) {
                continue;
            }
            $lines[] = "        {$stmtVar}->bindValue(':{$param->name}', \${$param->name}, {$param->pdoParam});";
            if ($param->optional) {
                $chk = $param->name . '_chk';
                $lines[] = "        {$stmtVar}->bindValue(':{$chk}', \${$param->name}, {$param->pdoParam});";
            }
        }

        // execute() receives positional values for all IN lists merged in order
        $executeArgParts = [];
        foreach ($inListParams as $param) {
            $executeArgParts[] = "...\${$param->name}";
        }
        $executeArgs = implode(', ', $executeArgParts);
        $lines[] = "        {$stmtVar}->execute([{$executeArgs}]);";
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Whether the query has IN-list params — affects how prepare/execute is emitted.
     */
    private function hasInListParams(QueryDefinition $query): bool
    {
        foreach ($query->params as $p) {
            if ($p->inList) return true;
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // :batch — run the same query N times inside a transaction
    // -------------------------------------------------------------------------

    private function renderBatchMethod(QueryDefinition $query): string
    {
        if (empty($query->params)) {
            throw new \RuntimeException(
                "Query '{$query->name}': :batch requires at least one named parameter."
            );
        }

        // Build the shape array for the @param docblock: array{name: type, ...}
        $shapeEntries = implode(', ', array_map(
            fn($p) => $p->name . ': ' . $p->phpType,
            $query->params
        ));
        $returnTag  = "@return int Number of rows processed";
        $docblock   = $this->buildDocblock($query, $returnTag);
        $sqlLiteral = $this->renderSqlLiteral($query->sql);

        // Generate bindValue lines for a single row from $row
        $bindLines = [];
        foreach ($query->params as $param) {
            $bindLines[] = "            \$stmt->bindValue(':{$param->name}', \$row['{$param->name}'], {$param->pdoParam});";
        }
        $bindStr = implode("\n", $bindLines);

        $methodName = $query->name;
        $signature  = "function {$methodName}(array \$rows): int";

        return <<<PHP
{$docblock}
    /**
     * Executes the query once per row in \$rows, inside a single transaction.
     * Rolls back and re-throws on any failure.
     *
     * @param array<array{{$shapeEntries}}> \$rows
     */
    public {$signature}
    {
        if (empty(\$rows)) return 0;

        \$stmt = \$this->pdo->prepare({$sqlLiteral});
        \$this->lastQuery = new QueryObject({$sqlLiteral}, [], '{$methodName}', true, count(\$rows));
        \$this->pdo->beginTransaction();
        \$__t0 = hrtime(true);
        try {
            foreach (\$rows as \$row) {
{$bindStr}
                \$stmt->execute();
            }
            \$this->pdo->commit();
        } catch (\Throwable \$e) {
            \$this->pdo->rollBack();
            throw \$e;
        }
        \$this->lastQuery = \$this->lastQuery->withDuration((hrtime(true) - \$__t0) / 1_000_000);
        \$this->logLastQuery();

        return count(\$rows);
    }
PHP;
    }

    // -------------------------------------------------------------------------
    // :transaction — run multiple :exec queries in one transaction
    // -------------------------------------------------------------------------

    private function renderTransactionMethod(QueryDefinition $query): string
    {
        // :transaction is a meta-query — the SQL is a comma-separated list of
        // @name values to call in sequence. Format:
        //   -- @name TransferFunds
        //   -- @returns :transaction
        //   -- @calls debitAccount,creditAccount
        // The SQL body is empty; @calls references other queries in the same group.

        $signature = $this->buildSignature($query, 'void');
        $docblock  = $this->buildDocblock($query, 'Executes multiple queries in a single transaction.');

        // Parse @calls from the sql body (stored as the raw SQL for :transaction)
        $calls     = array_filter(array_map('trim', explode(',', trim($query->sql))));

        if (empty($calls)) {
            throw new \RuntimeException(
                "Query '{$query->name}': :transaction requires @calls with a comma-separated " .
                "list of method names to execute (e.g. -- @calls debitAccount,creditAccount)."
            );
        }

        // Build the call lines — each callee receives the same params as this method
        $callLines = [];
        foreach ($calls as $callee) {
            // Forward matching params if the param name appears in this method's params
            $callLines[] = "            \$this->{$callee}(...func_get_args());";
        }
        $callStr = implode("\n", $callLines);

        // For :transaction methods the params are not auto-forwarded generically —
        // the user declares them explicitly and we call each sub-method with $this->method($arg)
        // For simplicity, emit individual $this->callee() calls without args;
        // the user can use @param to override the call signature.
        $callLinesSimple = [];
        foreach ($calls as $callee) {
            $callLinesSimple[] = "            \$this->{$callee}();";
        }

        // If the query has params, pass them through to all callees
        if (!empty($query->params)) {
            $paramPassStr = implode(', ', array_map(fn($p) => "\${$p->name}", $query->params));
            $callLinesSimple = [];
            foreach ($calls as $callee) {
                $callLinesSimple[] = "            \$this->{$callee}({$paramPassStr});";
            }
        }
        $callStr = implode("\n", $callLinesSimple);

        return <<<PHP
{$docblock}
    public {$signature}
    {
        \$this->pdo->beginTransaction();
        try {
{$callStr}
            \$this->pdo->commit();
        } catch (\Throwable \$e) {
            \$this->pdo->rollBack();
            throw \$e;
        }
    }
PHP;
    }

    /**
     * Normalise SQL into a single-line PHP single-quoted string literal.
     */
    private function renderSqlLiteral(string $sql): string
    {
        $oneLine = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $escaped = str_replace("'", "\\'", $oneLine);
        return "'{$escaped}'";
    }
}
