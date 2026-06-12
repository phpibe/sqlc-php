<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
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

class LoggerHookTest extends TestCase
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
                id     INT          AUTO_INCREMENT PRIMARY KEY,
                email  VARCHAR(100) NOT NULL,
                active TINYINT      NOT NULL DEFAULT 1
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
    // Generated constructor
    // =========================================================================

    public function test_constructor_has_pdo_logger_and_hook_params(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('private readonly PDO', $code);
        $this->assertStringContainsString('?LoggerInterface', $code);
        $this->assertStringContainsString('?Closure', $code);
        $this->assertStringContainsString('$logger     = null', $code);
        $this->assertStringContainsString('$afterQuery = null', $code);
    }

    public function test_constructor_logger_is_optional(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        // logger defaults to null — no breaking change
        $this->assertStringContainsString('?LoggerInterface  $logger     = null', $code);
    }

    public function test_constructor_hook_is_optional(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('?Closure          $afterQuery = null', $code);
    }

    public function test_generated_class_imports_logger_interface(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('use Psr\\Log\\LoggerInterface;', $code);
    }

    public function test_generated_class_imports_closure(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('use Closure;', $code);
    }

    // =========================================================================
    // logLastQuery() private method
    // =========================================================================

    public function test_generated_class_has_log_last_query_method(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('private function logLastQuery(): void', $code);
    }

    public function test_log_last_query_calls_logger_debug(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('$this->logger?->debug(', $code);
    }

    public function test_log_last_query_fires_after_query_hook(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('($this->afterQuery)($this->lastQuery)', $code);
    }

    public function test_log_last_query_passes_sql_as_message(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('$this->lastQuery->toString()', $code);
        $this->assertStringContainsString('$this->lastQuery->queryName', $code);
    }

    public function test_log_last_query_passes_values_as_context(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('$this->lastQuery->values()', $code);
    }

    // =========================================================================
    // logLastQuery() called after every method
    // =========================================================================

    public function test_many_method_calls_log_last_query(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;");
        $methodCode = substr($code, (int) strpos($code, 'function listUsers'));
        $this->assertStringContainsString('$this->logLastQuery()', $methodCode);
    }

    public function test_one_method_calls_log_last_query(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $methodCode = substr($code, (int) strpos($code, 'function getUser'));
        $this->assertStringContainsString('$this->logLastQuery()', $methodCode);
    }

    public function test_opt_method_calls_log_last_query(): void
    {
        $code = $this->code("-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE email = :email;");
        $methodCode = substr($code, (int) strpos($code, 'function findUser'));
        $this->assertStringContainsString('$this->logLastQuery()', $methodCode);
    }

    public function test_exec_method_calls_log_last_query(): void
    {
        $code = $this->code("-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;");
        $methodCode = substr($code, (int) strpos($code, 'function deleteUser'));
        $this->assertStringContainsString('$this->logLastQuery()', $methodCode);
    }

    public function test_batch_method_calls_log_last_query(): void
    {
        $code = $this->code(
            "-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $methodCode = substr($code, (int) strpos($code, 'function insertUsers'));
        $this->assertStringContainsString('$this->logLastQuery()', $methodCode);
    }

    public function test_paginated_method_calls_log_last_query(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $methodCode = substr($code, (int) strpos($code, 'function listUsers'));
        $this->assertStringContainsString('$this->logLastQuery()', $methodCode);
    }

    // =========================================================================
    // logLastQuery() is called AFTER $this->lastQuery is set, BEFORE return
    // =========================================================================

    public function test_log_called_after_last_query_assignment(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $methodStart    = (int) strpos($code, 'function getUser');
        $methodCode     = substr($code, $methodStart);

        $lastQueryPos   = strpos($methodCode, '$this->lastQuery = new QueryObject(');
        $logPos         = strpos($methodCode, '$this->logLastQuery()');

        $this->assertNotFalse($lastQueryPos);
        $this->assertNotFalse($logPos);
        $this->assertGreaterThan($lastQueryPos, $logPos,
            'logLastQuery() must be called AFTER $this->lastQuery is assigned');
    }

    public function test_log_called_after_execute(): void
    {
        // logLastQuery fires AFTER execute() — the duration is measured around execute
        // so we need execute to complete before we can record the duration.
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $methodStart = (int) strpos($code, 'function getUser');
        $methodCode  = substr($code, $methodStart);

        $executePos = strpos($methodCode, '$stmt->execute()');
        $logPos     = strpos($methodCode, '$this->logLastQuery()');

        $this->assertNotFalse($executePos);
        $this->assertNotFalse($logPos);
        $this->assertGreaterThan($executePos, $logPos,
            'logLastQuery() must be called AFTER $stmt->execute() so duration is captured');
    }

    public function test_with_duration_called_after_execute(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $methodStart  = (int) strpos($code, 'function getUser');
        $methodCode   = substr($code, $methodStart);

        $executePos  = strpos($methodCode, '$stmt->execute()');
        $durationPos = strpos($methodCode, 'withDuration(');

        $this->assertNotFalse($durationPos, 'withDuration() call not found');
        $this->assertGreaterThan($executePos, $durationPos,
            'withDuration() must come after $stmt->execute()');
    }

    // =========================================================================
    // Not in interface
    // =========================================================================

    public function test_logger_and_hook_not_in_interface(): void
    {
        $dtoGen = new ResultDtoGenerator('App', $this->mapper);
        $ig     = new InterfaceGenerator('App');
        $qg     = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App', true, $ig);

        $q     = $this->analyzer->analyze($this->parser->parse(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        ));
        $files = $qg->generateInterfaces($q);
        $iface = $files['UserQueryInterface']['code'];

        $this->assertStringNotContainsString('LoggerInterface', $iface);
        $this->assertStringNotContainsString('afterQuery',      $iface);
        $this->assertStringNotContainsString('logLastQuery',    $iface);
    }

    // =========================================================================
    // Constructor docblock
    // =========================================================================

    public function test_constructor_docblock_documents_logger(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('PSR-3 logger', $code);
        $this->assertStringContainsString('DEBUG level', $code);
    }

    public function test_constructor_docblock_documents_after_query(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('Debugbar', $code);
        $this->assertStringContainsString('function(QueryObject', $code);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_7_5(): void
    {
        $this->assertSame('2.9.4', \SqlcPhp\Version::VERSION);
    }
}
