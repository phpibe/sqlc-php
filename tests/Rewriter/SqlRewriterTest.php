<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Rewriter;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Rewriter\SqlRewriter;

class SqlRewriterTest extends TestCase
{
    private SqlRewriter $rewriter;

    protected function setUp(): void
    {
        $this->rewriter = new SqlRewriter();
    }

    // -------------------------------------------------------------------------
    // No-op cases
    // -------------------------------------------------------------------------

    public function test_returns_sql_unchanged_when_no_optional_params(): void
    {
        $sql = 'SELECT * FROM users WHERE id = :id';
        $this->assertSame($sql, $this->rewriter->rewrite($sql, []));
    }

    public function test_returns_sql_unchanged_when_param_not_present_in_sql(): void
    {
        // Under the new model, @optional for a param with no col OP :param
        // match throws an error — better than silently doing nothing.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not match any/');

        $sql = 'SELECT * FROM users WHERE id = :id';
        $this->rewriter->rewrite($sql, ['email']); // :email not in SQL
    }

    // -------------------------------------------------------------------------
    // Operator: =
    // -------------------------------------------------------------------------

    public function test_rewrites_equals_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);
        $this->assertSame(
            'SELECT * FROM users WHERE (:status_chk IS NULL OR status = :status)',
            $result
        );
    }

    public function test_rewrites_qualified_column_with_equals(): void
    {
        $sql    = 'SELECT * FROM users WHERE users.status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);
        $this->assertSame(
            'SELECT * FROM users WHERE (:status_chk IS NULL OR users.status = :status)',
            $result
        );
    }

    // -------------------------------------------------------------------------
    // All supported operators
    // -------------------------------------------------------------------------

    public function test_rewrites_not_equals_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE status <> :status';
        $result = $this->rewriter->rewrite($sql, ['status']);
        $this->assertStringContainsString(':status_chk IS NULL OR status <> :status', $result);
    }

    public function test_rewrites_not_equals_bang_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE status != :status';
        $result = $this->rewriter->rewrite($sql, ['status']);
        $this->assertStringContainsString(':status_chk IS NULL OR status != :status', $result);
    }

    public function test_rewrites_greater_than_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE age > :minAge';
        $result = $this->rewriter->rewrite($sql, ['minAge']);
        $this->assertStringContainsString(':minAge_chk IS NULL OR age > :minAge', $result);
    }

    public function test_rewrites_less_than_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE age < :maxAge';
        $result = $this->rewriter->rewrite($sql, ['maxAge']);
        $this->assertStringContainsString(':maxAge_chk IS NULL OR age < :maxAge', $result);
    }

    public function test_rewrites_greater_than_or_equal_operator(): void
    {
        $sql    = 'SELECT * FROM orders WHERE total >= :minTotal';
        $result = $this->rewriter->rewrite($sql, ['minTotal']);
        $this->assertStringContainsString(':minTotal_chk IS NULL OR total >= :minTotal', $result);
    }

    public function test_rewrites_less_than_or_equal_operator(): void
    {
        $sql    = 'SELECT * FROM orders WHERE total <= :maxTotal';
        $result = $this->rewriter->rewrite($sql, ['maxTotal']);
        $this->assertStringContainsString(':maxTotal_chk IS NULL OR total <= :maxTotal', $result);
    }

    public function test_rewrites_like_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE name LIKE :pattern';
        $result = $this->rewriter->rewrite($sql, ['pattern']);
        $this->assertStringContainsString(':pattern_chk IS NULL OR name LIKE :pattern', $result);
    }

    public function test_rewrites_ilike_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE name ILIKE :pattern';
        $result = $this->rewriter->rewrite($sql, ['pattern']);
        $this->assertStringContainsString(':pattern_chk IS NULL OR name ILIKE :pattern', $result);
    }

    // -------------------------------------------------------------------------
    // Multiple optional params
    // -------------------------------------------------------------------------

    public function test_rewrites_multiple_optional_params(): void
    {
        $sql = 'SELECT * FROM users WHERE status = :status AND name LIKE :pattern';
        $result = $this->rewriter->rewrite($sql, ['status', 'pattern']);

        $this->assertStringContainsString(':status_chk IS NULL OR status = :status', $result);
        $this->assertStringContainsString(':pattern_chk IS NULL OR name LIKE :pattern', $result);
    }

    public function test_non_optional_param_is_not_rewritten(): void
    {
        $sql    = 'SELECT * FROM users WHERE id = :id AND status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);

        // :id is NOT optional — must remain unchanged
        $this->assertStringContainsString('id = :id', $result);
        // :status IS optional — must be rewritten
        $this->assertStringContainsString(':status_chk IS NULL OR status = :status', $result);
    }

    // -------------------------------------------------------------------------
    // Repeated param in SQL
    // -------------------------------------------------------------------------

    public function test_rewrites_all_occurrences_of_same_param(): void
    {
        $sql    = 'SELECT * FROM users WHERE a = :val AND b = :val';
        $result = $this->rewriter->rewrite($sql, ['val']);

        // Both conditions must be rewritten with the _chk null-check token
        $this->assertSame(2, substr_count($result, ':val_chk IS NULL'));
        // And the original :val token must appear twice (the actual comparison)
        $this->assertSame(2, substr_count($result, ':val)'));
    }

    // -------------------------------------------------------------------------
    // Output structure
    // -------------------------------------------------------------------------

    public function test_rewritten_condition_is_wrapped_in_parentheses(): void
    {
        $sql    = 'SELECT * FROM users WHERE status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);

        $this->assertMatchesRegularExpression(
            '/\(\s*:status_chk IS NULL OR status = :status\s*\)/',
            $result
        );
    }

    public function test_rewritten_sql_still_contains_param_token(): void
    {
        $sql    = 'SELECT * FROM users WHERE email = :email';
        $result = $this->rewriter->rewrite($sql, ['email']);

        // The :email token must appear twice — once in IS NULL, once in the condition
        $this->assertSame(2, substr_count($result, ':email'));
    }

    // -------------------------------------------------------------------------
    // Unsafe construct guards
    // -------------------------------------------------------------------------

    // =========================================================================
    // JOIN — new behavior: allowed when param is in WHERE, not ON
    // =========================================================================

    public function test_join_with_param_in_where_is_now_allowed(): void
    {
        // The core fix: JOIN + WHERE-only @optional no longer throws
        $sql    = 'SELECT * FROM users INNER JOIN roles ON roles.id = users.role_id WHERE users.active = :active';
        $result = $this->rewriter->rewrite($sql, ['active']);

        $this->assertStringContainsString(':active_chk IS NULL OR', $result);
        $this->assertStringContainsString('ON roles.id = users.role_id', $result); // ON untouched
    }

    public function test_left_join_with_param_in_where_is_allowed(): void
    {
        $sql    = 'SELECT * FROM users LEFT JOIN roles ON roles.id = users.role_id WHERE users.active = :active';
        $result = $this->rewriter->rewrite($sql, ['active']);

        $this->assertStringContainsString(':active_chk IS NULL OR', $result);
    }

    public function test_right_join_with_param_in_where_is_allowed(): void
    {
        $sql    = 'SELECT * FROM users RIGHT JOIN roles ON roles.id = users.role_id WHERE users.status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);

        $this->assertStringContainsString(':status_chk IS NULL OR', $result);
    }

    public function test_cross_join_with_param_in_where_is_allowed(): void
    {
        $sql    = 'SELECT * FROM users CROSS JOIN roles WHERE users.status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);

        $this->assertStringContainsString(':status_chk IS NULL OR', $result);
    }

    public function test_throws_when_param_appears_in_on_clause(): void
    {
        // The actual unsafe case: :userId in the ON condition
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ON clause/');

        $sql = 'SELECT * FROM users LEFT JOIN orders ON orders.user_id = :userId WHERE users.active = 1';
        $this->rewriter->rewrite($sql, ['userId']);
    }

    public function test_on_clause_is_not_rewritten(): void
    {
        // Verify the ON condition is never touched by the rewriter
        $sql    = 'SELECT * FROM users LEFT JOIN roles ON roles.id = users.role_id WHERE users.name LIKE :name';
        $result = $this->rewriter->rewrite($sql, ['name']);

        $this->assertStringContainsString('ON roles.id = users.role_id', $result);
        $this->assertStringNotContainsString(':name_chk IS NULL OR roles.id', $result);
    }

    public function test_reported_bug_query_now_works(): void
    {
        // Full reproduction of the originally reported failing query
        $sql = <<<SQL
SELECT
    mcc.id, mcc.country_id, mcc.active, mcc.cae_id, mcc.start_num, mcc.end_num,
    IF(MAX(m.voucher_number) >= mcc.end_num, 0, mcc.end_num - MAX(m.voucher_number)) as rem_num,
    mcc.expiration_date, MAX(m.voucher_number) AS max_voucher_number
FROM memory_cae_configs mcc
LEFT JOIN memory m ON m.voucher_type = CASE
    WHEN mcc.country_id = 164 THEN 'factExp'
    ELSE 'factTicket' END
WHERE mcc.active = :active
GROUP BY mcc.id
SQL;
        $result = $this->rewriter->rewrite($sql, ['active'], 'ListActiveCaeValidity');

        $this->assertStringContainsString('(:active_chk IS NULL OR mcc.active = :active)', $result);
        $this->assertStringContainsString("ON m.voucher_type = CASE", $result); // ON untouched
    }

    // =========================================================================
    // HAVING
    // =========================================================================

    public function test_throws_on_having_clause(): void
    {
        // HAVING COUNT(*) > :minCount doesn't match col OP :param (COUNT(*) is not a column)
        // so the rewriter now throws "does not match" rather than "HAVING"
        $this->expectException(\RuntimeException::class);

        $sql = 'SELECT role_id, COUNT(*) FROM users GROUP BY role_id HAVING COUNT(*) > :minCount';
        $this->rewriter->rewrite($sql, ['minCount']);
    }

    // =========================================================================
    // Subqueries — still unsafe
    // =========================================================================

    public function test_throws_on_subquery_with_in(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/subquer/i');

        $sql = 'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders WHERE status = :status)';
        $this->rewriter->rewrite($sql, ['status']);
    }

    public function test_throws_on_subquery_with_exists(): void
    {
        $this->expectException(\RuntimeException::class);

        $sql = 'SELECT * FROM users WHERE EXISTS (SELECT 1 FROM orders WHERE user_id = :userId)';
        $this->rewriter->rewrite($sql, ['userId']);
    }

    // =========================================================================
    // Error messages
    // =========================================================================

    public function test_error_message_contains_query_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Query 'SearchUsers'/");

        // :status appears in ON → triggers the ON clause error
        $sql = 'SELECT * FROM users INNER JOIN roles ON roles.id = :status WHERE users.id = 1';
        $this->rewriter->rewrite($sql, ['status'], 'SearchUsers');
    }

    public function test_error_message_without_query_name_uses_generic_prefix(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/^@optional/");

        // :status in ON → throws with generic prefix when no query name given
        $sql = 'SELECT * FROM users INNER JOIN roles ON roles.id = :status WHERE users.id = 1';
        $this->rewriter->rewrite($sql, ['status']);
    }

    public function test_no_error_on_simple_where_without_join(): void
    {
        $sql    = 'SELECT * FROM users WHERE status = :status AND active = :active';
        $result = $this->rewriter->rewrite($sql, ['status', 'active'], 'ListUsers');

        $this->assertStringContainsString(':status_chk IS NULL OR', $result);
        $this->assertStringContainsString(':active_chk IS NULL OR', $result);
    }

    public function test_no_error_on_query_without_optional_params_even_with_join(): void
    {
        $sql    = 'SELECT * FROM users INNER JOIN roles ON roles.id = users.role_id WHERE users.id = :id';
        $result = $this->rewriter->rewrite($sql, []);

        $this->assertSame($sql, $result);
    }
}
