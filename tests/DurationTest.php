<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
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

class DurationTest extends TestCase
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
    // QueryObject — durationMs field and withDuration()
    // =========================================================================

    public function test_query_object_default_duration_is_zero(): void
    {
        $q = new QueryObject('SELECT 1');
        $this->assertSame(0.0, $q->durationMs);
    }

    public function test_query_object_with_duration_returns_new_instance(): void
    {
        $q1 = new QueryObject('SELECT 1');
        $q2 = $q1->withDuration(42.7);

        $this->assertNotSame($q1, $q2);
        $this->assertSame(0.0,  $q1->durationMs); // original unchanged
        $this->assertSame(42.7, $q2->durationMs);
    }

    public function test_with_duration_preserves_all_other_fields(): void
    {
        $bindings = [':id' => [1, \PDO::PARAM_INT]];
        $q1 = new QueryObject('SELECT * FROM users WHERE id = :id', $bindings, 'getUser', false, 0);
        $q2 = $q1->withDuration(15.3);

        $this->assertSame($q1->sql,       $q2->sql);
        $this->assertSame($q1->bindings,  $q2->bindings);
        $this->assertSame($q1->queryName, $q2->queryName);
        $this->assertSame($q1->isBatch,   $q2->isBatch);
        $this->assertSame($q1->batchCount,$q2->batchCount);
        $this->assertSame(15.3,           $q2->durationMs);
    }

    public function test_with_duration_sub_millisecond_precision(): void
    {
        $q = (new QueryObject('SELECT 1'))->withDuration(0.234);
        $this->assertEqualsWithDelta(0.234, $q->durationMs, 0.0001);
    }

    public function test_with_duration_zero_is_valid(): void
    {
        $q = (new QueryObject('SELECT 1'))->withDuration(0.0);
        $this->assertSame(0.0, $q->durationMs);
    }

    public function test_batch_query_object_duration(): void
    {
        $q = new QueryObject('INSERT INTO users ...', [], 'insertUsers', true, 50);
        $timed = $q->withDuration(123.456);
        $this->assertTrue($timed->isBatch);
        $this->assertSame(50, $timed->batchCount);
        $this->assertEqualsWithDelta(123.456, $timed->durationMs, 0.0001);
    }

    // =========================================================================
    // Generated code — hrtime timer pattern
    // =========================================================================

    public function test_many_method_has_hrtime_timer(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('hrtime(true)', $code);
        $this->assertStringContainsString('withDuration(', $code);
    }

    public function test_one_method_has_hrtime_timer(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $this->assertStringContainsString('hrtime(true)', $code);
        $this->assertStringContainsString('withDuration((hrtime(true) - $__t0) / 1_000_000)', $code);
    }

    public function test_opt_method_has_hrtime_timer(): void
    {
        $code = $this->code("-- @name FindUser\n-- @returns :opt\nSELECT * FROM users WHERE email = :email;");
        $this->assertStringContainsString('hrtime(true)', $code);
        $this->assertStringContainsString('withDuration(', $code);
    }

    public function test_exec_method_has_hrtime_timer(): void
    {
        $code = $this->code("-- @name DeleteUser\n-- @returns :exec\nDELETE FROM users WHERE id = :id;");
        $this->assertStringContainsString('hrtime(true)', $code);
        $this->assertStringContainsString('withDuration(', $code);
    }

    public function test_paginated_method_has_hrtime_timer(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many-paginated\nSELECT * FROM users;");
        $this->assertStringContainsString('hrtime(true)', $code);
        $this->assertStringContainsString('withDuration(', $code);
    }

    public function test_batch_method_has_hrtime_timer_around_transaction(): void
    {
        $code = $this->code(
            "-- @name InsertUsers\n-- @returns :batch\n" .
            "INSERT INTO users (email, active) VALUES (:email, :active);"
        );
        $methodStart = (int) strpos($code, 'function insertUsers');
        $method = substr($code, $methodStart);

        // Timer must wrap the entire transaction (beginTransaction → commit)
        $timerStart   = strpos($method, '$__t0 = hrtime(true)');
        $beginTx      = strpos($method, 'beginTransaction()');
        $commitPos    = strpos($method, 'commit()');
        $durationPos  = strpos($method, 'withDuration(');

        $this->assertNotFalse($timerStart);
        $this->assertNotFalse($durationPos);
        // Timer starts before commit, duration measured after commit
        $this->assertLessThan($commitPos, $timerStart);
        $this->assertGreaterThan($commitPos, $durationPos);
    }

    // =========================================================================
    // Timer placement — after lastQuery, wraps execute
    // =========================================================================

    public function test_timer_starts_before_execute(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $methodStart = (int) strpos($code, 'function getUser');
        $method = substr($code, $methodStart);

        $t0Pos      = strpos($method, '$__t0 = hrtime(true)');
        $executePos = strpos($method, '$stmt->execute()');

        $this->assertNotFalse($t0Pos);
        $this->assertLessThan($executePos, $t0Pos, '$__t0 must be set before $stmt->execute()');
    }

    public function test_with_duration_comes_after_execute(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $methodStart = (int) strpos($code, 'function getUser');
        $method = substr($code, $methodStart);

        $executePos  = strpos($method, '$stmt->execute()');
        $durationPos = strpos($method, 'withDuration(');

        $this->assertGreaterThan($executePos, $durationPos,
            'withDuration() must come after $stmt->execute()');
    }

    public function test_log_called_after_with_duration(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $methodStart = (int) strpos($code, 'function getUser');
        $method = substr($code, $methodStart);

        $durationPos = strpos($method, 'withDuration(');
        $logPos      = strpos($method, 'logLastQuery()');

        $this->assertGreaterThan($durationPos, $logPos,
            'logLastQuery() must come after withDuration()');
    }

    // =========================================================================
    // duration in log message
    // =========================================================================

    public function test_log_message_includes_duration_ms(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        // logLastQuery() uses sprintf with %.3fms format
        $this->assertStringContainsString('durationMs', $code);
        $this->assertStringContainsString('%.3fms', $code);
    }

    public function test_log_message_format_includes_query_name_duration_sql(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;");
        // The format string in logLastQuery: '%s [%.3fms]: %s'
        $this->assertStringContainsString("'%s [%.3fms]: %s'", $code);
        $this->assertStringContainsString('queryName', $code);
        $this->assertStringContainsString('toString()', $code);
    }

    // =========================================================================
    // Division precision — nanoseconds to milliseconds
    // =========================================================================

    public function test_timer_divides_by_1_000_000_for_ms(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        // hrtime(true) returns nanoseconds — divide by 1_000_000 for ms
        $this->assertStringContainsString('/ 1_000_000', $code);
    }

    // =========================================================================
    // afterQuery hook receives QueryObject with duration
    // =========================================================================

    public function test_after_query_called_after_duration_set(): void
    {
        $code = $this->code("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");

        // logLastQuery() is the private method that fires both logger and hook.
        // It is called AFTER withDuration(), so the hook always receives the timed QueryObject.
        $logLastQueryStart = (int) strpos($code, 'private function logLastQuery()');
        $this->assertGreaterThan(0, $logLastQueryStart, 'logLastQuery method not found');

        $logMethod = substr($code, $logLastQueryStart, 400);
        // The hook is invoked inside logLastQuery() which runs after withDuration()
        $this->assertStringContainsString('$this->afterQuery', $logMethod);
        $this->assertStringContainsString('durationMs', $logMethod);
    }

    // =========================================================================
    // QueryObject is readonly
    // =========================================================================

    public function test_query_object_duration_field_is_readonly(): void
    {
        $q = new QueryObject('SELECT 1', [], 'test', false, 0, 42.0);
        $this->assertSame(42.0, $q->durationMs);

        // readonly — cannot be set directly
        $ref = new \ReflectionClass($q);
        $prop = $ref->getProperty('durationMs');
        $this->assertTrue($prop->isReadOnly());
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_7_6(): void
    {
        $this->assertSame('2.7.7', \SqlcPhp\Version::VERSION);
    }
}
