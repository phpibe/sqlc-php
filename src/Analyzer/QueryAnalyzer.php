<?php

declare(strict_types=1);

namespace SqlcPhp\Analyzer;

use SqlcPhp\Parser\QueryDefinition;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ParamResolver;
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
        private readonly ParamResolver  $paramResolver,
        private readonly ColumnResolver $columnResolver,
        private readonly QueryParser    $queryParser,
        private readonly SqlRewriter    $rewriter = new SqlRewriter(),
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
        // 1. Rewrite SQL for optional parameters (validates unsafe constructs first)
        $rewrittenSql = $this->rewriter->rewrite($query->sql, $query->optionalParams, $query->name);

        // 2. Resolve parameters against the rewritten SQL
        $rawParams = $this->paramResolver->resolve($rewrittenSql, $query->paramAnnotations);

        // Mark params that were declared @optional
        $params = array_map(
            fn(QueryParam $p) => in_array($p->name, $query->optionalParams, true)
                ? new QueryParam(
                    name:     $p->name,
                    sqlType:  $p->sqlType,
                    nullable: $p->nullable,
                    pdoParam: $p->pdoParam,
                    phpType:  $p->phpType,
                    optional: true,
                )
                : $p,
            $rawParams
        );

        // 3. Resolve result columns, applying @nillable overrides
        $resultColumns        = [];
        $returnsModelDirectly = false;
        $modelClass           = null;

        if ($query->returns !== ReturnType::Exec) {
            $rawColumns    = $this->columnResolver->resolve($rewrittenSql);
            $resultColumns = $this->applyNillable($rawColumns, $query->nillableColumns);
            [$returnsModelDirectly, $modelClass] = $this->detectDirectModel($query, $resultColumns);
        }

        return new QueryDefinition(
            name:                 $query->name,
            group:                $query->group,
            returns:              $query->returns,
            sql:                  $rewrittenSql,
            fromTable:            $query->fromTable,
            params:               $params,
            resultColumns:        $resultColumns,
            paramAnnotations:     $query->paramAnnotations,
            optionalParams:       $query->optionalParams,
            returnsModelDirectly: $returnsModelDirectly,
            modelClass:           $modelClass,
            deprecated:           $query->deprecated,
            nillableColumns:      $query->nillableColumns,
        );
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
}
