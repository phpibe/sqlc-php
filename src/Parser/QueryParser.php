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
         * Format: alias → phpType  e.g. ['role' => 'string', 'total' => '?float']
         *
         * Applied after column resolution — overrides whatever type the resolver
         * inferred from the schema or expression. Useful for UNION queries where
         * the second branch has a different type, or for expressions that the
         * resolver cannot determine (constants, complex expressions, etc.).
         *
         * Usage:
         *   -- @type role string
         *   -- @type total ?float
         *   -- @type active bool
         *
         * @var array<string, string>  alias → phpType
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
         * JSON column → DTO class name mappings, declared via @json.
         *
         * Each entry declares that a result column containing a JSON array
         * (typically from JSON_ARRAYAGG) should be deserialized into an array
         * of the specified DTO class instead of a plain PHP array.
         *
         * Usage:
         *   -- @json cities City
         *   -- @json products ProductItem
         *
         * The referenced DTO class is always generated by sqlc-php.
         * Its properties are inferred from the schema table whose singular
         * name matches the DTO class name (e.g. City → cities table).
         * When no matching table is found, generation fails with a clear error.
         *
         * The generated fromRow cast becomes:
         *   array_map(fn(array $r) => City::fromRow($r), json_decode(...) ?? [])
         *
         * @var array<string, string>  alias → DTO class name
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

        // Split on blank lines between query blocks
        $blocks = preg_split('/(?=^\s*--\s*@name\b)/mi', $sql);

        foreach ($blocks as $block) {
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
        $embeds           = [];
        $dtoClassName     = null;
        $counted          = false;
        $searchable       = false;
        $partial          = false;
        $returning        = false;        $columnAliases    = [];   // @column originalName alias
        $typeOverrides    = [];           // @type alias phpType
        $cursorColumns    = [];           // @cursor col1 DIR, col2 DIR
        $jsonColumns      = [];           // @json alias ClassName
        $sqlLines         = [];

        foreach (explode("\n", $block) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '--')) {
                $comment = trim(substr($trimmed, 2));

                if (preg_match('/@name\s+(\w+)/i', $comment, $m)) {
                    $name = $m[1];
                } elseif (preg_match('/@class\s+(\w+)/i', $comment, $m)) {
                    // @class is the canonical annotation — @group is deprecated
                    $group ??= $m[1];
                } elseif (preg_match('/@group\s+(\w+)/i', $comment, $m)) {
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
                } elseif (preg_match('/@nillable\s+(\w+)/i', $comment, $m)) {
                    $nillableColumns[] = $m[1];
                } elseif (preg_match('/@deprecated(?:\s+(.+))?$/i', $comment, $m)) {
                    $deprecated = isset($m[1]) ? trim($m[1]) : '';
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
                } elseif (preg_match('/@type\s+(\w+)\s+(\S+)/i', $comment, $m)) {
                    $typeOverrides[$m[1]] = $m[2];
                } elseif (preg_match('/@json\s+(\w+)\s+(\w+)/i', $comment, $m)) {
                    // @json alias ClassName — deserialize JSON array column into typed DTO array
                    $jsonColumns[$m[1]] = $m[2];
                } elseif (preg_match('/@cursor\s+(.+)/i', $comment, $m)) {
                    // @cursor col1 [ASC|DESC], col2 [ASC|DESC], ...
                    foreach (preg_split('/\s*,\s*/', trim($m[1])) as $part) {
                        $part = trim($part);
                        if ($part === '') continue;
                        if (preg_match('/^(\w+)\s+(ASC|DESC)$/i', $part, $cm)) {
                            $cursorColumns[] = ['col' => $cm[1], 'dir' => strtoupper($cm[2])];
                        } elseif (preg_match('/^(\w+)$/i', $part, $cm)) {
                            // Default direction: ASC
                            $cursorColumns[] = ['col' => $cm[1], 'dir' => 'ASC'];
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
        );
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
