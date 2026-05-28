<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\InterfaceGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class CountedTest extends TestCase
{
    private SchemaCatalog  $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser    $parser;
    private QueryAnalyzer  $analyzer;
    private ResultDtoGenerator $dtoGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT          AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100) NOT NULL,
                active   TINYINT      NOT NULL DEFAULT 1,
                role_id  INT          NULL
            );
            CREATE TABLE billing_config (
                id          INT     AUTO_INCREMENT PRIMARY KEY,
                country_id  INT     NOT NULL,
                active      TINYINT NOT NULL,
                end_num     INT     NOT NULL,
                expiration_date DATE NOT NULL
            );
            CREATE TABLE memory (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                voucher_number INT NOT NULL,
                voucher_type   VARCHAR(50) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App', $this->mapper);
    }

    private function makeQG(bool $psc = false, bool $interfaces = false): QueryGenerator
    {
        $ig = $interfaces ? new InterfaceGenerator('App') : null;
        return new QueryGenerator(
            $this->catalog, $this->mapper, $this->dtoGen, 'App',
            $interfaces, $ig, $psc,
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function queryCode(string $sql, bool $interfaces = false): string
    {
        $q = $this->analyze($sql);
        return $this->makeQG(false, $interfaces)->generate($q)['UserQuery']['code'];
    }

    // =========================================================================
    // Parser
    // =========================================================================

    public function test_counted_flag_is_parsed(): void
    {
        $queries = $this->parser->parse(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertTrue($queries[0]->counted);
    }

    public function test_counted_false_by_default(): void
    {
        $queries = $this->parser->parse(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertFalse($queries[0]->counted);
    }

    public function test_counted_preserved_through_analyzer(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertTrue($q[0]->counted);
    }

    // =========================================================================
    // Analyzer validation
    // =========================================================================

    public function test_counted_on_many_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@counted.*:many-paginated/');

        $this->analyze(
            "-- @name ListUsers\n-- @counted\n-- @returns :many\nSELECT * FROM users;"
        );
    }

    public function test_counted_on_one_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyze(
            "-- @name GetUser\n-- @counted\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
    }

    public function test_counted_on_exec_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyze(
            "-- @name DelUser\n-- @counted\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"
        );
    }

    public function test_counted_on_many_paginated_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertNotEmpty($q);
    }

    // =========================================================================
    // Generated count method — basics
    // =========================================================================

    public function test_count_method_is_generated(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('function listUsersCount(', $code);
    }

    public function test_count_method_returns_int(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('listUsersCount(): int', $code);
    }

    public function test_count_method_not_generated_without_annotation(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringNotContainsString('Count(', $code);
    }

    public function test_main_method_still_generated_alongside_count(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('function listUsers(', $code);
        $this->assertStringContainsString('function listUsersCount(', $code);
    }

    // =========================================================================
    // Count method SQL — subquery wrapping
    // =========================================================================

    public function test_count_sql_wraps_original_in_subquery(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('SELECT COUNT(*) AS _total FROM (', $code);
        $this->assertStringContainsString(') AS _count_subquery', $code);
    }

    public function test_count_sql_contains_original_from_clause(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('FROM users', $code);
    }

    public function test_count_sql_does_not_contain_limit_offset(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        // Only the main method should have LIMIT/OFFSET binds
        // The count method SQL must NOT contain :limit or :offset
        $countMethodStart = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countMethodStart);
        $countMethodCode = substr($code, $countMethodStart);
        $this->assertStringNotContainsString('bindValue(\':limit\'',  $countMethodCode);
        $this->assertStringNotContainsString('bindValue(\':offset\'', $countMethodCode);
    }

    public function test_count_method_fetches_total_field(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString("_total", $code);
        $this->assertStringContainsString('(int)', $code);
    }

    // =========================================================================
    // Count method — parameters
    // =========================================================================

    public function test_count_method_has_no_limit_offset_params(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE id = :id;"
        );
        // Count method should have $id but not $limit or $offset
        $countPos = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countPos);
        $countSig = substr($code, $countPos, 100);
        $this->assertStringNotContainsString('$limit',  $countSig);
        $this->assertStringNotContainsString('$offset', $countSig);
    }

    public function test_count_method_inherits_user_params(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $this->assertStringContainsString('listUsersCount(int $active)', $code);
    }

    public function test_count_method_with_optional_param_has_default_null(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @optional active\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $countPos = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countPos);
        $countSig = substr($code, $countPos, 80);
        $this->assertStringContainsString('$active = null', $countSig);
    }

    public function test_count_method_with_optional_binds_chk_token(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @optional active\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $countPos = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countPos);
        $countMethodCode = substr($code, $countPos);
        $this->assertStringContainsString("bindValue(':active_chk'", $countMethodCode);
        $this->assertStringContainsString("bindValue(':active'",     $countMethodCode);
    }

    public function test_count_method_with_no_params_has_empty_signature(): void
    {
        $code = $this->queryCode(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('listUsersCount(): int', $code);
    }

    // =========================================================================
    // Complex query with JOIN and GROUP BY
    // =========================================================================

    public function test_counted_with_join_and_group_by(): void
    {
        $q    = $this->analyze(<<<SQL
            -- @name ListBillingConfig
            -- @group BillingConfig
            -- @counted
            -- @optional active
            -- @returns :many-paginated
            SELECT
                billing_config.id,
                billing_config.active,
                IF(MAX(m.voucher_number) >= billing_config.end_num, 0,
                   billing_config.end_num - MAX(m.voucher_number)) as rem_num,
                MAX(m.voucher_number) AS max_voucher_number
            FROM billing_config AS billing_config
            LEFT JOIN memory m ON m.voucher_type = CASE
                WHEN billing_config.country_id = 164 THEN 'factExp'
                ELSE 'factTicket' END
            WHERE billing_config.active = :active
            GROUP BY billing_config.id;
        SQL);

        $code = $this->makeQG()->generate($q)['BillingConfigQuery']['code'];

        $this->assertStringContainsString('function listBillingConfig(',      $code);
        $this->assertStringContainsString('function listBillingConfigCount(',  $code);
        $this->assertStringContainsString('SELECT COUNT(*) AS _total FROM (',  $code);
        $this->assertStringContainsString('GROUP BY billing_config.id',        $code);
        $this->assertStringContainsString('_count_subquery',                   $code);
    }

    // =========================================================================
    // Interface generation
    // =========================================================================

    public function test_interface_includes_count_method(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $qg   = $this->makeQG(false, true);
        $ig   = new InterfaceGenerator('App');
        $iface = $ig->generate('UserQuery', $q, $qg);

        $this->assertStringContainsString('listUsersCount(): int;',  $iface['code']);
        $this->assertStringContainsString('listUsers(',              $iface['code']);
    }

    public function test_interface_count_method_has_no_limit_offset(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        $qg   = $this->makeQG(false, true);
        $ig   = new InterfaceGenerator('App');
        $iface = $ig->generate('UserQuery', $q, $qg);

        // Count signature: listUsersCount(int $active): int; — no $limit, no $offset
        $this->assertStringContainsString('listUsersCount(int $active): int;', $iface['code']);
    }

    public function test_interface_without_counted_has_no_count_method(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $qg   = $this->makeQG(false, true);
        $ig   = new InterfaceGenerator('App');
        $iface = $ig->generate('UserQuery', $q, $qg);

        $this->assertStringNotContainsString('Count(', $iface['code']);
    }

    // =========================================================================
    // Prepared statement cache + @counted
    // =========================================================================

    public function test_count_method_uses_stmts_cache_when_enabled(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $code = $this->makeQG(psc: true)->generate($q)['UserQuery']['code'];
        $countPos = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countPos);
        $this->assertStringContainsString('$this->stmts[__FUNCTION__]', substr($code, $countPos));
    }
}
