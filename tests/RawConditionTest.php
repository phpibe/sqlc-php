<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Criteria\Filter;

/**
 * Tests for Criteria::andRawCondition() (v2.15.4).
 *
 * andRawCondition() injects a verbatim SQL fragment into the WHERE clause,
 * AND-ed with typed filters. Useful for filtering on JOIN columns that are
 * not in the SELECT list and therefore have no generated typed method.
 */
class RawConditionTest extends TestCase
{
    // =========================================================================
    // Basic behavior — andRawCondition
    // =========================================================================

    public function test_raw_condition_without_bindings_appears_in_clause(): void
    {
        $c = (new Criteria())->andRawCondition('reserve.id IS NOT NULL');

        $this->assertStringContainsString('reserve.id IS NOT NULL', $c->toFilterClause(false));
    }

    public function test_raw_condition_uses_where_keyword_when_no_other_filters(): void
    {
        $c = (new Criteria())->andRawCondition('reserve.id = 42');

        $clause = $c->toFilterClause(false);
        $this->assertStringStartsWith(' WHERE ', $clause);
        $this->assertStringContainsString('reserve.id = 42', $clause);
    }

    public function test_raw_condition_uses_and_keyword_in_append_mode(): void
    {
        $c = (new Criteria())->andRawCondition('reserve.id = 42');

        $clause = $c->toFilterClause(true);
        $this->assertStringStartsWith(' AND ', $clause);
    }

    // =========================================================================
    // orRawCondition
    // =========================================================================

    public function test_or_raw_condition_appears_in_clause(): void
    {
        $c = (new Criteria())->orRawCondition('reserve.is_legacy = 1');

        $clause = $c->toFilterClause(false);
        $this->assertStringContainsString('reserve.is_legacy = 1', $clause);
    }

    public function test_or_raw_condition_uses_or_connector_with_typed_filter(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->orRawCondition('reserve.is_legacy = 1');

        $clause = $c->toFilterClause(false);
        $this->assertStringContainsString(' OR ', $clause);
        $this->assertStringContainsString('status = :status_f0', $clause);
        $this->assertStringContainsString('reserve.is_legacy = 1', $clause);
    }

    public function test_or_raw_condition_standalone_uses_where(): void
    {
        $c = (new Criteria())->orRawCondition('reserve.id = 99');

        $clause = $c->toFilterClause(false);
        $this->assertStringStartsWith(' WHERE ', $clause);
    }

    public function test_or_raw_condition_with_binding(): void
    {
        $c = (new Criteria())->orRawCondition(
            'reserve.fallback_id = :fbId',
            [':fbId' => [42, \PDO::PARAM_INT]]
        );

        $bindings = $c->getBindings();
        $this->assertArrayHasKey(':fbId', $bindings);
        $this->assertSame(42, $bindings[':fbId'][0]);
    }

    public function test_and_and_or_raw_conditions_combined(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->andRawCondition('reserve.deleted_at IS NULL')
            ->orRawCondition('reserve.is_legacy = 1');

        $clause = $c->toFilterClause(false);

        // AND condition must be present
        $this->assertStringContainsString('reserve.deleted_at IS NULL', $clause);
        // OR condition must be present
        $this->assertStringContainsString('reserve.is_legacy = 1', $clause);
        // OR connector must be present
        $this->assertStringContainsString(' OR ', $clause);
    }

    public function test_multiple_or_raw_conditions(): void
    {
        $c = (new Criteria())
            ->orRawCondition('reserve.type = 1')
            ->orRawCondition('reserve.type = 2');

        $clause = $c->toFilterClause(false);
        $this->assertStringContainsString('reserve.type = 1', $clause);
        $this->assertStringContainsString('reserve.type = 2', $clause);
    }

    public function test_or_raw_bindings_merged_with_other_bindings(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->andRawCondition('a.col = :aVal', [':aVal' => [1, \PDO::PARAM_INT]])
            ->orRawCondition('b.col = :bVal', [':bVal' => [2, \PDO::PARAM_INT]]);

        $bindings = $c->getBindings();
        $this->assertArrayHasKey(':aVal', $bindings);
        $this->assertArrayHasKey(':bVal', $bindings);
        $this->assertSame(1, $bindings[':aVal'][0]);
        $this->assertSame(2, $bindings[':bVal'][0]);
    }

    // =========================================================================
    // Combining with typed filters — andRawCondition
    // =========================================================================

    public function test_raw_condition_combined_with_typed_filter(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->andRawCondition('reserve.id = 42');

        $clause = $c->toFilterClause(false);
        $this->assertStringContainsString('status = :status_f0', $clause);
        $this->assertStringContainsString('reserve.id = 42', $clause);
        // Both joined with AND
        $this->assertStringContainsString('AND', $clause);
    }

    public function test_raw_condition_appears_after_typed_filters(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->andRawCondition('reserve.id = 42');

        $clause = $c->toFilterClause(false);
        $typedPos = strpos($clause, 'status');
        $rawPos   = strpos($clause, 'reserve.id');
        $this->assertLessThan($rawPos, $typedPos);
    }

    public function test_multiple_raw_conditions_all_appear(): void
    {
        $c = (new Criteria())
            ->andRawCondition('reserve.id IS NOT NULL')
            ->andRawCondition('profiles.verified = 1');

        $clause = $c->toFilterClause(false);
        $this->assertStringContainsString('reserve.id IS NOT NULL', $clause);
        $this->assertStringContainsString('profiles.verified = 1', $clause);
        $this->assertStringContainsString('AND', $clause);
    }

    // =========================================================================
    // Bindings
    // =========================================================================

    public function test_raw_condition_with_named_placeholder_and_binding(): void
    {
        $c = (new Criteria())->andRawCondition(
            'reserve.id = :reserveId',
            [':reserveId' => [42, \PDO::PARAM_INT]]
        );

        $clause   = $c->toFilterClause(false);
        $bindings = $c->getBindings();

        $this->assertStringContainsString('reserve.id = :reserveId', $clause);
        $this->assertArrayHasKey(':reserveId', $bindings);
        $this->assertSame(42, $bindings[':reserveId'][0]);
        $this->assertSame(\PDO::PARAM_INT, $bindings[':reserveId'][1]);
    }

    public function test_raw_condition_bindings_merged_with_typed_filter_bindings(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->andRawCondition(
                'reserve.id = :reserveId',
                [':reserveId' => [42, \PDO::PARAM_INT]]
            );

        $bindings = $c->getBindings();

        // Typed filter binding
        $this->assertArrayHasKey(':status_f0', $bindings);
        $this->assertSame('active', $bindings[':status_f0'][0]);

        // Raw condition binding
        $this->assertArrayHasKey(':reserveId', $bindings);
        $this->assertSame(42, $bindings[':reserveId'][0]);
    }

    public function test_raw_condition_with_multiple_placeholders(): void
    {
        $c = (new Criteria())->andRawCondition(
            'reserve.status IN (:st1, :st2)',
            [
                ':st1' => ['pending', \PDO::PARAM_STR],
                ':st2' => ['active',  \PDO::PARAM_STR],
            ]
        );

        $bindings = $c->getBindings();
        $this->assertArrayHasKey(':st1', $bindings);
        $this->assertArrayHasKey(':st2', $bindings);
        $this->assertSame('pending', $bindings[':st1'][0]);
        $this->assertSame('active',  $bindings[':st2'][0]);
    }

    public function test_multiple_raw_conditions_bindings_all_present(): void
    {
        $c = (new Criteria())
            ->andRawCondition('reserve.id = :rid',    [':rid'  => [1, \PDO::PARAM_INT]])
            ->andRawCondition('profiles.code = :pcode',[':pcode'=> ['X', \PDO::PARAM_STR]]);

        $bindings = $c->getBindings();
        $this->assertArrayHasKey(':rid',   $bindings);
        $this->assertArrayHasKey(':pcode', $bindings);
    }

    // =========================================================================
    // hasFilters / isEmpty
    // =========================================================================

    public function test_has_filters_true_when_only_raw_condition(): void
    {
        $c = (new Criteria())->andRawCondition('reserve.id IS NOT NULL');
        $this->assertTrue($c->hasFilters());
    }

    public function test_is_empty_false_when_only_raw_condition(): void
    {
        $c = (new Criteria())->andRawCondition('reserve.id IS NOT NULL');
        $this->assertFalse($c->isEmpty());
    }

    public function test_is_empty_true_when_no_conditions(): void
    {
        $this->assertTrue((new Criteria())->isEmpty());
    }

    // =========================================================================
    // Immutability
    // =========================================================================

    public function test_add_raw_condition_is_immutable(): void
    {
        $original = new Criteria();
        $modified = $original->andRawCondition('reserve.id = 1');

        $this->assertFalse($original->hasFilters());
        $this->assertTrue($modified->hasFilters());
    }

    public function test_chaining_multiple_raw_conditions(): void
    {
        $c = (new Criteria())
            ->andRawCondition('a.col1 = 1')
            ->andRawCondition('b.col2 = 2')
            ->andRawCondition('c.col3 = 3');

        $clause = $c->toFilterClause(false);
        $this->assertStringContainsString('a.col1 = 1', $clause);
        $this->assertStringContainsString('b.col2 = 2', $clause);
        $this->assertStringContainsString('c.col3 = 3', $clause);
    }

    // =========================================================================
    // Real use case: filter on JOIN column not in SELECT
    // =========================================================================

    public function test_real_use_case_filter_on_join_column(): void
    {
        $criteria = (new Criteria())
            ->add(Filter::like('snap_firstname', 'cri'))
            ->andRawCondition('reserve.id = :reserveId', [':reserveId' => [99, \PDO::PARAM_INT]]);

        $clause   = $criteria->toFilterClause(true);
        $bindings = $criteria->getBindings();

        $this->assertStringContainsString('snap_firstname LIKE :snap_firstname_f0', $clause);
        $this->assertStringContainsString('reserve.id = :reserveId', $clause);
        $this->assertStringNotContainsString(' OR ', $clause);
        $this->assertArrayHasKey(':snap_firstname_f0', $bindings);
        $this->assertArrayHasKey(':reserveId', $bindings);
        $this->assertSame(99, $bindings[':reserveId'][0]);
    }

    public function test_real_use_case_or_filter_on_join_column(): void
    {
        $criteria = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->orRawCondition('reserve.fallback_id = :fbId', [':fbId' => [7, \PDO::PARAM_INT]]);

        $clause   = $criteria->toFilterClause(true);
        $bindings = $criteria->getBindings();

        $this->assertStringContainsString('status', $clause);
        $this->assertStringContainsString('reserve.fallback_id = :fbId', $clause);
        $this->assertStringContainsString(' OR ', $clause);
        $this->assertArrayHasKey(':fbId', $bindings);
    }
}