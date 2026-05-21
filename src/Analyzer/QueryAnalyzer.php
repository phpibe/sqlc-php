<?php

declare(strict_types=1);

namespace SqlcPhp\Analyzer;

use SqlcPhp\Parser\QueryDefinition;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ParamResolver;

/**
 * Enriches parsed QueryDefinitions with resolved parameters and result columns.
 *
 * After this step, each QueryDefinition has:
 *   - $params           → typed QueryParam[]
 *   - $resultColumns    → typed ResolvedColumn[]
 *   - $returnsModelDirectly → true when SELECT * from a single known table
 *   - $modelClass       → the DTO class name (e.g. "User")
 */
class QueryAnalyzer
{
    public function __construct(
        private readonly ParamResolver  $paramResolver,
        private readonly ColumnResolver $columnResolver,
        private readonly QueryParser    $queryParser,
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
        // --- Resolve parameters ---
        $params = $this->paramResolver->resolve($query->sql, $query->paramAnnotations);

        // --- Resolve result columns ---
        $resultColumns = [];
        $returnsModelDirectly = false;
        $modelClass = null;

        if ($query->returns !== ReturnType::Exec) {
            $resultColumns = $this->columnResolver->resolve($query->sql);

            // Detect if this is a simple "SELECT table.* FROM table" or "SELECT * FROM table"
            // → the return type is the existing Model class, no new DTO
            [$returnsModelDirectly, $modelClass] = $this->detectDirectModel($query, $resultColumns);
        }

        return new QueryDefinition(
            name:                 $query->name,
            group:                $query->group,
            returns:              $query->returns,
            sql:                  $query->sql,
            fromTable:            $query->fromTable,
            params:               $params,
            resultColumns:        $resultColumns,
            paramAnnotations:     $query->paramAnnotations,
            returnsModelDirectly: $returnsModelDirectly,
            modelClass:           $modelClass,
        );
    }

    /**
     * Returns [bool $direct, ?string $modelClass].
     * A query returns a model directly when:
     *   - All result columns come from a single table
     *   - That table is the primary FROM table
     *   - The column set matches the full table schema (i.e. SELECT * or table.*)
     *
     * @return array{0: bool, 1: ?string}
     */
    private function detectDirectModel(QueryDefinition $query, array $resultColumns): array
    {
        if (empty($resultColumns) || $query->fromTable === null) {
            return [false, null];
        }

        $tables = array_unique(array_map(fn($c) => $c->tableName, $resultColumns));

        // Multiple source tables or unknown source → custom DTO needed
        if (count($tables) > 1 || ($tables[0] ?? '') === '') {
            return [false, null];
        }

        $singleTable = $tables[0];

        // Must be the primary FROM table
        if (strtolower($singleTable) !== strtolower($query->fromTable)) {
            return [false, null];
        }

        $modelClass = $this->queryParser->toPascalCase(
            $this->queryParser->toSingular($singleTable)
        );

        return [true, $modelClass];
    }
}
