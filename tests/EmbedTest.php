<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EmbedGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\EmbedDefinition;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Resolver\ResolvedColumn;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class EmbedTest extends TestCase
{
    private SchemaCatalog  $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser    $parser;
    private QueryAnalyzer  $analyzer;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator $queryGen;

    private const NS = 'App\\Database';

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT          AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100) NOT NULL,
                username VARCHAR(45)  null
            );
            CREATE TABLE roles (
                id          SMALLINT     AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100) NOT NULL,
                description VARCHAR(255) null
            );
            CREATE TABLE addresses (
                id      INT          AUTO_INCREMENT PRIMARY KEY,
                street  VARCHAR(255) NOT NULL,
                city    VARCHAR(100) NOT NULL,
                country VARCHAR(50)  NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator(self::NS));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter());
        $this->dtoGen   = new ResultDtoGenerator(self::NS);
        $this->queryGen = new QueryGenerator(
            $this->catalog, $this->mapper, $this->dtoGen, self::NS
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // =========================================================================
    // EmbedDefinition value object
    // =========================================================================

    public function test_embed_definition_normalises_prefix_with_underscore(): void
    {
        $embed = new EmbedDefinition('Role', 'role_');
        $this->assertSame('role_', $embed->prefix);
    }

    public function test_embed_definition_property_name_strips_underscore(): void
    {
        $embed = new EmbedDefinition('Role', 'role_');
        $this->assertSame('role', $embed->propertyName());
    }

    public function test_embed_definition_matches_prefixed_alias(): void
    {
        $embed = new EmbedDefinition('Role', 'role_');
        $this->assertTrue($embed->matches('role_name'));
        $this->assertTrue($embed->matches('role_description'));
    }

    public function test_embed_definition_does_not_match_other_alias(): void
    {
        $embed = new EmbedDefinition('Role', 'role_');
        $this->assertFalse($embed->matches('id'));
        $this->assertFalse($embed->matches('email'));
        $this->assertFalse($embed->matches('user_role'));
    }

    public function test_embed_definition_strips_prefix_from_alias(): void
    {
        $embed = new EmbedDefinition('Role', 'role_');
        $this->assertSame('name',        $embed->stripPrefix('role_name'));
        $this->assertSame('description', $embed->stripPrefix('role_description'));
    }

    // =========================================================================
    // QueryParser — @embed annotation
    // =========================================================================

    public function test_parser_captures_single_embed(): void
    {
        $queries = $this->parser->parse(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, roles.name AS role_name FROM users\n" .
            "INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $this->assertCount(1, $queries[0]->embeds);
        $this->assertSame('Role',  $queries[0]->embeds[0]->className);
        $this->assertSame('role_', $queries[0]->embeds[0]->prefix);
    }

    public function test_parser_captures_multiple_embeds(): void
    {
        $queries = $this->parser->parse(
            "-- @name GetFull\n-- @returns :one\n-- @embed Role role_\n-- @embed Address addr_\n" .
            "SELECT users.id, roles.name AS role_name, addresses.street AS addr_street\n" .
            "FROM users\n" .
            "INNER JOIN roles ON roles.id = users.id\n" .
            "INNER JOIN addresses ON addresses.id = users.id\n" .
            "WHERE users.id = :id;"
        );

        $this->assertCount(2, $queries[0]->embeds);
        $this->assertSame('Role',    $queries[0]->embeds[0]->className);
        $this->assertSame('Address', $queries[0]->embeds[1]->className);
    }

    public function test_parser_normalises_prefix_without_trailing_underscore(): void
    {
        // "@embed Role role" (no trailing underscore) should still add it
        $queries = $this->parser->parse(
            "-- @name Get\n-- @returns :one\n-- @embed Role role\n" .
            "SELECT roles.name AS role_name FROM users\n" .
            "INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $this->assertSame('role_', $queries[0]->embeds[0]->prefix);
    }

    public function test_parser_empty_embeds_when_no_annotation(): void
    {
        $queries = $this->parser->parse(
            "-- @name Get\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );

        $this->assertSame([], $queries[0]->embeds);
    }

    // =========================================================================
    // QueryAnalyzer — @embed forces custom DTO
    // =========================================================================

    public function test_embed_forces_custom_dto_not_direct_model(): void
    {
        $queries = $this->analyze(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $this->assertFalse($queries[0]->returnsModelDirectly);
    }

    public function test_embed_annotation_is_preserved_after_analysis(): void
    {
        $queries = $this->analyze(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $this->assertCount(1, $queries[0]->embeds);
        $this->assertSame('Role', $queries[0]->embeds[0]->className);
    }

    // =========================================================================
    // EmbedGenerator — standalone value object generation
    // =========================================================================

    public function test_embed_generator_produces_readonly_class(): void
    {
        $gen   = new EmbedGenerator(self::NS);
        $embed = new EmbedDefinition('Role', 'role_');
        $cols  = [
            new ResolvedColumn('role_name', 'name', 'roles', 'VARCHAR', false, 'string'),
            new ResolvedColumn('role_description', 'description', 'roles', 'VARCHAR', true, '?string'),
        ];

        ['code' => $code] = $gen->generate($embed, $cols);

        $this->assertStringContainsString('readonly class Role', $code);
    }

    public function test_embed_generator_strips_prefix_from_property_names(): void
    {
        $gen   = new EmbedGenerator(self::NS);
        $embed = new EmbedDefinition('Role', 'role_');
        $cols  = [
            new ResolvedColumn('role_name', 'name', 'roles', 'VARCHAR', false, 'string'),
        ];

        ['code' => $code] = $gen->generate($embed, $cols);

        $this->assertStringContainsString('string $name', $code);
        $this->assertStringNotContainsString('$role_name', $code);
    }

    public function test_embed_generator_has_from_row_method(): void
    {
        $gen   = new EmbedGenerator(self::NS);
        $embed = new EmbedDefinition('Role', 'role_');
        $cols  = [
            new ResolvedColumn('role_name', 'name', 'roles', 'VARCHAR', false, 'string'),
        ];

        ['code' => $code] = $gen->generate($embed, $cols);

        $this->assertStringContainsString('public static function fromRow(array $row): self', $code);
    }

    public function test_embed_generator_from_row_uses_original_prefixed_key(): void
    {
        $gen   = new EmbedGenerator(self::NS);
        $embed = new EmbedDefinition('Role', 'role_');
        $cols  = [
            new ResolvedColumn('role_name', 'name', 'roles', 'VARCHAR', false, 'string'),
        ];

        ['code' => $code] = $gen->generate($embed, $cols);

        // fromRow must reference the original prefixed alias key, not the stripped name
        $this->assertStringContainsString("\$row['role_name']", $code);
    }

    public function test_embed_generator_uses_correct_namespace(): void
    {
        $gen   = new EmbedGenerator(self::NS);
        $embed = new EmbedDefinition('Role', 'role_');
        $cols  = [new ResolvedColumn('role_name', 'name', 'roles', 'VARCHAR', false, 'string')];

        ['code' => $code] = $gen->generate($embed, $cols);

        $this->assertStringContainsString('namespace App\\Database;', $code);
    }

    public function test_embed_generator_class_name_matches_annotation(): void
    {
        $gen   = new EmbedGenerator(self::NS);
        $embed = new EmbedDefinition('BillingAddress', 'billing_');
        $cols  = [new ResolvedColumn('billing_street', 'street', 'addresses', 'VARCHAR', false, 'string')];

        ['className' => $name] = $gen->generate($embed, $cols);

        $this->assertSame('BillingAddress', $name);
    }

    // =========================================================================
    // ResultDtoGenerator — @embed in parent DTO
    // =========================================================================

    private function analyzeWithEmbed(string $sql): \SqlcPhp\Parser\QueryDefinition
    {
        return $this->analyze($sql)[0];
    }

    public function test_parent_dto_has_nested_object_property(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        ['code' => $code] = $this->dtoGen->generate($query);

        $this->assertStringContainsString('Role $role', $code);
    }

    public function test_parent_dto_does_not_contain_flat_prefixed_properties(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        ['code' => $code] = $this->dtoGen->generate($query);

        // role_name should NOT appear as a direct property
        $this->assertStringNotContainsString('$role_name', $code);
    }

    public function test_parent_dto_from_row_calls_embed_from_row(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        ['code' => $code] = $this->dtoGen->generate($query);

        $this->assertStringContainsString('Role::fromRow($row)', $code);
    }

    public function test_parent_dto_contains_flat_columns(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        ['code' => $code] = $this->dtoGen->generate($query);

        $this->assertStringContainsString('$id',    $code);
        $this->assertStringContainsString('$email', $code);
    }

    public function test_generate_returns_embed_files(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, roles.name AS role_name, roles.description AS role_description\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $result = $this->dtoGen->generate($query);

        $this->assertArrayHasKey('embeds', $result);
        $this->assertArrayHasKey('Role', $result['embeds']);
    }

    public function test_generate_without_embed_returns_empty_embeds_array(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetJoined\n-- @returns :one\n" .
            "SELECT users.id, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $result = $this->dtoGen->generate($query);

        $this->assertSame([], $result['embeds']);
    }

    public function test_multiple_embeds_each_generate_separate_file(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetFull\n-- @returns :one\n-- @embed Role role_\n-- @embed Address addr_\n" .
            "SELECT users.id, roles.name AS role_name,\n" .
            "       addresses.street AS addr_street, addresses.city AS addr_city\n" .
            "FROM users\n" .
            "INNER JOIN roles ON roles.id = users.id\n" .
            "INNER JOIN addresses ON addresses.id = users.id\n" .
            "WHERE users.id = :id;"
        );

        $result = $this->dtoGen->generate($query);

        $this->assertArrayHasKey('Role',    $result['embeds']);
        $this->assertArrayHasKey('Address', $result['embeds']);
    }

    public function test_multiple_embeds_parent_dto_has_both_properties(): void
    {
        $query = $this->analyzeWithEmbed(
            "-- @name GetFull\n-- @returns :one\n-- @embed Role role_\n-- @embed Address addr_\n" .
            "SELECT users.id, roles.name AS role_name,\n" .
            "       addresses.street AS addr_street, addresses.city AS addr_city\n" .
            "FROM users\n" .
            "INNER JOIN roles ON roles.id = users.id\n" .
            "INNER JOIN addresses ON addresses.id = users.id\n" .
            "WHERE users.id = :id;"
        );

        ['code' => $code] = $this->dtoGen->generate($query);

        $this->assertStringContainsString('Role $role',    $code);
        $this->assertStringContainsString('Address $addr', $code);
    }

    // =========================================================================
    // QueryGenerator — return type uses *Row DTO
    // =========================================================================

    public function test_query_method_uses_row_dto_return_type_with_embed(): void
    {
        $queries = $this->analyze(
            "-- @name GetUserWithRole\n-- @returns :one\n-- @embed Role role_\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id WHERE users.id = :id;"
        );

        $code = $this->queryGen->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString('GetUserWithRoleRow', $code);
    }

    // =========================================================================
    // Regression: prefix normalization
    // =========================================================================

    public function test_embed_double_underscore_prefix_preserved(): void
    {
        // Regression: @embed Country country__ was normalised to country_
        // because rtrim($prefix, '_') . '_' stripped all trailing underscores.
        $queries = $this->parser->parse(<<<SQL
            -- @name List
            -- @embed Country country__
            -- @returns :many
            SELECT c.name AS country__name, c.iso2 AS country__iso2
            FROM users LEFT JOIN countries c ON c.id = users.country_id;
        SQL);

        $embed = $queries[0]->embeds[0];

        $this->assertSame('country__', $embed->prefix);
        $this->assertSame('country',   $embed->propertyName());
        $this->assertSame('name',      $embed->stripPrefix('country__name'));
        $this->assertSame('iso2',      $embed->stripPrefix('country__iso2'));
    }

    public function test_embed_single_underscore_prefix_preserved(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name Get
            -- @embed Role role_
            -- @returns :one
            SELECT r.name AS role_name FROM users JOIN roles r ON r.id = users.role_id WHERE users.id = :id;
        SQL);

        $embed = $queries[0]->embeds[0];
        $this->assertSame('role_', $embed->prefix);
        $this->assertSame('name',  $embed->stripPrefix('role_name'));
    }

    public function test_embed_no_trailing_underscore_gets_one_added(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name Get
            -- @embed Role role
            -- @returns :one
            SELECT r.name AS role_name FROM users JOIN roles r ON r.id = users.role_id WHERE users.id = :id;
        SQL);

        $embed = $queries[0]->embeds[0];
        $this->assertSame('role_', $embed->prefix,
            'Prefix without trailing _ must have one added for backward compat');
    }

    public function test_embed_double_underscore_generates_correct_property_names(): void
    {
        // End-to-end: the generated DTO must have $name and $description,
        // not $_name and $_description
        $queries = $this->analyze(<<<SQL
            -- @name List
            -- @embed Role role__
            -- @returns :many
            SELECT r.name AS role__name FROM users LEFT JOIN roles r ON r.id = users.role_id;
        SQL);

        $result = $this->dtoGen->generate($queries[0]);

        // The embed object should have $name, not $_name
        $embedCode = $result['embeds']['Role']['code'] ?? '';
        $this->assertNotEmpty($embedCode, 'Embed code must be generated');
        $this->assertStringContainsString('public string $name', $embedCode,
            'Property should be $name not $_name');
        $this->assertStringNotContainsString('$_name', $embedCode,
            'No property should start with underscore');
    }
}
