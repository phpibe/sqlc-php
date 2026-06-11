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
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for the table.* expansion and detectDirectModel improvements.
 *
 * Covers the scenario where a query uses `table.*` alongside JOIN columns,
 * including `@embed` patterns where the extra columns have `__` in their alias.
 */
class TableWildcardEmbedTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $qg;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE reserve_billing (
                id                   INT           AUTO_INCREMENT PRIMARY KEY,
                reserve_id           INT           NOT NULL,
                fiscal_type          VARCHAR(50)   NOT NULL,
                zip_code             VARCHAR(20)   NULL,
                fiscal_doc_type      VARCHAR(20)   NULL,
                fiscal_doc_number    VARCHAR(50)   NULL,
                fiscal_social        VARCHAR(100)  NULL,
                fiscal_email         VARCHAR(100)  NULL
            );
            CREATE TABLE reserve (
                id          INT              AUTO_INCREMENT PRIMARY KEY,
                created_at  DATETIME         NOT NULL,
                total_price DECIMAL(10,2)    NOT NULL,
                entity_id   INT              NOT NULL,
                product_id  INT              NOT NULL
            );
            CREATE TABLE products (
                id   INT          AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
            CREATE TABLE customers (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                entity_id  INT          NOT NULL,
                firstname  VARCHAR(100) NOT NULL,
                lastname   VARCHAR(100) NOT NULL
            );
            CREATE TABLE entities (
                id INT AUTO_INCREMENT PRIMARY KEY
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App', $this->mapper);
        $this->qg       = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function signature(string $sql): string
    {
        $q    = $this->analyze($sql);
        $key  = array_key_first($this->qg->generate($q));
        $code = $this->qg->generate($q)[$key]['code'];
        // Match the first query method — skip infrastructure methods
        // (lastQuery, logLastQuery). Query methods come after the class header.
        preg_match_all(
            '/public function (\w+)\([^)]*\):\s*(\S+)/',
            $code,
            $all,
            PREG_SET_ORDER
        );
        // Infrastructure methods to skip
        $skip = ['lastQuery', 'logLastQuery'];
        foreach ($all as $match) {
            if (!in_array($match[1], $skip, true)) {
                return $match[2];
            }
        }
        return 'NOT_FOUND';
    }

    // =========================================================================
    // Baseline — existing behaviour must be unchanged
    // =========================================================================

    public function test_plain_star_single_table_returns_model_directly(): void
    {
        $q = $this->analyze("-- @name GetBilling\n-- @returns :one\nSELECT * FROM reserve_billing WHERE id = :id;");
        $this->assertTrue($q[0]->returnsModelDirectly);
        $this->assertSame('ReserveBilling', $this->signature("-- @name GetBilling\n-- @returns :one\nSELECT * FROM reserve_billing WHERE id = :id;"));
    }

    public function test_table_star_single_table_returns_model_directly(): void
    {
        // reserve_billing.* with no JOINs — should behave identically to SELECT *
        $q = $this->analyze(
            "-- @name GetBilling\n-- @returns :one\n" .
            "SELECT reserve_billing.* FROM reserve_billing WHERE id = :id;"
        );
        $this->assertTrue($q[0]->returnsModelDirectly, 'table.* alone should return model directly');
        $this->assertSame('ReserveBilling', $this->signature(
            "-- @name GetBilling\n-- @returns :one\n" .
            "SELECT reserve_billing.* FROM reserve_billing WHERE id = :id;"
        ));
    }

    public function test_alias_star_single_table_returns_model_directly(): void
    {
        // rb.* with alias — should work the same
        $q = $this->analyze(
            "-- @name GetBilling\n-- @returns :one\n" .
            "SELECT rb.* FROM reserve_billing rb WHERE rb.id = :id;"
        );
        $this->assertTrue($q[0]->returnsModelDirectly);
        $this->assertSame('ReserveBilling', $this->signature(
            "-- @name GetBilling\n-- @returns :one\n" .
            "SELECT rb.* FROM reserve_billing rb WHERE rb.id = :id;"
        ));
    }

    // =========================================================================
    // Core fix — table.* + embedded __ columns should still resolve correctly
    // =========================================================================

    /**
     * The key scenario from the bug report:
     * reserve_billing.* expands all billing columns, plus JOIN columns with __
     * prefix are intended for @embed. The __ columns should NOT prevent
     * detectDirectModel from recognising reserve_billing as the single source.
     *
     * But since @embed IS present, the result needs a DTO (not the plain model)
     * — the DTO will have nested embedded properties. The return TYPE should be
     * the @dto class name.
     */
    public function test_table_star_plus_embed_cols_with_dto_returns_dto_class(): void
    {
        $q = $this->analyze(
            "-- @name GetDetails\n" .
            "-- @class ReserveBilling\n" .
            "-- @dto ReserveBilling\n" .
            "-- @embed ReserveBillingReserve reserve__\n" .
            "-- @returns :one\n" .
            "SELECT reserve_billing.*, reserve.id as reserve__id, reserve.created_at as reserve__created_at\n" .
            "FROM reserve_billing\n" .
            "INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "WHERE reserve_billing.id = :id;"
        );

        // @embed means DTO mode (the model doesn't have nested properties)
        $this->assertFalse($q[0]->returnsModelDirectly,
            'With @embed, result is a DTO — not the plain model');

        // But the DTO class name should be the explicit @dto name
        $this->assertSame('ReserveBilling', $q[0]->dtoClassName);

        // Method return type must be ReserveBilling, not array or GetDetailsRow
        $this->assertSame('ReserveBilling', $this->signature(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n" .
            "-- @embed ReserveBillingReserve reserve__\n-- @returns :one\n" .
            "SELECT reserve_billing.*, reserve.id as reserve__id, reserve.created_at as reserve__created_at\n" .
            "FROM reserve_billing INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "WHERE reserve_billing.id = :id;"
        ));
    }

    /**
     * Full query from the bug report — three @embed annotations.
     */
    public function test_full_bug_report_query(): void
    {
        $q = $this->analyze(
            "-- @name GetDetails\n" .
            "-- @class ReserveBilling\n" .
            "-- @dto ReserveBilling\n" .
            "-- @embed ReserveBillingCustomer customer__\n" .
            "-- @embed ReserveBillingProduct product__\n" .
            "-- @embed ReserveBillingReserve reserve__\n" .
            "-- @returns :one\n" .
            "SELECT reserve_billing.*,\n" .
            "    reserve.id as reserve__id,\n" .
            "    reserve.created_at as reserve__created_at,\n" .
            "    reserve.total_price as reserve__total_price,\n" .
            "    products.id as product__id,\n" .
            "    customers.firstname as customer__firstname\n" .
            "FROM reserve_billing\n" .
            "INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "INNER JOIN entities ON reserve.entity_id = entities.id\n" .
            "INNER JOIN products ON reserve.product_id = products.id\n" .
            "INNER JOIN customers ON customers.entity_id = entities.id\n" .
            "WHERE reserve_billing.reserve_id = :id"
        );

        // All reserve_billing columns must be resolved
        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertContains('id',            $aliases, 'reserve_billing.id must be in result');
        $this->assertContains('reserve_id',    $aliases, 'reserve_billing.reserve_id must be in result');
        $this->assertContains('fiscal_type',   $aliases, 'reserve_billing.fiscal_type must be in result');
        $this->assertContains('zip_code',      $aliases, 'reserve_billing.zip_code must be in result');

        // Embedded columns must also be present
        $this->assertContains('reserve__id',         $aliases);
        $this->assertContains('reserve__created_at', $aliases);
        $this->assertContains('product__id',         $aliases);
        $this->assertContains('customer__firstname',  $aliases);

        // Return type must be ReserveBilling (not array or GetDetailsRow)
        $code = $this->qg->generate($q)['ReserveBillingQuery']['code'];
        $this->assertStringContainsString('): ReserveBilling', $code);
        $this->assertStringNotContainsString('): array', $code);
        $this->assertStringNotContainsString('GetDetailsRow', $code);
    }

    /**
     * table.* + embed __ columns but WITHOUT @dto → auto-named DTO
     */
    public function test_table_star_embed_without_explicit_dto_generates_row_dto(): void
    {
        $q = $this->analyze(
            "-- @name GetDetails\n" .
            "-- @class ReserveBilling\n" .
            "-- @embed ReserveBillingReserve reserve__\n" .
            "-- @returns :one\n" .
            "SELECT reserve_billing.*, reserve.id as reserve__id\n" .
            "FROM reserve_billing\n" .
            "INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "WHERE reserve_billing.id = :id;"
        );

        // No explicit @dto → falls back to auto-name (GetDetailsRow)
        $this->assertNull($q[0]->dtoClassName);
        $this->assertFalse($q[0]->returnsModelDirectly);

        $code = $this->qg->generate($q)['ReserveBillingQuery']['code'];
        // Return type is the auto-named DTO, not array
        $this->assertStringNotContainsString('): array', $code);
        $this->assertStringContainsString('GetDetailsRow', $code);
    }

    // =========================================================================
    // detectDirectModel — embed __ filtering
    // =========================================================================

    public function test_embed_columns_excluded_from_table_uniqueness_check(): void
    {
        // reserve_billing.* + reserve.id as reserve__id
        // Non-embed columns: all from reserve_billing → single table → detect as model
        // But @embed is present → forced to DTO
        $q = $this->analyze(
            "-- @name GetBilling\n" .
            "-- @embed ReserveBillingReserve reserve__\n" .
            "-- @returns :one\n" .
            "SELECT reserve_billing.*, reserve.id as reserve__id\n" .
            "FROM reserve_billing INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "WHERE reserve_billing.id = :id;"
        );

        // With @embed → DTO mode regardless
        $this->assertFalse($q[0]->returnsModelDirectly);
        // Non-embed result columns must all come from reserve_billing
        $nonEmbedCols = array_filter($q[0]->resultColumns, fn($c) => !str_contains($c->alias, '__'));
        $tables = array_unique(array_map(fn($c) => $c->tableName, $nonEmbedCols));
        $this->assertSame(['reserve_billing'], array_values($tables),
            'Non-embed columns must all come from the primary table');
    }

    public function test_mixed_tables_without_embed_prefix_stays_dto(): void
    {
        // JOIN column WITHOUT __ prefix → genuine multi-table result → DTO
        $q = $this->analyze(
            "-- @name GetDetails\n" .
            "-- @returns :one\n" .
            "SELECT reserve_billing.*, reserve.created_at as reserve_created_at\n" .
            "FROM reserve_billing INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "WHERE reserve_billing.id = :id;"
        );

        // 'reserve_created_at' has no __ → multi-table → DTO
        $this->assertFalse($q[0]->returnsModelDirectly,
            'Mixed tables without __ prefix must stay in DTO mode');
    }

    // =========================================================================
    // table.* column expansion — verify all columns are present
    // =========================================================================

    public function test_table_star_expands_all_schema_columns(): void
    {
        $q = $this->analyze(
            "-- @name GetBilling\n-- @returns :one\n" .
            "SELECT reserve_billing.* FROM reserve_billing WHERE id = :id;"
        );

        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertContains('id',                  $aliases);
        $this->assertContains('reserve_id',          $aliases);
        $this->assertContains('fiscal_type',         $aliases);
        $this->assertContains('zip_code',            $aliases);
        $this->assertContains('fiscal_doc_type',     $aliases);
        $this->assertContains('fiscal_doc_number',   $aliases);
        $this->assertContains('fiscal_social',       $aliases);
        $this->assertContains('fiscal_email',        $aliases);
        $this->assertCount(8, $aliases, 'All 8 columns of reserve_billing must be expanded');
    }

    public function test_table_star_preserves_nullability(): void
    {
        $q = $this->analyze(
            "-- @name GetBilling\n-- @returns :one\n" .
            "SELECT reserve_billing.* FROM reserve_billing WHERE id = :id;"
        );

        $byAlias = [];
        foreach ($q[0]->resultColumns as $c) {
            $byAlias[$c->alias] = $c;
        }

        $this->assertFalse($byAlias['id']->nullable,         'id is NOT NULL');
        $this->assertFalse($byAlias['reserve_id']->nullable, 'reserve_id is NOT NULL');
        $this->assertTrue($byAlias['zip_code']->nullable,    'zip_code is NULL');
    }

    public function test_table_star_mixed_with_explicit_cols(): void
    {
        // reserve_billing.* followed by explicit reserve.id — both should resolve
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n-- @returns :one\n" .
            "SELECT reserve_billing.*, reserve.id as res_id\n" .
            "FROM reserve_billing INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "WHERE reserve_billing.id = :id;"
        );

        $aliases = array_map(fn($c) => $c->alias, $q[0]->resultColumns);
        $this->assertContains('reserve_id', $aliases, 'reserve_billing columns must be expanded');
        $this->assertContains('res_id',     $aliases, 'explicit reserve.id must be present');
    }

    public function test_table_star_column_types_are_correct(): void
    {
        $q = $this->analyze(
            "-- @name GetBilling\n-- @returns :one\n" .
            "SELECT reserve_billing.* FROM reserve_billing WHERE id = :id;"
        );

        $byAlias = [];
        foreach ($q[0]->resultColumns as $c) {
            $byAlias[$c->alias] = $c;
        }

        $this->assertSame('int',     $byAlias['id']->phpType);
        $this->assertSame('int',     $byAlias['reserve_id']->phpType);
        $this->assertSame('string',  $byAlias['fiscal_type']->phpType);
        $this->assertSame('?string', $byAlias['zip_code']->phpType);
    }

    // =========================================================================
    // :many with table.* + @embed
    // =========================================================================

    public function test_many_with_table_star_and_embed(): void
    {
        $q = $this->analyze(
            "-- @name ListDetails\n" .
            "-- @class ReserveBilling\n" .
            "-- @dto ReserveBilling\n" .
            "-- @embed ReserveBillingReserve reserve__\n" .
            "-- @returns :many\n" .
            "SELECT reserve_billing.*, reserve.id as reserve__id\n" .
            "FROM reserve_billing INNER JOIN reserve ON reserve_billing.reserve_id = reserve.id\n" .
            "WHERE reserve_billing.fiscal_type = :fiscal_type;"
        );

        $code = $this->qg->generate($q)['ReserveBillingQuery']['code'];
        $this->assertStringContainsString('ReserveBilling[]', $code);
        $this->assertStringNotContainsString('array[]', $code);
    }
}
