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
 * Tests for CTE (WITH clause) support and universal duplicate placeholder expansion.
 *
 * v2.9.7 additions:
 *   - CTE column resolution: columns from the outer SELECT are resolved against
 *     the CTE's own column set, not the underlying tables.
 *   - Multiple CTEs: each CTE is registered and available to the outer query.
 *   - expandDuplicatePlaceholders is universal — UPSERT, WHERE+HAVING, etc.
 */
class CteDuplicateParamTest extends TestCase
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
                id      INT              AUTO_INCREMENT PRIMARY KEY,
                user_id INT              NOT NULL,
                total   DECIMAL(10,2)   NOT NULL,
                status  VARCHAR(20)     NOT NULL,
                notes   VARCHAR(255)    NULL
            );
            CREATE TABLE users (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL,
                role  VARCHAR(20)  NOT NULL DEFAULT 'user',
                active TINYINT     NOT NULL DEFAULT 1
            );
            CREATE TABLE order_items (
                id       INT            AUTO_INCREMENT PRIMARY KEY,
                order_id INT            NOT NULL,
                price    DECIMAL(10,2)  NOT NULL,
                qty      INT            NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
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

    private function colTypes(array $query): array
    {
        return array_combine(
            array_map(fn($c) => $c->alias, $query[0]->resultColumns),
            array_map(fn($c) => $c->phpType, $query[0]->resultColumns)
        );
    }

    // =========================================================================
    // CTE — basic column resolution
    // =========================================================================

    public function test_cte_explicit_cols_resolve_types(): void
    {
        // WITH paid AS (SELECT id, total FROM orders ...) SELECT paid.id, paid.total
        // CTE exposes explicit columns → outer SELECT resolves their types correctly
        $q = $this->analyze(
            "-- @name GetPaidOrders\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH paid AS (SELECT id, total FROM orders WHERE status = 'paid')\n" .
            "SELECT paid.id, paid.total FROM paid WHERE paid.user_id = :userId;"
        );

        $types = $this->colTypes($q);
        $this->assertSame('int',   $types['id']);
        $this->assertSame('float', $types['total']);
        $this->assertCount(2, $q[0]->resultColumns);
    }

    public function test_cte_star_in_inner_expands_from_schema(): void
    {
        // WITH paid AS (SELECT * FROM orders ...) SELECT paid.id, paid.total
        // CTE's SELECT * expands all orders columns → outer SELECT resolves
        // only the two requested ones with correct types from schema
        $q = $this->analyze(
            "-- @name GetPaidOrders\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH paid AS (SELECT * FROM orders WHERE status = 'paid')\n" .
            "SELECT paid.id, paid.total FROM paid WHERE paid.user_id = :userId;"
        );

        $types = $this->colTypes($q);
        $this->assertSame('int',   $types['id'],    'id must be int');
        $this->assertSame('float', $types['total'],  'total must be float');
        $this->assertCount(2, $q[0]->resultColumns,
            'Only the two explicitly selected columns must appear');
    }

    public function test_cte_outer_star_expands_cte_columns(): void
    {
        // WITH paid AS (SELECT id, total, user_id FROM orders ...) SELECT paid.*
        // → expands to CTE columns (3 columns)
        $q = $this->analyze(
            "-- @name GetAllPaid\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH paid AS (SELECT id, total, user_id FROM orders WHERE status = 'paid')\n" .
            "SELECT paid.* FROM paid WHERE paid.user_id = :userId;"
        );

        $types = $this->colTypes($q);
        $this->assertArrayHasKey('id',      $types);
        $this->assertArrayHasKey('total',   $types);
        $this->assertArrayHasKey('user_id', $types);
        $this->assertSame('int',   $types['id']);
        $this->assertSame('float', $types['total']);
        $this->assertSame('int',   $types['user_id']);
    }

    public function test_cte_nullable_column_preserved(): void
    {
        // notes is NULL in orders schema — must stay nullable through CTE
        $q = $this->analyze(
            "-- @name GetNotes\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH data AS (SELECT id, notes FROM orders WHERE status = 'paid')\n" .
            "SELECT data.id, data.notes FROM data WHERE data.id = :id;"
        );

        $types = $this->colTypes($q);
        $this->assertSame('?string', $types['notes'],
            'Nullable column must stay nullable through CTE resolution');
    }

    // =========================================================================
    // CTE — multiple CTEs
    // =========================================================================

    public function test_multiple_ctes_both_available(): void
    {
        $q = $this->analyze(
            "-- @name GetSummary\n-- @class Order\n-- @dto Summary\n-- @returns :many\n" .
            "WITH paid AS (SELECT id, total FROM orders WHERE status = 'paid'),\n" .
            "     admins AS (SELECT id, email FROM users WHERE role = 'admin')\n" .
            "SELECT paid.id, paid.total, admins.email\n" .
            "FROM paid\n" .
            "INNER JOIN admins ON admins.id = paid.user_id;"
        );

        $types = $this->colTypes($q);
        $this->assertSame('int',    $types['id']);
        $this->assertSame('float',  $types['total']);
        $this->assertSame('string', $types['email']);
    }

    public function test_multiple_ctes_independent_columns(): void
    {
        // Each CTE exposes different columns — no bleed-through
        $q = $this->analyze(
            "-- @name GetCombined\n-- @class Order\n-- @dto Combined\n-- @returns :many\n" .
            "WITH order_data AS (SELECT id, user_id, total FROM orders WHERE status = :status),\n" .
            "     user_data AS (SELECT id, email, active FROM users WHERE active = :active)\n" .
            "SELECT order_data.id, order_data.total, user_data.email, user_data.active\n" .
            "FROM order_data\n" .
            "INNER JOIN user_data ON user_data.id = order_data.user_id;"
        );

        $types = $this->colTypes($q);
        $this->assertSame('int',    $types['id']);
        $this->assertSame('float',  $types['total']);
        $this->assertSame('string', $types['email']);
        $this->assertSame('int',    $types['active']); // TINYINT → int (not bool without override)
        $this->assertCount(4, $q[0]->resultColumns);
    }

    // =========================================================================
    // CTE — params are collected correctly
    // =========================================================================

    public function test_cte_params_resolved_from_inner_and_outer(): void
    {
        $q = $this->analyze(
            "-- @name GetPaidForUser\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH paid AS (SELECT id, total FROM orders WHERE status = 'paid')\n" .
            "SELECT paid.id, paid.total FROM paid WHERE paid.user_id = :userId;"
        );

        $params = array_map(fn($p) => $p->name, $q[0]->params);
        $this->assertContains('userId', $params);
    }

    public function test_cte_with_param_in_inner_query(): void
    {
        $q = $this->analyze(
            "-- @name GetByStatus\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH filtered AS (SELECT id, total FROM orders WHERE status = :status)\n" .
            "SELECT filtered.id, filtered.total FROM filtered WHERE filtered.user_id = :userId;"
        );

        $params = array_map(fn($p) => $p->name, $q[0]->params);
        $this->assertContains('status', $params, 'Param from inner CTE query must be collected');
        $this->assertContains('userId', $params, 'Param from outer query must be collected');
    }

    // =========================================================================
    // CTE — fromTable resolution
    // =========================================================================

    public function test_cte_fromtable_is_cte_name_not_underlying_table(): void
    {
        // Before fix: fromTable was 'orders' (from inside the CTE)
        // After fix: fromTable is 'paid' (the outer FROM)
        $q = $this->analyze(
            "-- @name GetPaidOrders\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH paid AS (SELECT * FROM orders WHERE status = 'paid')\n" .
            "SELECT paid.id, paid.total FROM paid WHERE paid.user_id = :userId;"
        );

        $this->assertSame('paid', $q[0]->fromTable,
            'fromTable must be the CTE name (outer FROM), not the underlying table');
    }

    // =========================================================================
    // CTE — generated code contains original SQL (with WITH clause)
    // =========================================================================

    public function test_cte_generated_code_contains_with_clause(): void
    {
        $q    = $this->analyze(
            "-- @name GetPaidOrders\n-- @class Order\n-- @dto OrderRow\n-- @returns :many\n" .
            "WITH paid AS (SELECT id, total FROM orders WHERE status = 'paid')\n" .
            "SELECT paid.id, paid.total FROM paid WHERE paid.user_id = :userId;"
        );
        $code = $this->qg->generate($q)['OrderQuery']['code'];

        $this->assertStringContainsString('WITH paid AS', $code,
            'Generated code must preserve the WITH clause in the SQL');
        $this->assertStringContainsString('paid.user_id = :userId', $code);
    }

    // =========================================================================
    // CTE — @type can fix column types from CTE
    // =========================================================================

    public function test_cte_type_annotation_overrides_cte_resolved_type(): void
    {
        $q = $this->analyze(
            "-- @name GetPaidOrders\n-- @class Order\n-- @dto OrderRow\n" .
            "-- @type total string\n" .
            "-- @returns :many\n" .
            "WITH paid AS (SELECT id, total FROM orders WHERE status = 'paid')\n" .
            "SELECT paid.id, paid.total FROM paid WHERE paid.user_id = :userId;"
        );

        $types = $this->colTypes($q);
        $this->assertSame('string', $types['total'],
            '@type must override the type resolved from the CTE');
    }

    // =========================================================================
    // Duplicate placeholders — universal (non-UNION)
    // =========================================================================

    public function test_upsert_on_duplicate_key_expands_repeated_params(): void
    {
        $q    = $this->analyze(
            "-- @name UpsertUser\n-- @returns :exec\n" .
            "INSERT INTO users (id, email, active) VALUES (:id, :email, :active)\n" .
            "ON DUPLICATE KEY UPDATE email = :email, active = :active;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];

        // Prepare SQL must rename second occurrences
        $this->assertStringContainsString(':email__2',  $code);
        $this->assertStringContainsString(':active__2', $code);

        // Both original and alias must be bound to the same PHP var
        $this->assertStringContainsString("bindValue(':email', \$email,",     $code);
        $this->assertStringContainsString("bindValue(':email__2', \$email,",  $code);
        $this->assertStringContainsString("bindValue(':active__2', \$active,", $code);
    }

    public function test_upsert_queryobject_has_original_sql(): void
    {
        $q    = $this->analyze(
            "-- @name UpsertUser\n-- @returns :exec\n" .
            "INSERT INTO users (id, email, active) VALUES (:id, :email, :active)\n" .
            "ON DUPLICATE KEY UPDATE email = :email, active = :active;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];

        // QueryObject must get the original SQL (no __2 suffixes)
        $qoPos     = strpos($code, 'new QueryObject(');
        $qoSnippet = substr($code, $qoPos, 300);
        $this->assertStringNotContainsString('email__2', $qoSnippet);
    }

    public function test_param_in_where_and_having_expanded(): void
    {
        $q    = $this->analyze(
            "-- @name GetActive\n-- @returns :many\n" .
            "SELECT id, email FROM users WHERE active = :active GROUP BY id HAVING id >= :active;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString(':active__2', $code);
        $this->assertStringContainsString("bindValue(':active__2', \$active,", $code);
    }

    public function test_single_occurrence_not_renamed(): void
    {
        $q    = $this->analyze(
            "-- @name GetUser\n-- @returns :one\n" .
            "SELECT * FROM users WHERE id = :id AND active = :active;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];

        $this->assertStringNotContainsString(':id__2',     $code);
        $this->assertStringNotContainsString(':active__2', $code);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_9_7(): void
    {
        $this->assertSame('2.12.1', \SqlcPhp\Version::VERSION);
    }
}
