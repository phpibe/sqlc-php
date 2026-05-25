<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class ParamResolverTest extends TestCase
{
    private ParamResolver $resolver;

    protected function setUp(): void
    {
        $schema  = <<<SQL
            CREATE TABLE users (
                id       INT          AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100) NOT NULL,
                username VARCHAR(45)  null,
                active   TINYINT      DEFAULT 1 null,
                role_id  SMALLINT     NOT NULL,
                updated_at TIMESTAMP  null
            );
            CREATE TABLE roles (
                id   SMALLINT     AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
        SQL;

        $catalog        = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->resolver = new ParamResolver($catalog, new MySQLTypeMapper());
    }

    // -------------------------------------------------------------------------
    // No parameters
    // -------------------------------------------------------------------------

    public function test_returns_empty_for_query_without_params(): void
    {
        $this->assertSame([], $this->resolver->resolve('SELECT * FROM users'));
    }

    // -------------------------------------------------------------------------
    // WHERE clause — qualified (table.col = :param)
    // -------------------------------------------------------------------------

    public function test_resolves_qualified_int_param(): void
    {
        $params = $this->resolver->resolve('SELECT * FROM users WHERE users.id = :id');

        $this->assertArrayHasKey('id', $params);
        $this->assertSame('int', $params['id']->phpType);
        $this->assertSame('PDO::PARAM_INT', $params['id']->pdoParam);
    }

    public function test_resolves_qualified_string_param(): void
    {
        $params = $this->resolver->resolve('SELECT * FROM users WHERE users.email = :email');

        $this->assertArrayHasKey('email', $params);
        $this->assertSame('string', $params['email']->phpType);
        $this->assertSame('PDO::PARAM_STR', $params['email']->pdoParam);
    }

    // -------------------------------------------------------------------------
    // WHERE clause — unqualified (col = :param)
    // -------------------------------------------------------------------------

    public function test_resolves_unqualified_param(): void
    {
        $params = $this->resolver->resolve('SELECT * FROM users WHERE id = :id');

        $this->assertArrayHasKey('id', $params);
        $this->assertSame('int', $params['id']->phpType);
    }

    // -------------------------------------------------------------------------
    // UPDATE SET clause
    // -------------------------------------------------------------------------

    public function test_resolves_set_clause_params(): void
    {
        $params = $this->resolver->resolve(
            'UPDATE users SET active = :active, updated_at = :updatedAt WHERE id = :id'
        );

        $this->assertArrayHasKey('active',    $params);
        $this->assertArrayHasKey('updatedAt', $params);
        $this->assertArrayHasKey('id',        $params);
    }

    public function test_resolves_camel_case_to_snake_case(): void
    {
        $params = $this->resolver->resolve(
            'UPDATE users SET updated_at = :updatedAt WHERE id = :id'
        );

        $this->assertArrayHasKey('updatedAt', $params);
        $this->assertSame('?string', $params['updatedAt']->phpType);
    }

    // -------------------------------------------------------------------------
    // Multiple parameters
    // -------------------------------------------------------------------------

    public function test_resolves_multiple_params(): void
    {
        $params = $this->resolver->resolve(
            'SELECT * FROM users WHERE active = :active AND role_id = :roleId'
        );

        $this->assertCount(2, $params);
        $this->assertArrayHasKey('active', $params);
        $this->assertArrayHasKey('roleId', $params);
    }

    // -------------------------------------------------------------------------
    // @param annotation override
    // -------------------------------------------------------------------------

    public function test_annotation_overrides_inferred_type(): void
    {
        $params = $this->resolver->resolve(
            'SELECT * FROM users WHERE id = :userId',
            ['userId' => 'users.id']
        );

        $this->assertArrayHasKey('userId', $params);
        $this->assertSame('int', $params['userId']->phpType);
    }

    // -------------------------------------------------------------------------
    // Unknown parameter fallback
    // -------------------------------------------------------------------------

    public function test_unknown_param_falls_back_to_mixed(): void
    {
        $params = $this->resolver->resolve(
            'SELECT * FROM users WHERE nonexistent_col = :mystery'
        );

        $this->assertArrayHasKey('mystery', $params);
        $this->assertSame('mixed', $params['mystery']->phpType);
        $this->assertSame('PDO::PARAM_STR', $params['mystery']->pdoParam);
    }

    // -------------------------------------------------------------------------
    // Table alias extraction
    // -------------------------------------------------------------------------

    public function test_extracts_table_aliases_from_from_clause(): void
    {
        $aliases = $this->resolver->extractTableAliases('SELECT * FROM users u');
        $this->assertArrayHasKey('users', $aliases);
    }

    public function test_extracts_table_aliases_from_join(): void
    {
        $sql     = 'SELECT * FROM users INNER JOIN roles ON roles.id = users.role_id';
        $aliases = $this->resolver->extractTableAliases($sql);

        $this->assertArrayHasKey('users', $aliases);
        $this->assertArrayHasKey('roles', $aliases);
    }

    public function test_extracts_table_from_update(): void
    {
        $aliases = $this->resolver->extractTableAliases('UPDATE users SET active = 1');
        $this->assertArrayHasKey('users', $aliases);
    }
}
