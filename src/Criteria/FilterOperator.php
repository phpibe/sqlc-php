<?php

declare(strict_types=1);

namespace SqlcPhp\Criteria;

/**
 * Supported filter operators for dynamic WHERE conditions.
 *
 * Used by generated {Group}Criteria classes and the base Criteria class.
 * The SQL template shows how each operator is rendered in the final query.
 */
enum FilterOperator: string
{
    case EQ         = '=';
    case NEQ        = '!=';
    case GT         = '>';
    case LT         = '<';
    case GTE        = '>=';
    case LTE        = '<=';
    case LIKE       = 'LIKE';        // LIKE '%value%'
    case STARTS     = 'STARTS';      // LIKE 'value%'
    case ENDS       = 'ENDS';        // LIKE '%value'
    case IN         = 'IN';          // IN (:col_in_0, :col_in_1, ...)
    case NOT_IN     = 'NOT IN';      // NOT IN (...)
    case IS_NULL    = 'IS NULL';     // IS NULL  (value ignored)
    case IS_NOT_NULL = 'IS NOT NULL'; // IS NOT NULL  (value ignored)
    case BETWEEN    = 'BETWEEN';     // BETWEEN :col_between_from AND :col_between_to

    /**
     * Returns true when the operator uses multiple values (arrays).
     */
    public function isMultiValue(): bool
    {
        return match ($this) {
            self::IN, self::NOT_IN => true,
            default                => false,
        };
    }

    /**
     * Returns true when the operator uses two values (from/to).
     */
    public function isTwoValue(): bool
    {
        return $this === self::BETWEEN;
    }

    /**
     * Returns true when no value is needed (NULL checks).
     */
    public function isNoValue(): bool
    {
        return match ($this) {
            self::IS_NULL, self::IS_NOT_NULL => true,
            default                          => false,
        };
    }

    /**
     * Resolve a human-readable operator string to a FilterOperator case.
     *
     * Accepted strings (case-insensitive):
     *   Equality:    '='  'eq'
     *   Inequality:  '!=' 'neq' '<>'
     *   Comparison:  '>'  'gt'  '<'  'lt'  '>=' 'gte'  '<=' 'lte'
     *   Pattern:     'like'  'starts_with'  'ends_with'
     *   Set:         'in'  'not_in'
     *   Null:        'is_null'  'null'  'is_not_null'  'not_null'
     *   Range:       'between'
     *
     * @throws \InvalidArgumentException for unknown operator strings
     */
    public static function fromString(string $op): self
    {
        return match (strtolower(trim($op))) {
            '=',  'eq'                  => self::EQ,
            '!=', 'neq', '<>'           => self::NEQ,
            '>',  'gt'                  => self::GT,
            '<',  'lt'                  => self::LT,
            '>=', 'gte'                 => self::GTE,
            '<=', 'lte'                 => self::LTE,
            'like'                      => self::LIKE,
            'starts_with', 'starts'     => self::STARTS,
            'ends_with',   'ends'       => self::ENDS,
            'in'                        => self::IN,
            'not_in'                    => self::NOT_IN,
            'is_null',  'null'          => self::IS_NULL,
            'is_not_null', 'not_null'   => self::IS_NOT_NULL,
            'between'                   => self::BETWEEN,
            default => throw new \InvalidArgumentException(
                "Unknown filter operator: '{$op}'. " .
                "Accepted: =, !=, >, <, >=, <=, like, starts_with, ends_with, " .
                "in, not_in, is_null, is_not_null, between"
            ),
        };
    }
}
