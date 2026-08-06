<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
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
 * Tests for @with params (v2.19.17).
 *
 * Groups 2+ input parameters into a readonly Params DTO instead of
 * positional arguments, mirroring sqlc Go's XxxParams struct.
 */
class WithParamsTest extends TestCase
{
    private SchemaCatalog     $catalog;
    private QueryAnalyzer     $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator    $queryGen;
    private QueryParser       $parser;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE cms_configs (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                country_id INT           NOT NULL,
                page       VARCHAR(100)  NOT NULL,
                section    VARCHAR(50)   NOT NULL,
                status     VARCHAR(20)   NOT NULL,
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
        $this->queryGen = new QueryGenerator($this->catalog, $mapper, $this->dtoGen, 'App\\Queries');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function params(string $sql): array
    {
        return $this->dtoGen->generateParams($this->analyze($sql)[0]);
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

    public function test_with_params_sets_flag(): void
    {
        $q = $this->analyze(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        $this->assertTrue($q[0]->useParams);
    }

    public function test_with_params_combined_with_returning(): void
    {
        $q = $this->analyze(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :one\n-- @with returning, params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        $this->assertTrue($q[0]->useParams);
        $this->assertTrue($q[0]->returning);
    }

    // =========================================================================
    // Params DTO — class name
    // =========================================================================

    public function test_params_class_name_derived_from_query_name(): void
    {
        $r = $this->params(
            "-- @name CreateCmsConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        $this->assertSame('CreateCmsConfigParams', $r['className']);
    }

    public function test_params_dto_is_readonly_class(): void
    {
        $r = $this->params(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        $this->assertStringContainsString('readonly class CreateConfigParams', $r['code']);
    }

    // =========================================================================
    // Params DTO — properties
    // =========================================================================

    public function test_params_dto_has_all_required_properties(): void
    {
        $r = $this->params(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        $this->assertStringContainsString('public int $country_id',    $r['code']);
        $this->assertStringContainsString('public string $page',       $r['code']);
        $this->assertStringContainsString('public string $section',    $r['code']);
        $this->assertStringContainsString('public string $status',     $r['code']);
    }

    public function test_nullable_param_typed_as_nullable(): void
    {
        $r = $this->params(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status, config)\n" .
            "VALUES (:country_id, :page, :section, :status, :config);"
        );

        // config is JSON NULL in schema → nullable type, required (caller must pass null explicitly)
        $this->assertStringContainsString('public ?array $config', $r['code']);
        // No default — it's nullable but still required
        $this->assertStringNotContainsString('$config = null', $r['code']);
    }

    public function test_optional_param_gets_null_default(): void
    {
        $r = $this->params(
            "-- @name SearchConfigs\n-- @class CmsConfig\n-- @returns :many\n-- @with params\n" .
            "-- @param section ?string:optional\n" .
            "SELECT cms_configs.id, cms_configs.page FROM cms_configs\n" .
            "WHERE country_id = :country_id AND (:section IS NULL OR section = :section);"
        );

        // optional params get = null default in the Params DTO
        $this->assertStringContainsString('public ?string $section = null', $r['code']);
        // required params have no default
        $this->assertStringContainsString('public int $country_id', $r['code']);
        $this->assertStringNotContainsString('$country_id = null', $r['code']);
    }

    // =========================================================================
    // Params DTO — namespace follows dto_scope
    // =========================================================================

    public function test_params_dto_namespace_flat_by_default(): void
    {
        $r = $this->params(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        $this->assertSame('App\\DTOs', $r['namespace']);
        $this->assertNull($r['scopeSubdir']);
    }

    public function test_params_dto_namespace_follows_dto_scope_class(): void
    {
        $dtoGen = new ResultDtoGenerator('App\\DTOs', new MySQLTypeMapper(), $this->catalog);
        $q = $this->analyze(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        $r = $dtoGen->generateParams($q[0], 'class');

        $this->assertSame('App\\DTOs\\CmsConfig', $r['namespace']);
        $this->assertSame('CmsConfig', $r['scopeSubdir']);
    }

    // =========================================================================
    // Query method — uses $params DTO instead of positional args
    // =========================================================================

    public function test_query_method_accepts_params_dto(): void
    {
        $code = $this->queryCode(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        // Method must accept the Params DTO, not 4 positional args
        $this->assertStringContainsString('CreateConfigParams $params', $code);
    }

    public function test_query_method_binds_from_params_properties(): void
    {
        $code = $this->queryCode(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );

        // Bindings must use $params->property, not $propertyName
        $this->assertStringContainsString('$params->country_id', $code);
        $this->assertStringContainsString('$params->page',       $code);
        $this->assertStringNotContainsString('$country_id,',     $code);
    }

    // =========================================================================
    // @with params with 1 param — no effect (rule: 2+ params required)
    // =========================================================================

    public function test_with_params_single_param_still_positional(): void
    {
        $code = $this->queryCode(
            "-- @name DeleteConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "DELETE FROM cms_configs WHERE id = :id;"
        );

        // Only 1 param — @with params has no effect
        $this->assertStringNotContainsString('DeleteConfigParams', $code);
        $this->assertStringContainsString('int $id', $code);
    }

    // =========================================================================
    // Params DTO — static from() method
    // =========================================================================

    public function test_params_dto_has_from_method(): void
    {
        $r = $this->params(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );
        $this->assertStringContainsString('public static function from(array $data): self', $r['code']);
    }

    public function test_from_casts_required_int(): void
    {
        $r = $this->params(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status)\n" .
            "VALUES (:country_id, :page, :section, :status);"
        );
        $this->assertStringContainsString("(int) \$data['country_id']", $r['code']);
    }

    public function test_from_casts_nullable_int_with_null_check(): void
    {
        // Uses @param id ?int to declare a nullable int param
        $r = $this->params(
            "-- @name SearchConfigs\n-- @class CmsConfig\n-- @returns :many\n-- @with params\n" .
            "-- @param country_id ?int\n" .
            "SELECT cms_configs.id, cms_configs.page FROM cms_configs\n" .
            "WHERE (:country_id IS NULL OR country_id = :country_id) AND status = :status;"
        );
        $fromMethod = substr($r['code'], strpos($r['code'], 'public static function from'));
        $this->assertStringContainsString(
            "(\$data['country_id'] ?? null) !== null",
            $fromMethod
        );
    }

    public function test_from_casts_json_accepting_both_array_and_string(): void
    {
        $r = $this->params(
            "-- @name CreateConfig\n-- @class CmsConfig\n-- @returns :exec\n-- @with params\n" .
            "INSERT INTO cms_configs (country_id, page, section, status, config)\n" .
            "VALUES (:country_id, :page, :section, :status, :config);"
        );
        $this->assertStringContainsString('is_string', $r['code']);
        $this->assertStringContainsString('json_decode', $r['code']);
    }
}
