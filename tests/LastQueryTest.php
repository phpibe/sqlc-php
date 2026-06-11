<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Criteria\Filter;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\InterfaceGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Query\QueryObject;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class LastQueryTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private QueryGenerator  $qg;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NULL,
                active     TINYINT       NOT NULL DEFAULT 1,
                country_id INT           NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App', $this->mapper);
        $this->qg       = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App');
    }

    private function code(string $sql): string
    {
        $q = $this->analyzer->analyze($this->parser->parse($sql));
        return $this->qg->generate($q)['UserQuery']['code'];
    }

    // =========================================================================
    // QueryObject unit tests
    // =========================================================================

    public function test_query_object_to_string(): void
    {
        $q = new QueryObject('SELECT * FROM users WHERE id = :id', [':id' => [1, \PDO::PARAM_INT]]);
        $this->assertSame('SELECT * FROM users WHERE id = :id', $q->toString());
    }

    public function test_query_object_to_string_magic(): void
    {
        $q = new QueryObject('SELECT 1');
        $this->assertSame('SELECT 1', (string) $q);
    }

    public function test_query_object_to_debug_sql_interpolates_int(): void
    {
        $q = new QueryObject('SELECT * FROM users WHERE id = :id', [':id' => [42, \PDO::PARAM_INT]]);
        $this->assertSame('SELECT * FROM users WHERE id = 42', $q->toDebugSql());
    }

    public function test_query_object_to_debug_sql_interpolates_string(): void
    {
        $q = new QueryObject("SELECT * FROM users WHERE email = :email", [':email' => ['test@example.com', \PDO::PARAM_STR]]);
        $this->assertSame("SELECT * FROM users WHERE email = 'test@example.com'", $q->toDebugSql());
    }

    public function test_query_object_to_debug_sql_interpolates_null(): void
    {
        $q = new QueryObject('SELECT * FROM users WHERE name = :name', [':name' => [null, \PDO::PARAM_NULL]]);
        $this->assertSame('SELECT * FROM users WHERE name = NULL', $q->toDebugSql());
    }

    public function test_query_object_to_debug_sql_interpolates_bool_true(): void
    {
        $q = new QueryObject('SELECT * FROM users WHERE active = :active', [':active' => [true, \PDO::PARAM_BOOL]]);
        $this->assertSame('SELECT * FROM users WHERE active = 1', $q->toDebugSql());
    }

    public function test_query_object_to_debug_sql_interpolates_bool_false(): void
    {
        $q = new QueryObject('SELECT * FROM users WHERE active = :active', [':active' => [false, \PDO::PARAM_BOOL]]);
        $this->assertSame('SELECT * FROM users WHERE active = 0', $q->toDebugSql());
    }

    public function test_query_object_to_debug_sql_longer_key_replaced_first(): void
    {
        // :param_chk must be replaced before :param to avoid partial replacement
        $q = new QueryObject(
            'WHERE (:active_chk IS NULL OR active = :active)',
            [
                ':active_chk' => [1, \PDO::PARAM_INT],
                ':active'     => [1, \PDO::PARAM_INT],
            ]
        );
        $debug = $q->toDebugSql();
        $this->assertStringNotContainsString(':active', $debug);
        $this->assertSame('WHERE (1 IS NULL OR active = 1)', $debug);
    }

    public function test_query_object_bindings(): void
    {
        $bindings = [':id' => [42, \PDO::PARAM_INT], ':email' => ['x@y.com', \PDO::PARAM_STR]];
        $q = new QueryObject('SELECT 1', $bindings);
        $this->assertSame($bindings, $q->bindings());
    }

    public function test_query_object_values(): void
    {
        $q = new QueryObject('SELECT 1', [':id' => [42, \PDO::PARAM_INT], ':name' => ['Alice', \PDO::PARAM_STR]]);
        $this->assertSame([':id' => 42, ':name' => 'Alice'], $q->values());
    }

    public function test_query_object_cache_key_is_stable(): void
    {
        $q1 = new QueryObject('SELECT * FROM users WHERE id = :id', [':id' => [1, \PDO::PARAM_INT]]);
        $q2 = new QueryObject('SELECT * FROM users WHERE id = :id', [':id' => [1, \PDO::PARAM_INT]]);
        $this->assertSame($q1->cacheKey(), $q2->cacheKey());
    }

    public function test_query_object_cache_key_differs_for_different_values(): void
    {
        $q1 = new QueryObject('SELECT * FROM users WHERE id = :id', [':id' => [1, \PDO::PARAM_INT]]);
        $q2 = new QueryObject('SELECT * FROM users WHERE id = :id', [':id' => [2, \PDO::PARAM_INT]]);
        $this->assertNotSame($q1->cacheKey(), $q2->cacheKey());
    }

    public function test_query_object_cache_key_differs_for_different_sql(): void
    {
        $q1 = new QueryObject('SELECT * FROM users', []);
        $q2 = new QueryObject('SELECT id FROM users', []);
        $this->assertNotSame($q1->cacheKey(), $q2->cacheKey());
    }

    public function test_query_object_param_count(): void
    {
        $q = new QueryObject('SELECT 1', [':a' => [1, \PDO::PARAM_INT], ':b' => [2, \PDO::PARAM_INT]]);
        $this->assertSame(2, $q->paramCount());
    }

    public function test_query_object_empty_bindings(): void
    {
        $q = new QueryObject('SELECT 1 FROM users');
        $this->assertSame([], $q->bindings());
        $this->assertSame(0,  $q->paramCount());
        $this->assertSame('SELECT 1 FROM users', $q->toDebugSql());
    }

    public function test_query_object_is_readonly(): void
    {
        $q = new QueryObject('SELECT 1');
        $this->assertTrue((new \ReflectionClass($q))->isReadOnly());
    }

    public function test_query_object_query_name(): void
    {
        $q = new QueryObject('SELECT 1', [], 'listActiveUsers');
        $this->assertSame('listActiveUsers', $q->queryName);
    }

    public function test_query_object_batch_flags(): void
    {
        $q = new QueryObject('INSERT INTO users ...', [], 'insertUsers', true, 5);
        $this->assertTrue($q->isBatch);
        $this->assertSame(5, $q->batchCount);
    }

    // =========================================================================
    // Generated class — lastQuery() property and method present
    // =========================================================================

    public function test_generated_class_has_last_query_property(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('private ?QueryObject $lastQuery = null', $code);
    }

    public function test_generated_class_has_last_query_method(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('public function lastQuery(): ?QueryObject', $code);
    }

    public function test_generated_class_imports_query_object(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('use SqlcPhp\\Query\\QueryObject', $code);
    }

    public function test_last_query_not_in_interface(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig);

        $q     = $this->analyzer->analyze($this->parser->parse(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        ));
        $files = $qg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];
        $this->assertStringNotContainsString('lastQuery', $iface);
    }

    // =========================================================================
    // :many — lastQuery recording
    // =========================================================================

    public function test_many_method_saves_last_query(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('$this->lastQuery = new QueryObject(', $code);
    }

    public function test_many_method_last_query_has_query_name(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString("'listUsers'", $code);
    }

    public function test_many_method_last_query_with_params(): void
    {
        $code = $this->code(
            "-- @name ListByCountry\n-- @returns :many\n" .
            "SELECT * FROM users WHERE country_id = :country_id;"
        );
        $this->assertStringContainsString("':country_id'", $code);
        $this->assertStringContainsString('$country_id', $code);
    }

    public function test_many_no_params_has_empty_bindings(): void
    {
        $code = $this->code("-- @name ListAll\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('new QueryObject(', $code);
        $this->assertStringContainsString("'listAll'", $code);
        // Empty bindings array
        $this->assertStringContainsString('[], ', $code);
    }

    // =========================================================================
    // :one — lastQuery recording
    // =========================================================================

    public function test_one_method_saves_last_query(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('$this->lastQuery = new QueryObject(', $code);
        $this->assertStringContainsString("'getUser'", $code);
    }

    // =========================================================================
    // :opt — lastQuery recording
    // =========================================================================

    public function test_opt_method_saves_last_query(): void
    {
        $code = $this->code("-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE email = :email;");
        $this->assertStringContainsString('$this->lastQuery = new QueryObject(', $code);
        $this->assertStringContainsString("'findUser'", $code);
    }

    // =========================================================================
    // :exec — lastQuery recording
    // =========================================================================

    public function test_exec_method_saves_last_query(): void
    {
        $code = $this->code("-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;");
        $this->assertStringContainsString('$this->lastQuery = new QueryObject(', $code);
        $this->assertStringContainsString("'deleteUser'", $code);
    }

    // =========================================================================
    // :many-paginated — lastQuery recording
    // =========================================================================

    public function test_paginated_method_saves_last_query_in_both_branches(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        // Two branches: null branch and paginated branch
        $this->assertSame(2, substr_count($code, '$this->lastQuery = new QueryObject('));
    }

    public function test_paginated_null_branch_has_no_limit_in_bindings(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $ifPos   = strpos($code, 'if ($limit === null)');
        $elsePos = strpos($code, '} else {');
        $nullBranch = substr($code, (int)$ifPos, (int)$elsePos - (int)$ifPos);

        // Null branch QueryObject must NOT contain :limit/:offset
        $lastQueryPos = strpos($nullBranch, 'lastQuery = new QueryObject');
        $this->assertNotFalse($lastQueryPos);
        $queryObjectLine = substr($nullBranch, (int)$lastQueryPos, 200);
        $this->assertStringNotContainsString(':limit',  $queryObjectLine);
        $this->assertStringNotContainsString(':offset', $queryObjectLine);
    }

    public function test_paginated_else_branch_has_limit_in_bindings(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $elsePos    = strpos($code, '} else {');
        $executePos = strpos($code, '$stmt->execute()');
        $elseBranch = substr($code, (int)$elsePos, (int)$executePos - (int)$elsePos);

        $this->assertStringContainsString(':limit',  $elseBranch);
        $this->assertStringContainsString(':offset', $elseBranch);
    }

    // =========================================================================
    // :batch — lastQuery recording
    // =========================================================================

    public function test_batch_method_saves_last_query_with_batch_flag(): void
    {
        $code = $this->code(
            "-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $this->assertStringContainsString('new QueryObject(', $code);
        $this->assertStringContainsString("'insertUsers'", $code);
        $this->assertStringContainsString('true,',         $code); // isBatch = true
        $this->assertStringContainsString('count($rows)',  $code); // batchCount
    }

    // =========================================================================
    // @optional — lastQuery includes _chk bindings
    // =========================================================================

    public function test_optional_params_included_in_last_query_bindings(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @optional active\n-- @returns :many\n" .
            "SELECT * FROM users WHERE active = :active;"
        );
        // QueryObject bindings must include both :active and :active_chk
        $queryObjectStart = strpos($code, 'new QueryObject(');
        $this->assertNotFalse($queryObjectStart);
        $snippet = substr($code, (int)$queryObjectStart, 300);
        $this->assertStringContainsString(':active_chk', $snippet);
        $this->assertStringContainsString(':active',     $snippet);
    }

    // =========================================================================
    // @searchable — lastQuery merges static bindings and criteria bindings
    // =========================================================================

    public function test_searchable_last_query_merges_criteria_bindings(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('$criteria?->getBindings()', $code);
        $this->assertStringContainsString('array_merge(', $code);
    }

    public function test_searchable_paginated_last_query_in_both_branches(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        // One lastQuery assignment outside the if/else uses $__sql (which holds
        // the correct SQL regardless of which branch ran)
        $this->assertSame(1, substr_count($code, '$this->lastQuery = new QueryObject('));
        // It must reference $__sql (the dynamic SQL variable)
        $this->assertStringContainsString('new QueryObject($__sql,', $code);
    }

    // =========================================================================
    // Criteria::getBindings()
    // =========================================================================

    public function test_criteria_get_bindings_eq(): void
    {
        $c = (new Criteria())->add(Filter::eq('active', 1));
        $b = $c->getBindings();
        $this->assertArrayHasKey(':active_f0', $b);
        $this->assertSame([1, \PDO::PARAM_INT], $b[':active_f0']);
    }

    public function test_criteria_get_bindings_in_expands(): void
    {
        $c = (new Criteria())->add(Filter::in('id', [1, 2, 3]));
        $b = $c->getBindings();
        $this->assertArrayHasKey(':id_f0_0', $b);
        $this->assertArrayHasKey(':id_f0_1', $b);
        $this->assertArrayHasKey(':id_f0_2', $b);
    }

    public function test_criteria_get_bindings_between(): void
    {
        $from = new \DateTimeImmutable('2024-01-01');
        $to   = new \DateTimeImmutable('2024-12-31');
        $c    = (new Criteria())->add(Filter::between('created_at', $from, $to));
        $b    = $c->getBindings();
        $this->assertArrayHasKey(':created_at_f0_from', $b);
        $this->assertArrayHasKey(':created_at_f0_to',   $b);
    }

    public function test_criteria_get_bindings_is_null_skipped(): void
    {
        $c = (new Criteria())->add(Filter::isNull('name'));
        $b = $c->getBindings();
        $this->assertEmpty($b);
    }

    public function test_criteria_bind_all_uses_get_bindings(): void
    {
        // bindAll() should bind exactly the same keys as getBindings()
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->add(Filter::eq('country_id', 164));

        $bindings = $c->getBindings();

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->exactly(count($bindings)))->method('bindValue');

        $c->bindAll($stmt);
    }

    public function test_criteria_get_bindings_multiple_filters(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->add(Filter::isNull('name'))     // skipped
            ->add(Filter::eq('country_id', 164));

        $b = $c->getBindings();
        $this->assertCount(2, $b); // isNull has no binding
        $this->assertArrayHasKey(':active_f0',     $b);
        $this->assertArrayHasKey(':country_id_f2', $b);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_7_4(): void
    {
        $this->assertSame('2.9.1', \SqlcPhp\Version::VERSION);
    }
}
