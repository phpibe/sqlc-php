<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for Criteria::fromArray() — v2.12.0.
 *
 * Uses a concrete anonymous subclass with a known column set to exercise
 * column validation without depending on the generator.
 */
class CriteriaFromArrayTest extends TestCase
{
    /** Concrete Criteria with a known allowed column list */
    private Criteria $criteria;

    protected function setUp(): void
    {
        $this->criteria = new class extends Criteria {
            protected array $allowedColumns = ['id', 'user_id', 'status', 'total', 'deleted_at', 'created_at'];
        };
    }

    private function make(array $filters): Criteria
    {
        return $this->criteria::fromArray($filters);
    }

    // =========================================================================
    // Basic operators
    // =========================================================================

    public function test_eq_operator(): void
    {
        $c = $this->make([['user_id', '=', 42]]);
        $this->assertStringContainsString('user_id = :user_id', $c->toFilterClause(false));
        $this->assertSame(42, $this->firstBindingValue($c));
    }

    public function test_eq_alias(): void
    {
        $c = $this->make([['user_id', 'eq', 42]]);
        $this->assertStringContainsString('user_id = :user_id', $c->toFilterClause(false));
    }

    public function test_neq_operator(): void
    {
        $c = $this->make([['status', '!=', 'cancelled']]);
        $this->assertStringContainsString('status != :status', $c->toFilterClause(false));
    }

    public function test_neq_alias_diamond(): void
    {
        $c = $this->make([['status', '<>', 'cancelled']]);
        $this->assertStringContainsString('status != :status', $c->toFilterClause(false));
    }

    public function test_neq_alias_word(): void
    {
        $c = $this->make([['status', 'neq', 'cancelled']]);
        $this->assertStringContainsString('status != :status', $c->toFilterClause(false));
    }

    public function test_gt_operator(): void
    {
        $c = $this->make([['total', '>', 100]]);
        $this->assertStringContainsString('total > :total', $c->toFilterClause(false));
    }

    public function test_lt_operator(): void
    {
        $c = $this->make([['total', '<', 50]]);
        $this->assertStringContainsString('total < :total', $c->toFilterClause(false));
    }

    public function test_gte_operator(): void
    {
        $c = $this->make([['total', '>=', 100]]);
        $this->assertStringContainsString('total >= :total', $c->toFilterClause(false));
    }

    public function test_lte_operator(): void
    {
        $c = $this->make([['total', '<=', 100]]);
        $this->assertStringContainsString('total <= :total', $c->toFilterClause(false));
    }

    // =========================================================================
    // Pattern operators
    // =========================================================================

    public function test_like_wraps_value_in_wildcards(): void
    {
        $c = $this->make([['status', 'like', 'paid']]);
        $this->assertStringContainsString('LIKE :status', $c->toFilterClause(false));
        $this->assertSame('%paid%', $this->firstBindingValue($c));
    }

    public function test_starts_with(): void
    {
        $c = $this->make([['status', 'starts_with', 'pa']]);
        $this->assertSame('pa%', $this->firstBindingValue($c));
    }

    public function test_ends_with(): void
    {
        $c = $this->make([['status', 'ends_with', 'id']]);
        $this->assertSame('%id', $this->firstBindingValue($c));
    }

    // =========================================================================
    // Set operators
    // =========================================================================

    public function test_in_operator(): void
    {
        $c = $this->make([['status', 'in', ['paid', 'pending']]]);
        $sql = $c->toFilterClause(false);
        $this->assertStringContainsString('status IN (', $sql);
        $this->assertStringContainsString(':status_f0_0', $sql);
        $this->assertStringContainsString(':status_f0_1', $sql);
    }

    public function test_not_in_operator(): void
    {
        $c = $this->make([['status', 'not_in', ['cancelled', 'refunded']]]);
        $this->assertStringContainsString('status NOT IN (', $c->toFilterClause(false));
    }

    // =========================================================================
    // Null operators
    // =========================================================================

    public function test_is_null_two_element_tuple(): void
    {
        // No value needed — 2-element tuple accepted
        $c = $this->make([['deleted_at', 'is_null']]);
        $this->assertStringContainsString('deleted_at IS NULL', $c->toFilterClause(false));
    }

    public function test_is_null_alias_null(): void
    {
        $c = $this->make([['deleted_at', 'null']]);
        $this->assertStringContainsString('deleted_at IS NULL', $c->toFilterClause(false));
    }

    public function test_is_not_null(): void
    {
        $c = $this->make([['deleted_at', 'is_not_null']]);
        $this->assertStringContainsString('deleted_at IS NOT NULL', $c->toFilterClause(false));
    }

    public function test_not_null_alias(): void
    {
        $c = $this->make([['deleted_at', 'not_null']]);
        $this->assertStringContainsString('deleted_at IS NOT NULL', $c->toFilterClause(false));
    }

    // =========================================================================
    // Between
    // =========================================================================

    public function test_between_operator(): void
    {
        $c = $this->make([['created_at', 'between', ['2024-01-01', '2024-12-31']]]);
        $sql = $c->toFilterClause(false);
        $this->assertStringContainsString('BETWEEN :created_at', $sql);
        $this->assertStringContainsString('AND :created_at', $sql);
    }

    public function test_between_requires_array_of_two(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/between.*\[from, to\]/i');
        $this->make([['created_at', 'between', '2024-01-01']]);
    }

    public function test_between_requires_exactly_two_elements(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make([['created_at', 'between', ['2024-01-01', '2024-06-01', '2024-12-31']]]);
    }

    // =========================================================================
    // Multiple filters — AND chain
    // =========================================================================

    public function test_multiple_filters_combined_with_and(): void
    {
        $c = $this->make([
            ['user_id', '=',  42],
            ['status',  '!=', 'cancelled'],
            ['total',   '>=', 100],
        ]);
        $sql = $c->toFilterClause(false);
        $this->assertStringContainsString('user_id = :user_id', $sql);
        $this->assertStringContainsString('status != :status', $sql);
        $this->assertStringContainsString('total >= :total', $sql);
        $this->assertStringContainsString(' AND ', $sql);
    }

    // =========================================================================
    // Empty input
    // =========================================================================

    public function test_empty_array_returns_empty_criteria(): void
    {
        $c = $this->make([]);
        $this->assertTrue($c->isEmpty());
        $this->assertSame('', $c->toFilterClause(false));
    }

    // =========================================================================
    // Column validation
    // =========================================================================

    public function test_unknown_column_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/column 'nonexistent' is not allowed/");
        $this->make([['nonexistent', '=', 1]]);
    }

    public function test_all_allowed_columns_pass(): void
    {
        $this->expectNotToPerformAssertions();
        $this->make([
            ['id',         '=', 1],
            ['user_id',    '=', 1],
            ['status',     '=', 'x'],
            ['total',      '=', 1],
            ['deleted_at', 'null'],
            ['created_at', '=', '2024-01-01'],
        ]);
    }

    public function test_criteria_without_allowed_columns_accepts_any(): void
    {
        // When $allowedColumns is empty, all columns are allowed (same as orderBy())
        $open = new class extends Criteria {
            protected array $allowedColumns = [];
        };
        $c = $open::fromArray([['any_column', '=', 1]]);
        $this->assertStringContainsString('any_column', $c->toFilterClause(false));
    }

    // =========================================================================
    // Operator validation
    // =========================================================================

    public function test_unknown_operator_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/unknown operator 'FUZZY'/i");
        $this->make([['user_id', 'FUZZY', 1]]);
    }

    public function test_operator_is_case_insensitive(): void
    {
        $this->expectNotToPerformAssertions();
        $this->make([['user_id', 'EQ', 1]]);
    }

    // =========================================================================
    // Tuple validation
    // =========================================================================

    public function test_single_element_tuple_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/filter at index 0 must be/');
        $this->make([['user_id']]);
    }

    public function test_non_array_element_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->make(['user_id = 1']);
    }

    public function test_empty_column_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/column must be a non-empty string/');
        $this->make([['', '=', 1]]);
    }

    // =========================================================================
    // Integration: fromArray + query execution (SQL + bindings)
    // =========================================================================

    public function test_from_array_bindings_match_filter_clause(): void
    {
        $c = $this->make([
            ['user_id', '=',  42],
            ['status',  'in', ['paid', 'pending']],
        ]);

        $sql      = $c->toFilterClause(false);
        $bindings = $c->getBindings();

        // Extract all placeholders from the WHERE clause
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $m);
        $placeholders = $m[0];

        foreach ($placeholders as $ph) {
            $this->assertArrayHasKey($ph, $bindings,
                "Placeholder {$ph} in SQL must have a binding");
        }
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_12_0(): void
    {
        $this->assertSame('2.12.4', \SqlcPhp\Version::VERSION);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function firstBindingValue(Criteria $c): mixed
    {
        $b = $c->getBindings();
        return reset($b)[0] ?? null;
    }
}
