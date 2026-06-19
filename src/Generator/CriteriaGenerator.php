<?php

declare(strict_types=1);

namespace SqlcPhp\Generator;

use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Criteria\Filter;
use SqlcPhp\Criteria\FilterOperator;
use SqlcPhp\Parser\QueryDefinition;
use SqlcPhp\Resolver\ResolvedColumn;

/**
 * Generates a typed {Group}Criteria class for queries annotated with @searchable.
 *
 * The generated class extends \SqlcPhp\Criteria\Criteria and adds:
 *   - ALLOWED_COLUMNS constant — whitelist for orderBy() validation
 *   - Per-column typed where methods (inferred from the query result columns)
 *   - Per-column orderBy methods
 *
 * Column→method mapping by PHP type:
 *   int / ?int        → Eq, Neq, Gt, Lt, Gte, Lte, In, NotIn, IsNull, IsNotNull
 *   float / ?float    → Eq, Neq, Gt, Lt, Gte, Lte, IsNull, IsNotNull
 *   string / ?string  → Eq, Neq, Like, StartsWith, EndsWith, In, NotIn, IsNull, IsNotNull
 *   bool              → Eq
 *   DateTimeImmutable → Eq, Neq, Gt, Lt, Gte, Lte, Between, IsNull, IsNotNull
 *   array (JSON)      → (no filter methods — JSON comparison is not supported)
 */
class CriteriaGenerator
{
    public function __construct(
        private readonly string $namespace,
    ) {}

    /**
     * Generate a Criteria class for a @searchable query.
     *
     * When $scopeName is provided (non-empty), the class is placed in a
     * subdirectory matching the query name — mirroring how scoped_dtos works:
     *   scopeName='GetByFilter' → Criterias/GetByFilter/FacturantelogCriteria.php
     *   namespace: App\Database\Criterias\GetByFilter
     *
     * When $suppressOrderBy is true, all orderBy*() typed methods are omitted and the
     * ALLOWED_COLUMNS constant is also suppressed. Use this for :cursor queries — the
     * ORDER BY is fixed by @cursor and must never change between pages, as it would
     * invalidate cursor tokens.
     *
     * @param  ResolvedColumn[] $columns
     * @param  string           $scopeName       Query @name for scoped placement ('' = flat)
     * @param  bool             $suppressOrderBy Omit orderBy methods (required for :cursor)
     * @return array{className: string, code: string, relPath: string}
     */
    public function generate(
        QueryDefinition $query,
        array           $columns,
        string          $scopeName       = '',
        bool            $suppressOrderBy = false,
    ): array {
        $className = $query->group . 'Criteria';

        // When scoped, namespace mirrors the query name subdirectory
        $namespace = $scopeName !== ''
            ? $this->namespace . '\\' . $scopeName
            : $this->namespace;

        // relPath: flat or scoped (used by CLI to route the file)
        $relPath = $scopeName !== ''
            ? $scopeName . '/' . $className . '.php'
            : $className . '.php';

        // Deduplicate columns by alias — use alias as the method name base
        $seen = [];
        $uniqueColumns = [];
        foreach ($columns as $col) {
            $alias = $col->alias;
            if (isset($seen[$alias])) continue;
            $seen[$alias] = true;
            $uniqueColumns[] = $col;
        }

        $methods         = [];
        $allowedCols     = [];
        $columnConstants = [];

        foreach ($uniqueColumns as $col) {
            $alias   = $col->alias;
            $phpType = ltrim($col->phpType, '?');
            $nullable = str_starts_with($col->phpType, '?');

            // Build COLUMN_ constants and allowed list for ORDER BY
            $constName         = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $alias));
            $columnConstants[] = "    public const COLUMN_{$constName} = '{$alias}';";
            $allowedCols[]     = "'{$alias}'";

            // Generate methods based on PHP type
            $titleAlias = $this->titleCase($alias);

            $methods = array_merge($methods, match (true) {
                $phpType === 'int'                     => $this->intMethods($titleAlias, $alias, $nullable),
                $phpType === 'float'                   => $this->floatMethods($titleAlias, $alias, $nullable),
                $phpType === 'string'                  => $this->stringMethods($titleAlias, $alias, $nullable),
                $phpType === 'bool'                    => $this->boolMethods($titleAlias, $alias),
                str_ends_with($phpType, 'DateTimeImmutable') => $this->dateMethods($titleAlias, $alias, $nullable),
                default                                => [],
            });

            // orderBy methods: omitted for :cursor queries (ORDER BY is fixed by @cursor)
            if (!$suppressOrderBy) {
                $methods[] = $this->orderByMethod($titleAlias, $alias);
            }
        }

        $allowedStr   = implode(', ', $allowedCols);
        $constantsStr = implode("\n", $columnConstants);
        $methodsStr   = implode("\n\n", $methods);

        // For cursor Criteria: suppress the allowed-columns list (no orderBy = no validation needed)
        // and add a note explaining why orderBy is absent.
        if ($suppressOrderBy) {
            $classBody = <<<PHP
    // Columns available for filtering
{$constantsStr}

    /** @var string[] */
    protected array \$allowedColumns = [{$allowedStr}];

    // orderBy() methods are intentionally omitted.
    // The ORDER BY for this query is fixed by @cursor and must not change between
    // pages — changing it would invalidate cursor tokens and return incorrect results.

{$methodsStr}
PHP;
        } else {
            $classBody = <<<PHP
    // Columns available for filtering and ordering
{$constantsStr}

    /** @var string[] */
    protected array \$allowedColumns = [{$allowedStr}];

{$methodsStr}
PHP;
        }

        // Usage example — cursor queries don't show orderBy in example
        $usageExample = $suppressOrderBy
            ? "   \$results = \$query->{$query->name}(\n" .
              "       (new {$className}())\n" .
              "           ->where{$this->titleCase($uniqueColumns[0]->alias ?? 'Id')}Eq(...),\n" .
              "   );"
            : "   \$results = \$query->{$query->name}(\n" .
              "       (new {$className}())\n" .
              "           ->where{$this->titleCase($uniqueColumns[0]->alias ?? 'Id')}Eq(...)\n" .
              "           ->orderBy{$this->titleCase($uniqueColumns[0]->alias ?? 'Id')}('ASC'),\n" .
              "   );";

        $cursorNote = $suppressOrderBy
            ? "\n * Note: orderBy() is not available — ORDER BY is fixed by \@cursor."
            : '';

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Criteria\Filter;
use SqlcPhp\Criteria\FilterOperator;

/**
 * Typed criteria for {@see {$query->group}Query::{$query->name}()}.
 * Generated by sqlc-php — do not edit manually.{$cursorNote}
 *
 * Usage:
{$usageExample}
 */
class {$className} extends Criteria
{
{$classBody}
}
PHP;

        return ['className' => $className, 'code' => $code, 'relPath' => $relPath];
    }

    // -------------------------------------------------------------------------
    // Method generators by type
    // -------------------------------------------------------------------------

    /** @return string[] */
    private function intMethods(string $title, string $col, bool $nullable): array
    {
        $m = [];
        foreach (['Eq' => '=', 'Neq' => '!=', 'Gt' => '>', 'Lt' => '<', 'Gte' => '>=', 'Lte' => '<='] as $suffix => $op) {
            $m[] = $this->singleMethod("where{$title}{$suffix}", 'int', $col, FilterOperator::from($op));
        }
        $m[] = $this->inMethod("where{$title}In",    'int', $col, FilterOperator::IN);
        $m[] = $this->inMethod("where{$title}NotIn", 'int', $col, FilterOperator::NOT_IN);
        if ($nullable) {
            $m[] = $this->noValueMethod("where{$title}IsNull",    $col, FilterOperator::IS_NULL);
            $m[] = $this->noValueMethod("where{$title}IsNotNull", $col, FilterOperator::IS_NOT_NULL);
        }
        return $m;
    }

    /** @return string[] */
    private function floatMethods(string $title, string $col, bool $nullable): array
    {
        $m = [];
        foreach (['Eq' => '=', 'Neq' => '!=', 'Gt' => '>', 'Lt' => '<', 'Gte' => '>=', 'Lte' => '<='] as $suffix => $op) {
            $m[] = $this->singleMethod("where{$title}{$suffix}", 'float', $col, FilterOperator::from($op));
        }
        if ($nullable) {
            $m[] = $this->noValueMethod("where{$title}IsNull",    $col, FilterOperator::IS_NULL);
            $m[] = $this->noValueMethod("where{$title}IsNotNull", $col, FilterOperator::IS_NOT_NULL);
        }
        return $m;
    }

    /** @return string[] */
    private function stringMethods(string $title, string $col, bool $nullable): array
    {
        $m = [];
        foreach (['Eq' => '=', 'Neq' => '!='] as $suffix => $op) {
            $m[] = $this->singleMethod("where{$title}{$suffix}", 'string', $col, FilterOperator::from($op));
        }
        $m[] = $this->likeMethod("where{$title}Like",       $col, FilterOperator::LIKE);
        $m[] = $this->likeMethod("where{$title}StartsWith", $col, FilterOperator::STARTS);
        $m[] = $this->likeMethod("where{$title}EndsWith",   $col, FilterOperator::ENDS);
        $m[] = $this->inMethod("where{$title}In",    'string', $col, FilterOperator::IN);
        $m[] = $this->inMethod("where{$title}NotIn", 'string', $col, FilterOperator::NOT_IN);
        if ($nullable) {
            $m[] = $this->noValueMethod("where{$title}IsNull",    $col, FilterOperator::IS_NULL);
            $m[] = $this->noValueMethod("where{$title}IsNotNull", $col, FilterOperator::IS_NOT_NULL);
        }
        return $m;
    }

    /** @return string[] */
    private function boolMethods(string $title, string $col): array
    {
        return [$this->singleMethod("where{$title}Eq", 'bool', $col, FilterOperator::EQ)];
    }

    /** @return string[] */
    private function dateMethods(string $title, string $col, bool $nullable): array
    {
        $m = [];
        foreach (['Eq' => '=', 'Neq' => '!=', 'Gt' => '>', 'Lt' => '<', 'Gte' => '>=', 'Lte' => '<='] as $suffix => $op) {
            $m[] = $this->singleMethod("where{$title}{$suffix}", '\\DateTimeImmutable', $col, FilterOperator::from($op), date: true);
        }
        $m[] = $this->betweenMethod("where{$title}Between", $col);
        if ($nullable) {
            $m[] = $this->noValueMethod("where{$title}IsNull",    $col, FilterOperator::IS_NULL);
            $m[] = $this->noValueMethod("where{$title}IsNotNull", $col, FilterOperator::IS_NOT_NULL);
        }
        return $m;
    }

    // -------------------------------------------------------------------------
    // Method templates
    // -------------------------------------------------------------------------

    private function singleMethod(
        string $name, string $phpType, string $col,
        FilterOperator $op, bool $date = false
    ): string {
        $filterFactory = match ($op) {
            FilterOperator::EQ  => "Filter::eq('{$col}', \$value)",
            FilterOperator::NEQ => "Filter::neq('{$col}', \$value)",
            FilterOperator::GT  => "Filter::gt('{$col}', \$value)",
            FilterOperator::LT  => "Filter::lt('{$col}', \$value)",
            FilterOperator::GTE => "Filter::gte('{$col}', \$value)",
            FilterOperator::LTE => "Filter::lte('{$col}', \$value)",
            default             => "new Filter('{$col}', FilterOperator::{$op->name}, \$value)",
        };
        $typeHint = $date ? '\\DateTimeImmutable' : $phpType;
        return <<<PHP
    public function {$name}({$typeHint} \$value): static
    {
        return \$this->add({$filterFactory});
    }
PHP;
    }

    private function likeMethod(string $name, string $col, FilterOperator $op): string
    {
        $factory = match ($op) {
            FilterOperator::LIKE   => "Filter::like('{$col}', \$value)",
            FilterOperator::STARTS => "Filter::starts('{$col}', \$value)",
            FilterOperator::ENDS   => "Filter::ends('{$col}', \$value)",
            default                => "new Filter('{$col}', FilterOperator::{$op->name}, \$value)",
        };
        return <<<PHP
    public function {$name}(string \$value): static
    {
        return \$this->add({$factory});
    }
PHP;
    }

    private function inMethod(string $name, string $phpType, string $col, FilterOperator $op): string
    {
        $factory = $op === FilterOperator::IN
            ? "Filter::in('{$col}', \$values)"
            : "Filter::notIn('{$col}', \$values)";
        return <<<PHP
    /** @param {$phpType}[] \$values */
    public function {$name}({$phpType} ...\$values): static
    {
        return \$this->add({$factory});
    }
PHP;
    }

    private function noValueMethod(string $name, string $col, FilterOperator $op): string
    {
        $factory = $op === FilterOperator::IS_NULL
            ? "Filter::isNull('{$col}')"
            : "Filter::isNotNull('{$col}')";
        return <<<PHP
    public function {$name}(): static
    {
        return \$this->add({$factory});
    }
PHP;
    }

    private function betweenMethod(string $name, string $col): string
    {
        return <<<PHP
    public function {$name}(\\DateTimeImmutable \$from, \\DateTimeImmutable \$to): static
    {
        return \$this->add(Filter::between('{$col}', \$from, \$to));
    }
PHP;
    }

    private function orderByMethod(string $title, string $col): string
    {
        return <<<PHP
    public function orderBy{$title}(string \$direction = 'ASC'): static
    {
        return \$this->orderBy('{$col}', \$direction);
    }
PHP;
    }

    // -------------------------------------------------------------------------

    private function titleCase(string $snake): string
    {
        return str_replace('_', '', ucwords($snake, '_'));
    }
}
