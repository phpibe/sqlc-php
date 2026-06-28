<?php

declare(strict_types=1);

namespace SqlcPhp\Generator;

use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Criteria\Filter;
use SqlcPhp\Criteria\FilterOperator;
use SqlcPhp\Parser\QueryDefinition;
use SqlcPhp\Resolver\ResolvedColumn;
use SqlcPhp\TypeMapper\TypeMapperInterface;

/**
 * Generates a typed {Group}Criteria class for queries annotated with @searchable.
 *
 * The generated class extends \SqlcPhp\Criteria\Criteria and adds:
 *   - ALLOWED_COLUMNS constant — whitelist for orderBy() validation
 *   - Per-column typed where methods (inferred from the query result columns)
 *   - Per-column orderBy methods
 *   - Per-column enum where methods for ENUM columns (Eq, Neq, In, NotIn + nullable IsNull/IsNotNull)
 *
 * Column→method mapping by PHP type:
 *   int / ?int        → Eq, Neq, Gt, Lt, Gte, Lte, In, NotIn, IsNull, IsNotNull
 *   float / ?float    → Eq, Neq, Gt, Lt, Gte, Lte, IsNull, IsNotNull
 *   string / ?string  → Eq, Neq, Like, StartsWith, EndsWith, In, NotIn, IsNull, IsNotNull
 *   bool              → Eq
 *   DateTimeImmutable → Eq, Neq, Gt, Lt, Gte, Lte, Between, IsNull, IsNotNull
 *   BackedEnum        → Eq, Neq, In, NotIn, IsNull, IsNotNull (using typed enum class)
 *   array (JSON)      → (no filter methods — JSON comparison is not supported)
 */
class CriteriaGenerator
{
    public function __construct(
        private readonly string                $namespace,
        private readonly ?TypeMapperInterface  $typeMapper = null,
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
        $enumUses        = []; // short name → FQCN, deduplicated

        foreach ($uniqueColumns as $col) {
            $alias   = $col->alias;
            $phpType = ltrim($col->phpType, '?');
            $nullable = str_starts_with($col->phpType, '?');

            // Resolve the SQL column reference used inside Filter (WHERE clause).
            //
            // When the column comes from a real table (tableName is set), always qualify
            // it as `table.column` to avoid MySQL "Column is ambiguous" errors on JOINs
            // where multiple tables share the same column name (e.g. reserve_id).
            //
            // Alias-only columns (expressions, aggregates, computed columns) have no
            // tableName — those cannot be qualified and must use the alias as-is.
            // Note: aliases from SELECT are NOT valid in WHERE in standard SQL, but for
            // expression columns (SUM, COUNT, JSON_ARRAYAGG…) there is no alternative
            // and the user is responsible for query correctness.
            $filterColumn = ($col->tableName !== '' && $col->columnName !== '')
                ? "{$col->tableName}.{$col->columnName}"
                : $alias;

            // Build COLUMN_ constants and allowed list for ORDER BY
            $constName         = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', $alias));
            $columnConstants[] = "    public const COLUMN_{$constName} = '{$alias}';";
            $allowedCols[]     = "'{$alias}'";

            // Generate methods based on PHP type
            $titleAlias = $this->titleCase($alias);

            // Enum columns: generate typed enum methods using the backed enum class
            if ($this->isEnumColumn($col)) {
                $enumFqcn = $this->typeMapper->toPhpFqcn($col->sqlType, $col->tableName, $col->columnName);
                if ($enumFqcn !== null) {
                    $short = ltrim(substr($enumFqcn, strrpos($enumFqcn, '\\') + 1), '\\');
                    // Deduplicate: track by short name, verify no FQCN collision
                    if (!isset($enumUses[$short])) {
                        $enumUses[$short] = $enumFqcn;
                    }
                    $methods = array_merge($methods, $this->enumMethods($titleAlias, $filterColumn, $short, $nullable));
                } else {
                    // Fallback to string methods if FQCN not resolvable
                    $methods = array_merge($methods, $this->stringMethods($titleAlias, $filterColumn, $nullable));
                }
            } else {
                $methods = array_merge($methods, match (true) {
                    $phpType === 'int'                     => $this->intMethods($titleAlias, $filterColumn, $nullable),
                    $phpType === 'float'                   => $this->floatMethods($titleAlias, $filterColumn, $nullable),
                    $phpType === 'string'                  => $this->stringMethods($titleAlias, $filterColumn, $nullable),
                    $phpType === 'bool'                    => $this->boolMethods($titleAlias, $filterColumn),
                    str_ends_with($phpType, 'DateTimeImmutable') => $this->dateMethods($titleAlias, $filterColumn, $nullable),
                    default                                => [],
                });
            }

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

        // Build deduped use block for enum classes.
        // Skip any FQCN whose namespace matches the criteria namespace (same-namespace imports are redundant).
        $useBlock = '';
        foreach ($enumUses as $short => $fqcn) {
            $fqcnNs = implode('\\', array_slice(explode('\\', $fqcn), 0, -1));
            if ($fqcnNs !== $namespace) {
                $useBlock .= "use {$fqcn};\n";
            }
        }
        if ($useBlock !== '') {
            $useBlock = "\n" . $useBlock;
        }

        $code = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Criteria\Filter;
use SqlcPhp\Criteria\FilterOperator;{$useBlock}

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
    // Enum column detection and method generation
    // -------------------------------------------------------------------------

    /**
     * Returns true when the column's SQL type is ENUM (with or without values list).
     * Requires a typeMapper to be present — without it, enum columns fall back to string.
     */
    private function isEnumColumn(ResolvedColumn $col): bool
    {
        if ($this->typeMapper === null) return false;
        $normalized = strtoupper(trim(preg_replace('/\(.*\)/s', '', $col->sqlType) ?? $col->sqlType));
        return $normalized === 'ENUM';
    }

    /**
     * Generate typed where methods for a backed-enum column.
     * The short class name (e.g. FacturantelogSupplier) must already be imported via `use`.
     *
     * Generated methods:
     *   whereXxxEq(EnumClass $value): static
     *   whereXxxNeq(EnumClass $value): static
     *   whereXxxIn(EnumClass ...$values): static
     *   whereXxxNotIn(EnumClass ...$values): static
     *   whereXxxIsNull(): static           (only when nullable)
     *   whereXxxIsNotNull(): static        (only when nullable)
     *
     * The enum ->value is extracted before passing to Filter — PDO cannot bind enum objects.
     *
     * @return string[]
     */
    private function enumMethods(string $title, string $col, string $enumClass, bool $nullable): array
    {
        $m = [];

        $m[] = <<<PHP
    public function where{$title}Eq({$enumClass} \$value): static
    {
        return \$this->add(Filter::eq('{$col}', \$value->value));
    }
PHP;

        $m[] = <<<PHP
    public function where{$title}Neq({$enumClass} \$value): static
    {
        return \$this->add(Filter::neq('{$col}', \$value->value));
    }
PHP;

        $m[] = <<<PHP
    /** @param {$enumClass}[] \$values */
    public function where{$title}In({$enumClass} ...\$values): static
    {
        return \$this->add(Filter::in('{$col}', array_map(fn(\$v) => \$v->value, \$values)));
    }
PHP;

        $m[] = <<<PHP
    /** @param {$enumClass}[] \$values */
    public function where{$title}NotIn({$enumClass} ...\$values): static
    {
        return \$this->add(Filter::notIn('{$col}', array_map(fn(\$v) => \$v->value, \$values)));
    }
PHP;

        if ($nullable) {
            $m[] = <<<PHP
    public function where{$title}IsNull(): static
    {
        return \$this->add(Filter::isNull('{$col}'));
    }
PHP;

            $m[] = <<<PHP
    public function where{$title}IsNotNull(): static
    {
        return \$this->add(Filter::isNotNull('{$col}'));
    }
PHP;
        }

        return $m;
    }

    // -------------------------------------------------------------------------

    private function titleCase(string $snake): string
    {
        return str_replace('_', '', ucwords($snake, '_'));
    }
}
