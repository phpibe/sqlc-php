<?php

declare(strict_types=1);

namespace SqlcPhp\Criteria;

/**
 * Fluent, immutable criteria builder for dynamic WHERE conditions.
 *
 * Generated {Group}Criteria classes extend this class and add typed
 * per-column methods (whereActiveEq, whereEmailLike, etc.) plus an
 * ALLOWED_COLUMNS whitelist to prevent SQL injection.
 *
 * This base class provides the SQL-building and PDO-binding machinery.
 * It can also be used directly with the add() method for ad-hoc filters.
 *
 * Usage (via generated subclass):
 *   $criteria = (new UserCriteria())
 *       ->whereActiveIn(1, 2)
 *       ->whereEmailLike('cristian')
 *       ->orderByCreatedAt('DESC');
 *
 * OR group usage:
 *   $criteria = (new UserCriteria())
 *       ->whereActiveEq(1)
 *       ->orGroup(fn(UserCriteria $c) => $c->whereCountryIdEq(164))
 *       ->orGroup(fn(UserCriteria $c) => $c->whereCountryIdEq(165));
 *   // WHERE (active = :active_f0) OR (country_id = :country_id_f1) OR (country_id = :country_id_f2)
 */
class Criteria
{
    /** @var Filter[] Top-level filters — combined with AND */
    protected array $filters = [];

    /**
     * OR groups — each group is an array of filters combined with AND internally.
     * The main filters block and each OR group are joined with OR.
     *
     * @var Filter[][]
     */
    protected array $orGroups = [];

    /**
     * Raw SQL conditions added via andRawCondition() / orRawCondition().
     * Each entry: {sql: string, bindings: array, connector: 'AND'|'OR'}
     *
     * @var array<int, array{sql: string, bindings: array<string, array{0:mixed,1:int}>, connector: string}>
     */
    protected array $rawConditions = [];

    protected ?string $orderByColumn = null;
    protected string  $orderByDir    = 'ASC';

    /**
     * Columns that may be used in orderBy().
     * Subclasses override this with the actual schema columns.
     * An empty array means all order-by columns are refused.
     *
     * @var string[]
     */
    protected array $allowedColumns = [];

    // -------------------------------------------------------------------------
    // Filter accumulation
    // -------------------------------------------------------------------------

    /**
     * Add a filter condition. Returns a new instance (immutable pattern).
     */
    public function add(Filter $filter): static
    {
        $clone          = clone $this;
        $clone->filters = [...$this->filters, $filter];
        return $clone;
    }

    /**
     * Add a raw SQL condition joined with AND to the WHERE clause.
     *
     * Injects the condition verbatim, AND-ed with any typed filters.
     * Use for conditions that cannot be expressed through generated typed
     * methods — e.g. filtering on JOIN columns not in the SELECT list.
     *
     * \u26a0\ufe0f  No SQL injection protection. Never interpolate user input directly —
     *     use named placeholders and pass values through $bindings.
     *
     * @param  string                                  $sql      Raw SQL condition (verbatim)
     * @param  array<string, array{0: mixed, 1: int}>  $bindings Named placeholder → [value, PDO::PARAM_*]
     * @return static
     */
    public function andRawCondition(string $sql, array $bindings = []): static
    {
        $clone                = clone $this;
        $clone->rawConditions = [
            ...$this->rawConditions,
            ['sql' => $sql, 'bindings' => $bindings, 'connector' => 'AND'],
        ];
        return $clone;
    }

    /**
     * Add a raw SQL condition joined with OR to the WHERE clause.
     *
     * Injects the condition verbatim, OR-ed against the rest of the WHERE
     * clause. The full expression is parenthesized to preserve operator
     * precedence:
     *   WHERE (typed_filters AND ...) OR (raw_condition)
     *
     * \u26a0\ufe0f  No SQL injection protection. Never interpolate user input directly —
     *     use named placeholders and pass values through $bindings.
     *
     * @param  string                                  $sql      Raw SQL condition (verbatim)
     * @param  array<string, array{0: mixed, 1: int}>  $bindings Named placeholder → [value, PDO::PARAM_*]
     * @return static
     */
    public function orRawCondition(string $sql, array $bindings = []): static
    {
        $clone                = clone $this;
        $clone->rawConditions = [
            ...$this->rawConditions,
            ['sql' => $sql, 'bindings' => $bindings, 'connector' => 'OR'],
        ];
        return $clone;
    }
    /**
     * Add an OR group via a closure that receives a fresh instance of the
     * same Criteria subclass. Filters inside the closure are combined with AND;
     * the group itself is OR-ed with all other filters and groups.
     *
     * Example:
     *   ->whereActiveEq(1)                              // AND active = 1
     *   ->orGroup(fn($c) => $c->whereCountryIdEq(164)) // OR (country_id = 164)
     *   ->orGroup(fn($c) => $c->whereCountryIdEq(165)) // OR (country_id = 165)
     *   // SQL: WHERE (active = :active_f0) OR (country_id = :country_id_f1)
     *   //          OR (country_id = :country_id_f2)
     *
     * @param callable(static): static $callback
     */
    public function orGroup(callable $callback): static
    {
        // Start from a fresh instance (same concrete type) with no filters
        $fresh  = new static();
        $filled = $callback($fresh);

        if (!($filled instanceof static) || empty($filled->filters)) {
            // Empty group or wrong return type — ignore silently
            return $this;
        }

        $clone           = clone $this;
        $clone->orGroups = [...$this->orGroups, $filled->filters];
        return $clone;
    }

    /**
     * Set ORDER BY. Returns a new instance.
     *
     * @throws \InvalidArgumentException if column is not in the allowed list
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        if (!empty($this->allowedColumns) && !in_array($column, $this->allowedColumns, true)) {
            throw new \InvalidArgumentException(
                "Column '{$column}' is not allowed for ordering. " .
                "Allowed: " . implode(', ', $this->allowedColumns)
            );
        }

        $clone              = clone $this;
        $clone->orderByColumn = $column;
        $clone->orderByDir    = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $clone;
    }

    public function isEmpty(): bool
    {
        return empty($this->filters) && empty($this->orGroups) && empty($this->rawConditions) && $this->orderByColumn === null;
    }

    public function hasFilters(): bool
    {
        return !empty($this->filters) || !empty($this->orGroups) || !empty($this->rawConditions);
    }

    public function hasOrderBy(): bool
    {
        return $this->orderByColumn !== null;
    }

    // -------------------------------------------------------------------------
    // SQL generation
    // -------------------------------------------------------------------------

    /**
     * Build the WHERE or AND clause for this criteria.
     *
     * When orGroups are present the structure becomes:
     *   WHERE (and_cond1 AND and_cond2) OR (group1_c1 AND group1_c2) OR (group2_c1)
     *
     * With no orGroups and top-level filters only, the parentheses are omitted
     * for backward compatibility:
     *   WHERE and_cond1 AND and_cond2
     *
     * @param bool $appendMode  When true, emits " AND ..." or " OR (...) OR ..."
     *                          instead of " WHERE ...".
     */
    public function toFilterClause(bool $appendMode = false): string
    {
        $hasTopLevel = !empty($this->filters);
        $hasOrGroups = !empty($this->orGroups);
        $hasRaw      = !empty($this->rawConditions);

        if (!$hasTopLevel && !$hasOrGroups && !$hasRaw) return '';

        // Separate raw conditions by connector
        $andRaw = array_values(array_filter(
            $this->rawConditions,
            fn($r) => ($r['connector'] ?? 'AND') === 'AND'
        ));
        $orRaw = array_values(array_filter(
            $this->rawConditions,
            fn($r) => ($r['connector'] ?? 'AND') === 'OR'
        ));

        // Shared counter for unique placeholder suffixes across all filters/groups
        $idx = 0;

        if (!$hasOrGroups && empty($orRaw)) {
            // Fast path: no OR groups, no OR raw conditions → plain AND chain (no parens)
            $parts = [];
            foreach ($this->filters as $filter) {
                $parts[] = $this->renderCondition($filter, $idx++);
            }
            foreach ($andRaw as $raw) {
                $parts[] = $raw['sql'];
            }
            $keyword = $appendMode ? ' AND ' : ' WHERE ';
            return $keyword . implode(' AND ', $parts);
        }

        // General path: build segments to be joined with OR.
        // AND-typed filters + andRaw form one block; orGroups and orRaw are OR segments.
        $segments = [];

        // 1. Typed AND filters
        if ($hasTopLevel) {
            $parts = [];
            foreach ($this->filters as $filter) {
                $parts[] = $this->renderCondition($filter, $idx++);
            }
            // Inline AND raw conditions into the same AND block
            foreach ($andRaw as $raw) {
                $parts[] = $raw['sql'];
            }
            $segments[] = count($parts) === 1
                ? $parts[0]
                : '(' . implode(' AND ', $parts) . ')';
        } elseif (!empty($andRaw)) {
            // No typed filters but there are AND raw conditions
            foreach ($andRaw as $raw) {
                $segments[] = $raw['sql'];
            }
        }

        // 2. orGroup() closures — each is its own OR segment
        foreach ($this->orGroups as $group) {
            $parts = [];
            foreach ($group as $filter) {
                $parts[] = $this->renderCondition($filter, $idx++);
            }
            if (!empty($parts)) {
                $segments[] = count($parts) === 1
                    ? $parts[0]
                    : '(' . implode(' AND ', $parts) . ')';
            }
        }

        // 3. orRawCondition() — each is its own parenthesized OR segment
        foreach ($orRaw as $raw) {
            $segments[] = '(' . $raw['sql'] . ')';
        }

        if (empty($segments)) return '';

        $keyword = $appendMode ? ' AND ' : ' WHERE ';
        return $keyword . implode(' OR ', $segments);
    }

    /**
     * Alias for toFilterClause(appendMode: false).
     */
    public function toWhereClause(): string
    {
        return $this->toFilterClause(false);
    }

    /**
     * Build the ORDER BY clause. Returns '' when no order is set.
     * Does NOT strip an existing ORDER BY from the base SQL — the caller must do that.
     */
    public function toOrderClause(): string
    {
        if ($this->orderByColumn === null) return '';
        return " ORDER BY {$this->orderByColumn} {$this->orderByDir}";
    }

    /**
     * Return all filter bindings as an associative array.
     * Matches the placeholder names generated by toFilterClause().
     * Includes bindings from both top-level filters and all OR groups.
     *
     * @return array<string, array{0: mixed, 1: int}>  placeholder → [value, PDO type]
     */
    public function getBindings(): array
    {
        $result = [];
        $idx    = 0;

        // Top-level filters
        foreach ($this->filters as $filter) {
            $this->collectFilterBindings($filter, $idx++, $result);
        }

        // OR groups
        foreach ($this->orGroups as $group) {
            foreach ($group as $filter) {
                $this->collectFilterBindings($filter, $idx++, $result);
            }
        }

        // Raw conditions (AND and OR) — merge their explicit bindings
        foreach ($this->rawConditions as $raw) {
            foreach ($raw['bindings'] as $placeholder => $binding) {
                $result[$placeholder] = $binding;
            }
        }

        return $result;
    }

    /**
     * Bind all filter values to a prepared statement.
     * Matches the placeholder names generated by toFilterClause().
     */
    public function bindAll(\PDOStatement $stmt): void
    {
        foreach ($this->getBindings() as $key => [$value, $type]) {
            $stmt->bindValue($key, $value, $type);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Collect bindings for a single filter into $result.
     */
    private function collectFilterBindings(Filter $filter, int $idx, array &$result): void
    {
        if ($filter->operator->isNoValue()) return;

        $base = $this->paramBase($filter->column, $idx);

        if ($filter->operator->isMultiValue()) {
            $values = is_array($filter->value) ? $filter->value : [$filter->value];
            foreach ($values as $i => $v) {
                $result[":{$base}_{$i}"] = [$v, $this->pdoType($v)];
            }
        } elseif ($filter->operator->isTwoValue()) {
            $result[":{$base}_from"] = [$filter->value,   $this->pdoType($filter->value)];
            $result[":{$base}_to"]   = [$filter->valueTo, $this->pdoType($filter->valueTo)];
        } elseif ($filter->operator === FilterOperator::LIKE
               || $filter->operator === FilterOperator::STARTS
               || $filter->operator === FilterOperator::ENDS) {
            $result[":{$base}"] = [$filter->value, \PDO::PARAM_STR];
        } else {
            $result[":{$base}"] = [$filter->value, $this->pdoType($filter->value)];
        }
    }

    private function renderCondition(Filter $filter, int $idx): string
    {
        $col  = $filter->column;
        $op   = $filter->operator;
        $base = $this->paramBase($col, $idx);

        return match (true) {
            $op->isNoValue() => "{$col} {$op->value}",

            $op->isMultiValue() => (function () use ($filter, $op, $col, $base): string {
                $values = is_array($filter->value) ? $filter->value : [$filter->value];
                if (empty($values)) {
                    return $op === FilterOperator::IN ? '(1 = 0)' : '(1 = 1)';
                }
                $placeholders = implode(', ', array_map(fn($i) => ":{$base}_{$i}", array_keys($values)));
                return "{$col} {$op->value} ({$placeholders})";
            })(),

            $op->isTwoValue() =>
                "{$col} BETWEEN :{$base}_from AND :{$base}_to",

            $op === FilterOperator::LIKE,
            $op === FilterOperator::STARTS,
            $op === FilterOperator::ENDS =>
                "{$col} LIKE :{$base}",

            default => "{$col} {$op->value} :{$base}",
        };
    }

    /**
     * Generate a unique, safe placeholder name for a filter.
     * Format: {sanitized_column}_f{idx}
     */
    private function paramBase(string $column, int $idx): string
    {
        $col = preg_replace('/[^a-zA-Z0-9_]/', '_', $column);
        $col = ltrim($col, '_');
        return "{$col}_f{$idx}";
    }

    private function pdoType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => \PDO::PARAM_INT,
            is_bool($value) => \PDO::PARAM_BOOL,
            is_null($value) => \PDO::PARAM_NULL,
            default         => \PDO::PARAM_STR,
        };
    }

    // -------------------------------------------------------------------------
    // Bulk construction
    // -------------------------------------------------------------------------

    /**
     * Build a Criteria instance from a positional filter array.
     *
     * Each element must be a tuple in one of these forms:
     *
     *   [column, operator]              — for IS NULL / IS NOT NULL (no value needed)
     *   [column, operator, value]       — for all other operators
     *   [column, 'in',     [...]]       — IN: value must be an array
     *   [column, 'not_in', [...]]       — NOT IN: value must be an array
     *   [column, 'between', [from, to]] — BETWEEN: value must be [from, to]
     *
     * Supported operators (case-insensitive):
     *   Equality:   '='  '!='  '<>'
     *   Aliases:    'eq' 'neq' 'gt' 'lt' 'gte' 'lte'
     *   Comparison: '>'  '<'  '>='  '<='
     *   Pattern:    'like'  'starts_with'  'ends_with'
     *   Set:        'in'  'not_in'
     *   Null:       'is_null'  'null'  'is_not_null'  'not_null'
     *   Range:      'between'
     *
     * Column names are validated against the subclass's $allowedColumns list
     * (the same list used by orderBy()). An empty list means all columns pass.
     *
     * Example:
     *   OrderCriteria::fromArray([
     *       ['user_id',    '=',         42],
     *       ['status',     '!=',        'cancelled'],
     *       ['total',      '>=',        100.0],
     *       ['name',       'like',      'john'],
     *       ['tag',        'in',        ['vip', 'premium']],
     *       ['deleted_at', 'is_null'],
     *       ['created_at', 'between',   ['2024-01-01', '2024-12-31']],
     *   ]);
     *
     * @param  array<int, array{0: string, 1: string, 2?: mixed}> $filters
     * @throws \InvalidArgumentException on unknown column, unknown operator, or malformed tuple
     */
    public static function fromArray(array $filters): static
    {
        $instance = new static();

        foreach ($filters as $i => $tuple) {
            if (!is_array($tuple) || count($tuple) < 2) {
                throw new \InvalidArgumentException(
                    "fromArray: filter at index {$i} must be [column, operator] or [column, operator, value]. " .
                    "Got: " . json_encode($tuple)
                );
            }

            [$column, $rawOp] = $tuple;
            $value = $tuple[2] ?? null;

            if (!is_string($column) || $column === '') {
                throw new \InvalidArgumentException(
                    "fromArray: filter at index {$i} — column must be a non-empty string. " .
                    "Got: " . json_encode($column)
                );
            }

            // Validate column against allowed list (same check as orderBy())
            if (!empty($instance->allowedColumns) && !in_array($column, $instance->allowedColumns, true)) {
                throw new \InvalidArgumentException(
                    "fromArray: column '{$column}' is not allowed. " .
                    "Allowed: " . implode(', ', $instance->allowedColumns)
                );
            }

            // Resolve operator string → FilterOperator enum
            try {
                $op = FilterOperator::fromString((string) $rawOp);
            } catch (\InvalidArgumentException) {
                throw new \InvalidArgumentException(
                    "fromArray: unknown operator '{$rawOp}' for column '{$column}'. " .
                    "Supported: = != <> > < >= <= eq neq gt lt gte lte " .
                    "like starts_with ends_with in not_in is_null is_not_null null not_null between"
                );
            }

            // Build the Filter
            $filter = match ($op) {
                FilterOperator::IS_NULL     => Filter::isNull($column),
                FilterOperator::IS_NOT_NULL => Filter::isNotNull($column),

                FilterOperator::IN      => Filter::in($column, (array) $value),
                FilterOperator::NOT_IN  => Filter::notIn($column, (array) $value),

                FilterOperator::BETWEEN => (function () use ($column, $value, $i): Filter {
                    if (!is_array($value) || count($value) !== 2) {
                        throw new \InvalidArgumentException(
                            "fromArray: 'between' at index {$i} requires value [from, to]. " .
                            "Got: " . json_encode($value)
                        );
                    }
                    return Filter::between($column, $value[0], $value[1]);
                })(),

                FilterOperator::LIKE   => Filter::like($column, (string) $value),
                FilterOperator::STARTS => Filter::starts($column, (string) $value),
                FilterOperator::ENDS   => Filter::ends($column, (string) $value),

                default => new Filter($column, $op, $value),
            };

            $instance = $instance->add($filter);
        }

        return $instance;
    }
}
