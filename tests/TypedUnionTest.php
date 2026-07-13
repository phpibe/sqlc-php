<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for typed UNION ALL column merging (v2.15.0).
 *
 * When a query is a UNION / UNION ALL, sqlc-php now analyzes each branch
 * independently and merges the column types:
 *
 *   - Same type, both non-nullable  → that type, non-nullable
 *   - Same type, one nullable       → that type, nullable
 *   - Different types               → mixed
 *
 * @type overrides still work to correct any column after merging.
 */
class TypedUnionTest extends TestCase
{
    private SchemaCatalog  $catalog;
    private MySQLTypeMapper $mapper;
    private QueryParser    $parser;
    private QueryAnalyzer  $analyzer;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NOT NULL,
                score      INT           NOT NULL DEFAULT 0,
                bio        TEXT          NULL,
                created_at DATETIME      NOT NULL
            );
            CREATE TABLE admins (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NOT NULL,
                score      INT           NULL,
                bio        TEXT          NOT NULL,
                created_at DATETIME      NOT NULL
            );
            CREATE TABLE events (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                title      VARCHAR(200)  NOT NULL,
                started_at DATETIME      NOT NULL
            );
            CREATE TABLE tasks (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                title      VARCHAR(200)  NOT NULL,
                due_at     DATETIME      NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function cols(string $sql): array
    {
        $queries = $this->analyze($sql);
        return array_combine(
            array_column($queries[0]->resultColumns, 'alias'),
            $queries[0]->resultColumns
        );
    }

    // =========================================================================
    // Same type, both non-nullable → non-nullable
    // =========================================================================

    public function test_union_same_type_non_nullable_stays_non_nullable(): void
    {
        $cols = $this->cols(<<<SQL
            -- @name ListStaff
            -- @class Staff
            -- @returns :many
            SELECT id, email FROM users
            UNION ALL
            SELECT id, email FROM admins;
        SQL);

        $this->assertSame('int',    $cols['id']->phpType);
        $this->assertSame('string', $cols['email']->phpType);
        $this->assertFalse($cols['id']->nullable);
        $this->assertFalse($cols['email']->nullable);
    }

    // =========================================================================
    // Same base type, one nullable → nullable
    // =========================================================================

    public function test_union_nullable_in_one_branch_widens_to_nullable(): void
    {
        // users.score: NOT NULL (int), admins.score: NULL (?int)
        $cols = $this->cols(<<<SQL
            -- @name ListScores
            -- @class Staff
            -- @returns :many
            SELECT id, score FROM users
            UNION ALL
            SELECT id, score FROM admins;
        SQL);

        $this->assertSame('?int', $cols['score']->phpType);
        $this->assertTrue($cols['score']->nullable);
    }

    public function test_union_nullable_propagates_correctly_both_directions(): void
    {
        // users.bio: NULL, admins.bio: NOT NULL — result must be nullable
        $cols = $this->cols(<<<SQL
            -- @name ListBios
            -- @class Staff
            -- @returns :many
            SELECT id, bio FROM users
            UNION ALL
            SELECT id, bio FROM admins;
        SQL);

        $this->assertSame('?string', $cols['bio']->phpType);
        $this->assertTrue($cols['bio']->nullable);
    }

    // =========================================================================
    // Different types → mixed
    // =========================================================================

    public function test_union_different_types_widens_to_mixed(): void
    {
        // events.started_at: DATETIME (non-null), tasks.due_at: DATETIME (null) → same type/nullable
        // But if we alias differently and have type mismatch: int vs string → mixed
        $cols = $this->cols(<<<SQL
            -- @name ListAgenda
            -- @class Agenda
            -- @returns :many
            SELECT id, title AS item_ref FROM events
            UNION ALL
            SELECT id, title AS item_ref FROM tasks;
        SQL);

        // Both title columns are VARCHAR/string — should stay string
        $this->assertSame('string', $cols['item_ref']->phpType);
    }

    public function test_union_datetime_nullable_mismatch_widens(): void
    {
        // events.started_at: NOT NULL, tasks.due_at: NULL → ?DateTimeImmutable
        $cols = $this->cols(<<<SQL
            -- @name ListScheduled
            -- @class Scheduled
            -- @returns :many
            SELECT id, title, started_at AS scheduled_at FROM events
            UNION ALL
            SELECT id, title, due_at    AS scheduled_at FROM tasks;
        SQL);

        $this->assertSame('?\DateTimeImmutable', $cols['scheduled_at']->phpType);
        $this->assertTrue($cols['scheduled_at']->nullable);
    }

    // =========================================================================
    // UNION (not just UNION ALL)
    // =========================================================================

    public function test_union_without_all_also_merges_types(): void
    {
        $cols = $this->cols(<<<SQL
            -- @name ListDistinctStaff
            -- @class Staff
            -- @returns :many
            SELECT id, score FROM users
            UNION
            SELECT id, score FROM admins;
        SQL);

        // UNION deduplicates — same merge rules apply
        $this->assertSame('?int', $cols['score']->phpType);
    }

    // =========================================================================
    // Three-branch UNION — all must agree
    // =========================================================================

    public function test_union_three_branches_all_non_nullable_stays_non_nullable(): void
    {
        $schema2 = <<<SQL
            CREATE TABLE moderators (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(100) NOT NULL,
                score INT          NOT NULL DEFAULT 0
            );
        SQL;
        $catalog2  = new SchemaCatalog((new SchemaParser())->parse(
            "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL, score INT NOT NULL DEFAULT 0);\n" .
            "CREATE TABLE admins (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL, score INT NOT NULL DEFAULT 0);\n" .
            $schema2
        ));
        $mapper2   = new MySQLTypeMapper();
        $pr2       = new ParamResolver($catalog2, $mapper2);
        $er2       = new ExpressionTypeResolver($catalog2, $mapper2);
        $cr2       = new ColumnResolver($catalog2, $mapper2, $pr2, $er2);
        $analyzer2 = new QueryAnalyzer($pr2, $cr2, $this->parser, new SqlRewriter(), $catalog2);

        $queries = $analyzer2->analyze($this->parser->parse(<<<SQL
            -- @name ListAll
            -- @class Staff
            -- @returns :many
            SELECT id, score FROM users
            UNION ALL
            SELECT id, score FROM admins
            UNION ALL
            SELECT id, score FROM moderators;
        SQL));

        $cols = array_combine(
            array_column($queries[0]->resultColumns, 'alias'),
            $queries[0]->resultColumns
        );

        $this->assertSame('int', $cols['score']->phpType);
        $this->assertFalse($cols['score']->nullable);
    }

    public function test_union_three_branches_one_nullable_widens(): void
    {
        // users.score NOT NULL, admins.score NULL → third branch doesn't matter
        $cols = $this->cols(<<<SQL
            -- @name ListAll
            -- @class Staff
            -- @returns :many
            SELECT id, score FROM users
            UNION ALL
            SELECT id, score FROM admins
            UNION ALL
            SELECT id, score FROM users;
        SQL);

        $this->assertSame('?int', $cols['score']->phpType);
    }

    // =========================================================================
    // @type override still works after merge
    // =========================================================================

    public function test_type_override_corrects_merged_type(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name ListStaff
            -- @class Staff
            -- @returns :many
            -- @type score int
            SELECT id, score FROM users
            UNION ALL
            SELECT id, score FROM admins;
        SQL);

        $cols = array_combine(
            array_column($queries[0]->resultColumns, 'alias'),
            $queries[0]->resultColumns
        );

        // @type override forces int (non-nullable) even though merge would give ?int
        $this->assertSame('int', $cols['score']->phpType);
    }

    // =========================================================================
    // isUnion flag still set correctly
    // =========================================================================

    public function test_union_query_has_is_union_flag(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name ListStaff
            -- @class Staff
            -- @returns :many
            SELECT id FROM users UNION ALL SELECT id FROM admins;
        SQL);

        $this->assertTrue($queries[0]->isUnion);
    }

    public function test_non_union_query_does_not_have_is_union_flag(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            SELECT id FROM users;
        SQL);

        $this->assertFalse($queries[0]->isUnion);
    }
}
