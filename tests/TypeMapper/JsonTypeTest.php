<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\TypeMapper;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class JsonTypeTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Type mapping
    // -------------------------------------------------------------------------

    public function test_json_maps_to_array(): void
    {
        $mapper = new MySQLTypeMapper();
        $this->assertSame('array', $mapper->toPhpType('JSON', false));
    }

    public function test_nullable_json_maps_to_nullable_array(): void
    {
        $mapper = new MySQLTypeMapper();
        $this->assertSame('?array', $mapper->toPhpType('JSON', true));
    }

    public function test_json_pdo_param_is_str(): void
    {
        $this->assertSame('PDO::PARAM_STR', (new MySQLTypeMapper())->toPdoParam('JSON'));
    }

    // -------------------------------------------------------------------------
    // ModelGenerator — fromRow casts
    // -------------------------------------------------------------------------

    private function makeModelCode(string $columnDef): string
    {
        $tables  = (new SchemaParser())->parse("CREATE TABLE items ({$columnDef});");
        $catalog = new SchemaCatalog($tables);
        $mapper  = new MySQLTypeMapper();
        $qp      = new QueryParser();
        ['code' => $code] = (new ModelGenerator($catalog, $mapper, $qp, 'App\\Database'))->generate('items');
        return $code;
    }

    public function test_json_not_null_generates_json_decode_with_fallback(): void
    {
        $code = $this->makeModelCode('metadata JSON NOT NULL');

        $this->assertStringContainsString('json_decode(', $code);
        $this->assertStringContainsString('?? []', $code);
    }

    public function test_json_nullable_generates_conditional_json_decode(): void
    {
        $code = $this->makeModelCode('metadata JSON null');

        $this->assertStringContainsString('json_decode(', $code);
        $this->assertStringContainsString('null', $code);
    }

    public function test_json_property_type_is_array(): void
    {
        $code = $this->makeModelCode('metadata JSON NOT NULL');

        $this->assertStringContainsString('array $metadata', $code);
    }

    public function test_nullable_json_property_type_is_nullable_array(): void
    {
        $code = $this->makeModelCode('metadata JSON null');

        $this->assertStringContainsString('?array $metadata', $code);
    }
}
