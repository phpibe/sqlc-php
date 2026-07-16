<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\CriteriaGenerator;
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
 * Tests for the @filter annotation (v2.19.4).
 *
 * @filter allows generating Criteria filter methods for JOIN columns that
 * are NOT in the SELECT list. Methods always carry the table prefix to
 * avoid collision with SELECT-derived methods.
 *
 * Syntax:
 *   -- @filter accounts.email      → specific column
 *   -- @filter accounts.*          → all columns of a table
 *   -- @filter accounts.email, accounts.password  → comma list
 */
class FilterAnnotationTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private CriteriaGenerator $criteriaGen;
    private QueryGenerator  $queryGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE profiles (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                firstname  VARCHAR(100)  NOT NULL,
                lastname   VARCHAR(100)  NOT NULL,
                passport   VARCHAR(50)   NULL,
                created_at DATETIME      NOT NULL
            );
            CREATE TABLE accounts (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                profile_id INT           NOT NULL,
                email      VARCHAR(100)  NOT NULL,
                password   VARCHAR(255)  NULL,
                google_id  VARCHAR(100)  NULL,
                active     TINYINT       NOT NULL DEFAULT 1
            );
            CREATE TABLE reserve (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                profile_id INT           NOT NULL,
                status     VARCHAR(20)   NOT NULL,
                total      DECIMAL(10,2) NOT NULL,
                created_at DATETIME      NOT NULL
            );
        SQL;

        $this->catalog     = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper      = new MySQLTypeMapper();
        $this->parser      = new QueryParser();
        $pr                = new ParamResolver($this->catalog, $this->mapper);
        $er                = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr                = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer    = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->criteriaGen = new CriteriaGenerator('App\\Criterias', $this->mapper, $this->catalog);
        $dtoGen            = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
        $this->queryGen    = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App\\Queries');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function criteria(string $sql): string
    {
        $q = $this->analyze($sql);
        $r = $this->criteriaGen->generate($q[0], $q[0]->resultColumns);
        return $r['code'];
    }

    private function queryCode(string $sql): string
    {
        $q = $this->analyze($sql);
        $r = $this->queryGen->generate($q);
        foreach ($r as $item) {
            if (str_ends_with($item['className'], 'Query')) return $item['code'];
        }
        return array_values($r)[0]['code'];
    }

    // =========================================================================
    // Parser — @filter sets filterColumns correctly
    // =========================================================================

    public function test_filter_specific_column_is_parsed(): void
    {
        $q = $this->analyze(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.email\n" .
            "SELECT profiles.id, profiles.firstname FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        $this->assertCount(1, $q[0]->filterColumns);
        $this->assertSame('accounts', $q[0]->filterColumns[0]['table']);
        $this->assertSame('email',    $q[0]->filterColumns[0]['column']);
        $this->assertFalse($q[0]->filterColumns[0]['all']);
    }

    public function test_filter_wildcard_is_parsed(): void
    {
        $q = $this->analyze(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.*\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        $this->assertCount(1, $q[0]->filterColumns);
        $this->assertSame('accounts', $q[0]->filterColumns[0]['table']);
        $this->assertSame('*',        $q[0]->filterColumns[0]['column']);
        $this->assertTrue($q[0]->filterColumns[0]['all']);
    }

    public function test_filter_comma_list_is_parsed(): void
    {
        $q = $this->analyze(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.email, accounts.password\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        $this->assertCount(2, $q[0]->filterColumns);
        $this->assertSame('email',    $q[0]->filterColumns[0]['column']);
        $this->assertSame('password', $q[0]->filterColumns[1]['column']);
    }

    public function test_filter_multiple_lines_accumulate(): void
    {
        $q = $this->analyze(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.email\n" .
            "-- @filter reserve.status\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        $this->assertCount(2, $q[0]->filterColumns);
        $tables = array_column($q[0]->filterColumns, 'table');
        $this->assertContains('accounts', $tables);
        $this->assertContains('reserve',  $tables);
    }

    // =========================================================================
    // Generated methods — naming convention (table prefix always applied)
    // =========================================================================

    public function test_filter_specific_column_generates_table_prefixed_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.email\n" .
            "SELECT profiles.id, profiles.firstname FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        // Methods must carry table prefix: whereAccountsEmail*
        $this->assertStringContainsString('whereAccountsEmailEq',        $code);
        $this->assertStringContainsString('whereAccountsEmailLike',       $code);
        $this->assertStringContainsString('whereAccountsEmailStartsWith', $code);
        $this->assertStringContainsString('whereAccountsEmailIn',         $code);
    }

    public function test_filter_nullable_column_generates_null_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.password\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        // password is nullable → IsNull / IsNotNull
        $this->assertStringContainsString('whereAccountsPasswordIsNull',    $code);
        $this->assertStringContainsString('whereAccountsPasswordIsNotNull', $code);
    }

    public function test_filter_non_nullable_column_has_no_null_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.email\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        // email is NOT NULL → no IsNull/IsNotNull
        $this->assertStringNotContainsString('whereAccountsEmailIsNull',    $code);
        $this->assertStringNotContainsString('whereAccountsEmailIsNotNull', $code);
    }

    public function test_filter_generates_qualified_sql_column_reference(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.email\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        // The Filter must reference the qualified column: accounts.email
        $this->assertStringContainsString("'accounts.email'", $code);
    }

    // =========================================================================
    // @filter accounts.* — wildcard expands all table columns
    // =========================================================================

    public function test_filter_wildcard_generates_methods_for_all_columns(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.*\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        // accounts has: id, profile_id, email, password, google_id, active
        $this->assertStringContainsString('whereAccountsEmailEq',     $code);
        $this->assertStringContainsString('whereAccountsPasswordIsNull', $code);
        $this->assertStringContainsString('whereAccountsGoogleIdIsNull', $code);
        $this->assertStringContainsString('whereAccountsActiveEq',    $code);
    }

    public function test_filter_wildcard_does_not_duplicate_columns_in_select(): void
    {
        // accounts.email IS in SELECT — @filter accounts.* should still generate
        // whereAccountsEmail* (table-prefixed), NOT whereEmail* (which already exists)
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.*\n" .
            "SELECT profiles.id, profiles.firstname, accounts.email FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        // Both exist without collision
        $this->assertStringContainsString('whereEmailEq',        $code); // from SELECT
        $this->assertStringContainsString('whereAccountsEmailEq', $code); // from @filter
    }

    // =========================================================================
    // Multiple tables
    // =========================================================================

    public function test_filter_multiple_tables_all_generate_prefixed_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.google_id\n" .
            "-- @filter reserve.status\n" .
            "-- @filter reserve.total\n" .
            "SELECT profiles.id, profiles.firstname FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id\n" .
            "LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('whereAccountsGoogleIdIsNull', $code);
        $this->assertStringContainsString('whereReserveStatusEq',        $code);
        $this->assertStringContainsString('whereReserveTotalGte',        $code);
    }

    // =========================================================================
    // Type resolution for @filter columns
    // =========================================================================

    public function test_filter_int_column_generates_comparison_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.id\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('whereAccountsIdEq',  $code);
        $this->assertStringContainsString('whereAccountsIdGte', $code);
        $this->assertStringContainsString('whereAccountsIdIn',  $code);
    }

    public function test_filter_decimal_column_generates_float_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter reserve.total\n" .
            "SELECT profiles.id FROM profiles\n" .
            "LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('whereReserveTotalEq',  $code);
        $this->assertStringContainsString('whereReserveTotalGte', $code);
        $this->assertStringContainsString('whereReserveTotalLte', $code);
    }

    public function test_filter_datetime_column_generates_date_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter reserve.created_at\n" .
            "SELECT profiles.id FROM profiles\n" .
            "LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('whereReserveCreatedAtGt',      $code);
        $this->assertStringContainsString('whereReserveCreatedAtBetween',  $code);
    }

    // =========================================================================
    // Fatal errors — unknown table or column
    // =========================================================================

    public function test_filter_unknown_table_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/table 'nonexistent' not found in schema/");

        $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter nonexistent.email\n" .
            "SELECT profiles.id FROM profiles;"
        );
    }

    public function test_filter_unknown_column_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/column 'ghost' not found in table 'accounts'/");

        $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.ghost\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );
    }

    public function test_filter_unknown_column_error_lists_available_columns(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Available columns:/');

        $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter accounts.ghost\n" .
            "SELECT profiles.id FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );
    }

    public function test_filter_wildcard_unknown_table_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/table 'nonexistent' not found in schema/");

        $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "-- @filter nonexistent.*\n" .
            "SELECT profiles.id FROM profiles;"
        );
    }

    // =========================================================================
    // No @filter — no extra methods
    // =========================================================================

    public function test_no_filter_annotation_generates_no_filter_methods(): void
    {
        $code = $this->criteria(
            "-- @name ListProfiles\n-- @class Profiles\n-- @returns :many\n-- @with criteria\n" .
            "SELECT profiles.id, profiles.firstname FROM profiles\n" .
            "INNER JOIN accounts ON accounts.profile_id = profiles.id;"
        );

        // No table-prefixed methods should exist
        $this->assertStringNotContainsString('whereAccounts', $code);
        $this->assertStringNotContainsString('whereReserve',  $code);
    }
}
