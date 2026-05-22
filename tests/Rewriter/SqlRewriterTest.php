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
        // This case should be caught earlier by the parser; rewriter is defensive.
        $sql = 'SELECT * FROM users WHERE id = :id';
        $result = $this->rewriter->rewrite($sql, ['email']);
        $this->assertSame($sql, $result);
    }

    // -------------------------------------------------------------------------
    // Operator: =
    // -------------------------------------------------------------------------

    public function test_rewrites_equals_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);
        $this->assertSame(
            'SELECT * FROM users WHERE (:status IS NULL OR status = :status)',
            $result
        );
    }

    public function test_rewrites_qualified_column_with_equals(): void
    {
        $sql    = 'SELECT * FROM users WHERE users.status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);
        $this->assertSame(
            'SELECT * FROM users WHERE (:status IS NULL OR users.status = :status)',
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
        $this->assertStringContainsString(':status IS NULL OR status <> :status', $result);
    }

    public function test_rewrites_not_equals_bang_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE status != :status';
        $result = $this->rewriter->rewrite($sql, ['status']);
        $this->assertStringContainsString(':status IS NULL OR status != :status', $result);
    }

    public function test_rewrites_greater_than_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE age > :minAge';
        $result = $this->rewriter->rewrite($sql, ['minAge']);
        $this->assertStringContainsString(':minAge IS NULL OR age > :minAge', $result);
    }

    public function test_rewrites_less_than_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE age < :maxAge';
        $result = $this->rewriter->rewrite($sql, ['maxAge']);
        $this->assertStringContainsString(':maxAge IS NULL OR age < :maxAge', $result);
    }

    public function test_rewrites_greater_than_or_equal_operator(): void
    {
        $sql    = 'SELECT * FROM orders WHERE total >= :minTotal';
        $result = $this->rewriter->rewrite($sql, ['minTotal']);
        $this->assertStringContainsString(':minTotal IS NULL OR total >= :minTotal', $result);
    }

    public function test_rewrites_less_than_or_equal_operator(): void
    {
        $sql    = 'SELECT * FROM orders WHERE total <= :maxTotal';
        $result = $this->rewriter->rewrite($sql, ['maxTotal']);
        $this->assertStringContainsString(':maxTotal IS NULL OR total <= :maxTotal', $result);
    }

    public function test_rewrites_like_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE name LIKE :pattern';
        $result = $this->rewriter->rewrite($sql, ['pattern']);
        $this->assertStringContainsString(':pattern IS NULL OR name LIKE :pattern', $result);
    }

    public function test_rewrites_ilike_operator(): void
    {
        $sql    = 'SELECT * FROM users WHERE name ILIKE :pattern';
        $result = $this->rewriter->rewrite($sql, ['pattern']);
        $this->assertStringContainsString(':pattern IS NULL OR name ILIKE :pattern', $result);
    }

    // -------------------------------------------------------------------------
    // Multiple optional params
    // -------------------------------------------------------------------------

    public function test_rewrites_multiple_optional_params(): void
    {
        $sql = 'SELECT * FROM users WHERE status = :status AND name LIKE :pattern';
        $result = $this->rewriter->rewrite($sql, ['status', 'pattern']);

        $this->assertStringContainsString(':status IS NULL OR status = :status', $result);
        $this->assertStringContainsString(':pattern IS NULL OR name LIKE :pattern', $result);
    }

    public function test_non_optional_param_is_not_rewritten(): void
    {
        $sql    = 'SELECT * FROM users WHERE id = :id AND status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);

        // :id is NOT optional — must remain unchanged
        $this->assertStringContainsString('id = :id', $result);
        // :status IS optional — must be rewritten
        $this->assertStringContainsString(':status IS NULL OR status = :status', $result);
    }

    // -------------------------------------------------------------------------
    // Repeated param in SQL
    // -------------------------------------------------------------------------

    public function test_rewrites_all_occurrences_of_same_param(): void
    {
        $sql    = 'SELECT * FROM users WHERE a = :val AND b = :val';
        $result = $this->rewriter->rewrite($sql, ['val']);

        // Both conditions must be rewritten
        $this->assertSame(2, substr_count($result, ':val IS NULL'));
    }

    // -------------------------------------------------------------------------
    // Output structure
    // -------------------------------------------------------------------------

    public function test_rewritten_condition_is_wrapped_in_parentheses(): void
    {
        $sql    = 'SELECT * FROM users WHERE status = :status';
        $result = $this->rewriter->rewrite($sql, ['status']);

        $this->assertMatchesRegularExpression(
            '/\(\s*:status IS NULL OR status = :status\s*\)/',
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
}
