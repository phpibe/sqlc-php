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
    case One           = ':one';
    case Opt           = ':opt';
    case Exec          = ':exec';
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
        $sqlLines         = [];

        foreach (explode("\n", $block) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '--')) {
                $comment = trim(substr($trimmed, 2));

                if (preg_match('/@name\s+(\w+)/i', $comment, $m)) {
                    $name = $m[1];
                } elseif (preg_match('/@group\s+(\w+)/i', $comment, $m)) {
                    $group = $m[1];
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
                } elseif (preg_match('/@embed\s+(\w+)\s+(\w+)/i', $comment, $m)) {
                    // @embed ClassName prefix_  (trailing underscore optional)
                    $prefix   = rtrim($m[2], '_') . '_';
                    $embeds[] = new EmbedDefinition(className: $m[1], prefix: $prefix);
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
        );
    }
    private function extractFromTable(string $sql): ?string
    {
        // SELECT … FROM table  /  DELETE FROM table
        if (preg_match('/FROM\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            return $m[1];
        }
        // UPDATE table SET …
        if (preg_match('/^\s*UPDATE\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            return $m[1];
        }
        return null;
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
