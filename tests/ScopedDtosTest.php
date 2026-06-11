<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\Target;
use SqlcPhp\Config\OutputConfig;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for scoped_dtos: true and embed collision detection.
 *
 * The problem: two @embed annotations with the same class name but different
 * columns would silently overwrite each other (last writer wins).
 *
 * Solutions:
 *   1. scoped_dtos: true — each method gets a subdirectory, collisions impossible.
 *   2. scoped_dtos: false (default) — collision detected at generation time, error.
 */
class ScopedDtosTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private ResultDtoGenerator $dtoGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE billing (
                id         INT              AUTO_INCREMENT PRIMARY KEY,
                amount     DECIMAL(10,2)   NOT NULL,
                reserve_id INT              NOT NULL
            );
            CREATE TABLE reserves (
                id              INT              AUTO_INCREMENT PRIMARY KEY,
                total_price     DECIMAL(10,2)   NOT NULL,
                created_at      DATETIME         NOT NULL,
                neighborhood_id INT              NOT NULL
            );
            CREATE TABLE neighborhoods (
                id   INT          AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                city VARCHAR(100) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $this->mapper);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // =========================================================================
    // ResultDtoGenerator — scopedNamespace()
    // =========================================================================

    public function test_scoped_namespace_adds_method_subdir(): void
    {
        $q = $this->analyze("-- @name GetBillingDetails\n-- @class Billing\n-- @returns :one\n-- @dto BillingDetails\nSELECT billing.* FROM billing WHERE id = :id;");
        $ns = $this->dtoGen->scopedNamespace($q[0]);
        $this->assertSame('App\\DTOs\\Billing\\GetBillingDetails', $ns);
    }

    public function test_scoped_namespace_uses_pascal_case_of_method(): void
    {
        $q = $this->analyze("-- @name listBillingByDate\n-- @class Billing\n-- @returns :many\n-- @dto ListBillingByDateRow\nSELECT billing.* FROM billing;");
        $ns = $this->dtoGen->scopedNamespace($q[0]);
        $this->assertSame('App\\DTOs\\Billing\\ListBillingByDate', $ns);
    }

    // =========================================================================
    // ResultDtoGenerator — generate(scoped: true)
    // =========================================================================

    public function test_scoped_generate_uses_scoped_namespace(): void
    {
        $q = $this->analyze(
            "-- @name GetBillingDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: true);

        $this->assertSame('Billing/GetBillingDetails', $r['scopeSubdir']);
        $this->assertStringContainsString('namespace App\\DTOs\\Billing\\GetBillingDetails;', $r['code']);
    }

    public function test_scoped_generate_embeds_use_scoped_namespace(): void
    {
        $q = $this->analyze(
            "-- @name GetBillingDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: true);

        $this->assertArrayHasKey('BillingReserve', $r['embeds']);
        $embedCode = $r['embeds']['BillingReserve']['code'];
        $this->assertStringContainsString('namespace App\\DTOs\\Billing\\GetBillingDetails;', $embedCode,
            'Embed class must use the scoped namespace');
    }

    public function test_non_scoped_generate_uses_flat_namespace(): void
    {
        $q = $this->analyze(
            "-- @name GetBillingDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: false);

        $this->assertNull($r['scopeSubdir']);
        $this->assertStringContainsString('namespace App\\DTOs;', $r['code']);
    }

    public function test_scoped_generate_same_embed_different_queries_different_namespaces(): void
    {
        $q1 = $this->analyze(
            "-- @name GetBillingDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );
        $q2 = $this->analyze(
            "-- @name GetBillingWithDate\n-- @class Billing\n-- @dto BillingWithDate\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.created_at as reserve__created_at\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r1 = $this->dtoGen->generate($q1[0], scoped: true);
        $r2 = $this->dtoGen->generate($q2[0], scoped: true);

        // Same class name — but different namespaces
        $this->assertSame('BillingReserve', $r1['embeds']['BillingReserve']['className']);
        $this->assertSame('BillingReserve', $r2['embeds']['BillingReserve']['className']);

        $ns1 = 'App\\DTOs\\Billing\\GetBillingDetails';
        $ns2 = 'App\\DTOs\\Billing\\GetBillingWithDate';

        $this->assertStringContainsString($ns1, $r1['embeds']['BillingReserve']['code']);
        $this->assertStringContainsString($ns2, $r2['embeds']['BillingReserve']['code']);
        $this->assertStringNotContainsString($ns2, $r1['embeds']['BillingReserve']['code']);
        $this->assertStringNotContainsString($ns1, $r2['embeds']['BillingReserve']['code']);
    }

    public function test_scoped_embed_has_correct_columns_per_query(): void
    {
        $q1 = $this->analyze(
            "-- @name GetBillingDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );
        $q2 = $this->analyze(
            "-- @name GetBillingWithDate\n-- @class Billing\n-- @dto BillingWithDate\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.created_at as reserve__created_at\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r1 = $this->dtoGen->generate($q1[0], scoped: true);
        $r2 = $this->dtoGen->generate($q2[0], scoped: true);

        // GetBillingDetails embed has $id + $total_price
        $embed1Code = $r1['embeds']['BillingReserve']['code'];
        $this->assertStringContainsString('$id', $embed1Code);
        $this->assertStringContainsString('$total_price', $embed1Code);
        $this->assertStringNotContainsString('$created_at', $embed1Code);

        // GetBillingWithDate embed has $id + $created_at
        $embed2Code = $r2['embeds']['BillingReserve']['code'];
        $this->assertStringContainsString('$id', $embed2Code);
        $this->assertStringContainsString('$created_at', $embed2Code);
        $this->assertStringNotContainsString('$total_price', $embed2Code);
    }

    // =========================================================================
    // scopeSubdir is null when scoped: false
    // =========================================================================

    public function test_non_scoped_returns_null_scope_subdir(): void
    {
        $q = $this->analyze(
            "-- @name GetBillingDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: false);
        $this->assertNull($r['scopeSubdir']);
    }

    // =========================================================================
    // Embed collision detection simulation
    // =========================================================================

    public function test_same_embed_name_same_shape_no_collision(): void
    {
        // Two queries with the same @embed class AND same columns → OK
        $q1 = $this->analyze(
            "-- @name GetDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );
        $q2 = $this->analyze(
            "-- @name GetOther\n-- @class Billing\n-- @dto BillingOther\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $allQueries = array_merge($q1, $q2);

        // Simulate collision check
        $registry = [];
        $collisions = [];
        foreach ($allQueries as $q) {
            foreach ($q->embeds as $embed) {
                $cols  = array_filter($q->resultColumns, fn($c) => $embed->matches($c->alias));
                $shape = implode(',', array_map(
                    fn($c) => $embed->stripPrefix($c->alias) . ':' . $c->phpType,
                    $cols
                ));
                if (isset($registry[$embed->className]) && $registry[$embed->className]['shape'] !== $shape) {
                    $collisions[] = $embed->className;
                }
                $registry[$embed->className] ??= ['shape' => $shape, 'queryName' => $q->name];
            }
        }

        $this->assertEmpty($collisions, 'Same embed name with same columns must not collide');
    }

    public function test_same_embed_name_different_shape_detects_collision(): void
    {
        // Two queries with the same @embed class but DIFFERENT columns → collision
        $q1 = $this->analyze(
            "-- @name GetDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );
        $q2 = $this->analyze(
            "-- @name GetWithDate\n-- @class Billing\n-- @dto BillingWithDate\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.created_at as reserve__created_at\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $allQueries = array_merge($q1, $q2);

        $registry   = [];
        $collisions = [];
        foreach ($allQueries as $q) {
            foreach ($q->embeds as $embed) {
                $cols  = array_filter($q->resultColumns, fn($c) => $embed->matches($c->alias));
                $shape = implode(',', array_map(
                    fn($c) => $embed->stripPrefix($c->alias) . ':' . $c->phpType,
                    $cols
                ));
                if (isset($registry[$embed->className]) && $registry[$embed->className]['shape'] !== $shape) {
                    $collisions[] = [
                        'class'     => $embed->className,
                        'existing'  => $registry[$embed->className]['queryName'],
                        'new'       => $q->name,
                    ];
                }
                $registry[$embed->className] ??= ['shape' => $shape, 'queryName' => $q->name];
            }
        }

        $this->assertCount(1, $collisions, 'Must detect one collision');
        $this->assertSame('BillingReserve', $collisions[0]['class']);
        $this->assertSame('getDetails',    $collisions[0]['existing']);
        $this->assertSame('getWithDate',   $collisions[0]['new']);
    }

    // =========================================================================
    // Target::$scopedDtos config parsing
    // =========================================================================

    public function test_scoped_dtos_defaults_to_false(): void
    {
        $target = Target::fromArray([
            'namespace' => 'App\\Database',
            'out'       => 'generated',
            'queries'   => ['queries.sql'],
        ]);
        $this->assertFalse($target->scopedDtos);
    }

    public function test_scoped_dtos_true_parsed(): void
    {
        $target = Target::fromArray([
            'namespace'   => 'App\\Database',
            'out'         => 'generated',
            'queries'     => ['queries.sql'],
            'scoped_dtos' => true,
        ]);
        $this->assertTrue($target->scopedDtos);
    }

    public function test_scoped_dtos_string_true_parsed(): void
    {
        $target = Target::fromArray([
            'namespace'   => 'App\\Database',
            'out'         => 'generated',
            'queries'     => ['queries.sql'],
            'scoped_dtos' => 'true',
        ]);
        $this->assertTrue($target->scopedDtos);
    }

    public function test_scoped_dtos_false_explicit(): void
    {
        $target = Target::fromArray([
            'namespace'   => 'App\\Database',
            'out'         => 'generated',
            'queries'     => ['queries.sql'],
            'scoped_dtos' => false,
        ]);
        $this->assertFalse($target->scopedDtos);
    }

    // =========================================================================
    // Group/Method directory structure — the key 2.9.3 behaviour
    // =========================================================================

    /**
     * The canonical example from the feature request:
     * @class ReserveBilling + @name GetDetails
     * must produce DTOs/ReserveBilling/GetDetails/XXX.php
     * NOT DTOs/GetDetails/XXX.php
     */
    public function test_scoped_path_includes_group_as_parent_dir(): void
    {
        $q = $this->analyze(
            "-- @name     GetDetails\n" .
            "-- @class    ReserveBilling\n" .
            "-- @dto      ReserveBilling\n" .
            "-- @embed    ReserveBillingReserve reserve__\n" .
            "-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: true);

        // scopeSubdir must be Group/Method — not just Method
        $this->assertSame('ReserveBilling/GetDetails', $r['scopeSubdir'],
            'scopeSubdir must be Group/Method, not just Method');

        // Namespace: baseNs\Group\Method
        $this->assertStringContainsString(
            'namespace App\\DTOs\\ReserveBilling\\GetDetails;',
            $r['code']
        );
    }

    public function test_scoped_embed_path_includes_group_as_parent_dir(): void
    {
        $q = $this->analyze(
            "-- @name     GetDetails\n" .
            "-- @class    ReserveBilling\n" .
            "-- @dto      ReserveBilling\n" .
            "-- @embed    ReserveBillingReserve reserve__\n" .
            "-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: true);

        $embedCode = $r['embeds']['ReserveBillingReserve']['code'];

        // Embed lives in same namespace as parent DTO
        $this->assertStringContainsString(
            'namespace App\\DTOs\\ReserveBilling\\GetDetails;',
            $embedCode,
            'Embed class must share the Group/Method namespace'
        );
    }

    public function test_scoped_two_methods_same_group_different_subdirs(): void
    {
        // GetDetails and GetSummary both in ReserveBilling group
        // → ReserveBilling/GetDetails/ and ReserveBilling/GetSummary/
        $q1 = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );
        $q2 = $this->analyze(
            "-- @name GetSummary\n-- @class ReserveBilling\n-- @dto ReserveBillingSummary\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.created_at as reserve__created_at\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r1 = $this->dtoGen->generate($q1[0], scoped: true);
        $r2 = $this->dtoGen->generate($q2[0], scoped: true);

        $this->assertSame('ReserveBilling/GetDetails', $r1['scopeSubdir']);
        $this->assertSame('ReserveBilling/GetSummary', $r2['scopeSubdir']);

        // Different namespaces despite same group
        $this->assertStringContainsString('App\\DTOs\\ReserveBilling\\GetDetails',  $r1['code']);
        $this->assertStringContainsString('App\\DTOs\\ReserveBilling\\GetSummary', $r2['code']);
    }

    public function test_scoped_two_groups_can_have_same_method_name(): void
    {
        // Both groups have "GetDetails" — scoped path uses group prefix to distinguish
        $q1 = $this->analyze(
            "-- @name GetDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );
        $q2 = $this->analyze(
            "-- @name GetDetails\n-- @class Reserve\n-- @dto ReserveDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r1 = $this->dtoGen->generate($q1[0], scoped: true);
        $r2 = $this->dtoGen->generate($q2[0], scoped: true);

        // Different groups → different paths even with same method name
        $this->assertSame('Billing/GetDetails', $r1['scopeSubdir']);
        $this->assertSame('Reserve/GetDetails',  $r2['scopeSubdir']);

        $this->assertStringContainsString('App\\DTOs\\Billing\\GetDetails',  $r1['embeds']['BillingReserve']['code']);
        $this->assertStringContainsString('App\\DTOs\\Reserve\\GetDetails',  $r2['embeds']['BillingReserve']['code']);
    }

    public function test_scoped_namespace_structure_group_then_method(): void
    {
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @returns :one\n" .
            "-- @dto ReserveBilling\nSELECT billing.* FROM billing WHERE id = :id;"
        );

        $ns = $this->dtoGen->scopedNamespace($q[0]);

        // Must be: baseNs\Group\Method
        $parts = explode('\\', $ns);
        $last  = array_slice($parts, -2);
        $this->assertSame(['ReserveBilling', 'GetDetails'], $last,
            'Last two segments must be [Group, MethodPascalCase]');
    }

    public function test_generate_returns_namespace_key(): void
    {
        $q = $this->analyze(
            "-- @name GetBillingDetails\n-- @class Billing\n-- @dto BillingDetails\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $rFlat   = $this->dtoGen->generate($q[0], scoped: false);
        $rScoped = $this->dtoGen->generate($q[0], scoped: true);

        $this->assertSame('App\\DTOs', $rFlat['namespace']);
        $this->assertSame('App\\DTOs\\Billing\\GetBillingDetails', $rScoped['namespace']);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_9_2(): void
    {
        $this->assertSame('2.9.3', \SqlcPhp\Version::VERSION);
    }
}
