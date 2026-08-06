<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\InterfaceGenerator;
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
 * Tests for @visibility annotation (v2.19.22).
 *
 * @visibility protected makes the generated method protected instead of public.
 * This enables the pattern:
 *   - SQL raw method: protected insertConfig() — hidden from callers
 *   - Extension trait: public createConfig() — domain logic calling insertConfig()
 */
class VisibilityAnnotationTest extends TestCase
{
    private SchemaCatalog $catalog;
    private QueryAnalyzer $analyzer;
    private QueryGenerator $queryGen;
    private QueryGenerator $queryGenWithInterface;
    private ResultDtoGenerator $dtoGen;
    private QueryParser $parser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE cms_configs (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                country_id INT           NOT NULL,
                page       VARCHAR(100)  NOT NULL,
                config     JSON          NULL
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

        $ifaceGen = new InterfaceGenerator('App\\Contracts');
        $this->queryGen = new QueryGenerator(
            $this->catalog, $mapper, $this->dtoGen, 'App\\Queries'
        );
        $this->queryGenWithInterface = new QueryGenerator(
            $this->catalog, $mapper, $this->dtoGen, 'App\\Queries',
            true, $ifaceGen
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function queryCode(string $sql): string
    {
        $q = $this->analyze($sql);
        foreach ($this->queryGen->generate($q) as $item) {
            if (str_ends_with($item['className'], 'Query')) return $item['code'];
        }
        return '';
    }

    private function interfaceCode(string $sql): string
    {
        $q = $this->analyze($sql);
        foreach ($this->queryGenWithInterface->generateInterfaces($q) as $item) {
            return $item['code'];
        }
        return '';
    }

    // =========================================================================
    // Parser
    // =========================================================================

    public function test_visibility_protected_parsed(): void
    {
        $q = $this->analyze(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @visibility protected\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);"
        );
        $this->assertSame('protected', $q[0]->visibility);
    }

    public function test_visibility_public_parsed(): void
    {
        $q = $this->analyze(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @visibility public\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);"
        );
        $this->assertSame('public', $q[0]->visibility);
    }

    public function test_visibility_defaults_to_public(): void
    {
        $q = $this->analyze(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);"
        );
        $this->assertSame('public', $q[0]->visibility);
    }

    // =========================================================================
    // Generated method visibility
    // =========================================================================

    public function test_protected_method_generated_as_protected(): void
    {
        $code = $this->queryCode(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @visibility protected\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);"
        );
        $this->assertStringContainsString('protected function insertConfig(', $code);
        $this->assertStringNotContainsString('public function insertConfig(', $code);
    }

    public function test_public_method_generated_as_public_by_default(): void
    {
        $code = $this->queryCode(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);"
        );
        $this->assertStringContainsString('public function insertConfig(', $code);
    }

    public function test_protected_and_public_methods_in_same_class(): void
    {
        $code = $this->queryCode(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @visibility protected\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);\n\n" .
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT cms_configs.* FROM cms_configs WHERE id = :id;"
        );
        $this->assertStringContainsString('protected function insertConfig(', $code);
        $this->assertStringContainsString('public function getConfig(', $code);
    }

    // =========================================================================
    // Interface — protected methods excluded
    // =========================================================================

    public function test_protected_method_excluded_from_interface(): void
    {
        $code = $this->interfaceCode(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @visibility protected\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);\n\n" .
            "-- @name GetConfig\n-- @class CmsConfig\n-- @returns :opt\n" .
            "SELECT cms_configs.* FROM cms_configs WHERE id = :id;"
        );
        $this->assertStringNotContainsString('insertConfig', $code);
        $this->assertStringContainsString('getConfig', $code);
    }

    public function test_interface_empty_when_all_methods_protected(): void
    {
        $code = $this->interfaceCode(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @visibility protected\n" .
            "INSERT INTO cms_configs (country_id, page) VALUES (:country_id, :page);"
        );
        // Interface should be empty or not generated
        $this->assertStringNotContainsString('function insertConfig', $code);
    }

    // =========================================================================
    // Params DTO — @internal when method is protected
    // =========================================================================

    public function test_params_dto_has_internal_tag_when_protected(): void
    {
        $q = $this->analyze(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n" .
            "-- @with params\n-- @visibility protected\n" .
            "INSERT INTO cms_configs (country_id, page, config) VALUES (:country_id, :page, :config);"
        );
        $r = $this->dtoGen->generateParams($q[0]);
        $this->assertStringContainsString('@internal', $r['code']);
    }

    public function test_params_dto_no_internal_tag_when_public(): void
    {
        $q = $this->analyze(
            "-- @name InsertConfig\n-- @class CmsConfig\n-- @returns :exec\n" .
            "-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, config) VALUES (:country_id, :page, :config);"
        );
        $r = $this->dtoGen->generateParams($q[0]);
        $this->assertStringNotContainsString('@internal', $r['code']);
    }
}
