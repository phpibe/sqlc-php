<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Config;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\Config;
use SqlcPhp\Config\TypeOverride;

// =============================================================================
// MultipleSchemaFilesTest
// =============================================================================

class MultipleSchemaFilesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-schema-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    private function writeConfig(string $yaml): string
    {
        $path = $this->tmpDir . '/sqlc.yaml';
        file_put_contents($path, $yaml);
        return $path;
    }

    // -------------------------------------------------------------------------
    // Scalar schema (backward compat)
    // -------------------------------------------------------------------------

    public function test_scalar_schema_is_wrapped_in_array(): void
    {
        $path   = $this->writeConfig("version: \"2\"\nschema: schema.sql\ntargets:\n  - namespace: \"App\\\\Db\"\n    out: gen\n    queries: q.sql\n");
        $config = Config::fromFile($path);

        $this->assertIsArray($config->schemas);
        $this->assertCount(1, $config->schemas);
        $this->assertSame('schema.sql', $config->schemas[0]);
    }

    public function test_scalar_schema_sets_schema_property_for_compatibility(): void
    {
        $path   = $this->writeConfig("version: \"2\"\nschema: schema.sql\ntargets:\n  - namespace: \"App\\\\Db\"\n    out: gen\n    queries: q.sql\n");
        $config = Config::fromFile($path);

        $this->assertSame('schema.sql', $config->schemas[0]);
    }

    // -------------------------------------------------------------------------
    // List schema
    // -------------------------------------------------------------------------

    public function test_list_schema_with_single_entry(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema:\n" .
            "  - database/schema/users.sql\n" .
            "targets:\n  - namespace: \"App\\\\Db\"\n    out: gen\n    queries: q.sql\n"
        );
        $config = Config::fromFile($path);

        $this->assertCount(1, $config->schemas);
        $this->assertSame('database/schema/users.sql', $config->schemas[0]);
    }

    public function test_list_schema_with_multiple_entries(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema:\n" .
            "  - database/schema/users.sql\n" .
            "  - database/schema/orders.sql\n" .
            "  - database/schema/roles.sql\n" .
            "targets:\n  - namespace: \"App\\\\Db\"\n    out: gen\n    queries: q.sql\n"
        );
        $config = Config::fromFile($path);

        $this->assertCount(3, $config->schemas);
        $this->assertSame('database/schema/users.sql',  $config->schemas[0]);
        $this->assertSame('database/schema/orders.sql', $config->schemas[1]);
        $this->assertSame('database/schema/roles.sql',  $config->schemas[2]);
    }

    public function test_schema_is_always_array(): void
    {
        foreach ([
            "version: \"2\"\nschema: single.sql\ntargets:\n  - namespace: \"App\\\\Db\"\n    out: gen\n    queries: q.sql\n",
            "version: \"2\"\nschema:\n  - single.sql\ntargets:\n  - namespace: \"App\\\\Db\"\n    out: gen\n    queries: q.sql\n",
        ] as $yaml) {
            $path   = $this->writeConfig($yaml);
            $config = Config::fromFile($path);
            $this->assertIsArray($config->schemas);
        }
    }

    public function test_schema_first_entry_populates_schema_property(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema:\n" .
            "  - first.sql\n" .
            "  - second.sql\n" .
            "targets:\n  - namespace: \"App\\\\Db\"\n    out: gen\n    queries: q.sql\n"
        );
        $config = Config::fromFile($path);

        $this->assertSame('first.sql', $config->schemas[0]);
    }

    public function test_multiple_schemas_and_queries_coexist(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema:\n" .
            "  - schema/users.sql\n" .
            "  - schema/orders.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\\\\Db\"\n" .
            "    out: gen\n" .
            "    queries:\n" .
            "      - queries/users.sql\n" .
            "      - queries/orders.sql\n"
        );
        $config = Config::fromFile($path);

        $this->assertCount(2, $config->schemas);
        $this->assertCount(2, $config->targets[0]->queries);
    }
}

// =============================================================================
// NullableOverrideTest
// =============================================================================

class NullableOverrideTest extends TestCase
{
    // -------------------------------------------------------------------------
    // TypeOverride::fromArray — nullable field parsing
    // -------------------------------------------------------------------------

    public function test_nullable_true_is_parsed(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool', 'nullable' => true]);
        $this->assertTrue($o->nullable);
    }

    public function test_nullable_false_is_parsed(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool', 'nullable' => false]);
        $this->assertFalse($o->nullable);
    }

    public function test_nullable_string_true_is_parsed(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool', 'nullable' => 'true']);
        $this->assertTrue($o->nullable);
    }

    public function test_nullable_string_false_is_parsed(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool', 'nullable' => 'false']);
        $this->assertFalse($o->nullable);
    }

    public function test_without_nullable_field_is_null(): void
    {
        $o = TypeOverride::fromArray(['column' => 'users.active', 'php_type' => 'bool']);
        $this->assertNull($o->nullable);
    }

    public function test_nullable_only_without_php_type_is_valid(): void
    {
        // nullable: false alone — no type change, just force not-null
        $o = TypeOverride::fromArray(['column' => 'users.active', 'nullable' => false]);
        $this->assertNull($o->phpType);
        $this->assertFalse($o->nullable);
    }

    public function test_throws_when_neither_php_type_nor_nullable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TypeOverride::fromArray(['column' => 'users.active']);
    }

    // -------------------------------------------------------------------------
    // MySQLTypeMapper — nullable override applied
    // -------------------------------------------------------------------------

    public function test_nullable_true_forces_nullable_on_not_null_column(): void
    {
        $override = TypeOverride::fromArray([
            'column'   => 'users.email',
            'php_type' => 'string',
            'nullable' => true,
        ]);
        $mapper = new \SqlcPhp\TypeMapper\MySQLTypeMapper([$override]);

        // email is NOT NULL in schema ($nullable = false) — override forces ?string
        $result = $mapper->toPhpType('VARCHAR', false, 'users', 'email');
        $this->assertSame('?string', $result);
    }

    public function test_nullable_false_forces_not_null_on_nullable_column(): void
    {
        $override = TypeOverride::fromArray([
            'column'   => 'users.username',
            'php_type' => 'string',
            'nullable' => false,
        ]);
        $mapper = new \SqlcPhp\TypeMapper\MySQLTypeMapper([$override]);

        // username is NULL in schema ($nullable = true) — override forces string
        $result = $mapper->toPhpType('VARCHAR', true, 'users', 'username');
        $this->assertSame('string', $result);
    }

    public function test_without_nullable_override_inherits_schema_nullability(): void
    {
        $override = TypeOverride::fromArray([
            'column'   => 'users.email',
            'php_type' => 'string',
        ]);
        $mapper = new \SqlcPhp\TypeMapper\MySQLTypeMapper([$override]);

        $this->assertSame('string',  $mapper->toPhpType('VARCHAR', false, 'users', 'email'));
        $this->assertSame('?string', $mapper->toPhpType('VARCHAR', true,  'users', 'email'));
    }

    public function test_nullable_override_on_db_type(): void
    {
        $override = TypeOverride::fromArray([
            'db_type'  => 'TIMESTAMP',
            'php_type' => '\\DateTimeImmutable',
            'nullable' => true,
        ]);
        $mapper = new \SqlcPhp\TypeMapper\MySQLTypeMapper([$override]);

        // Even if the schema says NOT NULL, the override forces nullable
        $result = $mapper->toPhpType('TIMESTAMP', false, 'users', 'created_at');
        $this->assertSame('?\\DateTimeImmutable', $result);
    }

    public function test_nullable_only_override_changes_nullability_not_type(): void
    {
        $override = TypeOverride::fromArray([
            'column'   => 'users.created_at',
            'nullable' => false,  // no php_type — just force not-null
        ]);
        $mapper = new \SqlcPhp\TypeMapper\MySQLTypeMapper([$override]);

        // TIMESTAMP now maps to \DateTimeImmutable; nullable=false forces non-null
        $result = $mapper->toPhpType('TIMESTAMP', true, 'users', 'created_at');
        $this->assertSame('\DateTimeImmutable', $result);
    }
}

// =============================================================================
// DeprecatedAnnotationTest
// =============================================================================

class DeprecatedAnnotationTest extends TestCase
{
    private \SqlcPhp\Parser\QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new \SqlcPhp\Parser\QueryParser();
    }

    private function makeQueryGen(): array
    {
        $schema = "CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);";
        $catalog = new \SqlcPhp\Catalog\SchemaCatalog(
            (new \SqlcPhp\Parser\SchemaParser())->parse($schema)
        );
        $mapper  = new \SqlcPhp\TypeMapper\MySQLTypeMapper();
        $pr      = new \SqlcPhp\Resolver\ParamResolver($catalog, $mapper);
        $er      = new \SqlcPhp\Resolver\ExpressionTypeResolver($catalog, $mapper);
        $cr      = new \SqlcPhp\Resolver\ColumnResolver($catalog, $mapper, $pr, $er);
        $az      = new \SqlcPhp\Analyzer\QueryAnalyzer($pr, $cr, $this->parser, new \SqlcPhp\Rewriter\SqlRewriter());
        $dg      = new \SqlcPhp\Generator\ResultDtoGenerator('App\\Database');
        $qg      = new \SqlcPhp\Generator\QueryGenerator($catalog, $mapper, $dg, 'App\\Database');
        return [$az, $qg];
    }

    // -------------------------------------------------------------------------
    // Parser
    // -------------------------------------------------------------------------

    public function test_parser_captures_deprecated_with_message(): void
    {
        $sql     = "-- @name GetUser\n-- @returns :one\n-- @deprecated Use getUserById instead\nSELECT * FROM users WHERE id = :id;";
        $queries = $this->parser->parse($sql);

        $this->assertSame('Use getUserById instead', $queries[0]->deprecated);
    }

    public function test_parser_captures_deprecated_without_message(): void
    {
        $sql     = "-- @name GetUser\n-- @returns :one\n-- @deprecated\nSELECT * FROM users WHERE id = :id;";
        $queries = $this->parser->parse($sql);

        $this->assertSame('', $queries[0]->deprecated);
    }

    public function test_parser_deprecated_is_null_when_not_present(): void
    {
        $sql     = "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;";
        $queries = $this->parser->parse($sql);

        $this->assertNull($queries[0]->deprecated);
    }

    // -------------------------------------------------------------------------
    // Generator — @deprecated in docblock
    // -------------------------------------------------------------------------

    public function test_generated_method_has_deprecated_tag_with_message(): void
    {
        [$az, $qg] = $this->makeQueryGen();
        $queries   = $az->analyze($this->parser->parse(
            "-- @name GetUser\n-- @returns :one\n-- @deprecated Use getUserById instead\nSELECT * FROM users WHERE id = :id;"
        ));
        $code = $qg->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString('@deprecated Use getUserById instead', $code);
    }

    public function test_generated_method_has_deprecated_tag_without_message(): void
    {
        [$az, $qg] = $this->makeQueryGen();
        $queries   = $az->analyze($this->parser->parse(
            "-- @name GetUser\n-- @returns :one\n-- @deprecated\nSELECT * FROM users WHERE id = :id;"
        ));
        $code = $qg->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString('@deprecated', $code);
    }

    public function test_non_deprecated_method_has_no_deprecated_tag(): void
    {
        [$az, $qg] = $this->makeQueryGen();
        $queries   = $az->analyze($this->parser->parse(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        ));
        $code = $qg->generate($queries)['UserQuery']['code'];

        $this->assertStringNotContainsString('@deprecated', $code);
    }

    public function test_deprecated_tag_appears_before_param_tags(): void
    {
        [$az, $qg] = $this->makeQueryGen();
        $queries   = $az->analyze($this->parser->parse(
            "-- @name GetUser\n-- @returns :one\n-- @deprecated Old method\nSELECT * FROM users WHERE id = :id;"
        ));
        $code = $qg->generate($queries)['UserQuery']['code'];

        // Scope check to the getUser method only — the constructor docblock also has @param
        $methodStart    = strpos($code, 'public function getUser');
        $this->assertNotFalse($methodStart, 'getUser method not found');
        $methodSnippet  = substr($code, 0, (int) $methodStart);

        $deprecatedPos = strrpos($methodSnippet, '@deprecated');
        $paramPos      = strrpos($methodSnippet, '@param');

        $this->assertNotFalse($deprecatedPos, '@deprecated tag not found before method');
        $this->assertLessThan($paramPos, $deprecatedPos);
    }
}

// =============================================================================
// NillableAnnotationTest
// =============================================================================

class NillableAnnotationTest extends TestCase
{
    private \SqlcPhp\Parser\QueryParser $parser;
    private \SqlcPhp\Analyzer\QueryAnalyzer $analyzer;
    private \SqlcPhp\Generator\QueryGenerator $queryGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT          AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100) NOT NULL,
                username VARCHAR(45)  NOT NULL,
                active   TINYINT      NOT NULL
            );
            CREATE TABLE roles (
                id   SMALLINT     AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            );
        SQL;

        $catalog         = new \SqlcPhp\Catalog\SchemaCatalog((new \SqlcPhp\Parser\SchemaParser())->parse($schema));
        $mapper          = new \SqlcPhp\TypeMapper\MySQLTypeMapper();
        $this->parser    = new \SqlcPhp\Parser\QueryParser();
        $pr              = new \SqlcPhp\Resolver\ParamResolver($catalog, $mapper);
        $er              = new \SqlcPhp\Resolver\ExpressionTypeResolver($catalog, $mapper);
        $cr              = new \SqlcPhp\Resolver\ColumnResolver($catalog, $mapper, $pr, $er);
        $this->analyzer  = new \SqlcPhp\Analyzer\QueryAnalyzer($pr, $cr, $this->parser, new \SqlcPhp\Rewriter\SqlRewriter());
        $dg              = new \SqlcPhp\Generator\ResultDtoGenerator('App\\Database');
        $this->queryGen  = new \SqlcPhp\Generator\QueryGenerator($catalog, $mapper, $dg, 'App\\Database');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // -------------------------------------------------------------------------
    // Parser
    // -------------------------------------------------------------------------

    public function test_parser_captures_nillable_column(): void
    {
        $sql     = "-- @name Get\n-- @returns :one\n-- @nillable email\nSELECT users.* FROM users WHERE id = :id;";
        $queries = $this->parser->parse($sql);

        $this->assertContains('email', $queries[0]->nillableColumns);
    }

    public function test_parser_captures_multiple_nillable_columns(): void
    {
        $sql     = "-- @name Get\n-- @returns :one\n-- @nillable email\n-- @nillable username\nSELECT users.* FROM users WHERE id = :id;";
        $queries = $this->parser->parse($sql);

        $this->assertContains('email',    $queries[0]->nillableColumns);
        $this->assertContains('username', $queries[0]->nillableColumns);
    }

    public function test_parser_nillable_is_empty_when_not_declared(): void
    {
        $sql     = "-- @name Get\n-- @returns :one\nSELECT users.* FROM users WHERE id = :id;";
        $queries = $this->parser->parse($sql);

        $this->assertEmpty($queries[0]->nillableColumns);
    }

    // -------------------------------------------------------------------------
    // Analyzer — @nillable forces nullable on result columns
    // -------------------------------------------------------------------------

    public function test_nillable_forces_nullable_on_not_null_column(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\n-- @nillable email\n" .
            "SELECT users.id, users.email FROM users WHERE id = :id;"
        );

        $emailCol = collect_by_alias($queries[0]->resultColumns, 'email');
        $this->assertTrue($emailCol->nullable);
        $this->assertStringStartsWith('?', $emailCol->phpType);
    }

    public function test_nillable_does_not_affect_other_columns(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\n-- @nillable email\n" .
            "SELECT users.id, users.email, users.username FROM users WHERE id = :id;"
        );

        $usernameCol = collect_by_alias($queries[0]->resultColumns, 'username');
        $this->assertFalse($usernameCol->nullable);
        $this->assertStringStartsNotWith('?', $usernameCol->phpType);
    }

    public function test_nillable_on_already_nullable_column_is_idempotent(): void
    {
        // email is NOT NULL — after @nillable it becomes nullable
        // Applying @nillable again should still produce ?string, not ??string
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\n-- @nillable email\n" .
            "SELECT users.email FROM users WHERE id = :id;"
        );

        $col = collect_by_alias($queries[0]->resultColumns, 'email');
        $this->assertSame('?string', $col->phpType);
        $this->assertStringNotContainsString('??', $col->phpType);
    }

    // -------------------------------------------------------------------------
    // Generator — @nillable in result DTO
    // -------------------------------------------------------------------------

    public function test_nillable_column_is_nullable_in_generated_dto(): void
    {
        $queries = $this->analyze(
            "-- @name GetJoined\n-- @returns :one\n-- @nillable role_name\n" .
            "SELECT users.id, users.email, roles.name AS role_name\n" .
            "FROM users INNER JOIN roles ON roles.id = users.id\n" .
            "WHERE users.id = :id;"
        );

        $dg   = new \SqlcPhp\Generator\ResultDtoGenerator('App\\Database');
        $code = $dg->generate($queries[0])['code'];

        $this->assertStringContainsString('?string $role_name', $code);
    }
}

// -------------------------------------------------------------------------
// Helper — find a ResolvedColumn by alias (avoids array_filter verbosity)
// -------------------------------------------------------------------------
function collect_by_alias(array $columns, string $alias): \SqlcPhp\Resolver\ResolvedColumn
{
    foreach ($columns as $col) {
        if ($col->alias === $alias) return $col;
    }
    throw new \RuntimeException("Column alias '{$alias}' not found in result set.");
}
