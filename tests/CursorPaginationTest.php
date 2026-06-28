<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Query\CursorResult;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for :cursor return type and @cursor annotation (v2.11.0).
 *
 * Cursor pagination (keyset pagination) is O(1) regardless of page depth
 * because it uses a WHERE clause instead of OFFSET to locate the starting point.
 *
 * Usage:
 *   -- @name ListOrders
 *   -- @class Order
 *   -- @cursor created_at DESC, id DESC
 *   -- @returns :cursor
 *   SELECT id, user_id, total, created_at
 *   FROM orders
 *   WHERE user_id = :userId
 *   ORDER BY created_at DESC, id DESC;
 */
class CursorPaginationTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $qg;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE orders (
                id         INT              AUTO_INCREMENT PRIMARY KEY,
                user_id    INT              NOT NULL,
                total      DECIMAL(10,2)   NOT NULL,
                status     VARCHAR(20)     NOT NULL DEFAULT 'pending',
                created_at DATETIME        NOT NULL
            );
            CREATE TABLE users (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL,
                active TINYINT     NOT NULL DEFAULT 1
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App\\Enums'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App\\DTOs', $this->mapper);
        $this->qg       = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App\\Repos');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function code(array $queries): string
    {
        $result = $this->qg->generate($queries);
        return array_values($result)[0]['code'];
    }

    // =========================================================================
    // Parser — @cursor annotation
    // =========================================================================

    public function test_parser_reads_cursor_single_column(): void
    {
        $defs = $this->parser->parse(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT * FROM orders ORDER BY id DESC;"
        );
        $this->assertCount(1, $defs[0]->cursorColumns);
        $this->assertSame('id',   $defs[0]->cursorColumns[0]['col']);
        $this->assertSame('DESC', $defs[0]->cursorColumns[0]['dir']);
    }

    public function test_parser_reads_cursor_multiple_columns(): void
    {
        $defs = $this->parser->parse(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT * FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $this->assertCount(2, $defs[0]->cursorColumns);
        $this->assertSame('created_at', $defs[0]->cursorColumns[0]['col']);
        $this->assertSame('DESC',       $defs[0]->cursorColumns[0]['dir']);
        $this->assertSame('id',         $defs[0]->cursorColumns[1]['col']);
        $this->assertSame('DESC',       $defs[0]->cursorColumns[1]['dir']);
    }

    public function test_parser_cursor_default_direction_is_asc(): void
    {
        $defs = $this->parser->parse(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id\n-- @returns :cursor\n" .
            "SELECT * FROM orders ORDER BY id;"
        );
        $this->assertSame('ASC', $defs[0]->cursorColumns[0]['dir']);
    }

    public function test_parser_cursor_empty_without_annotation(): void
    {
        $defs = $this->parser->parse(
            "-- @name ListOrders\n-- @class Order\n-- @returns :many\nSELECT * FROM orders;"
        );
        $this->assertEmpty($defs[0]->cursorColumns);
    }

    // =========================================================================
    // Analyzer — validation
    // =========================================================================

    public function test_analyzer_accepts_valid_cursor_query(): void
    {
        $this->expectNotToPerformAssertions();
        $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, user_id, total, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
    }

    public function test_analyzer_rejects_cursor_without_annotation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@cursor/');
        $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @returns :cursor\n" .
            "SELECT * FROM orders ORDER BY id DESC;"
        );
    }

    public function test_analyzer_rejects_cursor_with_wrong_return_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/:cursor/');
        $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :many\n" .
            "SELECT * FROM orders ORDER BY id DESC;"
        );
    }

    public function test_analyzer_rejects_cursor_with_union(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/UNION/');
        $this->analyze(
            "-- @name List\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC\n" .
            "UNION ALL SELECT id FROM users ORDER BY id DESC;"
        );
    }

    public function test_analyzer_rejects_counted_with_cursor_on_union(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/UNION/');
        $this->analyze(
            "-- @name List\n-- @class Order\n-- @cursor id DESC\n-- @counted\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC\n" .
            "UNION ALL SELECT id FROM users ORDER BY id DESC;"
        );
    }

    public function test_analyzer_accepts_counted_with_cursor(): void
    {
        $this->expectNotToPerformAssertions();
        $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @counted\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
    }

    public function test_counted_cursor_generates_count_method(): void
    {
        // @counted without @searchable — no $criteria param
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n" .
            "-- @counted\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listOrdersCount(): int', $code,
            '@counted + :cursor (no @searchable) must generate listOrdersCount() with no $criteria');
    }

    public function test_counted_cursor_count_sql_has_no_cursor_where(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n" .
            "-- @counted\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        // Extract the COUNT SQL literal
        preg_match('/listOrdersCount.*?prepare\(\'(SELECT COUNT[^\']+)\'/s', $code, $m);
        $countSql = $m[1] ?? '';
        $this->assertNotEmpty($countSql, 'COUNT SQL must be present');
        $this->assertStringNotContainsString('__cursor', $countSql,
            'COUNT SQL must not contain cursor WHERE placeholders');
        $this->assertStringNotContainsString('ORDER BY', $countSql,
            'COUNT SQL must not contain ORDER BY');
        $this->assertStringContainsString('_cursor_total', $countSql,
            'COUNT SQL must wrap original SQL in a subquery');
    }

    public function test_counted_cursor_count_method_has_user_params_only(): void
    {
        // @counted without @searchable — signature has user params but no $criteria/$after/$before/$limit
        $q    = $this->analyze(
            "-- @name ListByUser\n-- @class Order\n-- @cursor created_at DESC, id DESC\n" .
            "-- @counted\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders WHERE user_id = :userId ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('function listByUserCount(int $userId): int', $code);
        $countStart  = strpos($code, 'function listByUserCount');
        $countMethod = substr($code, $countStart, 400);
        $this->assertStringNotContainsString('$criteria', $countMethod);
        $this->assertStringNotContainsString('$after', $countMethod);
        $this->assertStringNotContainsString('$before', $countMethod);
        $this->assertStringNotContainsString('$limit', $countMethod);
    }

    public function test_searchable_counted_cursor_count_accepts_criteria(): void
    {
        // @counted + @searchable: Count method must accept $criteria
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n-- @cursor id DESC\n" .
            "-- @counted\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString(
            'function listOrdersCount(?OrderCriteria $criteria = null): int',
            $code,
            '@counted + @searchable must add ?OrderCriteria $criteria to Count method'
        );
    }

    public function test_searchable_counted_cursor_count_uses_filter_clause(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n-- @cursor id DESC\n" .
            "-- @counted\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY id DESC;"
        );
        $code        = $this->code($q);
        $countStart  = strpos($code, 'function listOrdersCount');
        $countMethod = substr($code, $countStart, 800);

        $this->assertStringContainsString('toFilterClause', $countMethod,
            'Searchable count must apply criteria filters via toFilterClause()');
        $this->assertStringContainsString('criteria->bindAll', $countMethod,
            'Searchable count must bind criteria params');
        $this->assertStringNotContainsString('__cursor', $countMethod,
            'Count method must not contain cursor placeholders');
    }

    public function test_searchable_counted_cursor_count_with_user_where(): void
    {
        // @searchable + @counted with existing user WHERE — hasWhere starts true
        $q    = $this->analyze(
            "-- @name ListByUser\n-- @class Order\n-- @searchable\n-- @cursor id DESC\n" .
            "-- @counted\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders WHERE user_id = :userId ORDER BY id DESC;"
        );
        $code        = $this->code($q);
        $countStart  = strpos($code, 'function listByUserCount');
        $countMethod = substr($code, $countStart, 600);

        $this->assertStringContainsString(
            'function listByUserCount(int $userId, ?OrderCriteria $criteria = null): int',
            $code
        );
        $this->assertStringContainsString('hasWhere = true', $countMethod,
            'When user SQL has WHERE, hasWhere must start as true');
    }

    public function test_counted_cursor_count_sql_includes_user_where(): void
    {
        $q    = $this->analyze(
            "-- @name ListByUser\n-- @class Order\n-- @cursor created_at DESC, id DESC\n" .
            "-- @counted\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders WHERE user_id = :userId ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        preg_match('/listByUserCount.*?prepare\(\'(SELECT COUNT[^\']+)\'/s', $code, $m);
        $countSql = $m[1] ?? '';
        $this->assertStringContainsString('WHERE user_id = :userId', $countSql,
            'COUNT SQL must preserve user WHERE filters');
    }

    public function test_counted_cursor_count_sql_includes_optional_where(): void
    {
        $q    = $this->analyze(
            "-- @name ListByStatus\n-- @class Order\n-- @cursor id DESC\n" .
            "-- @counted\n-- @optional status\n-- @returns :cursor\n" .
            "SELECT id, status FROM orders WHERE status = :status ORDER BY id DESC;"
        );
        $code = $this->code($q);
        preg_match('/listByStatusCount.*?prepare\(\'(SELECT COUNT[^\']+)\'/s', $code, $m);
        $countSql = $m[1] ?? '';
        $this->assertStringContainsString(':status_chk IS NULL', $countSql,
            'COUNT SQL must preserve @optional conditions');
    }

    // =========================================================================
    // Code generation — basic cursor (no user params)
    // =========================================================================

    public function test_generated_method_signature_has_after_and_limit(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, user_id, total, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString(
            'function listOrders(?string $after = null, ?string $before = null, int $limit = 20): CursorResult',
            $code
        );
    }

    public function test_generated_sql_has_cursor_where_clause(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, user_id, total, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString(':__cursor_created_at_chk IS NULL', $code);
        $this->assertStringContainsString(':__cursor_created_at', $code);
        $this->assertStringContainsString(':__cursor_id', $code);
        $this->assertStringContainsString('LIMIT :__limit', $code);
    }

    public function test_generated_sql_uses_lt_operator_for_desc(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        // DESC → < operator
        $this->assertMatchesRegularExpression('/id\s+<\s+:__cursor_id/', $code);
    }

    public function test_generated_sql_uses_gt_operator_for_asc(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id ASC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id ASC;"
        );
        $code = $this->code($q);
        // ASC → > operator
        $this->assertMatchesRegularExpression('/id\s+>\s+:__cursor_id/', $code);
    }

    public function test_generated_method_decodes_cursor_token(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('CursorResult::decodeCursor(', $code);
        $this->assertStringContainsString('$__cursor[\'id\'] ?? null', $code);
    }

    public function test_generated_method_encodes_next_cursor(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, total, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('CursorResult::encodeCursor(', $code);
        $this->assertStringContainsString("'created_at' =>", $code);
        $this->assertStringContainsString("'id' =>", $code);
    }

    public function test_generated_method_fetches_limit_plus_one(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('$limit + 1, PDO::PARAM_INT', $code);
    }

    public function test_generated_method_pops_probe_row(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('array_pop($__rows)', $code);
        $this->assertStringContainsString('count($__rows) > $limit', $code);
    }

    public function test_generated_method_returns_cursor_result(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('return new CursorResult(', $code);
        $this->assertStringContainsString('hasMore:', $code);
        $this->assertStringContainsString('hasPrev:', $code);
        $this->assertStringContainsString('nextCursor:', $code);
        $this->assertStringContainsString('prevCursor:', $code);
    }

    public function test_class_imports_cursor_result(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('use SqlcPhp\\Query\\CursorResult;', $code);
    }

    // =========================================================================
    // Code generation — cursor with required param
    // =========================================================================

    public function test_required_param_appears_before_after_in_signature(): void
    {
        $q    = $this->analyze(
            "-- @name ListByUser\n-- @class Order\n-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, user_id, total, created_at FROM orders\n" .
            "WHERE user_id = :userId ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString(
            'function listByUser(int $userId, ?string $after = null, ?string $before = null, int $limit = 20): CursorResult',
            $code
        );
    }

    public function test_required_param_is_bound_before_cursor_params(): void
    {
        $q    = $this->analyze(
            "-- @name ListByUser\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id, user_id FROM orders WHERE user_id = :userId ORDER BY id DESC;"
        );
        $code = $this->code($q);
        // userId binding must appear before cursor binding
        $posUserId = strpos($code, "bindValue(':userId'");
        $posCursor = strpos($code, "bindValue(':__cursor_id'");
        $this->assertNotFalse($posUserId);
        $this->assertNotFalse($posCursor);
        $this->assertLessThan($posCursor, $posUserId);
    }

    // =========================================================================
    // Code generation — cursor with @optional param
    // =========================================================================

    public function test_optional_param_appears_before_after_in_signature(): void
    {
        $q    = $this->analyze(
            "-- @name ListByStatus\n-- @class Order\n-- @cursor created_at DESC, id DESC\n" .
            "-- @optional status\n-- @returns :cursor\n" .
            "SELECT id, total, status, created_at FROM orders\n" .
            "WHERE status = :status ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString(
            'function listByStatus(?string $status = null, ?string $after = null, ?string $before = null, int $limit = 20): CursorResult',
            $code
        );
    }

    public function test_optional_param_cursor_sql_has_both_conditions(): void
    {
        $q    = $this->analyze(
            "-- @name ListByStatus\n-- @class Order\n-- @cursor id DESC\n" .
            "-- @optional status\n-- @returns :cursor\n" .
            "SELECT id, status FROM orders WHERE status = :status ORDER BY id DESC;"
        );
        $code = $this->code($q);
        // Both the optional condition and cursor condition must be in WHERE
        $this->assertStringContainsString(':status_chk IS NULL OR status = :status', $code);
        $this->assertStringContainsString(':__cursor_id_chk IS NULL OR id < :__cursor_id', $code);
    }

    // =========================================================================
    // Code generation — no LIMIT in generated SQL from user SQL
    // =========================================================================

    public function test_user_limit_in_sql_is_stripped_and_replaced(): void
    {
        // If user accidentally includes LIMIT :limit, it should be stripped
        // and replaced by the internal :__limit
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC LIMIT :limit;"
        );
        $code = $this->code($q);
        // Should not have :limit param in signature or bindings
        $this->assertStringNotContainsString("'\$limit, PDO::", $code,
            ':limit must not appear as a bound user param');
        $this->assertStringContainsString('LIMIT :__limit', $code);
    }

    // =========================================================================
    // CursorResult — runtime class
    // =========================================================================

    public function test_cursor_result_encode_decode_roundtrip(): void
    {
        $values  = ['created_at' => '2024-01-15 10:30:00', 'id' => 42];
        $encoded = CursorResult::encodeCursor($values);
        $decoded = CursorResult::decodeCursor($encoded);
        $this->assertSame($values, $decoded);
    }

    public function test_cursor_result_decode_null_returns_null(): void
    {
        $this->assertNull(CursorResult::decodeCursor(null));
    }

    public function test_cursor_result_decode_empty_returns_null(): void
    {
        $this->assertNull(CursorResult::decodeCursor(''));
    }

    public function test_cursor_result_decode_invalid_returns_null(): void
    {
        $this->assertNull(CursorResult::decodeCursor('not-valid-base64!!!'));
    }

    public function test_cursor_result_has_more_and_next_cursor(): void
    {
        $result = new CursorResult(
            items:      [['id' => 1], ['id' => 2]],
            hasMore:    true,
            hasPrev:    false,
            nextCursor: 'abc123',
            prevCursor: null,
        );
        $this->assertTrue($result->hasMore);
        $this->assertFalse($result->hasPrev);
        $this->assertSame('abc123', $result->nextCursor);
        $this->assertNull($result->prevCursor);
        $this->assertCount(2, $result->items);
    }

    public function test_cursor_result_last_page_has_null_next_cursor(): void
    {
        $result = new CursorResult(items: [], hasMore: false, hasPrev: true, nextCursor: null, prevCursor: 'prev123');
        $this->assertFalse($result->hasMore);
        $this->assertTrue($result->hasPrev);
        $this->assertNull($result->nextCursor);
        $this->assertSame('prev123', $result->prevCursor);
    }

    // =========================================================================
    public function test_generated_method_has_before_param(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('?string $before = null', $code);
        $this->assertStringContainsString('$after and $before are mutually exclusive', $code);
    }

    public function test_backward_sql_inverts_operator_and_order(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('(created_at, id) < (', $code);
        $this->assertStringContainsString('ORDER BY created_at DESC, id DESC', $code);
        $this->assertStringContainsString('(created_at, id) > (', $code);
        $this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $code);
    }

    public function test_backward_query_reverses_rows(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('if ($before !== null) $__rows = array_reverse($__rows)', $code);
    }

    public function test_generated_method_sets_prev_cursor_and_has_prev(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString('$__prevCursor', $code);
        $this->assertStringContainsString('$__hasPrev', $code);
        $this->assertStringContainsString('prevCursor: $__prevCursor', $code);
        $this->assertStringContainsString('hasPrev:    $__hasPrev', $code);
    }

    public function test_prev_cursor_built_from_first_row(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);
        $this->assertStringContainsString("\$__firstRow['id']", $code);
    }

    public function test_cursor_result_first_page_has_null_prev_cursor(): void
    {
        $result = new CursorResult(
            items:      [['id' => 1]],
            hasMore:    true,
            hasPrev:    false,
            nextCursor: 'next',
            prevCursor: null,
        );
        $this->assertFalse($result->hasPrev);
        $this->assertNull($result->prevCursor);
    }

    public function test_cursor_result_middle_page_has_both_cursors(): void
    {
        $result = new CursorResult(
            items:      [['id' => 3], ['id' => 2]],
            hasMore:    true,
            hasPrev:    true,
            nextCursor: 'next_token',
            prevCursor: 'prev_token',
        );
        $this->assertTrue($result->hasMore);
        $this->assertTrue($result->hasPrev);
        $this->assertSame('next_token', $result->nextCursor);
        $this->assertSame('prev_token', $result->prevCursor);
    }

    // =========================================================================
    // @searchable + :cursor (v2.11.3)
    // =========================================================================

    public function test_searchable_cursor_is_accepted_by_analyzer(): void
    {
        $this->expectNotToPerformAssertions();
        $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
    }

    public function test_searchable_cursor_generates_criteria_class(): void
    {
        $q     = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $files = $this->qg->generate($q);

        $criteria = null;
        foreach ($files as $f) {
            if (($f['className'] ?? '') === 'OrderCriteria') { $criteria = $f; break; }
        }
        $this->assertNotNull($criteria, 'OrderCriteria must be generated');
    }

    public function test_searchable_cursor_criteria_has_no_order_by_methods(): void
    {
        // orderBy* methods must be absent — ORDER BY is fixed by @cursor
        $q     = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $files = $this->qg->generate($q);

        $criteria = null;
        foreach ($files as $f) {
            if (($f['className'] ?? '') === 'OrderCriteria') { $criteria = $f; break; }
        }

        $this->assertStringNotContainsString('function orderBy', $criteria['code'],
            'Cursor Criteria must NOT expose orderBy() — ORDER BY is fixed by @cursor');
    }

    public function test_searchable_cursor_criteria_has_where_methods(): void
    {
        $q     = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY id DESC;"
        );
        $files = $this->qg->generate($q);

        $criteria = null;
        foreach ($files as $f) {
            if (($f['className'] ?? '') === 'OrderCriteria') { $criteria = $f; break; }
        }

        $this->assertStringContainsString('function where', $criteria['code'],
            'Cursor Criteria must still have where() filter methods');
    }

    public function test_searchable_cursor_criteria_has_cursor_note_in_docblock(): void
    {
        $q     = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $files = $this->qg->generate($q);

        $criteria = null;
        foreach ($files as $f) {
            if (($f['className'] ?? '') === 'OrderCriteria') { $criteria = $f; break; }
        }

        $this->assertStringContainsString('fixed by @cursor', $criteria['code'],
            'Cursor Criteria docblock must explain why orderBy() is absent');
    }

    public function test_searchable_cursor_method_uses_filter_clause_not_to_sql(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id FROM orders ORDER BY id DESC;"
        );
        $code = $this->code($q);

        $this->assertStringContainsString('toFilterClause', $code,
            'Searchable cursor method must use toFilterClause() not toSql()');
        $this->assertStringNotContainsString('toSql()', $code,
            'toSql() does not exist on Criteria — must not be called');
        $this->assertStringNotContainsString('toOrderClause', $code,
            'Cursor ORDER BY is fixed — toOrderClause() must not be called');
    }

    public function test_searchable_cursor_method_signature_has_criteria_before_after(): void
    {
        $q    = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);

        $this->assertStringContainsString(
            'function listOrders(?OrderCriteria $criteria = null, ?string $after = null, ?string $before = null, int $limit = 20): CursorResult',
            $code
        );
    }

    public function test_searchable_cursor_no_user_where_sql_is_valid(): void
    {
        // Regression: when the original SQL has no WHERE clause, criteria filters
        // were being inserted incorrectly causing MySQL syntax errors.
        // The generated SQL must be: SELECT ... WHERE criteria AND (cursor) ORDER BY ...
        $q    = $this->analyze(
            "-- @name ListByCriteria\n-- @class Order\n-- @searchable\n" .
            "-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, user_id, total, created_at FROM orders\n" .
            "ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);

        // The generated SQL assembly must not contain regex-patching of the cursor WHERE
        $this->assertStringNotContainsString('toSql()', $code);
        $this->assertStringNotContainsString('preg_replace', $code,
            'SQL assembly must not use regex — it should be built in layers');

        // Must use toFilterClause for criteria injection
        $this->assertStringContainsString('toFilterClause', $code);

        // The base SQL part must be separable from cursor condition and ORDER BY
        $this->assertStringContainsString('$__baseSql', $code);
    }

    public function test_searchable_cursor_with_user_where_sql_is_valid(): void
    {
        // When original SQL already has WHERE, criteria must append with AND
        $q    = $this->analyze(
            "-- @name ListByUser\n-- @class Order\n-- @searchable\n" .
            "-- @cursor created_at DESC, id DESC\n-- @returns :cursor\n" .
            "SELECT id, user_id, total, created_at FROM orders\n" .
            "WHERE user_id = :userId ORDER BY created_at DESC, id DESC;"
        );
        $code = $this->code($q);

        $this->assertStringContainsString('toFilterClause', $code);
        $this->assertStringNotContainsString('preg_replace', $code);
    }

    public function test_searchable_cursor_criteria_has_allowed_columns_for_validation(): void
    {
        // Regression: cursor Criteria was missing $allowedColumns, which disabled
        // fromArray() column validation — allowing invalid column names through.
        $q     = $this->analyze(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n" .
            "-- @cursor id DESC\n-- @returns :cursor\n" .
            "SELECT id, created_at FROM orders ORDER BY id DESC;"
        );
        $files = $this->qg->generate($q);

        $criteria = null;
        foreach ($files as $f) {
            if (($f['className'] ?? '') === 'OrderCriteria') { $criteria = $f; break; }
        }

        $this->assertStringContainsString(
            'protected array $allowedColumns',
            $criteria['code'],
            'Cursor Criteria must have $allowedColumns to enable fromArray() column validation'
        );
    }

    // Version
    // =========================================================================

    public function test_version_is_2_11_0(): void
    {
        $this->assertSame('2.12.3', \SqlcPhp\Version::VERSION);
    }
}
