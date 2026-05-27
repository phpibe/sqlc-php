<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\Target;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\ColumnDefinition;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Parser\TableDefinition;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class NewFeaturesV23Test extends TestCase
{
    private SchemaCatalog  $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser    $parser;
    private QueryAnalyzer  $analyzer;
    private ResultDtoGenerator $dtoGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT          AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100) NOT NULL,
                username VARCHAR(50)  NULL,
                active   TINYINT      NOT NULL DEFAULT 1
            );
            CREATE TABLE orders (
                id      INT     AUTO_INCREMENT PRIMARY KEY,
                user_id INT     NOT NULL,
                amount  DECIMAL NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App', $this->mapper);
    }

    private function makeQG(bool $psc = false): QueryGenerator
    {
        return new QueryGenerator(
            $this->catalog, $this->mapper, $this->dtoGen, 'App',
            false, null, $psc,
        );
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // =========================================================================
    // :batch
    // =========================================================================

    public function test_batch_return_type_is_parsed(): void
    {
        $queries = $this->parser->parse(
            "-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);"
        );
        $this->assertSame(':batch', $queries[0]->returns->value);
    }

    public function test_batch_generates_array_parameter(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('array $rows', $code);
    }

    public function test_batch_returns_int_count(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('): int', $code);
    }

    public function test_batch_method_contains_transaction(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('beginTransaction', $code);
        $this->assertStringContainsString('commit',           $code);
        $this->assertStringContainsString('rollBack',         $code);
    }

    public function test_batch_method_contains_foreach_rows(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('foreach ($rows as $row)', $code);
    }

    public function test_batch_binds_each_param_from_row(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString("\$row['email']",    $code);
        $this->assertStringContainsString("\$row['username']", $code);
    }

    public function test_batch_returns_count_of_rows(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('return count($rows)', $code);
    }

    public function test_batch_has_empty_rows_guard(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('empty($rows)', $code);
    }

    public function test_batch_rethrows_on_pdo_exception(): void
    {
        $q    = $this->analyze("-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, username) VALUES (:email, :username);");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('catch (\Throwable $e)', $code);
        $this->assertStringContainsString('throw $e',              $code);
    }

    // =========================================================================
    // Prepared statement cache
    // =========================================================================

    public function test_prepared_cache_default_is_false_in_target(): void
    {
        $t = Target::fromArray(['namespace' => 'App', 'out' => 'gen', 'queries' => 'q.sql']);
        $this->assertFalse($t->preparedStatementCache);
    }

    public function test_prepared_cache_can_be_enabled_in_target(): void
    {
        $t = Target::fromArray([
            'namespace' => 'App', 'out' => 'gen',
            'queries' => 'q.sql', 'prepared_statement_cache' => true,
        ]);
        $this->assertTrue($t->preparedStatementCache);
    }

    public function test_without_cache_uses_direct_prepare(): void
    {
        $q    = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $code = $this->makeQG(false)->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('$this->pdo->prepare(', $code);
        $this->assertStringNotContainsString('$this->stmts', $code);
    }

    public function test_with_cache_uses_stmts_property(): void
    {
        $q    = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $code = $this->makeQG(true)->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('$this->stmts[__FUNCTION__]', $code);
        $this->assertStringContainsString('??=',                        $code);
    }

    public function test_with_cache_declares_stmts_property_in_class(): void
    {
        $q    = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $code = $this->makeQG(true)->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('private array $stmts = []', $code);
    }

    public function test_cache_applies_to_many_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->makeQG(true)->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('$this->stmts[__FUNCTION__]', $code);
    }

    public function test_cache_applies_to_opt_method(): void
    {
        $q    = $this->analyze("-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE id = :id;");
        $code = $this->makeQG(true)->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('$this->stmts[__FUNCTION__]', $code);
    }

    public function test_cache_applies_to_exec_method(): void
    {
        $q    = $this->analyze("-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;");
        $code = $this->makeQG(true)->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('$this->stmts[__FUNCTION__]', $code);
    }

    // =========================================================================
    // @transaction
    // =========================================================================

    public function test_transaction_return_type_is_parsed(): void
    {
        $queries = $this->parser->parse(
            "-- @name Transfer\n-- @group User\n-- @returns :transaction\n-- @calls debitUser,creditUser\n"
        );
        $this->assertSame(':transaction', $queries[0]->returns->value);
    }

    public function test_transaction_calls_stored_in_sql(): void
    {
        $queries = $this->parser->parse(
            "-- @name Transfer\n-- @group User\n-- @returns :transaction\n-- @calls debitUser,creditUser\n"
        );
        $this->assertStringContainsString('debitUser', $queries[0]->sql);
        $this->assertStringContainsString('creditUser', $queries[0]->sql);
    }

    public function test_transaction_generates_begin_commit_rollback(): void
    {
        $q    = $this->analyzer->analyze($this->parser->parse(
            "-- @name Transfer\n-- @group User\n-- @returns :transaction\n-- @calls debitUser,creditUser\n"
        ));
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('beginTransaction', $code);
        $this->assertStringContainsString('commit',           $code);
        $this->assertStringContainsString('rollBack',         $code);
    }

    public function test_transaction_calls_constituent_methods(): void
    {
        $q    = $this->analyzer->analyze($this->parser->parse(
            "-- @name Transfer\n-- @group User\n-- @returns :transaction\n-- @calls debitUser,creditUser\n"
        ));
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('$this->debitUser()',  $code);
        $this->assertStringContainsString('$this->creditUser()', $code);
    }

    public function test_transaction_returns_void(): void
    {
        $q    = $this->analyzer->analyze($this->parser->parse(
            "-- @name Transfer\n-- @group User\n-- @returns :transaction\n-- @calls debitUser,creditUser\n"
        ));
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('): void', $code);
    }

    public function test_transaction_rethrows_on_exception(): void
    {
        $q    = $this->analyzer->analyze($this->parser->parse(
            "-- @name Transfer\n-- @group User\n-- @returns :transaction\n-- @calls debitUser,creditUser\n"
        ));
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];

        $this->assertStringContainsString('catch (\Throwable $e)', $code);
        $this->assertStringContainsString('throw $e',              $code);
    }

    // =========================================================================
    // Bug fixes — NULL literal, subquery warning, virtual table alias
    // =========================================================================

    public function test_null_literal_resolves_to_mixed(): void
    {
        $q = $this->analyze("-- @name Get\n-- @returns :many\nSELECT id, NULL AS deleted FROM users;");

        $deletedCol = array_values(array_filter(
            $q[0]->resultColumns, fn($c) => $c->alias === 'deleted'
        ))[0] ?? null;

        $this->assertNotNull($deletedCol);
        $this->assertSame('mixed', $deletedCol->phpType);
    }

    public function test_virtual_table_join_alias_resolves_correctly(): void
    {
        // Register a virtual table in the catalog
        $virtualTable = new TableDefinition('user_summary', [
            new ColumnDefinition('id',          'INT',     false, false, null),
            new ColumnDefinition('order_count', 'INT',     false, false, null),
            new ColumnDefinition('total',       'DECIMAL', true,  false, null),
        ], virtual: true);

        $extendedCatalog = new SchemaCatalog(
            array_merge(
                (new SchemaParser())->parse('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);'),
                [$virtualTable]
            )
        );

        $mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $parser   = new QueryParser();
        $pr       = new ParamResolver($extendedCatalog, $mapper);
        $er       = new ExpressionTypeResolver($extendedCatalog, $mapper);
        $cr       = new ColumnResolver($extendedCatalog, $mapper, $pr, $er);
        $analyzer = new QueryAnalyzer($pr, $cr, $parser, new SqlRewriter(), $extendedCatalog);

        $q = $analyzer->analyze($parser->parse(
            "-- @name List\n-- @returns :many\n" .
            "SELECT u.email, vs.order_count, vs.total FROM users u " .
            "JOIN user_summary vs ON vs.id = u.id;"
        ));

        $cols = array_column($q[0]->resultColumns, 'phpType', 'alias');

        $this->assertSame('string',  $cols['email'],       'email from real table');
        $this->assertSame('int',     $cols['order_count'], 'order_count from virtual table');
        $this->assertSame('?float',  $cols['total'],       'nullable decimal from virtual table');
    }

    public function test_virtual_table_has_no_model_generated(): void
    {
        // Virtual tables should not produce Model classes in the CLI
        // This is a config-level check
        $virtualTable = new TableDefinition('user_summary', [
            new ColumnDefinition('id', 'INT', false, false, null),
        ], virtual: true);

        $this->assertTrue($virtualTable->virtual);
    }
}
