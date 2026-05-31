<?php

declare(strict_types=1);

namespace SqlcPhp\Criteria;

/**
 * Immutable single filter condition: column OP value(s).
 *
 * Not instantiated directly by users — use the named constructors in
 * generated {Group}Criteria classes (whereActiveEq, whereEmailLike, etc.)
 * or via the static helpers on this class for ad-hoc use.
 */
final readonly class Filter
{
    /**
     * @param string         $column    Column name (validated against whitelist by Criteria)
     * @param FilterOperator $operator
     * @param mixed          $value     Single value, or array for IN/NOT_IN, or null for IS NULL/IS NOT NULL
     * @param mixed          $valueTo   Second value for BETWEEN (the upper bound)
     */
    public function __construct(
        public string         $column,
        public FilterOperator $operator,
        public mixed          $value   = null,
        public mixed          $valueTo = null,
    ) {}

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    public static function eq(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::EQ, $value);
    }

    public static function neq(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::NEQ, $value);
    }

    public static function gt(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::GT, $value);
    }

    public static function lt(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::LT, $value);
    }

    public static function gte(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::GTE, $value);
    }

    public static function lte(string $column, mixed $value): self
    {
        return new self($column, FilterOperator::LTE, $value);
    }

    public static function like(string $column, string $value): self
    {
        return new self($column, FilterOperator::LIKE, '%' . $value . '%');
    }

    public static function starts(string $column, string $value): self
    {
        return new self($column, FilterOperator::STARTS, $value . '%');
    }

    public static function ends(string $column, string $value): self
    {
        return new self($column, FilterOperator::ENDS, '%' . $value);
    }

    /** @param mixed[] $values */
    public static function in(string $column, array $values): self
    {
        return new self($column, FilterOperator::IN, $values);
    }

    /** @param mixed[] $values */
    public static function notIn(string $column, array $values): self
    {
        return new self($column, FilterOperator::NOT_IN, $values);
    }

    public static function isNull(string $column): self
    {
        return new self($column, FilterOperator::IS_NULL);
    }

    public static function isNotNull(string $column): self
    {
        return new self($column, FilterOperator::IS_NOT_NULL);
    }

    public static function between(string $column, mixed $from, mixed $to): self
    {
        return new self($column, FilterOperator::BETWEEN, $from, $to);
    }
}
