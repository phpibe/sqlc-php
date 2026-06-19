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
 * Tests for the @type annotation — explicit PHP type override for result columns.
 *
 * Syntax: -- @type alias phpType
 *
 * Primary use cases:
 *   1. UNION queries — second branch may have a different type (NULL, expression, etc.)
 *   2. Constant discriminators — 'user' as role (constant string, type unknown to resolver)
 *   3. Complex expressions — price * qty as subtotal (resolver returns 'mixed')
 *   4. Any column where the inferred type is wrong or unknown
 */
class TypeAnnotationTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $qg;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE reserve (
                id         INT              AUTO_INCREMENT PRIMARY KEY,
                product_id INT              NOT NULL,
                active     TINYINT          NOT NULL DEFAULT 1,
                price      DECIMAL(10,2)    NOT NULL
            );
            CREATE TABLE products (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                name  VARCHAR(100) NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                sku   VARCHAR(50)  NULL
            );
            CREATE TABLE reserve_insured_upgrade (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                reserve_id INT NOT NULL,
                upgrade_id INT NOT NULL
            );
            CREATE TABLE users (
                id     INT          AUTO_INCREMENT PRIMARY KEY,
                email  VARCHAR(100) NOT NULL,
                active TINYINT      NOT NULL DEFAULT 1
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

    private function colType(array $query, string $alias): ?string
    {
        foreach ($query[0]->resultColumns as $c) {
            if ($c->alias === $alias) return $c->phpType;
        }
        return null;
    }

    // =========================================================================
    // Parser — @type annotation is parsed correctly
    // =========================================================================

    public function test_parser_reads_type_annotation(): void
    {
        $defs = $this->parser->parse(
            "-- @name GetItems\n-- @class Res\n-- @returns :many\n" .
            "-- @type role string\n" .
            "SELECT users.id, users.email FROM users;"
        );
        $this->assertSame(['role' => 'string'], $defs[0]->typeOverrides);
    }

    public function test_parser_reads_multiple_type_annotations(): void
    {
        $defs = $this->parser->parse(
            "-- @name GetItems\n-- @class Res\n-- @returns :many\n" .
            "-- @type role string\n" .
            "-- @type total ?float\n" .
            "-- @type active bool\n" .
            "SELECT users.id FROM users;"
        );
        $this->assertSame([
            'role'   => 'string',
            'total'  => '?float',
            'active' => 'bool',
        ], $defs[0]->typeOverrides);
    }

    public function test_parser_type_annotations_empty_by_default(): void
    {
        $defs = $this->parser->parse(
            "-- @name GetItems\n-- @class Res\n-- @returns :many\nSELECT * FROM users;"
        );
        $this->assertSame([], $defs[0]->typeOverrides);
    }

    // =========================================================================
    // Analyzer — @type overrides the resolved PHP type
    // =========================================================================

    public function test_type_override_changes_inferred_type(): void
    {
        // products.price is DECIMAL(10,2) → float by default
        // @type price string forces it to string
        $q = $this->analyze(
            "-- @name GetProducts\n-- @class Res\n-- @dto Row\n" .
            "-- @type price string\n" .
            "-- @returns :many\n" .
            "SELECT products.id, products.price FROM products WHERE products.id = :id;"
        );
        $this->assertSame('string', $this->colType($q, 'price'));
    }

    public function test_type_override_nullable_prefix(): void
    {
        $q = $this->analyze(
            "-- @name GetProducts\n-- @class Res\n-- @dto Row\n" .
            "-- @type price ?float\n" .
            "-- @returns :many\n" .
            "SELECT products.id, products.price FROM products WHERE products.id = :id;"
        );
        $this->assertSame('?float', $this->colType($q, 'price'));
    }

    public function test_type_override_bool(): void
    {
        $q = $this->analyze(
            "-- @name GetUsers\n-- @class Res\n-- @dto Row\n" .
            "-- @type active bool\n" .
            "-- @returns :many\n" .
            "SELECT users.id, users.active FROM users;"
        );
        $this->assertSame('bool', $this->colType($q, 'active'));
    }

    public function test_type_override_mixed(): void
    {
        $q = $this->analyze(
            "-- @name GetItems\n-- @class Res\n-- @dto Row\n" .
            "-- @type computed mixed\n" .
            "-- @returns :many\n" .
            "SELECT users.id, users.active as computed FROM users;"
        );
        $this->assertSame('mixed', $this->colType($q, 'computed'));
    }

    public function test_type_override_nullable_sets_nullable_flag(): void
    {
        $q = $this->analyze(
            "-- @name GetProducts\n-- @class Res\n-- @dto Row\n" .
            "-- @type price ?float\n" .
            "-- @returns :many\n" .
            "SELECT products.id, products.price FROM products WHERE products.id = :id;"
        );
        $col = $q[0]->resultColumns[array_search('price',
            array_map(fn($c) => $c->alias, $q[0]->resultColumns))];
        $this->assertTrue($col->nullable);
    }

    public function test_type_override_non_nullable_sets_nullable_false(): void
    {
        $q = $this->analyze(
            "-- @name GetUsers\n-- @class Res\n-- @dto Row\n" .
            "-- @type active bool\n" .
            "-- @returns :many\n" .
            "SELECT users.id, users.active FROM users;"
        );
        $col = $q[0]->resultColumns[array_search('active',
            array_map(fn($c) => $c->alias, $q[0]->resultColumns))];
        $this->assertFalse($col->nullable);
    }

    public function test_type_override_does_not_affect_other_columns(): void
    {
        $q = $this->analyze(
            "-- @name GetProducts\n-- @class Res\n-- @dto Row\n" .
            "-- @type price string\n" .
            "-- @returns :many\n" .
            "SELECT products.id, products.name, products.price FROM products WHERE products.id = :id;"
        );
        // id and name must keep their inferred types
        $this->assertSame('int',    $this->colType($q, 'id'));
        $this->assertSame('string', $this->colType($q, 'name'));
        $this->assertSame('string', $this->colType($q, 'price')); // overridden
    }

    public function test_type_override_for_unknown_alias_is_ignored(): void
    {
        // @type for a column that doesn't exist in the result is silently ignored
        $q = $this->analyze(
            "-- @name GetUsers\n-- @class Res\n-- @dto Row\n" .
            "-- @type nonexistent string\n" .
            "-- @returns :many\n" .
            "SELECT users.id, users.email FROM users;"
        );
        // No error thrown, other columns are unaffected
        $this->assertSame('int',    $this->colType($q, 'id'));
        $this->assertSame('string', $this->colType($q, 'email'));
    }

    // =========================================================================
    // Primary use case: UNION queries with constant discriminators
    // =========================================================================

    public function test_type_union_constant_string_discriminator(): void
    {
        // 'user' and 'admin' are string constants — resolver returns 'mixed'
        // @type role string fixes this
        $q = $this->analyze(
            "-- @name GetAll\n-- @class Res\n-- @dto Row\n" .
            "-- @type role string\n-- @returns :many\n" .
            "SELECT users.id, users.email, 'user' as role FROM users WHERE users.active = :active\n" .
            "UNION ALL\n" .
            "SELECT users.id, users.email, 'admin' as role FROM users WHERE users.active = :active;"
        );
        $this->assertSame('string', $this->colType($q, 'role'));
    }

    public function test_type_union_null_in_second_branch(): void
    {
        // Second branch has NULL for price → should be ?float not float
        $q = $this->analyze(
            "-- @name GetProducts\n-- @class Res\n-- @dto Row\n" .
            "-- @type price ?float\n-- @returns :many\n" .
            "SELECT products.id, products.price FROM reserve\n" .
            "INNER JOIN products ON reserve.product_id = products.id\n" .
            "WHERE reserve.id = :reserveId\n" .
            "UNION ALL\n" .
            "SELECT products.id, NULL\n" .
            "FROM reserve_insured_upgrade\n" .
            "INNER JOIN products ON reserve_insured_upgrade.upgrade_id = products.id\n" .
            "WHERE reserve_insured_upgrade.reserve_id = :reserveId;"
        );
        $this->assertSame('?float', $this->colType($q, 'price'));
    }

    public function test_type_union_multiple_overrides(): void
    {
        $q = $this->analyze(
            "-- @name GetAll\n-- @class Res\n-- @dto Row\n" .
            "-- @type role string\n" .
            "-- @type source string\n" .
            "-- @type amount ?float\n" .
            "-- @returns :many\n" .
            "SELECT users.id, 'direct' as role, 'main' as source, reserve.price as amount\n" .
            "FROM reserve INNER JOIN users ON users.id = reserve.id\n" .
            "WHERE reserve.id = :reserveId\n" .
            "UNION ALL\n" .
            "SELECT users.id, 'upgrade' as role, 'insured' as source, NULL as amount\n" .
            "FROM reserve_insured_upgrade INNER JOIN users ON users.id = reserve_insured_upgrade.id\n" .
            "WHERE reserve_insured_upgrade.reserve_id = :reserveId;"
        );
        $this->assertSame('string', $this->colType($q, 'role'));
        $this->assertSame('string', $this->colType($q, 'source'));
        $this->assertSame('?float', $this->colType($q, 'amount'));
        $this->assertSame('int',    $this->colType($q, 'id')); // unaffected
    }

    // =========================================================================
    // DTO code generation — @type produces correct property types
    // =========================================================================

    private function dtoCode(string $sql): string
    {
        $dtoGen = new ResultDtoGenerator('App\\DTOs', $this->mapper);
        $q      = $this->analyze($sql);
        return $dtoGen->generate($q[0])['code'];
    }

    public function test_type_override_in_dto_code(): void
    {
        $code = $this->dtoCode(
            "-- @name GetProducts\n-- @class Res\n-- @dto ProductRow\n" .
            "-- @type role string\n" .
            "-- @type active bool\n" .
            "-- @returns :many\n" .
            "SELECT products.id, products.price as role, products.sku as active FROM products;"
        );

        $this->assertStringContainsString('public string $role',  $code,
            'DTO must have string $role from @type override');
        $this->assertStringContainsString('public bool $active',  $code,
            'DTO must have bool $active from @type override');
    }

    public function test_type_override_nullable_in_dto_code(): void
    {
        $code = $this->dtoCode(
            "-- @name GetProducts\n-- @class Res\n-- @dto ProductRow\n" .
            "-- @type price ?float\n" .
            "-- @returns :many\n" .
            "SELECT products.id, products.price FROM products;"
        );

        $this->assertStringContainsString('public ?float $price', $code);
    }

    // =========================================================================
    // @type and @nillable can coexist — @type takes full control of the type,
    // @nillable only forces nullable on existing types
    // =========================================================================

    public function test_type_override_takes_precedence_over_nillable(): void
    {
        // @type float on a column makes it non-nullable float
        // even if @nillable is also present for the same column
        $q = $this->analyze(
            "-- @name GetProducts\n-- @class Res\n-- @dto Row\n" .
            "-- @type price float\n" .
            "-- @nillable price\n" .
            "-- @returns :many\n" .
            "SELECT products.id, products.price FROM products;"
        );
        // @type is applied AFTER @nillable — final type is 'float' (not ?float)
        $this->assertSame('float', $this->colType($q, 'price'));
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_9_6(): void
    {
        $this->assertSame('2.12.0', \SqlcPhp\Version::VERSION);
    }
}
