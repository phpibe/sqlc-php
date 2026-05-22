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
    case Many = ':many';
    case One  = ':one';
    case Opt  = ':opt';
    case Exec = ':exec';
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
    ) {}
}

/**
 * Parses SQL files containing annotation-decorated queries.
 *
 * Supported annotations:
 *   -- @name     ListUsers
 *   -- @group    User
 *   -- @returns  :many | :one | :opt | :exec
 *   -- @param    userId users.id        (explicit type override for a parameter)
 *   -- @optional status                 (passing null skips the filter condition)
 */
class QueryParser
{
    /**
     * @return QueryDefinition[]
     */
    public function parse(string $sql): array
    {
        $queries = [];

        // Split on blank lines between query blocks
        $blocks = preg_split('/\n\s*\n/', $sql);

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
        $sqlLines         = [];

        foreach (explode("\n", $block) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '--')) {
                $comment = trim(substr($trimmed, 2));

                if (preg_match('/@name\s+(\w+)/i', $comment, $m)) {
                    $name = $m[1];
                } elseif (preg_match('/@group\s+(\w+)/i', $comment, $m)) {
                    $group = $m[1];
                } elseif (preg_match('/@returns\s+(:\w+)/i', $comment, $m)) {
                    $returns = ReturnType::from($m[1]);
                } elseif (preg_match('/@param\s+(\w+)\s+([\w.]+)/i', $comment, $m)) {
                    $paramAnnotations[$m[1]] = $m[2];
                } elseif (preg_match('/@optional\s+(\w+)/i', $comment, $m)) {
                    $optionalParams[] = $m[1];
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

        // Validate @optional names against :params present in the SQL
        // This catches typos at parse time rather than at runtime.
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

    public function toSingular(string $word): string
    {
        $word = strtolower($word);

        $irregulars = ['people' => 'person', 'children' => 'child', 'statuses' => 'status'];
        if (isset($irregulars[$word])) return $irregulars[$word];

        if (str_ends_with($word, 'ies'))  return substr($word, 0, -3) . 'y';
        if (str_ends_with($word, 'ses'))  return substr($word, 0, -2);
        if (str_ends_with($word, 'xes'))  return substr($word, 0, -2);
        if (str_ends_with($word, 'ches')) return substr($word, 0, -2);
        if (str_ends_with($word, 'shes')) return substr($word, 0, -2);
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) return substr($word, 0, -1);

        return $word;
    }

    public function toPascalCase(string $word): string
    {
        return ucfirst(strtolower($word));
    }
}
