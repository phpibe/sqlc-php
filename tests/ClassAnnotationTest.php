<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\Config;
use SqlcPhp\Config\Target;
use SqlcPhp\Generator\EnumGenerator;
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

class ClassAnnotationTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private string          $tmpDir;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id    INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);

        $this->tmpDir = sys_get_temp_dir() . '/sqlc-class-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    private function makeQG(string $suffix = 'Query'): QueryGenerator
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        return new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', false, null, false, $suffix);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function writeConfig(string $yaml): string
    {
        $path = $this->tmpDir . '/sqlc.yaml';
        file_put_contents($path, $yaml);
        return $path;
    }

    // =========================================================================
    // @class annotation — parsing
    // =========================================================================

    public function test_class_annotation_sets_group(): void
    {
        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @class User\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertSame('User', $queries[0]->group);
    }

    public function test_class_annotation_is_case_insensitive(): void
    {
        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @CLASS UserAdmin\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertSame('UserAdmin', $queries[0]->group);
    }

    public function test_class_first_wins_over_subsequent(): void
    {
        // Same as @group: first declaration wins
        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @class First\n-- @class Second\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertSame('First', $queries[0]->group);
    }

    public function test_class_annotation_generates_correct_class_name(): void
    {
        $q    = $this->analyze(
            "-- @name GetUser\n-- @class User\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('class UserQuery', $code);
    }

    // =========================================================================
    // @group is deprecated — emits stderr warning
    // =========================================================================

    public function test_group_annotation_still_sets_group_for_backward_compat(): void
    {
        // Redirect stderr temporarily
        $stderr = fopen('php://memory', 'r+');
        $prev   = ini_set('error_log', '/dev/null');

        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @group User\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );

        $this->assertSame('User', $queries[0]->group);
    }

    public function test_group_annotation_works_without_class(): void
    {
        // @group still infers group correctly (backward compat)
        $q    = $this->analyze(
            "-- @name GetUser\n-- @group User\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('class UserQuery', $code);
    }

    public function test_class_takes_precedence_over_group_when_both_declared(): void
    {
        // @class comes first → it wins
        $queries = $this->parser->parse(
            "-- @name GetUser\n-- @class ClassValue\n-- @group GroupValue\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertSame('ClassValue', $queries[0]->group);
    }

    // =========================================================================
    // class_suffix — Target
    // =========================================================================

    public function test_target_class_suffix_defaults_to_query(): void
    {
        $t = Target::fromArray(['namespace' => 'App', 'out' => 'gen', 'queries' => 'q.sql']);
        $this->assertSame('Query', $t->classSuffix);
    }

    public function test_target_class_suffix_can_be_set(): void
    {
        $t = Target::fromArray([
            'namespace'    => 'App',
            'out'          => 'gen',
            'queries'      => 'q.sql',
            'class_suffix' => 'Repository',
        ]);
        $this->assertSame('Repository', $t->classSuffix);
    }

    public function test_target_inherits_global_class_suffix(): void
    {
        $t = Target::fromArray(
            ['namespace' => 'App', 'out' => 'gen', 'queries' => 'q.sql'],
            [],     // overrides
            'mysql',
            'english',
            'Repository',  // globalClassSuffix
        );
        $this->assertSame('Repository', $t->classSuffix);
    }

    public function test_target_overrides_global_class_suffix(): void
    {
        $t = Target::fromArray(
            ['namespace' => 'App', 'out' => 'gen', 'queries' => 'q.sql', 'class_suffix' => 'Service'],
            [],
            'mysql',
            'english',
            'Repository',
        );
        $this->assertSame('Service', $t->classSuffix);
    }

    // =========================================================================
    // class_suffix — Config::fromFile
    // =========================================================================

    public function test_config_class_suffix_defaults_to_query(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\ntargets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );
        $cfg = Config::fromFile($path);
        $this->assertSame('Query', $cfg->classSuffix);
        $this->assertSame('Query', $cfg->targets[0]->classSuffix);
    }

    public function test_config_global_class_suffix_propagates_to_targets(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\nclass_suffix: Repository\n" .
            "targets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n"
        );
        $cfg = Config::fromFile($path);
        $this->assertSame('Repository', $cfg->classSuffix);
        $this->assertSame('Repository', $cfg->targets[0]->classSuffix);
    }

    public function test_config_per_target_class_suffix_overrides_global(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\nschema: schema.sql\nclass_suffix: Repository\n" .
            "targets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n    class_suffix: Service\n"
        );
        $cfg = Config::fromFile($path);
        $this->assertSame('Repository', $cfg->classSuffix);       // global unchanged
        $this->assertSame('Service',    $cfg->targets[0]->classSuffix); // per-target wins
    }

    // =========================================================================
    // class_suffix — code generation
    // =========================================================================

    public function test_repository_suffix_in_generated_class_name(): void
    {
        $q    = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $code = $this->makeQG('Repository')->generate($q)['UserRepository']['code'];
        $this->assertStringContainsString('class UserRepository', $code);
    }

    public function test_service_suffix_in_generated_class_name(): void
    {
        $q    = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $code = $this->makeQG('Service')->generate($q)['UserService']['code'];
        $this->assertStringContainsString('class UserService', $code);
    }

    public function test_custom_suffix_in_generated_file_key(): void
    {
        $q      = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $files  = $this->makeQG('Repository')->generate($q);
        $this->assertArrayHasKey('UserRepository', $files);
        $this->assertArrayNotHasKey('UserQuery', $files);
    }

    public function test_query_default_suffix_still_works(): void
    {
        $q     = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $files = $this->makeQG('Query')->generate($q);
        $this->assertArrayHasKey('UserQuery', $files);
    }

    public function test_class_annotation_with_custom_suffix(): void
    {
        $q    = $this->analyze(
            "-- @name GetUser\n-- @class User\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $code = $this->makeQG('Repository')->generate($q)['UserRepository']['code'];
        $this->assertStringContainsString('class UserRepository', $code);
    }

    // =========================================================================
    // Interface naming with custom suffix
    // =========================================================================

    public function test_interface_name_uses_custom_suffix(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig, false, 'Repository');

        $q     = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $files = $qg->generateInterfaces($q);

        $this->assertArrayHasKey('UserRepositoryInterface', $files);
        $this->assertStringContainsString('interface UserRepositoryInterface', $files['UserRepositoryInterface']['code']);
    }

    public function test_implements_clause_uses_custom_suffix(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig, false, 'Repository');

        $q    = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $code = $qg->generate($q)['UserRepository']['code'];

        $this->assertStringContainsString('implements UserRepositoryInterface', $code);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_5_3(): void
    {
        $this->assertSame('2.7.5', \SqlcPhp\Version::VERSION);
    }
}
