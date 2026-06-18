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
    // Use statement injection — regression for 2.9.3
    // =========================================================================

    public function test_scoped_dto_namespace_is_deeper_than_query_namespace(): void
    {
        // When scoped_dtos: true, the DTO lives in App\DTOs\Group\Method
        // while the Query class lives in App\Repositories.
        // These are different — so use injection must happen.
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r      = $this->dtoGen->generate($q[0], scoped: true);
        $dtoNs  = $r['namespace'];    // App\DTOs\ReserveBilling\GetDetails (or Billing\GetDetails)
        $baseNs = 'App\\DTOs';

        $this->assertStringStartsWith($baseNs, $dtoNs);
        $this->assertNotSame($baseNs, $dtoNs,
            'Scoped DTO namespace must be deeper than base DTO namespace');
    }

    public function test_embed_shares_namespace_with_parent_dto(): void
    {
        // The parent DTO and all its embeds must share the same namespace.
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: true);

        $dtoNs = $r['namespace'];
        $embedNs = null;
        preg_match('/^namespace\s+([\w\\\\]+);/m', $r['embeds']['BillingReserve']['code'], $m);
        $embedNs = $m[1] ?? null;

        $this->assertSame($dtoNs, $embedNs,
            'Embed class must share the same scoped namespace as its parent DTO — no cross-ns use needed');
    }

    public function test_embed_does_not_import_class_matching_namespace_segment(): void
    {
        // Bug: `preg_match('/\bReserveBilling\b/', $embedCode)` matched the word
        // 'ReserveBilling' inside `namespace ...DTOs\ReserveBilling\GetDetails`
        // causing a spurious `use ...\Models\ReserveBilling` in the embed class.
        // Fix: strip namespace/use/class declaration lines before searching.
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id, reserve.total_price as reserve__total_price\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: true);

        // The embed class lives in namespace ...DTOs\ReserveBilling\GetDetails
        // That namespace contains 'ReserveBilling' as a segment — it must NOT
        // trigger injection of `use ...Models\ReserveBilling` or similar
        $embedCode = $r['embeds']['BillingReserve']['code'];

        // The embed only has int and float properties — it never references ReserveBilling
        $this->assertStringNotContainsString('use ', $embedCode,
            'Embed with no cross-namespace dependencies must have no use statements');
    }

    public function test_model_does_not_import_scoped_dto_with_same_class_name(): void
    {
        // Bug: Models/ReserveBilling.php was getting
        // `use ...DTOs\ReserveBilling\GetDetails\ReserveBilling`
        // because injectUseStatements found 'ReserveBilling' referenced in the file
        // (it's the class name itself). Fix: skip any class whose short name === myClassName.
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r = $this->dtoGen->generate($q[0], scoped: true);

        // The DTO class is ReserveBilling in namespace ...DTOs\ReserveBilling\GetDetails
        // A Model class named ReserveBilling (in ...Models) must NOT import the DTO
        $dtoCls  = $r['className'];               // 'ReserveBilling'
        $dtoNs   = $r['namespace'];               // ...DTOs\ReserveBilling\GetDetails
        $dtoCode = $r['code'];

        // Verify the DTO itself has no self-import
        $this->assertStringNotContainsString(
            "use {$dtoNs}\\{$dtoCls};",
            $dtoCode,
            'DTO must not import itself'
        );
        $this->assertStringNotContainsString(
            'use App\\DTOs\\' . $dtoCls . ';',
            $dtoCode,
            'DTO must not import a class with same short name'
        );
    }

    public function test_scoped_dto_does_not_self_import(): void
    {
        // The DTO ReserveBilling in namespace ...DTOs\ReserveBilling\GetDetails
        // must not have `use ...Models\ReserveBilling` even though a model
        // with the same name exists — they have different FQCNs but same short name.
        $q = $this->analyze(
            "-- @name GetDetails\n-- @class ReserveBilling\n-- @dto ReserveBilling\n" .
            "-- @embed BillingReserve reserve__\n-- @returns :one\n" .
            "SELECT billing.*, reserve.id as reserve__id\n" .
            "FROM billing INNER JOIN reserves reserve ON billing.reserve_id = reserve.id\n" .
            "WHERE billing.id = :id;"
        );

        $r       = $this->dtoGen->generate($q[0], scoped: true);
        $dtoCls  = $r['className']; // 'ReserveBilling'

        // Simulate the toWrite map
        $subdir  = $r['scopeSubdir'];
        $toWrite = [
            "{$subdir}/{$dtoCls}.php" => ['type' => 'dtos', 'code' => $r['code']],
            "{$dtoCls}.php"           => [
                'type' => 'models',
                'code' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nreadonly class {$dtoCls} {}\n",
            ],
        ];

        // Count unique class names to verify no self-import would occur
        $classNames = [];
        foreach ($toWrite as $file => $entry) {
            preg_match('/^(?:readonly\s+)?class\s+(\w+)/m', $entry['code'], $m);
            if (isset($m[1])) $classNames[$file] = $m[1];
        }

        // Both files declare the same short class name — the injector must skip
        // importing one into the other
        $this->assertSame($dtoCls, $classNames["{$subdir}/{$dtoCls}.php"]);
        $this->assertSame($dtoCls, $classNames["{$dtoCls}.php"]);
    }

    public function test_exact_user_scenario_no_self_import_in_dto_or_model(): void
    {
        // Regression: DTO `ReserveBilling` (in ...DTOs\ReserveBilling\GetDetails) must NOT
        // get `use ...Models\ReserveBilling`, and Model `ReserveBilling` (in ...Models) must
        // NOT get `use ...DTOs\...\ReserveBilling`. Both had the same short class name.
        // Also: Query class MUST get `use ...Interface` for the interface it implements.
        $dtoCode = "<?php\ndeclare(strict_types=1);\nnamespace Modules\\Billing\\Database\\DTOs\\ReserveBilling\\GetDetails;\nreadonly class ReserveBilling {\n    public function __construct(public int \$id, public ReserveBillingReserve \$reserve) {}\n    public static function fromRow(array \$row): self { return new self((int)\$row['id'], ReserveBillingReserve::fromRow(\$row)); }\n}";
        $modelCode = "<?php\ndeclare(strict_types=1);\nnamespace Modules\\Billing\\Database\\Models;\nreadonly class ReserveBilling {\n    public function __construct(public int \$id) {}\n    public static function fromRow(array \$row): self { return new self((int)\$row['id']); }\n}";
        $embedCode = "<?php\ndeclare(strict_types=1);\nnamespace Modules\\Billing\\Database\\DTOs\\ReserveBilling\\GetDetails;\nreadonly class ReserveBillingReserve {\n    public function __construct(public int \$id) {}\n    public static function fromRow(array \$row): self { return new self((int)\$row['reserve__id']); }\n}";
        $ifaceCode = "<?php\ndeclare(strict_types=1);\nnamespace Modules\\Billing\\Database\\Contracts;\ninterface ReserveBillingRepositoryInterface {\n    public function getDetails(int \$id): ReserveBilling;\n}";
        $queryCode = "<?php\ndeclare(strict_types=1);\nnamespace Modules\\Billing\\Database\\Repositories;\nuse Closure;\nuse PDO;\nclass ReserveBillingRepository implements ReserveBillingRepositoryInterface\n{\n    public function getDetails(int \$id): ReserveBilling { return ReserveBilling::fromRow([]); }\n}";

        $toWrite = [
            'ReserveBilling.php'                                  => ['type' => 'models',     'code' => $modelCode],
            'ReserveBilling/GetDetails/ReserveBilling.php'        => ['type' => 'dtos',        'code' => $dtoCode],
            'ReserveBilling/GetDetails/ReserveBillingReserve.php' => ['type' => 'dtos',        'code' => $embedCode],
            'ReserveBillingRepositoryInterface.php'               => ['type' => 'interfaces',  'code' => $ifaceCode],
            'ReserveBillingRepository.php'                        => ['type' => 'queries',     'code' => $queryCode],
        ];

        $dtoInjected   = $this->inject($dtoCode,   $toWrite['ReserveBilling/GetDetails/ReserveBilling.php'],        $toWrite);
        $modelInjected = $this->inject($modelCode, $toWrite['ReserveBilling.php'],                                   $toWrite);
        $embedInjected = $this->inject($embedCode, $toWrite['ReserveBilling/GetDetails/ReserveBillingReserve.php'],  $toWrite);
        $queryInjected = $this->inject($queryCode, $toWrite['ReserveBillingRepository.php'],                         $toWrite);

        // DTO must NOT import model with same class name
        $this->assertStringNotContainsString('use Modules\\Billing\\Database\\Models\\ReserveBilling;', $dtoInjected,
            'DTO must not import model with same class name');
        $this->assertStringNotContainsString('use Modules\\Billing\\Database\\DTOs\\ReserveBilling\\GetDetails\\ReserveBilling;', $dtoInjected,
            'DTO must not self-import');

        // Model must NOT import DTO with same class name
        $this->assertStringNotContainsString('use Modules\\Billing\\Database\\DTOs\\ReserveBilling\\GetDetails\\ReserveBilling;', $modelInjected,
            'Model must not import DTO with same class name');

        // Embed with only primitives must have no cross-namespace use
        $this->assertStringNotContainsString('use Modules', $embedInjected,
            'Embed with primitives only must have no cross-namespace use');

        // Query class MUST import the interface it implements
        $this->assertStringContainsString(
            'use Modules\\Billing\\Database\\Contracts\\ReserveBillingRepositoryInterface;',
            $queryInjected,
            'Query class must import the interface it implements'
        );

        // Query class MUST import the scoped DTO it returns
        $this->assertStringContainsString(
            'use Modules\\Billing\\Database\\DTOs\\ReserveBilling\\GetDetails\\ReserveBilling;',
            $queryInjected,
            'Query class must import the scoped DTO it uses as return type'
        );
    }

    private function inject(string $code, array $entry, array $allFiles): string
    {
        if (!preg_match('/^namespace\s+([\w\\\\]+);/m', $code, $nsMatch)) return $code;
        $myNs = $nsMatch[1];
        $myClassName = null;
        if (preg_match('/^(?:readonly\s+)?class\s+(\w+)/m', $code, $clsMatch)) {
            $myClassName = $clsMatch[1];
        }
        // Collect siblings — short names already in $myNs, no use needed
        $siblingNames = [];
        foreach ($allFiles as $fileName => $other) {
            if (!preg_match('/^namespace\s+([\w\\\\]+);/m', $other['code'], $m)) continue;
            if ($m[1] !== $myNs) continue;
            $siblingNames[basename($fileName, '.php')] = true;
        }
        $classToFqcn = [];
        foreach ($allFiles as $fileName => $other) {
            if (!preg_match('/^namespace\s+([\w\\\\]+);/m', $other['code'], $m)) continue;
            $otherNs = $m[1];
            if ($otherNs === $myNs) continue;
            $cls = basename($fileName, '.php');
            if ($cls === $myClassName) continue;
            if (isset($siblingNames[$cls])) continue;   // sibling takes priority
            $classToFqcn[$cls] = $otherNs . '\\' . $cls;
        }
        $bodyOnly = preg_replace('/^namespace\s+[^;]+;\s*/m', '', $code) ?? $code;
        $bodyOnly = preg_replace('/^use\s+[^;]+;\s*/m', '', $bodyOnly) ?? $bodyOnly;
        // Strip ONLY "class ClassName" — keep implements/extends clause for interface detection
        $bodyOnly = preg_replace('/^(?:readonly\s+)?class\s+\w+/m', '', $bodyOnly) ?? $bodyOnly;
        $needed = [];
        foreach ($classToFqcn as $cls => $fqcn) {
            if (preg_match('/\b' . preg_quote($cls, '/') . '\b/', $bodyOnly)) {
                $needed[$fqcn] = true;
            }
        }
        if (empty($needed)) return $code;
        $uses = implode("\n", array_map(fn($fqcn) => "use {$fqcn};", array_keys($needed)));
        return preg_replace('/^(namespace [^;]+;)/m', "$1\n\n{$uses}", $code, 1) ?? $code;
    }

    public function test_sibling_embed_shadows_same_named_model(): void
    {
        // Regression: when a second query is added to the same @class group,
        // the DTO for the first query starts getting spurious `use ...Models\Reserve`
        // because Models/Reserve.php has the same short name as the sibling embed
        // DTOs/ReserveBilling/GetByReserveId/Reserve.php.
        // Fix: siblings (classes in the same namespace as the current file) shadow
        // same-named classes from other namespaces — no use injection for them.
        $dtoCode     = "<?php\ndeclare(strict_types=1);\nnamespace Mod\\DTOs\\ReserveBilling\\GetByReserveId;\nreadonly class ReserveBilling {\n    public function __construct(public Reserve \$reserve) {}\n    public static function fromRow(array \$row): self { return new self(Reserve::fromRow(\$row)); }\n}";
        $embedCode   = "<?php\ndeclare(strict_types=1);\nnamespace Mod\\DTOs\\ReserveBilling\\GetByReserveId;\nreadonly class Reserve {\n    public function __construct(public int \$id) {}\n}";
        $modelCode   = "<?php\ndeclare(strict_types=1);\nnamespace Mod\\Models;\nreadonly class Reserve {\n    public function __construct(public int \$id) {}\n}";
        // Second query in same @class — this is what triggered the bug
        $dto2Code    = "<?php\ndeclare(strict_types=1);\nnamespace Mod\\DTOs\\ReserveBilling\\GetProducts;\nreadonly class ProductRow {\n    public function __construct(public int \$id) {}\n}";

        $toWrite = [
            'ReserveBilling/GetByReserveId/ReserveBilling.php' => ['type' => 'dtos',   'code' => $dtoCode],
            'ReserveBilling/GetByReserveId/Reserve.php'        => ['type' => 'dtos',   'code' => $embedCode],
            'Reserve.php'                                       => ['type' => 'models', 'code' => $modelCode],
            'ReserveBilling/GetProducts/ProductRow.php'        => ['type' => 'dtos',   'code' => $dto2Code],
        ];

        $injected = $this->inject(
            $dtoCode,
            $toWrite['ReserveBilling/GetByReserveId/ReserveBilling.php'],
            $toWrite
        );

        // The sibling Reserve.php in the same namespace shadows Models\Reserve
        // → must NOT inject `use Mod\Models\Reserve`
        $this->assertStringNotContainsString(
            'use Mod\\Models\\Reserve;',
            $injected,
            'Sibling embed Reserve must shadow Models\Reserve — no use injection'
        );
    }

    public function test_non_sibling_different_name_still_injected(): void
    {
        // A class from another namespace whose name does NOT clash with any sibling
        // must still be imported correctly.
        $qryCode   = "<?php\ndeclare(strict_types=1);\nnamespace Mod\\Repos;\nclass FooQuery implements FooQueryInterface\n{\n    public function get(): FooResult { return FooResult::fromRow([]); }\n}";
        $ifaceCode = "<?php\ndeclare(strict_types=1);\nnamespace Mod\\Contracts;\ninterface FooQueryInterface { public function get(): FooResult; }";
        $dtoCode   = "<?php\ndeclare(strict_types=1);\nnamespace Mod\\DTOs;\nreadonly class FooResult {\n    public function __construct(public int \$id) {}\n    public static function fromRow(array \$row): self { return new self(1); }\n}";

        $toWrite = [
            'FooQuery.php'          => ['type' => 'queries',    'code' => $qryCode],
            'FooQueryInterface.php' => ['type' => 'interfaces', 'code' => $ifaceCode],
            'FooResult.php'         => ['type' => 'dtos',       'code' => $dtoCode],
        ];

        $injected = $this->inject(
            $qryCode,
            $toWrite['FooQuery.php'],
            $toWrite
        );

        $this->assertStringContainsString('use Mod\\Contracts\\FooQueryInterface;', $injected);
        $this->assertStringContainsString('use Mod\\DTOs\\FooResult;', $injected);
    }

    public function test_version_is_2_9_4(): void
    {
        $this->assertSame('2.11.1', \SqlcPhp\Version::VERSION);
    }
}
