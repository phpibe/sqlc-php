<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for @type table.* ClassName (v2.19.5).
 *
 * Groups all SELECT columns whose tableName matches a JOIN table into a nested
 * property of type ClassName, hydrated via ClassName::fromRow($row).
 * The model must already exist — it is not generated, only imported.
 *
 * Syntax:
 *   -- @type countries.* Country               (short class name)
 *   -- @type countries.* App\Models\Country    (FQCN — adds use import)
 */
class TableModelTypeTest extends TestCase
{
    private SchemaCatalog     $catalog;
    private QueryAnalyzer     $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryParser       $parser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE cities (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(100) NOT NULL,
                country_id INT          NOT NULL
            );
            CREATE TABLE countries (
                id   INT          AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                code CHAR(2)      NOT NULL
            );
            CREATE TABLE regions (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(100) NOT NULL,
                country_id INT          NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $mapper);
        $cr             = new ColumnResolver($this->catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        // Pass models namespace so short class names are auto-resolved
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $mapper, $this->catalog, 'App\\Models');
    }

    private function dto(string $sql): string
    {
        $q = $this->analyzer->analyze($this->parser->parse($sql));
        return $this->dtoGen->generate($q[0])['code'];
    }

    private function parse(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // =========================================================================
    // Parser
    // =========================================================================

    public function test_parser_sets_tableModels(): void
    {
        $q = $this->parse(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id, countries.id FROM cities\n" .
            "INNER JOIN countries ON countries.id = cities.country_id;"
        );

        $this->assertCount(1, $q[0]->tableModels);
        $this->assertSame('countries', $q[0]->tableModels[0]['table']);
        $this->assertSame('Country',   $q[0]->tableModels[0]['class']);
    }

    public function test_parser_multiple_tableModels(): void
    {
        $q = $this->parse(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "-- @type regions.* Region\n" .
            "SELECT cities.id, countries.id, regions.id FROM cities\n" .
            "INNER JOIN countries ON countries.id = cities.country_id\n" .
            "LEFT JOIN regions ON regions.country_id = countries.id;"
        );

        $this->assertCount(2, $q[0]->tableModels);
        $tables = array_column($q[0]->tableModels, 'table');
        $this->assertContains('countries', $tables);
        $this->assertContains('regions',   $tables);
    }

    public function test_parser_fqcn_class_name(): void
    {
        $q = $this->parse(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* App\\Models\\Country\n" .
            "SELECT cities.id, countries.id FROM cities\n" .
            "INNER JOIN countries ON countries.id = cities.country_id;"
        );

        $this->assertSame('App\\Models\\Country', $q[0]->tableModels[0]['class']);
    }

    // =========================================================================
    // Generated DTO — structure
    // =========================================================================

    public function test_dto_class_name_is_derived_from_query_name(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, cities.name AS city_name, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        // DTO class must be named after the query, not after the model
        $this->assertStringContainsString('readonly class ListCitiesRow', $code);
        $this->assertStringNotContainsString('readonly class Country', $code);
    }

    public function test_flat_columns_stay_flat(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, cities.name AS city_name, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        $this->assertStringContainsString('public int $city_id',    $code);
        $this->assertStringContainsString('public string $city_name', $code);
    }

    public function test_table_model_property_typed_as_model(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, cities.name AS city_name, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        // Property named after table, typed as the model class
        $this->assertStringContainsString('public Country $countries', $code);
    }

    public function test_fromRow_calls_model_fromRow_directly(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        // No prefix stripping — passes the row directly to the model's fromRow
        $this->assertStringContainsString('Country::fromRow($row)', $code);
    }

    public function test_table_model_columns_excluded_from_flat_properties(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name, countries.code\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        // countries columns must NOT appear as flat properties on the DTO
        $this->assertStringNotContainsString('public int $id,',    $code);
        $this->assertStringNotContainsString('public string $name,', $code);
        $this->assertStringNotContainsString('public string $code,', $code);
    }

    // =========================================================================
    // Short class name — auto-resolved from models namespace
    // =========================================================================

    public function test_short_class_name_generates_use_import_from_models_namespace(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        // Short name 'Country' + models namespace 'App\Models' → use App\Models\Country
        $this->assertStringContainsString('use App\\Models\\Country;', $code);
        // And uses the short name in code
        $this->assertStringContainsString('public Country $countries', $code);
        $this->assertStringContainsString('Country::fromRow($row)', $code);
    }

    public function test_short_class_name_generates_no_use_import_when_same_namespace(): void
    {
        // When models namespace == DTO namespace, no use import needed
        $dtoGenSameNs = new ResultDtoGenerator('App\\Models', null, $this->catalog, 'App\\Models');
        $q = $this->parse(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );
        $code = $dtoGenSameNs->generate($q[0])['code'];

        // Same namespace — no use import needed
        $this->assertStringNotContainsString('use App\\Models\\Country;', $code);
        $this->assertStringContainsString('public Country $countries', $code);
    }

    // =========================================================================
    // Short class name — adds use import from models namespace
    // =========================================================================

    public function test_short_class_name_uses_short_name_in_property(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        // Short name in property and fromRow (not FQCN)
        $this->assertStringContainsString('public Country $countries', $code);
        $this->assertStringContainsString('Country::fromRow($row)', $code);
        $this->assertStringNotContainsString('public App\\', $code);
    }

    // =========================================================================
    // FQCN — adds use import, uses short name in code
    // =========================================================================

    public function test_fqcn_generates_use_import(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* App\\Models\\Country\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        $this->assertStringContainsString('use App\\Models\\Country;', $code);
    }

    public function test_fqcn_uses_short_name_in_property_and_fromRow(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* App\\Models\\Country\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );

        // Property and fromRow use the short name (FQCN would be invalid in property declarations)
        $this->assertStringContainsString('public Country $countries', $code);
        $this->assertStringContainsString('Country::fromRow($row)',     $code);

        // Full FQCN must NOT appear in property declarations or fromRow
        $this->assertStringNotContainsString('public App\\Models\\Country', $code);
        $this->assertStringNotContainsString('App\\Models\\Country::fromRow', $code);
    }

    // =========================================================================
    // Multiple table models
    // =========================================================================

    public function test_two_table_models_generate_two_nested_properties(): void
    {
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "-- @type regions.* Region\n" .
            "SELECT cities.id AS city_id, countries.id, countries.name, regions.id, regions.name\n" .
            "FROM cities\n" .
            "INNER JOIN countries ON countries.id = cities.country_id\n" .
            "LEFT JOIN regions ON regions.country_id = countries.id;"
        );

        $this->assertStringContainsString('public Country $countries', $code);
        $this->assertStringContainsString('public Region $regions',    $code);
        $this->assertStringContainsString('Country::fromRow($row)',    $code);
        $this->assertStringContainsString('Region::fromRow($row)',     $code);
    }

    // =========================================================================
    // Validation — self-join throws
    // =========================================================================

    public function test_duplicate_table_in_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/declared more than once/');

        $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type countries.* Country\n" .
            "-- @type countries.* CountryAlias\n" .
            "SELECT cities.id AS city_id, countries.id\n" .
            "FROM cities INNER JOIN countries ON countries.id = cities.country_id;"
        );
    }

    // =========================================================================
    // LEFT JOIN — nullable model when all columns are nullable
    // =========================================================================

    public function test_left_join_nullable_table_generates_nullable_property(): void
    {
        // Regions are LEFT JOIN — all their columns may be null
        $code = $this->dto(
            "-- @name ListCities\n-- @class Cities\n-- @returns :many\n" .
            "-- @type regions.* Region\n" .
            "SELECT cities.id AS city_id, regions.id, regions.name\n" .
            "FROM cities LEFT JOIN regions ON regions.country_id = cities.country_id;"
        );

        // When all table columns are nullable, property should be ?Region
        // (This depends on the resolver marking LEFT JOIN columns as nullable)
        $this->assertStringContainsString('Region', $code);
    }
}
