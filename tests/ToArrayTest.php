<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\TypeOverride;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for toArray() on generated DTOs and Models (v2.19.18).
 *
 * toArray() rules:
 *   - Scalars (int, string, float, bool, array) → returned as-is
 *   - BackedEnum → ->value (string/int)
 *   - ?BackedEnum → ?->value (null if null)
 *   - DateTimeImmutable → ->format($datetimeFormat)
 *   - ?DateTimeImmutable → ?->format(...)
 *   - Nested DTO → ->toArray() if available, else (array) cast
 */
class ToArrayTest extends TestCase
{
    private SchemaCatalog $catalog;
    private MySQLTypeMapper $mapper;
    private QueryAnalyzer $analyzer;
    private QueryParser $parser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE cms_configs (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                country_id INT           NOT NULL,
                page       VARCHAR(20)   NOT NULL,
                section    VARCHAR(20)   NOT NULL,
                programmed VARCHAR(20)   NOT NULL,
                status     VARCHAR(20)   NULL,
                config     JSON          NULL,
                created_at DATETIME      NOT NULL
            );
        SQL;

        $sectionOverride   = TypeOverride::fromArray([
            'column' => 'cms_configs.section', 'php_type' => 'CmsConfigsSection',
            'enum_values' => ['hero', 'faq'],
        ]);
        $programmedOverride = TypeOverride::fromArray([
            'column' => 'cms_configs.programmed', 'php_type' => 'CmsConfigProgrammed',
            'enum_values' => ['yes', 'no'],
        ]);

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $enumGen        = new EnumGenerator('App\\Enums');
        $this->mapper   = new MySQLTypeMapper([$sectionOverride, $programmedOverride], $enumGen);
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
    }

    private function dto(string $sql, string $datetimeFormat = 'Y-m-d H:i:s'): string
    {
        $dtoGen = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog, null, $datetimeFormat);
        $q = $this->analyzer->analyze($this->parser->parse($sql));
        return $dtoGen->generate($q[0])['code'];
    }

    private function model(string $datetimeFormat = 'Y-m-d H:i:s'): string
    {
        $modelGen = new ModelGenerator($this->catalog, $this->mapper, new \SqlcPhp\Parser\QueryParser(), 'App\\Models', $datetimeFormat);
        return $modelGen->generate('cms_configs')['code'];
    }

    // =========================================================================
    // toArray() generated on DTOs
    // =========================================================================

    public function test_dto_has_toArray_method(): void
    {
        $code = $this->dto(
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT country_id, page, section, created_at FROM cms_configs WHERE id = :id;"
        );
        $this->assertStringContainsString('public function toArray(): array', $code);
    }

    public function test_scalar_columns_returned_as_is(): void
    {
        $code = $this->dto(
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT country_id, page FROM cms_configs WHERE id = :id;"
        );
        $this->assertStringContainsString("'country_id' => \$this->country_id", $code);
        $this->assertStringContainsString("'page' => \$this->page", $code);
    }

    public function test_backed_enum_unwrapped_to_value(): void
    {
        $code = $this->dto(
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT country_id, section, programmed FROM cms_configs WHERE id = :id;"
        );
        $this->assertStringContainsString("'section' => \$this->section->value",    $code);
        $this->assertStringContainsString("'programmed' => \$this->programmed->value", $code);
        // Scalar is NOT unwrapped
        $this->assertStringNotContainsString("country_id->value", $code);
    }

    public function test_nullable_enum_uses_safe_accessor(): void
    {
        $code = $this->dto(
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT country_id, status FROM cms_configs WHERE id = :id;"
        );
        // status is nullable VARCHAR — no enum, so direct
        $this->assertStringContainsString("'status' => \$this->status", $code);
        $this->assertStringNotContainsString("status->value", $code);
    }

    public function test_datetime_formatted_with_default_format(): void
    {
        $code = $this->dto(
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT country_id, created_at FROM cms_configs WHERE id = :id;"
        );
        $this->assertStringContainsString("->format('Y-m-d H:i:s')", $code);
        $this->assertStringNotContainsString('$this->created_at,', $code);
    }

    public function test_datetime_formatted_with_custom_format(): void
    {
        $code = $this->dto(
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT country_id, created_at FROM cms_configs WHERE id = :id;",
            'Y-m-d\TH:i:sP'
        );
        $this->assertStringContainsString("->format('Y-m-d\\\\TH:i:sP')", $code);
    }

    public function test_nullable_json_array_returned_as_is(): void
    {
        $code = $this->dto(
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT country_id, config FROM cms_configs WHERE id = :id;"
        );
        $this->assertStringContainsString("'config' => \$this->config", $code);
        $this->assertStringNotContainsString("config->value", $code);
        $this->assertStringNotContainsString("config->format", $code);
    }

    // =========================================================================
    // toArray() generated on Models
    // =========================================================================

    public function test_model_has_toArray_method(): void
    {
        $code = $this->model();
        $this->assertStringContainsString('public function toArray(): array', $code);
    }

    public function test_model_enum_columns_unwrapped(): void
    {
        $code = $this->model();
        $this->assertStringContainsString("'section' => \$this->section->value",     $code);
        $this->assertStringContainsString("'programmed' => \$this->programmed->value", $code);
    }

    public function test_model_datetime_formatted(): void
    {
        $code = $this->model();
        $this->assertStringContainsString("'created_at' => \$this->created_at->format('Y-m-d H:i:s')", $code);
    }

    public function test_model_custom_datetime_format(): void
    {
        $code = $this->model('d/m/Y');
        $this->assertStringContainsString("->format('d/m/Y')", $code);
    }
}
