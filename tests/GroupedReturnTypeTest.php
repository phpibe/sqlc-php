<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for @returns :grouped + @group_by (v2.19.16).
 *
 * :grouped collapses a flat JOIN result (one row per join) into grouped objects
 * where repeated columns become typed arrays.
 */
class GroupedReturnTypeTest extends TestCase
{
    private SchemaCatalog     $catalog;
    private QueryAnalyzer     $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator    $queryGen;
    private QueryParser       $parser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE profiles (
                id        INT           AUTO_INCREMENT PRIMARY KEY,
                firstname VARCHAR(100)  NOT NULL,
                lastname  VARCHAR(100)  NOT NULL
            );
            CREATE TABLE reserve (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                profile_id INT           NOT NULL,
                total      DECIMAL(10,2) NOT NULL,
                status     VARCHAR(20)   NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $mapper);
        $cr             = new ColumnResolver($this->catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $mapper, $this->catalog);
        $this->queryGen = new QueryGenerator($this->catalog, $mapper, $this->dtoGen, 'App\\Queries');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function grouped(string $sql): array
    {
        return $this->dtoGen->generateGrouped($this->analyze($sql)[0]);
    }

    private function queryCode(string $sql): string
    {
        $q = $this->analyze($sql);
        $r = $this->queryGen->generate($q);
        foreach ($r as $item) {
            if (str_ends_with($item['className'], 'Query')) return $item['code'];
        }
        return '';
    }

    // =========================================================================
    // Parser
    // =========================================================================

    public function test_grouped_return_type_parsed(): void
    {
        $q = $this->analyze(
            "-- @name GetProfiles\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertSame(ReturnType::Grouped, $q[0]->returns);
        $this->assertSame('profiles.id', $q[0]->groupByColumn);
    }

    public function test_grouped_without_group_by_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@group_by/');

        $this->analyze(
            "-- @name GetProfiles\n-- @class P\n-- @returns :grouped\n" .
            "SELECT profiles.id, reserve.id AS rid FROM profiles\n" .
            "LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );
    }

    // =========================================================================
    // Generated DTO — main class
    // =========================================================================

    public function test_main_dto_class_name(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total, reserve.status\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );
        $this->assertSame('GetProfilesWithReservesRow', $r['className']);
    }

    public function test_main_dto_has_scalar_properties(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('public int $id',        $r['code']);
        $this->assertStringContainsString('public string $firstname', $r['code']);
    }

    public function test_main_dto_has_array_property_for_repeated_columns(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('public array $reserve', $r['code']);
        $this->assertStringContainsString('@var GetProfilesWithReservesItem[]', $r['code']);
    }

    public function test_main_dto_has_groupResults_method(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('public static function groupResults(', $r['code']);
    }

    public function test_groupResults_uses_group_by_column_as_key(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString("\$row['id']", $r['code']);
    }

    // =========================================================================
    // Generated DTO — item class
    // =========================================================================

    public function test_item_class_generated(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total, reserve.status\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertSame('GetProfilesWithReservesItem', $r['itemClass']);
        $this->assertNotEmpty($r['itemCode']);
    }

    public function test_item_class_has_repeated_column_properties(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total, reserve.status\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('public int $rid',      $r['itemCode']);
        $this->assertStringContainsString('public float $total',  $r['itemCode']);
        $this->assertStringContainsString('public string $status', $r['itemCode']);
    }

    public function test_item_class_does_not_have_scalar_properties(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringNotContainsString('$id',        $r['itemCode']);
        $this->assertStringNotContainsString('$firstname', $r['itemCode']);
    }

    public function test_item_class_has_fromRow_method(): void
    {
        $r = $this->grouped(
            "-- @name GetProfilesWithReserves\n-- @class P\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('public static function fromRow(', $r['itemCode']);
    }

    // =========================================================================
    // Generated Query method
    // =========================================================================

    public function test_query_method_calls_groupResults(): void
    {
        $code = $this->queryCode(
            "-- @name GetProfilesWithReserves\n-- @class Profiles\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('groupResults($stmt->fetchAll', $code);
    }

    public function test_query_method_return_type_is_array(): void
    {
        $code = $this->queryCode(
            "-- @name GetProfilesWithReserves\n-- @class Profiles\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $this->assertStringContainsString('): array', $code);
    }

    public function test_query_method_uses_fetchAll_not_fetch(): void
    {
        $code = $this->queryCode(
            "-- @name GetProfilesWithReserves\n-- @class Profiles\n-- @returns :grouped\n-- @group_by profiles.id\n" .
            "SELECT profiles.id, profiles.firstname, reserve.id AS rid, reserve.total\n" .
            "FROM profiles LEFT JOIN reserve ON reserve.profile_id = profiles.id;"
        );

        $start = strpos($code, 'function getProfilesWithReserves');
        $end   = strpos($code, "\n    }", $start);
        $body  = substr($code, $start, $end - $start);

        $this->assertStringContainsString('fetchAll', $body);
        $this->assertStringNotContainsString('fetch(PDO', $body);
    }
}
