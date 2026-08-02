<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\TypeOverride;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for enum_values in type_overrides (v2.19.13).
 *
 * Allows VARCHAR/CHAR columns to be treated as PHP backed enums by declaring
 * the allowed values in sqlc.yaml:
 *
 *   type_overrides:
 *     - column: pages.section
 *       php_type: SectionEnum
 *       enum_values: [hero, faq, contact]
 */
class EnumValuesOverrideTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private QueryAnalyzer   $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryParser     $parser;
    private EnumGenerator   $enumGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE pages (
                id      INT          AUTO_INCREMENT PRIMARY KEY,
                title   VARCHAR(200) NOT NULL,
                section VARCHAR(20)  NOT NULL,
                status  VARCHAR(20)  NULL
            );
        SQL;

        $override = TypeOverride::fromArray([
            'column'      => 'pages.section',
            'php_type'    => 'SectionEnum',
            'enum_values' => ['hero', 'faq', 'contact'],
        ]);

        $nullableOverride = TypeOverride::fromArray([
            'column'      => 'pages.status',
            'php_type'    => 'PageStatus',
            'enum_values' => ['active', 'draft', 'archived'],
        ]);

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->enumGen  = new EnumGenerator('App\\Enums');
        $mapper         = new MySQLTypeMapper([$override, $nullableOverride], $this->enumGen);
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $mapper);
        $cr             = new ColumnResolver($this->catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $mapper, $this->catalog);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function dto(string $sql): string
    {
        $q = $this->analyze($sql);
        return $this->dtoGen->generate($q[0])['code'];
    }

    // =========================================================================
    // TypeOverride parsing
    // =========================================================================

    public function test_enum_values_parsed_from_array(): void
    {
        $o = TypeOverride::fromArray([
            'column'      => 'pages.section',
            'php_type'    => 'SectionEnum',
            'enum_values' => ['hero', 'faq', 'contact'],
        ]);

        $this->assertTrue($o->isEnumOverride());
        $this->assertSame(['hero', 'faq', 'contact'], $o->enumValues);
        $this->assertSame('SectionEnum', $o->phpType);
    }

    public function test_enum_values_without_php_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/php_type/');

        TypeOverride::fromArray([
            'column'      => 'pages.section',
            'enum_values' => ['hero', 'faq'],
        ]);
    }

    public function test_regular_override_is_not_enum_override(): void
    {
        $o = TypeOverride::fromArray([
            'column'   => 'pages.section',
            'php_type' => 'string',
        ]);
        $this->assertFalse($o->isEnumOverride());
        $this->assertEmpty($o->enumValues);
    }

    // =========================================================================
    // Type resolution — VARCHAR → SectionEnum
    // =========================================================================

    public function test_varchar_column_resolves_to_enum_type(): void
    {
        $q = $this->analyze(
            "-- @name GetPages\n-- @class Pages\n-- @returns :many\n" .
            "SELECT pages.id, pages.title, pages.section FROM pages;"
        );

        $col = array_values(array_filter(
            $q[0]->resultColumns, fn($c) => $c->alias === 'section'
        ))[0];

        $this->assertSame('SectionEnum', $col->phpType);
    }

    public function test_nullable_varchar_column_resolves_to_nullable_enum(): void
    {
        $q = $this->analyze(
            "-- @name GetPages\n-- @class Pages\n-- @returns :many\n" .
            "SELECT pages.id, pages.status FROM pages;"
        );

        $col = array_values(array_filter(
            $q[0]->resultColumns, fn($c) => $c->alias === 'status'
        ))[0];

        // status is NULL in schema → ?PageStatus
        $this->assertSame('?PageStatus', $col->phpType);
    }

    public function test_non_overridden_column_stays_string(): void
    {
        $q = $this->analyze(
            "-- @name GetPages\n-- @class Pages\n-- @returns :many\n" .
            "SELECT pages.id, pages.title FROM pages;"
        );

        $col = array_values(array_filter(
            $q[0]->resultColumns, fn($c) => $c->alias === 'title'
        ))[0];

        $this->assertSame('string', $col->phpType);
    }

    // =========================================================================
    // Generated DTO — fromRow uses enum::from()
    // =========================================================================

    public function test_dto_property_typed_as_enum(): void
    {
        $code = $this->dto(
            "-- @name GetPages\n-- @class Pages\n-- @returns :many\n" .
            "SELECT pages.id, pages.title, pages.section FROM pages;"
        );

        $this->assertStringContainsString('public SectionEnum $section', $code);
    }

    public function test_dto_fromRow_uses_enum_from(): void
    {
        $code = $this->dto(
            "-- @name GetPages\n-- @class Pages\n-- @returns :many\n" .
            "SELECT pages.id, pages.section FROM pages;"
        );

        $this->assertStringContainsString("SectionEnum::from((string) \$row['section'])", $code);
        // Must NOT use (string) cast directly
        $this->assertStringNotContainsString("(string) \$row['section'],", $code);
    }

    public function test_dto_nullable_enum_uses_tryFrom(): void
    {
        $code = $this->dto(
            "-- @name GetPages\n-- @class Pages\n-- @returns :many\n" .
            "SELECT pages.id, pages.status FROM pages;"
        );

        $this->assertStringContainsString('?PageStatus $status', $code);
        $this->assertStringContainsString("PageStatus::tryFrom((string) \$row['status'])", $code);
    }

    // =========================================================================
    // EnumGenerator — generateFromValues()
    // =========================================================================

    public function test_generate_from_values_produces_backed_enum(): void
    {
        $result = $this->enumGen->generateFromValues('SectionEnum', ['hero', 'faq', 'contact']);

        $this->assertSame('SectionEnum', $result['className']);
        $this->assertStringContainsString('enum SectionEnum: string', $result['code']);
        $this->assertStringContainsString("case Hero = 'hero'", $result['code']);
        $this->assertStringContainsString("case Faq = 'faq'", $result['code']);
        $this->assertStringContainsString("case Contact = 'contact'", $result['code']);
    }

    public function test_generate_from_values_uses_enum_namespace(): void
    {
        $result = $this->enumGen->generateFromValues('SectionEnum', ['hero', 'faq']);
        $this->assertStringContainsString('namespace App\\Enums', $result['code']);
    }

    public function test_generate_from_values_hyphenated_case_names(): void
    {
        $result = $this->enumGen->generateFromValues('MyEnum', ['in-progress', 'not-started', 'done']);
        $this->assertStringContainsString("case InProgress = 'in-progress'", $result['code']);
        $this->assertStringContainsString("case NotStarted = 'not-started'", $result['code']);
    }
}
