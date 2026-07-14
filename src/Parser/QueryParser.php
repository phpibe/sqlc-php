<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

use SqlcPhp\Resolver\QueryParam;
use SqlcPhp\Resolver\ResolvedColumn;

/**
 * Supported return cardinalities (mirrors sqlc conventions).
 */
enum ReturnType: string
{
    case Many          = ':many';
    case ManyPaginated = ':many-paginated';
    case Paginated     = ':paginated';
    case Cursor        = ':cursor';
    case One           = ':one';
    case Opt           = ':opt';
    case Exec          = ':exec';
    case Batch         = ':batch';
    case Transaction   = ':transaction';
}

/**
 * A single parsed and annotated SQL query, fully resolved.
 */
class QueryDefinition
{
    /**
     * @param QueryParam[]         $params            Resolved named parameters
     * @param ResolvedColumn[]     $resultColumns     Resolved SELECT output columns
     * @param array<string,string> $paramAnnotations  Raw @param annotations (name → table.col)
     * @param string[]             $optionalParams    Names declared with @optional
     * @param string|null          $deprecated        Deprecation message, or null if not deprecated
     * @param string[]             $nillableColumns   Column aliases forced to nullable via @nillable
     * @param EmbedDefinition[]    $embeds            Nested object groups declared with @embed
     */
    public function __construct(
        public readonly string     $name,
        public readonly string     $group,
        public readonly ReturnType $returns,
        public readonly string     $sql,
        public readonly ?string    $fromTable,
        public readonly array      $params = [],
        public readonly array      $resultColumns = [],
        public readonly array      $paramAnnotations = [],
        public readonly array      $optionalParams = [],
        /**
         * When true the SELECT is exactly "table.*" or "*" from a single table,
         * meaning the return type is the existing Model class (no new DTO needed).
         */
        public readonly bool       $returnsModelDirectly = false,
        /**
         * The model class name to use as return type (e.g. "User").
         * Set when returnsModelDirectly = true.
         */
        public readonly ?string    $modelClass = null,
        /** Deprecation message from @deprecated annotation. null = not deprecated. */
        public readonly ?string    $deprecated = null,
        /**
         * Human-readable description lines from @comment annotations.
         * Each @comment line becomes one entry in the array.
         * Emitted as the description block in the generated method docblock,
         * before @param and @return tags.
         *
         * -- @comment Returns the active user matching the given ID.
         * -- @comment Returns null when no match is found.
         *
         * @var string[]
         */
        public readonly array      $comment = [],
        /** Column aliases (or names) forced nullable via @nillable. */
        public readonly array      $nillableColumns = [],
        /**
         * Embed groups declared with @embed. Each entry describes a nested
         * readonly object to generate inside the result DTO.
         *
         * @var EmbedDefinition[]
         */
        public readonly array      $embeds = [],
        /**
         * When @dto ClassName is declared, this overrides the auto-generated
         * {QueryName}Row DTO class name with the specified ClassName.
         * Multiple queries can share the same @dto name if their column shapes match.
         */
        public readonly ?string    $dtoClassName = null,
        /**
         * Column renames declared via @column originalName alias.
         * Applied after column resolution — renames the alias of matching columns
         * in the result DTO without requiring SQL AS clauses.
         *
         * @var array<string, string>  originalName → alias
         */
        public readonly array      $columnAliases = [],
        /**
         * When true, an additional {name}Count() method is generated alongside
         * the :many-paginated method. The count method wraps the original SQL in
         * a SELECT COUNT(*) FROM (...) AS _count_subquery and returns int.
         * Only valid on :many-paginated queries.
         */
        public readonly bool       $counted = false,
        /**
         * When true, the method accepts a typed {Group}Criteria object
         * for dynamic WHERE conditions and ORDER BY. Valid on :many and
         * :many-paginated queries. A companion {Group}Criteria class is generated.
         */
        public readonly bool       $searchable = false,
        /**
         * When true, params that appear inside COALESCE(:param, col) in the SET
         * clause are marked optional (nullable, default null). Params in the WHERE
         * clause remain required. Only valid on :exec UPDATE queries.
         */
        public readonly bool       $partial = false,
        /**
         * When true, after executing the INSERT the generated method fetches
         * the newly created row by its primary key (via lastInsertId()) and
         * returns it as a model object. Only valid on :one INSERT queries.
         */
        public readonly bool       $returning = false,
        /**
         * True when the SQL contains UNION or UNION ALL.
         * Column types are resolved from the first SELECT only.
         * @searchable is disallowed (appending WHERE to UNION applies only
         * to the last branch). @partial and @returning are also disallowed.
         */
        public readonly bool       $isUnion   = false,
        /**
         * Explicit PHP type overrides for result columns, declared via @type.
         * This is the unified annotation for all column type overrides — scalar, JSON DTO, and nullable.
         *
         * Format: alias → phpType  e.g. ['role' => 'string', 'total' => '?float']
         *
         * Scalar types:
         *   -- @type role   string
         *   -- @type total  ?float         ← nullable scalar
         *   -- @type active bool
         *   -- @type ids    array          ← scalar JSON array (json_decode applied automatically)
         *
         * JSON DTO types (populate $jsonColumns internally):
         *   -- @type cities  json:City[]   ← City[]     (many, non-nullable)
         *   -- @type cities  ?json:City[]  ← City[]|null (many, nullable)
         *   -- @type address json:City     ← City       (one, non-nullable)
         *   -- @type address ?json:City    ← City|null  (one, nullable)
         *
         * @var array<string, string>  alias → phpType (scalar/class overrides only, not json:)
         */
        public readonly array      $typeOverrides = [],
        /**
         * Cursor columns for :cursor return type, declared via @cursor.
         * Format: [{col: string, dir: 'ASC'|'DESC'}]
         *
         * -- @cursor created_at DESC, id DESC
         *
         * @var array<int, array{col: string, dir: string}>
         */
        public readonly array      $cursorColumns = [],
        /**
         * Parameter names forced nullable via @nullable annotation.
         *
         * -- @nullable avatarUrl
         * -- @nullable deletedAt, closedAt   (comma-separated list also supported)
         *
         * Unlike @optional (which rewrites the SQL condition to IS NULL OR),
         * @nullable only changes the PHP type of the parameter to ?type.
         * The SQL is unchanged — the caller may pass null to set the column to NULL.
         *
         * Useful for UPDATE SET nullable_col = :param patterns where the column
         * accepts NULL and the resolver would otherwise infer a non-nullable type.
         *
         * @var string[]
         */
        public readonly array      $nullableParams = [],
        /**
         * CTE names declared via @use — resolved against CteRegistry at generation time.
         *
         * -- @use active_users
         * -- @use active_users, recent_orders
         *
         * @var string[]
         */
        public readonly array      $usedCtes = [],
        /**
         * JSON column → DTO class name mappings.
         *
         * Populated by two annotation forms (both equivalent):
         *
         * Unified @type syntax (preferred):
         *   -- @type cities   json:City      → City     (one, non-nullable)
         *   -- @type cities   ?json:City     → ?City    (one, nullable)
         *   -- @type cities   json:City[]    → City[]   (many, non-nullable)
         *   -- @type cities   ?json:City[]   → City[]|null (many, nullable)
         *
         * Legacy @json syntax (deprecated, still works):
         *   -- @json       cities City   → City[]  (array, default)
         *   -- @json:many  cities City   → City[]  (array, explicit)
         *   -- @json:one   address City  → City    (single object)
         *
         * Each entry: alias => ['class' => ClassName, 'many' => bool, 'nullable' => bool]
         *
         * @var array<string, array{class: string, many: bool, nullable: bool}>
         */
        public readonly array      $jsonColumns = [],
    ) {}
}

/**
 * Parses SQL files containing annotation-decorated queries.
 *
 * Supported annotations:
 *   -- @name       ListUsers
 *   -- @group      User
 *   -- @returns    :many | :many-paginated | :one | :opt | :exec
 *   -- @param      userId users.id       (explicit type override for a parameter)
 *   -- @optional   status                (passing null skips the filter condition)
 *   -- @deprecated Use newMethod instead (marks generated method as deprecated)
 *   -- @nillable   column_name           (forces a result column to be nullable)
 *   -- @embed      ClassName prefix_     (groups prefixed columns into a nested object)
 */
class QueryParser
{
    private \SqlcPhp\Inflector\InflectorService $inflector;

    public function __construct(string $language = 'english')
    {
        $this->inflector = new \SqlcPhp\Inflector\InflectorService($language);
    }

    /**
     * @return QueryDefinition[]
     */
    public function parse(string $sql): array
    {
        $queries = [];

        // Split on the @name annotation — each block starts where @name appears.
        // We use a lookahead so the @name line itself stays inside the block.
        $blocks = preg_split('/(?=^\s*--\s*@name\b)/mi', $sql);

        // Annotations that appear before the first @name (e.g. @comment placed
        // above @name) end up in a leading fragment with no @name of their own.
        // Carry them forward by prepending them to the next block.
        $carry = '';
        $merged = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;

            if (!preg_match('/--\s*@name\b/i', $block)) {
                // No @name in this fragment — accumulate and prepend to the next
                $carry .= "\n" . $block;
            } else {
                $merged[] = $carry !== '' ? trim($carry) . "\n" . $block : $block;
                $carry    = '';
            }
        }
        // Any trailing carry (annotations after the last query) is silently dropped.

        foreach ($merged as $block) {
            $block = trim($block);
            if (empty($block)) continue;

            $query = $this->parseBlock($block);
            if ($query !== null) {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    private function parseBlock(string $block): ?QueryDefinition
    {
        $name             = null;
        $group            = null;
        $returns          = null;
        $paramAnnotations = [];
        $optionalParams   = [];
        $nillableColumns  = [];
        $deprecated       = null;
        $commentLines     = [];           // @comment description lines
        $embeds           = [];
        $dtoClassName     = null;
        $counted          = false;
        $searchable       = false;
        $partial          = false;
        $returning        = false;        $columnAliases    = [];   // @column originalName alias
        $typeOverrides    = [];           // @type alias phpType
        $cursorColumns    = [];           // @cursor col1 DIR, col2 DIR
        $jsonColumns      = [];           // @json alias ClassName
        $usedCtes         = [];           // @use cte1, cte2
        $nullableParams   = [];           // @nullable param1, param2
        $sqlLines         = [];

        foreach (explode("\n", $block) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '--')) {
                $comment = trim(substr($trimmed, 2));

                if (preg_match('/^@name\s+(\w+)/i', $comment, $m)) {
                    $name = $m[1];
                } elseif (preg_match('/^@class\s+(\w+)/i', $comment, $m)) {
                    // @class is the canonical annotation — @group is deprecated
                    $group ??= $m[1];
                } elseif (preg_match('/^@group\s+(\w+)/i', $comment, $m)) {
                    // @group is deprecated in favour of @class — emit a stderr warning
                    // but continue normally so existing configs keep working.
                    fwrite(STDERR,
                        "sqlc-php: @group is deprecated, use @class instead " .
                        "(query: '{$name}').\n"
                    );
                    $group ??= $m[1];
                } elseif (preg_match('/@returns\s+(:[a-z-]+)/i', $comment, $m)) {
                    $returns = ReturnType::from($m[1]);
                } elseif (preg_match('/@param\s+(\w+)\s+([\w.]+)/i', $comment, $m)) {
                    $paramAnnotations[$m[1]] = $m[2];
                } elseif (preg_match('/@optional\s+(\w+)/i', $comment, $m)) {
                    $optionalParams[] = $m[1];
                } elseif (preg_match('/@nillable\s+(.+)/i', $comment, $m)) {
                    // @nillable — DEPRECATED since v2.16.0.
                    // Use: -- @type colname ?type   e.g. -- @type street ?string
                    foreach (preg_split('/[\s,]+/', trim($m[1])) as $nillCol) {
                        $nillCol = trim($nillCol);
                        if ($nillCol === '') continue;
                        fwrite(STDERR,
                            "sqlc-php: @nillable is deprecated since v2.16.0. " .
                            "Replace '-- @nillable {$nillCol}' with '-- @type {$nillCol} ?<phptype>'.\n"
                        );
                        $nillableColumns[] = $nillCol;
                    }
                } elseif (preg_match('/@deprecated(?:\s+(.+))?$/i', $comment, $m)) {
                    $deprecated = isset($m[1]) ? trim($m[1]) : '';
                } elseif (preg_match('/@comment\s+(.*)/i', $comment, $m)) {
                    // @comment One description line.
                    // Multiple @comment lines are joined as separate sentences
                    // in the generated docblock description.
                    // NOTE: use $commentText (not $line) to avoid shadowing the
                    // foreach control variable, which caused @comment to be lost
                    // when placed before @name (the block-split boundary).
                    $commentText = trim($m[1]);
                    if ($commentText !== '') {
                        $commentLines[] = $commentText;
                    }
                } elseif (preg_match('/@dto\s+(\w+)/i', $comment, $m)) {
                    $dtoClassName = $m[1];
                } elseif (preg_match('/^@counted\b/i', $comment)) {
                    $counted = true;
                } elseif (preg_match('/^@searchable\b/i', $comment)) {
                    $searchable = true;
                } elseif (preg_match('/^@partial\b/i', $comment)) {
                    $partial = true;
                } elseif (preg_match('/^@returning\b/i', $comment)) {
                    $returning = true;
                } elseif (preg_match('/@calls\s+(.+)$/i', $comment, $m)) {
                    // @calls query1,query2,query3 — used by :transaction
                    // Store as the SQL body so the generator can retrieve it
                    $sqlLines = [trim($m[1])];
                } elseif (preg_match('/@column\s+(\w+)\s+(\w+)/i', $comment, $m)) {
                    // @column originalName alias  — rename a result column in the DTO
                    $columnAliases[$m[1]] = $m[2];
                } elseif (preg_match('/^@type\s+(\w+)\s+(\S+)$/i', $comment, $m)) {
                    $alias    = $m[1];
                    $rawType  = $m[2];

                    // Detect json:Class / json:Class[] / ?json:Class / ?json:Class[] forms
                    $nullable = str_starts_with($rawType, '?');
                    $baseType = ltrim($rawType, '?');

                    if (preg_match('/^json:([A-Z][A-Za-z0-9_\\\\]*)(\[\])?$/', $baseType, $jm)) {
                        // @type alias json:ClassName      → ClassName  (one, non-nullable)
                        // @type alias ?json:ClassName     → ?ClassName (one, nullable)
                        // @type alias json:ClassName[]    → ClassName[] (many, non-nullable)
                        // @type alias ?json:ClassName[]   → ClassName[]|null (many, nullable)
                        $jsonColumns[$alias] = [
                            'class'    => $jm[1],
                            'many'     => isset($jm[2]),   // true when [] suffix present
                            'nullable' => $nullable,
                        ];
                    } else {
                        // Regular scalar/class type override
                        $typeOverrides[$alias] = $rawType;
                    }
                } elseif (preg_match('/@json(?::(one|many))?\\s+(\\w+)\\s+(\\w+)/i', $comment, $m)) {
                    // @json / @json:one / @json:many — DEPRECATED since v2.16.0.
                    // Use: -- @type alias json:Class[]   (many)
                    //      -- @type alias json:Class     (one)
                    //      -- @type alias ?json:Class[]  (many, nullable)
                    //      -- @type alias ?json:Class    (one, nullable)
                    $cardinality = ($m[1] !== '' && strtolower($m[1]) === 'one') ? 'one' : 'many';
                    $suggestion  = $cardinality === 'many'
                        ? "@type {$m[2]} json:{$m[3]}[]"
                        : "@type {$m[2]} json:{$m[3]}";
                    fwrite(STDERR,
                        "sqlc-php: @json is deprecated since v2.16.0. " .
                        "Replace '-- @json {$m[1]}{$m[2]} {$m[3]}' with '-- {$suggestion}'.\n"
                    );
                    $jsonColumns[$m[2]] = [
                        'class'    => $m[3],
                        'many'     => $cardinality === 'many',
                        'nullable' => false,
                    ];
                } elseif (preg_match('/@use\s+(.+)/i', $comment, $m)) {
                    // @use cte_name
                    // @use cte_name, other_cte, third_cte   (comma-separated list)
                    foreach (preg_split('/\s*,\s*/', trim($m[1])) as $cteName) {
                        $cteName = trim($cteName);
                        if ($cteName !== '') {
                            $usedCtes[] = $cteName;
                        }
                    }
                } elseif (preg_match('/@nullable\s+(.+)/i', $comment, $m)) {
                    // @nullable paramName
                    // @nullable param1, param2   (comma-separated list)
                    foreach (preg_split('/\s*,\s*/', trim($m[1])) as $paramName) {
                        $paramName = trim($paramName);
                        if ($paramName !== '') {
                            $nullableParams[] = $paramName;
                        }
                    }
                } elseif (preg_match('/@cursor\s+(.+)/i', $comment, $m)) {
                    // @cursor col [ASC|DESC], col [ASC|DESC], ...
                    // Accepts bare column names (created_at) and qualified names
                    // (table.col, `table`.`col`) for disambiguation in JOIN queries.
                    // The PHP key (variable name, array key, cursor token key) is
                    // always the bare column name — the last identifier after the dot.
                    foreach (preg_split('/\s*,\s*/', trim($m[1])) as $part) {
                        $part = trim($part);
                        if ($part === '') continue;
                        // Pattern: [table.]col [ASC|DESC]?
                        // table/col can be bare words or backtick-quoted
                        $colPat = '[`"]?[\w]+[`"]?(?:\.[`"]?[\w]+[`"]?)*';
                        if (preg_match('/^(' . $colPat . ')\s+(ASC|DESC)$/i', $part, $cm)) {
                            $sqlRef = $cm[1];
                            $phpKey = $this->cursorPhpKey($sqlRef);
                            $cursorColumns[] = ['col' => $sqlRef, 'key' => $phpKey, 'dir' => strtoupper($cm[2])];
                        } elseif (preg_match('/^(' . $colPat . ')$/i', $part, $cm)) {
                            $sqlRef = $cm[1];
                            $phpKey = $this->cursorPhpKey($sqlRef);
                            $cursorColumns[] = ['col' => $sqlRef, 'key' => $phpKey, 'dir' => 'ASC'];
                        }
                    }
                } elseif (preg_match('/@embed\s+(\w+)\s+(\S+)/i', $comment, $m)) {
                    // @embed ClassName prefix_  (trailing underscore optional, multiple allowed)
                    // Normalise: if user wrote "country" add one underscore → "country_"
                    //            if user wrote "country_" or "country__" → keep as-is
                    $prefix   = str_ends_with($m[2], '_') ? $m[2] : $m[2] . '_';
                    $embeds[] = new EmbedDefinition(className: $m[1], prefix: $prefix);
                } elseif (preg_match('/@embed\s+(\w+)\s*$/i', $comment, $m)) {
                    // @embed ClassName  — missing prefix → fatal error
                    throw new \RuntimeException(
                        "Query '{$name}': @embed '{$m[1]}' is missing the column prefix. " .
                        "Usage: -- @embed ClassName prefix_"
                    );
                }
            } else {
                $sqlLines[] = $line;
            }
        }

        if ($name === null || $returns === null) {
            return null;
        }

        $cleanSql  = trim(implode("\n", $sqlLines));
        $fromTable = $this->extractFromTable($cleanSql);

        if ($group === null && $fromTable !== null) {
            $group = $this->toPascalCase($this->toSingular($fromTable));
        }

        // For :transaction, the SQL is @calls content with no FROM table.
        // Derive the group from the method name itself.
        if ($group === null && $returns !== null && $returns->value === ':transaction') {
            $group = 'Query'; // will be overridden if @group is specified
        }

        if ($group === null) {
            return null;
        }

        // Validate @optional names
        if (!empty($optionalParams)) {
            preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $cleanSql, $paramMatches);
            $knownParams = array_map(
                fn(string $p) => ltrim($p, ':'),
                $paramMatches[0] ?? []
            );
            foreach ($optionalParams as $optName) {
                if (!in_array($optName, $knownParams, true)) {
                    throw new \RuntimeException(
                        "Query '{$name}': @optional '{$optName}' does not match any" .
                        " named parameter in the SQL. Known params: " .
                        (empty($knownParams) ? '(none)' : implode(', ', $knownParams))
                    );
                }
            }
        }

        // Detect UNION/UNION ALL — affects column resolution and disallows @searchable
        $isUnion = (bool) preg_match('/\bUNION\b/i', $cleanSql);

        return new QueryDefinition(
            name:             lcfirst($name),
            group:            $group,
            returns:          $returns,
            sql:              $cleanSql,
            fromTable:        $fromTable,
            params:           [],
            resultColumns:    [],
            paramAnnotations: $paramAnnotations,
            optionalParams:   $optionalParams,
            deprecated:       $deprecated,
            comment:          $commentLines,
            nillableColumns:  $nillableColumns,
            embeds:           $embeds,
            dtoClassName:     $dtoClassName,
            columnAliases:    $columnAliases,
            counted:          $counted,
            searchable:       $searchable,
            partial:          $partial,
            returning:        $returning,
            isUnion:          $isUnion,
            typeOverrides:    $typeOverrides,
            cursorColumns:    $cursorColumns,
            jsonColumns:      $jsonColumns,
            usedCtes:         array_values(array_unique($usedCtes)),
            nullableParams:   array_values(array_unique($nullableParams)),
        );
    }
    /**
     * Derive the PHP-safe key from a cursor SQL column reference.
     *
     * The PHP key is used as a variable name ($__cursor_{key}), PDO placeholder
     * (:__cursor_{key}), and cursor token array key ({key} => value).
     * It must be a valid PHP identifier — no dots, backticks, or quotes.
     *
     * Strategy: take the last word-segment after the final dot (or backtick-dot).
     * Examples:
     *   created_at                → created_at
     *   profile_reserve.created_at → created_at
     *   `reserve`.`created_at`    → created_at
     */
    private function cursorPhpKey(string $sqlRef): string
    {
        // Strip backticks and double-quotes
        $clean = str_replace(['`', '"'], '', $sqlRef);
        // Take the part after the last dot
        $parts = explode('.', $clean);
        return end($parts);
    }

    private function extractFromTable(string $sql): ?string
    {
        // For CTE queries (WITH ... AS (...) SELECT ...), skip past all CTE
        // definitions and extract the FROM table of the outer SELECT only.
        $stripped = ltrim($sql);
        if (preg_match('/^WITH\b/i', $stripped)) {
            $stripped = $this->stripCteBlock($stripped);
        }

        // SELECT … FROM table  /  DELETE FROM table
        // Use preg_match_all and take the LAST match to avoid picking up
        // table names from subqueries that appear before the main FROM.
        // For simple queries this is always the outer FROM.
        if (preg_match('/\bFROM\s+[`"]?(\w+)[`"]?(?!\s*\()/i', $stripped, $m)) {
            return $m[1];
        }
        // UPDATE table SET …
        if (preg_match('/^\s*UPDATE\s+[`"]?(\w+)[`"]?/i', $stripped, $m)) {
            return $m[1];
        }
        // INSERT INTO table
        if (preg_match('/INSERT\s+INTO\s+[`"]?(\w+)[`"]?/i', $stripped, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Strip all CTE definitions from a SQL string that starts with WITH.
     * Returns the outer query (SELECT/INSERT/UPDATE/DELETE) alone.
     *
     * WITH a AS (...), b AS (...) SELECT ... → SELECT ...
     */
    private function stripCteBlock(string $sql): string
    {
        $len = strlen($sql);
        // Skip 'WITH'
        $pos = 4;

        while ($pos < $len) {
            // Skip whitespace
            while ($pos < $len && ctype_space($sql[$pos])) $pos++;

            // Skip CTE name
            while ($pos < $len && (ctype_alnum($sql[$pos]) || $sql[$pos] === '_')) $pos++;

            // Skip whitespace then AS
            while ($pos < $len && ctype_space($sql[$pos])) $pos++;
            if (stripos($sql, 'AS', $pos) === $pos) $pos += 2;

            // Skip whitespace then ( ... )
            while ($pos < $len && ctype_space($sql[$pos])) $pos++;
            if ($pos >= $len || $sql[$pos] !== '(') break;

            $depth = 0;
            while ($pos < $len) {
                if ($sql[$pos] === '(') $depth++;
                elseif ($sql[$pos] === ')') { $depth--; if ($depth === 0) { $pos++; break; } }
                $pos++;
            }

            // Skip whitespace; if comma, another CTE follows
            while ($pos < $len && ctype_space($sql[$pos])) $pos++;
            if ($pos < $len && $sql[$pos] === ',') { $pos++; continue; }
            break;
        }

        return trim(substr($sql, $pos));
    }

    /** @deprecated Use InflectorService::singularize() — kept for backward compatibility */
    public function toSingular(string $word): string
    {
        return $this->inflector->singularize($word);
    }

    /** @deprecated Use InflectorService::toPascalCase() — kept for backward compatibility */
    public function toPascalCase(string $word): string
    {
        return $this->inflector->toPascalCase($word);
    }
}
