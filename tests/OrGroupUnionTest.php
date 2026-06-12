<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Criteria\Criteria;
use SqlcPhp\Criteria\Filter;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class OrGroupUnionTest extends TestCase
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
                country_id INT           NULL,
                role       VARCHAR(50)   NOT NULL DEFAULT 'user'
            );
            CREATE TABLE admins (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL,
                name  VARCHAR(100) NULL,
                level INT          NOT NULL DEFAULT 1
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

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // =========================================================================
    // OrGroup — Criteria::orGroup() API
    // =========================================================================

    public function test_or_group_empty_criteria_has_no_or_groups(): void
    {
        $c = new Criteria();
        $this->assertFalse($c->hasFilters());
        $this->assertTrue($c->isEmpty());
    }

    public function test_or_group_single_top_level_filter_no_parens(): void
    {
        // Without OR groups, behaviour must be backward compatible — no extra parens
        $c = (new Criteria())->add(Filter::eq('active', 1));
        $this->assertSame(' WHERE active = :active_f0', $c->toFilterClause());
    }

    public function test_or_group_top_level_and_filters_no_parens(): void
    {
        // Multiple top-level AND filters without OR groups → plain AND chain, no parens
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->add(Filter::eq('country_id', 164));
        $this->assertSame(
            ' WHERE active = :active_f0 AND country_id = :country_id_f1',
            $c->toFilterClause()
        );
    }

    public function test_or_group_single_or_group_generates_or(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)));

        $sql = $c->toFilterClause();
        $this->assertStringContainsString(' WHERE ', $sql);
        $this->assertStringContainsString(' OR ', $sql);
        $this->assertStringContainsString('active = :active_f0', $sql);
        $this->assertStringContainsString('country_id = :country_id_f1', $sql);
    }

    public function test_or_group_two_or_groups(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 165)));

        $sql = $c->toFilterClause();
        // Three segments joined by OR
        $this->assertSame(2, substr_count($sql, ' OR '));
        $this->assertStringContainsString('country_id = :country_id_f1', $sql);
        $this->assertStringContainsString('country_id = :country_id_f2', $sql);
    }

    public function test_or_group_multi_filter_group_wrapped_in_parens(): void
    {
        // Group with multiple filters → (cond1 AND cond2)
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c
                ->add(Filter::eq('country_id', 164))
                ->add(Filter::like('email', 'test'))
            );

        $sql = $c->toFilterClause();
        // The OR group has two conditions → must be wrapped
        $this->assertStringContainsString('(country_id = :country_id_f1 AND email LIKE :email_f2)', $sql);
    }

    public function test_or_group_only_or_groups_no_top_level(): void
    {
        $c = (new Criteria())
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 165)));

        $sql = $c->toFilterClause();
        $this->assertStringContainsString(' WHERE ', $sql);
        $this->assertStringContainsString(' OR ', $sql);
    }

    public function test_or_group_immutability(): void
    {
        $original = (new Criteria())->add(Filter::eq('active', 1));
        $withOr   = $original->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)));

        // Original must be unchanged
        $this->assertStringNotContainsString('OR', $original->toFilterClause());
        $this->assertStringContainsString('OR', $withOr->toFilterClause());
    }

    public function test_or_group_returns_same_concrete_type(): void
    {
        // orGroup callback receives the same concrete type (allows subclass methods)
        $c = (new Criteria())
            ->orGroup(fn(Criteria $inner) => $inner->add(Filter::isNull('name')));

        // No exception — empty IS NULL group contributes correctly
        $sql = $c->toFilterClause();
        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function test_or_group_empty_callback_ignored(): void
    {
        // Callback that adds no filters → group is silently ignored
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c); // returns with no filters added

        // No OR in output
        $this->assertStringNotContainsString('OR', $c->toFilterClause());
    }

    public function test_or_group_placeholder_indices_unique_across_all_groups(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('country_id', 100))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 165)));

        $bindings = $c->getBindings();

        // All three country_id filters must have unique placeholders
        $this->assertArrayHasKey(':country_id_f0', $bindings);
        $this->assertArrayHasKey(':country_id_f1', $bindings);
        $this->assertArrayHasKey(':country_id_f2', $bindings);

        $this->assertSame([100],  [$bindings[':country_id_f0'][0]]);
        $this->assertSame([164],  [$bindings[':country_id_f1'][0]]);
        $this->assertSame([165],  [$bindings[':country_id_f2'][0]]);
    }

    public function test_or_group_get_bindings_includes_or_group_values(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c
                ->add(Filter::eq('country_id', 164))
                ->add(Filter::like('email', 'test'))
            );

        $bindings = $c->getBindings();
        $this->assertCount(3, $bindings);
        $this->assertArrayHasKey(':active_f0', $bindings);
        $this->assertArrayHasKey(':country_id_f1', $bindings);
        $this->assertArrayHasKey(':email_f2', $bindings);
    }

    public function test_or_group_bind_all_binds_all_groups(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)));

        $bindings = $c->getBindings();
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->exactly(count($bindings)))->method('bindValue');
        $c->bindAll($stmt);
    }

    public function test_or_group_append_mode(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)));

        $sql = $c->toFilterClause(appendMode: true);
        $this->assertStringStartsWith(' AND ', $sql);
        $this->assertStringContainsString(' OR ', $sql);
    }

    public function test_or_group_has_filters_true_when_only_or_groups(): void
    {
        $c = (new Criteria())
            ->orGroup(fn($c) => $c->add(Filter::eq('active', 1)));
        $this->assertTrue($c->hasFilters());
        $this->assertFalse($c->isEmpty());
    }

    // =========================================================================
    // OrGroup — integration with @searchable SQL building
    // =========================================================================

    public function test_or_group_sql_contains_or_keyword(): void
    {
        // Simulate how @searchable uses toFilterClause in generated code
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c->add(Filter::eq('country_id', 164)));

        $baseSql   = 'SELECT * FROM users';
        $fullSql   = $baseSql . $c->toFilterClause();

        $this->assertStringContainsString('WHERE', $fullSql);
        $this->assertStringContainsString('OR', $fullSql);
    }

    public function test_or_group_with_in_filter(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c->add(Filter::in('country_id', [164, 165])));

        $sql      = $c->toFilterClause();
        $bindings = $c->getBindings();

        $this->assertStringContainsString('IN', $sql);
        $this->assertArrayHasKey(':country_id_f1_0', $bindings);
        $this->assertArrayHasKey(':country_id_f1_1', $bindings);
    }

    public function test_or_group_with_between_filter(): void
    {
        $c = (new Criteria())
            ->orGroup(fn($c) => $c->add(Filter::between('country_id', 100, 200)));

        $sql      = $c->toFilterClause();
        $bindings = $c->getBindings();

        $this->assertStringContainsString('BETWEEN', $sql);
        $this->assertArrayHasKey(':country_id_f0_from', $bindings);
        $this->assertArrayHasKey(':country_id_f0_to', $bindings);
    }

    public function test_or_group_with_is_null_no_binding(): void
    {
        $c = (new Criteria())
            ->add(Filter::eq('active', 1))
            ->orGroup(fn($c) => $c->add(Filter::isNull('name')));

        $sql      = $c->toFilterClause();
        $bindings = $c->getBindings();

        $this->assertStringContainsString('IS NULL', $sql);
        // IS NULL has no binding
        $this->assertCount(1, $bindings);
    }

    // =========================================================================
    // UNION — parser detection
    // =========================================================================

    public function test_union_flag_detected_in_simple_union(): void
    {
        $q = $this->parser->parse(
            "-- @name GetAll\n-- @returns :many\n" .
            "SELECT id, email FROM users WHERE active = :active\n" .
            "UNION ALL\n" .
            "SELECT id, email FROM admins WHERE level = :level;"
        );
        $this->assertTrue($q[0]->isUnion);
    }

    public function test_union_flag_detected_case_insensitive(): void
    {
        $q = $this->parser->parse(
            "-- @name GetAll\n-- @returns :many\n" .
            "SELECT id, email FROM users\nunion all\nSELECT id, email FROM admins;"
        );
        $this->assertTrue($q[0]->isUnion);
    }

    public function test_non_union_flag_false(): void
    {
        $q = $this->parser->parse(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $this->assertFalse($q[0]->isUnion);
    }

    public function test_union_distinct_flag_detected(): void
    {
        $q = $this->parser->parse(
            "-- @name GetAll\n-- @returns :many\n" .
            "SELECT id FROM users UNION DISTINCT SELECT id FROM admins;"
        );
        $this->assertTrue($q[0]->isUnion);
    }

    // =========================================================================
    // UNION — analyzer validation
    // =========================================================================

    public function test_union_with_searchable_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@searchable.*UNION/');
        $this->analyze(
            "-- @name GetAll\n-- @searchable\n-- @returns :many\n" .
            "SELECT id, email FROM users\nUNION ALL\nSELECT id, email FROM admins;"
        );
    }

    public function test_union_with_partial_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/@partial.*UNION/');
        $this->analyze(
            "-- @name UpdateAll\n-- @partial\n-- @returns :exec\n" .
            "UPDATE users SET name = :name\nUNION ALL\nUPDATE admins SET name = :name;"
        );
    }

    public function test_union_basic_many_does_not_throw(): void
    {
        $q = $this->analyze(
            "-- @name ListAll\n-- @returns :many\n" .
            "SELECT id, email FROM users\nUNION ALL\nSELECT id, email FROM admins;"
        );
        $this->assertTrue($q[0]->isUnion);
    }

    // =========================================================================
    // UNION — column resolution (first SELECT only)
    // =========================================================================

    public function test_union_resolves_columns_from_first_select(): void
    {
        // Both SELECTs return same columns — verify we get types from first
        $q = $this->analyze(
            "-- @name ListAll\n-- @class User\n-- @returns :many\n" .
            "SELECT id, email FROM users\n" .
            "UNION ALL\n" .
            "SELECT id, email FROM admins;"
        );

        $cols = $q[0]->resultColumns;
        $names = array_map(fn($c) => $c->alias, $cols);
        $this->assertContains('id', $names);
        $this->assertContains('email', $names);
        $this->assertCount(2, $cols);
    }

    public function test_union_generates_dto_for_custom_columns(): void
    {
        // Aliased columns that don't map to a single table → DTO is generated
        $q = $this->analyze(
            "-- @name ListAll\n-- @class Member\n-- @returns :many\n" .
            "SELECT id, email, 'user' AS role FROM users\n" .
            "UNION ALL\n" .
            "SELECT id, email, 'admin' AS role FROM admins;"
        );

        $files = $this->qg->generate($q);
        $this->assertArrayHasKey('MemberQuery', $files, 'Query class must be generated');
    }

    public function test_union_params_from_all_branches_resolved(): void
    {
        // Parameters from both UNION branches must be in the method signature
        $q = $this->analyze(
            "-- @name SearchAll\n-- @class User\n-- @returns :many\n" .
            "SELECT id, email FROM users WHERE active = :active\n" .
            "UNION ALL\n" .
            "SELECT id, email FROM admins WHERE level = :level;"
        );

        $params = array_map(fn($p) => $p->name, $q[0]->params);
        $this->assertContains('active', $params, 'Param from first branch must be resolved');
        $this->assertContains('level',  $params, 'Param from second branch must be resolved');
    }

    public function test_union_generated_method_contains_union_sql(): void
    {
        $q = $this->analyze(
            "-- @name ListAll\n-- @class User\n-- @returns :many\n" .
            "SELECT id, email FROM users\n" .
            "UNION ALL\n" .
            "SELECT id, email FROM admins;"
        );

        $code = $this->qg->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('UNION ALL', $code);
    }

    public function test_union_generated_method_has_correct_return_type(): void
    {
        $q = $this->analyze(
            "-- @name ListAll\n-- @class User\n-- @returns :many\n" .
            "SELECT id, email FROM users\n" .
            "UNION ALL\n" .
            "SELECT id, email FROM admins;"
        );

        $code = $this->qg->generate($q)['UserQuery']['code'];
        $this->assertStringContainsString('function listAll', $code);
        $this->assertStringContainsString('): array', $code);
    }

    // =========================================================================
    // UNION — ColumnResolver extractFirstUnionBranch
    // =========================================================================

    public function test_union_select_list_from_first_branch_only(): void
    {
        // The second SELECT has different column aliases — should be ignored for typing
        $q = $this->analyze(
            "-- @name GetEntities\n-- @class User\n-- @returns :many\n" .
            "SELECT id, email FROM users\n" .
            "UNION ALL\n" .
            "SELECT id, email FROM admins;"
        );

        $cols  = $q[0]->resultColumns;
        $names = array_map(fn($c) => $c->alias, $cols);
        // Should have exactly the columns from the first SELECT
        $this->assertSame(['id', 'email'], $names);
    }

    public function test_union_subquery_union_not_split_prematurely(): void
    {
        // A UNION inside a subquery must not cause the outer SELECT to be split
        // This verifies that extractFirstUnionBranch respects paren depth
        $q = $this->analyze(
            "-- @name GetFiltered\n-- @class User\n-- @returns :many\n" .
            "SELECT * FROM (SELECT id, email FROM users UNION ALL SELECT id, email FROM admins) AS combined;"
        );

        // Should resolve columns from the outer SELECT (SELECT * from subquery)
        // The subquery UNION must not cause premature split
        $this->assertNotEmpty($q[0]->resultColumns);
    }

    public function test_union_with_opt_return_type(): void
    {
        $q = $this->analyze(
            "-- @name FindFirst\n-- @class User\n-- @returns :opt\n" .
            "SELECT id, email FROM users WHERE id = :id\n" .
            "UNION ALL\n" .
            "SELECT id, email FROM admins WHERE id = :id;"
        );
        $this->assertTrue($q[0]->isUnion);
        $this->assertSame(':opt', $q[0]->returns->value);
    }

    // =========================================================================
    // Duplicate placeholder expansion — UNION queries with same param in each branch
    // =========================================================================

    public function test_union_duplicate_param_expands_in_prepare_sql(): void
    {
        // PDO does not allow the same named placeholder more than once.
        // When :reserveId appears in both UNION branches, the second occurrence
        // must be renamed to :reserveId__2 in the SQL sent to prepare().
        $q    = $this->analyze(
            "-- @name GetProducts\n-- @class Reserve\n-- @dto ProductRow\n-- @returns :many\n" .
            "SELECT users.id, users.email FROM users WHERE users.active = :active\n" .
            "UNION ALL\n" .
            "SELECT users.id, users.email FROM admins WHERE admins.active = :active;"
        );
        $code = $this->qg->generate($q)['ReserveQuery']['code'];

        // Prepare SQL must have :active and :active__2
        $this->assertStringContainsString(':active__2', $code,
            'Second occurrence of :active must be renamed to :active__2 in prepare SQL');

        // Both must be bound
        $this->assertStringContainsString("bindValue(':active',",    $code);
        $this->assertStringContainsString("bindValue(':active__2',", $code);
    }

    public function test_union_duplicate_param_both_bindings_use_same_php_var(): void
    {
        $q    = $this->analyze(
            "-- @name GetProducts\n-- @class Reserve\n-- @dto ProductRow\n-- @returns :many\n" .
            "SELECT users.id, users.email FROM users WHERE users.active = :active\n" .
            "UNION ALL\n" .
            "SELECT users.id, users.email FROM admins WHERE admins.active = :active;"
        );
        $code = $this->qg->generate($q)['ReserveQuery']['code'];

        // Both bindings reference the same PHP variable $active
        $this->assertStringContainsString("bindValue(':active', \$active,",    $code);
        $this->assertStringContainsString("bindValue(':active__2', \$active,", $code);
    }

    public function test_union_three_occurrences_get_numbered_aliases(): void
    {
        // Three branches: :id appears three times → :id, :id__2, :id__3
        $q    = $this->analyze(
            "-- @name GetAll\n-- @class Reserve\n-- @dto Row\n-- @returns :many\n" .
            "SELECT users.id, users.email FROM users WHERE users.id = :id\n" .
            "UNION ALL SELECT users.id, users.email FROM admins WHERE admins.id = :id\n" .
            "UNION ALL SELECT users.id, users.email FROM users WHERE users.active = :id;"
        );
        $code = $this->qg->generate($q)['ReserveQuery']['code'];

        $this->assertStringContainsString(':id__2', $code);
        $this->assertStringContainsString(':id__3', $code);
        $this->assertStringContainsString("bindValue(':id__2', \$id,", $code);
        $this->assertStringContainsString("bindValue(':id__3', \$id,", $code);
    }

    public function test_union_queryobject_preserves_original_sql(): void
    {
        // QueryObject (for logging/debugging) must use the ORIGINAL SQL
        // with the repeated placeholder, not the expanded version.
        $q    = $this->analyze(
            "-- @name GetProducts\n-- @class Reserve\n-- @dto ProductRow\n-- @returns :many\n" .
            "SELECT users.id, users.email FROM users WHERE users.active = :active\n" .
            "UNION ALL\n" .
            "SELECT users.id, users.email FROM admins WHERE admins.active = :active;"
        );
        $code = $this->qg->generate($q)['ReserveQuery']['code'];

        // QueryObject constructor must get the original SQL (no __2 alias)
        $qoStart = strpos($code, 'new QueryObject(');
        $qoSnippet = substr($code, $qoStart, 300);
        $this->assertStringNotContainsString('active__2', $qoSnippet,
            'QueryObject must use original SQL, not the expanded version');
    }

    public function test_non_union_query_not_affected_by_expansion(): void
    {
        // A normal (non-UNION) query with one :param occurrence must not be changed.
        $q    = $this->analyze(
            "-- @name GetUser\n-- @returns :one\nSELECT * FROM users WHERE id = :id;"
        );
        $code = $this->qg->generate($q)['UserQuery']['code'];

        $this->assertStringNotContainsString('id__2', $code,
            'Non-UNION query with single param occurrence must not get alias suffixes');
    }

    public function test_union_different_params_each_branch_no_expansion(): void
    {
        // If each branch uses a different param name, no expansion is needed.
        $q    = $this->analyze(
            "-- @name GetCombined\n-- @class Reserve\n-- @dto Row\n-- @returns :many\n" .
            "SELECT users.id, users.email FROM users WHERE users.id = :userId\n" .
            "UNION ALL\n" .
            "SELECT users.id, users.email FROM admins WHERE admins.id = :adminId;"
        );
        $code = $this->qg->generate($q)['ReserveQuery']['code'];

        $this->assertStringNotContainsString('userId__2',  $code);
        $this->assertStringNotContainsString('adminId__2', $code);
        $this->assertStringContainsString("bindValue(':userId',",  $code);
        $this->assertStringContainsString("bindValue(':adminId',", $code);
    }

    public function test_version_is_2_9_0(): void
    {
        $this->assertSame('2.9.4', \SqlcPhp\Version::VERSION);
    }
}
