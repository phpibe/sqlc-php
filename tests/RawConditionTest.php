<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Criteria\Filter;

/**
 * Tests for Criteria::addRawCondition() (v2.15.4).
 *
 * addRawCondition() injects a verbatim SQL fragment into the WHERE clause,
 * AND-ed with typed filters. Useful for filtering on JOIN columns that are
 * not in the SELECT list and therefore have no generated typed method.
 */
class RawConditionTest extends TestCase
{
    // =========================================================================
    // Basic behavior
    // =========================================================================

    public function test_raw_condition_without_bindings_appears_in_clause(): void
    {
        $c = (new Criteria())->addRawCondition('reserve.id IS NOT NULL');

        $this->assertStringContainsString('reserve.id IS NOT NULL', $c->toFilterClause(false));
    }

    public function test_raw_condition_uses_where_keyword_when_no_other_filters(): void
    {
        $c = (new Criteria())->addRawCondition('reserve.id = 42');

        $clause = $c->toFilterClause(false);
        $this->assertStringStartsWith(' WHERE ', $clause);
        $this->assertStringContainsString('reserve.id = 42', $clause);
    }

    public function test_raw_condition_uses_and_keyword_in_append_mode(): void
    {
        $c = (new Criteria())->addRawCondition('reserve.id = 42');

        $clause = $c->toFilterClause(true);
        $this->assertStringStartsWith(' AND ', $clause);
    }

    // =========================================================================
    // Combining with typed filters
    // =========================================================================

    public function test_raw_condition_combined_with_typed_filter(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->addRawCondition('reserve.id = 42');

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
            ->addRawCondition('reserve.id = 42');

        $clause = $c->toFilterClause(false);
        $typedPos = strpos($clause, 'status');
        $rawPos   = strpos($clause, 'reserve.id');
        $this->assertLessThan($rawPos, $typedPos);
    }

    public function test_multiple_raw_conditions_all_appear(): void
    {
        $c = (new Criteria())
            ->addRawCondition('reserve.id IS NOT NULL')
            ->addRawCondition('profiles.verified = 1');

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
        $c = (new Criteria())->addRawCondition(
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
            ->addRawCondition(
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
        $c = (new Criteria())->addRawCondition(
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
            ->addRawCondition('reserve.id = :rid',    [':rid'  => [1, \PDO::PARAM_INT]])
            ->addRawCondition('profiles.code = :pcode',[':pcode'=> ['X', \PDO::PARAM_STR]]);

        $bindings = $c->getBindings();
        $this->assertArrayHasKey(':rid',   $bindings);
        $this->assertArrayHasKey(':pcode', $bindings);
    }

    // =========================================================================
    // hasFilters / isEmpty
    // =========================================================================

    public function test_has_filters_true_when_only_raw_condition(): void
    {
        $c = (new Criteria())->addRawCondition('reserve.id IS NOT NULL');
        $this->assertTrue($c->hasFilters());
    }

    public function test_is_empty_false_when_only_raw_condition(): void
    {
        $c = (new Criteria())->addRawCondition('reserve.id IS NOT NULL');
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
        $modified = $original->addRawCondition('reserve.id = 1');

        $this->assertFalse($original->hasFilters());
        $this->assertTrue($modified->hasFilters());
    }

    public function test_chaining_multiple_raw_conditions(): void
    {
        $c = (new Criteria())
            ->addRawCondition('a.col1 = 1')
            ->addRawCondition('b.col2 = 2')
            ->addRawCondition('c.col3 = 3');

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
        // Simulates: ProfileReserveCriteria filtering by reserve.id
        // where reserve is joined but reserve.id is not in the SELECT
        $criteria = (new Criteria())
            ->add(Filter::like('snap_firstname', 'cri'))
            ->addRawCondition(
                'reserve.id = :reserveId',
                [':reserveId' => [99, \PDO::PARAM_INT]]
            );

        $clause   = $criteria->toFilterClause(true); // append mode (existing WHERE)
        $bindings = $criteria->getBindings();

        // Both conditions present
        $this->assertStringContainsString('snap_firstname LIKE :snap_firstname_f0', $clause);
        $this->assertStringContainsString('reserve.id = :reserveId', $clause);

        // Both bindings present
        $this->assertArrayHasKey(':snap_firstname_f0', $bindings);
        $this->assertStringContainsString('cri', $bindings[':snap_firstname_f0'][0]); // like wraps with %
        $this->assertArrayHasKey(':reserveId', $bindings);
        $this->assertSame(99, $bindings[':reserveId'][0]);
    }

    public function test_raw_condition_with_or_group(): void
    {
        // addRawCondition can be combined with orGroup
        $c = (new Criteria())
            ->add(Filter::eq('status', 'active'))
            ->addRawCondition('reserve.deleted_at IS NULL');

        $clause = $c->toFilterClause(false);
        $this->assertStringContainsString('status = :status_f0',    $clause);
        $this->assertStringContainsString('reserve.deleted_at IS NULL', $clause);
    }
}
