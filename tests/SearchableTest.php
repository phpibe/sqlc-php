<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Criteria\Filter;
use SqlcPhp\Criteria\FilterOperator;
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

class SearchableTest extends TestCase
{
    private SchemaCatalog   $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser     $parser;
    private QueryAnalyzer   $analyzer;
    private ResultDtoGenerator $dtoGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NULL,
                active     TINYINT       NOT NULL DEFAULT 1,
                country_id INT           NULL,
                score      DECIMAL(8,2)  NULL,
                created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE roles (
                id   INT          AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50)  NOT NULL
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

    private function makeQG(bool $interfaces = false): QueryGenerator
    {
        $ig = $interfaces ? new InterfaceGenerator('App') : null;
        return new QueryGenerator($this->catalog, $this->mapper, $this->dtoGen, 'App', $interfaces, $ig);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function code(string $sql, bool $interfaces = false): string
    {
        $q = $this->analyze($sql);
        return $this->makeQG($interfaces)->generate($q)['UserQuery']['code'];
    }

    /**
     * Find a generated file by its className (relPath key may be className.php or scoped/path.php).
     */
    private function findByClassName(array $files, string $className): ?array
    {
        foreach ($files as $entry) {
            if (($entry['className'] ?? '') === $className) {
                return $entry;
            }
        }
        return null;
    }

    // =========================================================================
    // Criteria — FilterOperator enum
    // =========================================================================

    public function test_filter_operator_is_multi_value(): void
    {
        $this->assertTrue(FilterOperator::IN->isMultiValue());
        $this->assertTrue(FilterOperator::NOT_IN->isMultiValue());
        $this->assertFalse(FilterOperator::EQ->isMultiValue());
        $this->assertFalse(FilterOperator::LIKE->isMultiValue());
    }

    public function test_filter_operator_is_no_value(): void
    {
        $this->assertTrue(FilterOperator::IS_NULL->isNoValue());
        $this->assertTrue(FilterOperator::IS_NOT_NULL->isNoValue());
        $this->assertFalse(FilterOperator::EQ->isNoValue());
    }

    public function test_filter_operator_is_two_value(): void
    {
        $this->assertTrue(FilterOperator::BETWEEN->isTwoValue());
        $this->assertFalse(FilterOperator::EQ->isTwoValue());
    }

    // =========================================================================
    // Criteria — Filter named constructors
    // =========================================================================

    public function test_filter_eq(): void
    {
        $f = Filter::eq('active', 1);
        $this->assertSame('active', $f->column);
        $this->assertSame(FilterOperator::EQ, $f->operator);
        $this->assertSame(1, $f->value);
    }

    public function test_filter_like_wraps_with_percent(): void
    {
        $f = Filter::like('email', 'cristian');
        $this->assertSame('%cristian%', $f->value);
        $this->assertSame(FilterOperator::LIKE, $f->operator);
    }

    public function test_filter_starts_with(): void
    {
        $f = Filter::starts('email', 'cristian');
        $this->assertSame('cristian%', $f->value);
    }

    public function test_filter_ends_with(): void
    {
        $f = Filter::ends('email', 'cristian');
        $this->assertSame('%cristian', $f->value);
    }

    public function test_filter_in(): void
    {
        $f = Filter::in('country_id', [1, 2, 3]);
        $this->assertSame(FilterOperator::IN, $f->operator);
        $this->assertSame([1, 2, 3], $f->value);
    }

    public function test_filter_not_in(): void
    {
        $f = Filter::notIn('status', ['a', 'b']);
        $this->assertSame(FilterOperator::NOT_IN, $f->operator);
    }

    public function test_filter_is_null(): void
    {
        $f = Filter::isNull('name');
        $this->assertSame(FilterOperator::IS_NULL, $f->operator);
        $this->assertNull($f->value);
    }

    public function test_filter_between(): void
    {
        $from = new \DateTimeImmutable('2024-01-01');
        $to   = new \DateTimeImmutable('2024-12-31');
        $f    = Filter::between('created_at', $from, $to);
        $this->assertSame(FilterOperator::BETWEEN, $f->operator);
        $this->assertSame($from, $f->value);
        $this->assertSame($to,   $f->valueTo);
    }

    // =========================================================================
    // Criteria — SQL clause generation
    // =========================================================================

    public function test_empty_criteria_produces_no_clause(): void
    {
        $c = new Criteria();
        $this->assertSame('', $c->toWhereClause());
        $this->assertSame('', $c->toOrderClause());
        $this->assertTrue($c->isEmpty());
    }

    public function test_single_eq_filter_produces_where_clause(): void
    {
        $c = (new Criteria())->add(Filter::eq('active', 1));
        $this->assertSame(' WHERE active = :active_f0', $c->toWhereClause());
    }

    public function test_two_filters_joined_with_and(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->add(Filter::eq('country_id', 164));
        $this->assertStringContainsString('active = :active_f0', $c->toWhereClause());
        $this->assertStringContainsString('AND', $c->toWhereClause());
        $this->assertStringContainsString('country_id = :country_id_f1', $c->toWhereClause());
    }

    public function test_append_mode_uses_and_not_where(): void
    {
        $c = (new Criteria())->add(Filter::eq('active', 1));
        $this->assertStringStartsWith(' AND ', $c->toFilterClause(appendMode: true));
        $this->assertStringStartsWith(' WHERE ', $c->toFilterClause(appendMode: false));
    }

    public function test_in_filter_expands_placeholders(): void
    {
        $c = (new Criteria())->add(Filter::in('country_id', [1, 2, 3]));
        $clause = $c->toWhereClause();
        $this->assertStringContainsString('IN', $clause);
        $this->assertStringContainsString(':country_id_f0_0', $clause);
        $this->assertStringContainsString(':country_id_f0_1', $clause);
        $this->assertStringContainsString(':country_id_f0_2', $clause);
    }

    public function test_not_in_filter(): void
    {
        $c = (new Criteria())->add(Filter::notIn('active', [0, 3]));
        $this->assertStringContainsString('NOT IN', $c->toWhereClause());
    }

    public function test_like_filter(): void
    {
        $c = (new Criteria())->add(Filter::like('email', 'test'));
        $clause = $c->toWhereClause();
        $this->assertStringContainsString('LIKE', $clause);
        $this->assertStringContainsString(':email_f0', $clause);
    }

    public function test_is_null_filter(): void
    {
        $c = (new Criteria())->add(Filter::isNull('name'));
        $this->assertStringContainsString('name IS NULL', $c->toWhereClause());
    }

    public function test_is_not_null_filter(): void
    {
        $c = (new Criteria())->add(Filter::isNotNull('name'));
        $this->assertStringContainsString('name IS NOT NULL', $c->toWhereClause());
    }

    public function test_between_filter(): void
    {
        $from = new \DateTimeImmutable('2024-01-01');
        $to   = new \DateTimeImmutable('2024-12-31');
        $c    = (new Criteria())->add(Filter::between('created_at', $from, $to));
        $clause = $c->toWhereClause();
        $this->assertStringContainsString('BETWEEN', $clause);
        $this->assertStringContainsString(':created_at_f0_from', $clause);
        $this->assertStringContainsString(':created_at_f0_to', $clause);
    }

    public function test_order_by_clause(): void
    {
        $c = new Criteria();
        $c = $c->orderBy('created_at', 'DESC');
        $this->assertSame(' ORDER BY created_at DESC', $c->toOrderClause());
    }

    public function test_order_by_defaults_to_asc(): void
    {
        $c = (new Criteria())->orderBy('id');
        $this->assertSame(' ORDER BY id ASC', $c->toOrderClause());
    }

    public function test_criteria_is_immutable(): void
    {
        $original = new Criteria();
        $modified = $original->add(Filter::eq('active', 1));
        $this->assertTrue($original->isEmpty());
        $this->assertFalse($modified->isEmpty());
    }

    public function test_in_with_empty_values_produces_always_false(): void
    {
        $c      = (new Criteria())->add(Filter::in('id', []));
        $clause = $c->toWhereClause();
        $this->assertStringContainsString('1 = 0', $clause);
    }

    public function test_not_in_with_empty_values_produces_always_true(): void
    {
        $c      = (new Criteria())->add(Filter::notIn('id', []));
        $clause = $c->toWhereClause();
        $this->assertStringContainsString('1 = 1', $clause);
    }

    // =========================================================================
    // Criteria — allowedColumns whitelist
    // =========================================================================

    public function test_order_by_with_allowed_columns_throws_for_invalid(): void
    {
        $c = new class extends Criteria {
            protected array $allowedColumns = ['id', 'email'];
        };
        $this->expectException(\InvalidArgumentException::class);
        $c->orderBy('injected_column; DROP TABLE users');
    }

    public function test_order_by_with_allowed_columns_accepts_valid(): void
    {
        $c = new class extends Criteria {
            protected array $allowedColumns = ['id', 'email'];
        };
        $result = $c->orderBy('email', 'DESC');
        $this->assertSame(' ORDER BY email DESC', $result->toOrderClause());
    }

    // =========================================================================
    // Parser — @searchable annotation
    // =========================================================================

    public function test_searchable_flag_parsed(): void
    {
        $q = $this->parser->parse(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;"
        );
        $this->assertTrue($q[0]->searchable);
    }

    public function test_searchable_false_by_default(): void
    {
        $q = $this->parser->parse(
            "-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;"
        );
        $this->assertFalse($q[0]->searchable);
    }

    public function test_searchable_preserved_through_analyzer(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;"
        );
        $this->assertTrue($q[0]->searchable);
    }

    // =========================================================================
    // Analyzer validation
    // =========================================================================

    public function test_searchable_on_one_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@searchable.*:many/');
        $this->analyze(
            "-- @name GetUser\n-- @searchable\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
    }

    public function test_searchable_on_exec_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->analyze(
            "-- @name DelUser\n-- @searchable\n-- @returns :exec\nDELETE FROM users WHERE id = :id;"
        );
    }

    public function test_searchable_on_many_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;"
        );
        $this->assertNotEmpty($q);
    }

    public function test_searchable_on_many_paginated_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertNotEmpty($q);
    }

    // =========================================================================
    // CriteriaGenerator — class generation
    // =========================================================================

    public function test_criteria_class_is_generated_for_searchable(): void
    {
        $q     = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $files = $this->makeQG()->generate($q);
        $this->assertNotNull($this->findByClassName($files, 'UserCriteria'), 'UserCriteria must exist');
    }

    public function test_criteria_class_not_generated_without_searchable(): void
    {
        $q     = $this->analyze("-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;");
        $files = $this->makeQG()->generate($q);
        $this->assertNull($this->findByClassName($files, 'UserCriteria'), 'UserCriteria must not exist');
    }

    public function test_criteria_class_extends_base_criteria(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('extends Criteria', $code);
        $this->assertStringContainsString('use SqlcPhp\\Criteria\\Criteria', $code);
    }

    public function test_criteria_class_has_column_constants(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('COLUMN_ID', $code);
        $this->assertStringContainsString('COLUMN_EMAIL', $code);
        $this->assertStringContainsString("'id'", $code);
    }

    public function test_criteria_int_column_generates_eq_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('function whereIdEq(int $value)', $code);
    }

    public function test_criteria_int_column_generates_in_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('function whereIdIn(int ...$values)', $code);
    }

    public function test_criteria_int_column_generates_not_in_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('function whereIdNotIn(int ...$values)', $code);
    }

    public function test_criteria_string_column_generates_like_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('function whereEmailLike(string $value)', $code);
        $this->assertStringContainsString('function whereEmailStartsWith(string $value)', $code);
        $this->assertStringContainsString('function whereEmailEndsWith(string $value)', $code);
    }

    public function test_criteria_nullable_column_generates_is_null_methods(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        // name is nullable VARCHAR → should have IsNull/IsNotNull
        $this->assertStringContainsString('function whereNameIsNull()', $code);
        $this->assertStringContainsString('function whereNameIsNotNull()', $code);
    }

    public function test_criteria_non_nullable_int_has_no_is_null_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT id, active FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        // active is TINYINT NOT NULL → no IsNull/IsNotNull
        $this->assertStringNotContainsString('whereActiveIsNull', $code);
    }

    public function test_criteria_all_columns_have_order_by_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT id, email FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('function orderById(', $code);
        $this->assertStringContainsString('function orderByEmail(', $code);
    }

    public function test_criteria_date_column_generates_between_method(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT created_at FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString('function whereCreatedAtBetween(\\DateTimeImmutable $from, \\DateTimeImmutable $to)', $code);
    }

    public function test_criteria_allowed_columns_whitelist_includes_all_columns(): void
    {
        $q    = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT id, email FROM users;");
        $code = $this->findByClassName($this->makeQG()->generate($q), 'UserCriteria')['code'];
        $this->assertStringContainsString("'id', 'email'", $code);
    }

    // =========================================================================
    // Generated method — :many + @searchable
    // =========================================================================

    public function test_searchable_many_method_has_criteria_parameter(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('?UserCriteria $criteria = null', $code);
    }

    public function test_searchable_many_method_builds_dynamic_sql(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('$__sql', $code);
        $this->assertStringContainsString('hasFilters()', $code);
        $this->assertStringContainsString('toFilterClause(', $code);
    }

    public function test_searchable_many_method_calls_criteria_bind_all(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('$criteria?->bindAll($stmt)', $code);
    }

    public function test_searchable_with_static_where_uses_append_mode(): void
    {
        $code = $this->code(
            "-- @name ListActive\n-- @searchable\n-- @returns :many\n" .
            "SELECT * FROM users WHERE active = 1;"
        );
        // When SQL has WHERE, $__hasWhere = true → AND mode
        $this->assertStringContainsString('$__hasWhere = true', $code);
    }

    public function test_searchable_without_static_where_uses_where_mode(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        // No WHERE in SQL → $__hasWhere = false → WHERE mode
        $this->assertStringContainsString('$__hasWhere = false', $code);
    }

    public function test_searchable_applies_order_by(): void
    {
        $code = $this->code("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $this->assertStringContainsString('toOrderClause()', $code);
    }

    public function test_searchable_preserves_static_order_by_when_criteria_has_none(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many\n" .
            "SELECT * FROM users ORDER BY created_at DESC;"
        );
        // Static ORDER BY should appear as fallback
        $this->assertStringContainsString('ORDER BY created_at DESC', $code);
    }

    public function test_searchable_with_group_by(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many\n" .
            "SELECT id, active FROM users GROUP BY id;"
        );
        // GROUP BY preserved, criteria appended before GROUP BY would not be
        // possible with simple append — the base SQL has no WHERE, criteria adds WHERE before GROUP BY
        $this->assertStringContainsString('$__sql', $code);
        $this->assertStringContainsString('GROUP BY', $code);
    }

    // =========================================================================
    // Generated method — :many-paginated + @searchable
    // =========================================================================

    public function test_searchable_paginated_has_criteria_and_limit(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('?UserCriteria $criteria = null', $code);
        $this->assertStringContainsString('?int $limit = null', $code);
        $this->assertStringContainsString('int $offset = 0', $code);
    }

    public function test_searchable_paginated_has_two_branches(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $this->assertStringContainsString('if ($limit === null)', $code);
    }

    public function test_searchable_paginated_null_branch_has_no_limit_offset(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $ifPos   = strpos($code, 'if ($limit === null)');
        $elsePos = strpos($code, '} else {');
        $nullBranch = substr($code, $ifPos, $elsePos - $ifPos);
        $this->assertStringNotContainsString(':limit',  $nullBranch);
        $this->assertStringNotContainsString(':offset', $nullBranch);
    }

    public function test_searchable_paginated_else_branch_binds_limit_offset(): void
    {
        $code = $this->code(
            "-- @name ListUsers\n-- @searchable\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $elsePos    = strpos($code, '} else {');
        $executePos = strpos($code, '$stmt->execute()');
        $elseBranch = substr($code, $elsePos, $executePos - $elsePos);
        $this->assertStringContainsString("bindValue(':limit'",  $elseBranch);
        $this->assertStringContainsString("bindValue(':offset'", $elseBranch);
    }

    // =========================================================================
    // @searchable + @counted
    // =========================================================================

    public function test_searchable_counted_generates_count_method(): void
    {
        $q     = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $files = $this->makeQG()->generate($q);
        $code  = $files['UserQuery']['code'];
        $this->assertStringContainsString('function listUsersCount(', $code);
    }

    public function test_searchable_count_method_accepts_criteria(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];
        $countPos = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countPos);
        $countSig = substr($code, $countPos, 80);
        $this->assertStringContainsString('$criteria', $countSig);
    }

    public function test_searchable_count_has_no_limit_offset(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $code     = $this->makeQG()->generate($q)['UserQuery']['code'];
        $countPos = strpos($code, 'function listUsersCount');
        $this->assertNotFalse($countPos);
        $countCode = substr($code, $countPos);
        $this->assertStringNotContainsString("bindValue(':limit'",  $countCode);
        $this->assertStringNotContainsString("bindValue(':offset'", $countCode);
    }

    public function test_searchable_count_wraps_dynamic_sql_in_subquery(): void
    {
        $q    = $this->analyze(
            "-- @name ListUsers\n-- @searchable\n-- @counted\n-- @returns :many-paginated\nSELECT * FROM users;"
        );
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('_count_subquery', $code);
        $this->assertStringContainsString("SELECT COUNT(*) AS _total FROM (", $code);
    }

    // =========================================================================
    // Interface generation
    // =========================================================================

    public function test_interface_includes_searchable_signature(): void
    {
        $q   = $this->analyze("-- @name ListUsers\n-- @searchable\n-- @returns :many\nSELECT * FROM users;");
        $ig  = new InterfaceGenerator('App');
        $qg  = new QueryGenerator($this->catalog, $this->mapper, $this->dtoGen, 'App', true, $ig);
        $files = $qg->generateInterfaces($q);
        $code = $files['UserQueryInterface']['code'];
        $this->assertStringContainsString('?UserCriteria $criteria = null', $code);
    }

    // =========================================================================
    // @searchable does not break non-searchable queries
    // =========================================================================

    public function test_non_searchable_query_unchanged(): void
    {
        $q    = $this->analyze("-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;");
        $code = $this->makeQG()->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('function getUser(int $id)', $code);
        $this->assertStringNotContainsString('Criteria', $code);
        $this->assertStringNotContainsString('$__sql', $code);
    }

    public function test_mix_searchable_and_non_searchable_in_same_group(): void
    {
        $sql = <<<SQL
            -- @name GetUser
            -- @returns :one
            SELECT * FROM users WHERE id = :id;

            -- @name ListUsers
            -- @searchable
            -- @returns :many
            SELECT * FROM users;
        SQL;
        $q     = $this->analyze($sql);
        $files = $this->makeQG()->generate($q);

        $this->assertArrayHasKey('UserQuery',    $files);
        $this->assertNotNull($this->findByClassName($files, 'UserCriteria'), 'UserCriteria must exist');

        $qCode = $files['UserQuery']['code'];
        $this->assertStringContainsString('function getUser(int $id)', $qCode);
        $this->assertStringContainsString('function listUsers(?UserCriteria $criteria = null)', $qCode);
    }

    // =========================================================================
    // Criteria — bindAll (via mock PDOStatement)
    // =========================================================================

    public function test_criteria_bind_all_calls_bind_value(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);

        $stmt->expects($this->exactly(2))
             ->method('bindValue');

        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->add(Filter::eq('country_id', 164));

        $c->bindAll($stmt);
    }

    public function test_criteria_bind_all_in_expands_per_value(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        // IN with 3 values → 3 bindValue calls
        $stmt->expects($this->exactly(3))->method('bindValue');

        $c = (new Criteria())->add(Filter::in('id', [1, 2, 3]));
        $c->bindAll($stmt);
    }

    public function test_criteria_bind_all_skips_no_value_operators(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        // IS NULL → no bindValue calls
        $stmt->expects($this->never())->method('bindValue');

        $c = (new Criteria())->add(Filter::isNull('name'));
        $c->bindAll($stmt);
    }

    public function test_criteria_bind_all_between_binds_two_params(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->exactly(2))->method('bindValue');

        $c = (new Criteria())->add(
            Filter::between('created_at', new \DateTimeImmutable(), new \DateTimeImmutable())
        );
        $c->bindAll($stmt);
    }

    // =========================================================================
    // Enum column criteria methods (v2.12.1)
    // =========================================================================

    private function enumCriteriaCode(): string
    {
        $schema = <<<SQL
            CREATE TABLE orders (
                id       INT AUTO_INCREMENT PRIMARY KEY,
                status   ENUM('pending','paid','failed') NOT NULL,
                priority ENUM('low','high')              NULL,
                total    DECIMAL(10,2)                   NOT NULL
            );
        SQL;
        $cat    = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper = new MySQLTypeMapper([], new EnumGenerator('App\\Enums'));
        $pr     = new ParamResolver($cat, $mapper);
        $er     = new ExpressionTypeResolver($cat, $mapper);
        $cr     = new ColumnResolver($cat, $mapper, $pr, $er);
        $az     = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $cat);
        $dg     = new ResultDtoGenerator('App\\DTOs', $mapper);
        $qg     = new QueryGenerator($cat, $mapper, $dg, 'App\\Repos');
        $qs     = $az->analyze($this->parser->parse(
            "-- @name ListOrders\n-- @class Order\n-- @searchable\n-- @returns :many\n" .
            "SELECT id, status, priority, total FROM orders;"
        ));
        $files  = $qg->generate($qs);
        foreach ($files as $f) {
            if (($f['className'] ?? '') === 'OrderCriteria') return $f['code'];
        }
        return '';
    }

    public function test_enum_column_generates_eq_method_with_enum_type(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('function whereStatusEq(OrderStatus $value)', $code);
    }

    public function test_enum_column_generates_neq_method(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('function whereStatusNeq(OrderStatus $value)', $code);
    }

    public function test_enum_column_generates_in_method_variadic(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('function whereStatusIn(OrderStatus ...$values)', $code);
    }

    public function test_enum_column_generates_not_in_method(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('function whereStatusNotIn(OrderStatus ...$values)', $code);
    }

    public function test_nullable_enum_generates_is_null_methods(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('function wherePriorityIsNull()', $code);
        $this->assertStringContainsString('function wherePriorityIsNotNull()', $code);
    }

    public function test_non_nullable_enum_has_no_is_null_method(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringNotContainsString('whereStatusIsNull', $code);
    }

    public function test_enum_methods_use_value_extraction(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('$value->value', $code);
    }

    public function test_enum_methods_use_array_map_for_in(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('array_map(fn($v) => $v->value, $values)', $code);
    }

    public function test_enum_criteria_imports_enum_class(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('use App\\Enums\\OrderStatus;', $code);
    }

    public function test_enum_criteria_no_duplicate_use_statements(): void
    {
        $code = $this->enumCriteriaCode();
        preg_match_all('/^use .+;$/m', $code, $m);
        $uses   = $m[0];
        $unique = array_unique($uses);
        $this->assertSame(count($unique), count($uses),
            'Duplicate uses: ' . implode(', ', array_diff_assoc($uses, $unique)));
    }

    public function test_enum_criteria_no_string_methods_for_enum_col(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringNotContainsString('whereStatusLike', $code);
        $this->assertStringNotContainsString('whereStatusStartsWith', $code);
    }

    public function test_multiple_enum_columns_each_import_their_enum(): void
    {
        $code = $this->enumCriteriaCode();
        $this->assertStringContainsString('use App\\Enums\\OrderStatus;', $code);
        $this->assertStringContainsString('use App\\Enums\\OrderPriority;', $code);
    }
}
