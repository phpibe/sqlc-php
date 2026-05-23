<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Generator;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class EnumGeneratorTest extends TestCase
{
    private SchemaParser  $schemaParser;
    private EnumGenerator $enumGen;

    protected function setUp(): void
    {
        $this->schemaParser = new SchemaParser();
        $this->enumGen      = new EnumGenerator('App\\Database');
    }

    // -------------------------------------------------------------------------
    // SchemaParser — ENUM column detection
    // -------------------------------------------------------------------------

    public function test_parser_detects_enum_column(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('pending','completed') NOT NULL);"
        );

        $col = $tables[0]->columns[0];
        $this->assertTrue($col->isEnum());
        $this->assertSame('ENUM', $col->sqlType);
    }

    public function test_parser_extracts_enum_values(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('pending','processing','completed','cancelled') NOT NULL);"
        );

        $col = $tables[0]->columns[0];
        $this->assertSame(['pending', 'processing', 'completed', 'cancelled'], $col->enumValues);
    }

    public function test_non_enum_column_is_not_enum(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE users (status VARCHAR(50) NOT NULL);"
        );

        $this->assertFalse($tables[0]->columns[0]->isEnum());
    }

    public function test_enum_with_hyphenated_values(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE t (status ENUM('in-progress','not-started') NOT NULL);"
        );

        $values = $tables[0]->columns[0]->enumValues;
        $this->assertContains('in-progress', $values);
        $this->assertContains('not-started', $values);
    }

    // -------------------------------------------------------------------------
    // EnumGenerator — class name
    // -------------------------------------------------------------------------

    public function test_enum_class_name_uses_table_and_column(): void
    {
        $this->assertSame('OrderStatus', $this->enumGen->enumClassName('orders', 'status'));
    }

    public function test_enum_class_name_singularises_table(): void
    {
        $this->assertSame('UserRole', $this->enumGen->enumClassName('users', 'role'));
    }

    // -------------------------------------------------------------------------
    // EnumGenerator — code generation
    // -------------------------------------------------------------------------

    public function test_generates_backed_enum(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('pending','completed') NOT NULL);"
        );
        $col = $tables[0]->columns[0];

        ['code' => $code] = $this->enumGen->generate('orders', $col);

        $this->assertStringContainsString('enum OrderStatus: string', $code);
    }

    public function test_generated_enum_has_cases(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('pending','completed') NOT NULL);"
        );
        $col = $tables[0]->columns[0];

        ['code' => $code] = $this->enumGen->generate('orders', $col);

        $this->assertStringContainsString("case Pending = 'pending';", $code);
        $this->assertStringContainsString("case Completed = 'completed';", $code);
    }

    public function test_generated_enum_has_correct_namespace(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('pending','completed') NOT NULL);"
        );
        ['code' => $code] = $this->enumGen->generate('orders', $tables[0]->columns[0]);

        $this->assertStringContainsString('namespace App\\Database;', $code);
    }

    public function test_generated_enum_has_do_not_edit_notice(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('active') NOT NULL);"
        );
        ['code' => $code] = $this->enumGen->generate('orders', $tables[0]->columns[0]);

        $this->assertStringContainsString('do not edit manually', $code);
    }

    public function test_hyphenated_value_becomes_pascal_case_name(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE t (status ENUM('in-progress','not-started') NOT NULL);"
        );
        ['code' => $code] = $this->enumGen->generate('t', $tables[0]->columns[0]);

        $this->assertStringContainsString("case InProgress = 'in-progress';", $code);
        $this->assertStringContainsString("case NotStarted = 'not-started';", $code);
    }

    // -------------------------------------------------------------------------
    // MySQLTypeMapper — ENUM → backed enum class
    // -------------------------------------------------------------------------

    public function test_type_mapper_maps_enum_to_class_name(): void
    {
        $mapper = new MySQLTypeMapper([], $this->enumGen);
        $result = $mapper->toPhpType('ENUM', false, 'orders', 'status');

        $this->assertSame('OrderStatus', $result);
    }

    public function test_type_mapper_maps_nullable_enum(): void
    {
        $mapper = new MySQLTypeMapper([], $this->enumGen);
        $result = $mapper->toPhpType('ENUM', true, 'orders', 'status');

        $this->assertSame('?OrderStatus', $result);
    }

    public function test_type_mapper_enum_without_enum_gen_falls_back_to_string(): void
    {
        $mapper = new MySQLTypeMapper([], null); // no EnumGenerator
        $result = $mapper->toPhpType('ENUM', false, 'orders', 'status');

        $this->assertSame('string', $result);
    }

    // -------------------------------------------------------------------------
    // Model fromRow — ENUM cast
    // -------------------------------------------------------------------------

    public function test_model_generates_enum_from_cast(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('pending','completed') NOT NULL);"
        );
        $catalog  = new SchemaCatalog($tables);
        $mapper   = new MySQLTypeMapper([], $this->enumGen);
        $qp       = new \SqlcPhp\Parser\QueryParser();
        $modelGen = new \SqlcPhp\Generator\ModelGenerator($catalog, $mapper, $qp, 'App\\Database');

        ['code' => $code] = $modelGen->generate('orders');

        $this->assertStringContainsString('OrderStatus::from(', $code);
    }

    public function test_model_generates_nullable_enum_tryfrom_cast(): void
    {
        $tables = $this->schemaParser->parse(
            "CREATE TABLE orders (status ENUM('pending','completed') null);"
        );
        $catalog  = new SchemaCatalog($tables);
        $mapper   = new MySQLTypeMapper([], $this->enumGen);
        $qp       = new \SqlcPhp\Parser\QueryParser();
        $modelGen = new \SqlcPhp\Generator\ModelGenerator($catalog, $mapper, $qp, 'App\\Database');

        ['code' => $code] = $modelGen->generate('orders');

        $this->assertStringContainsString('::tryFrom(', $code);
    }
}
