<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;

class QueryParserTest extends TestCase
{
    private QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryParser();
    }

    // -------------------------------------------------------------------------
    // Annotation parsing
    // -------------------------------------------------------------------------

    public function test_parses_name_annotation(): void
    {
        $queries = $this->parser->parse($this->makeQuery('@name ListUsers', ':many', 'SELECT * FROM users'));
        $this->assertSame('listUsers', $queries[0]->name);
    }

    public function test_name_is_lowercased_first_char(): void
    {
        $queries = $this->parser->parse($this->makeQuery('@name GetUser', ':one', 'SELECT * FROM users WHERE id = :id'));
        $this->assertSame('getUser', $queries[0]->name);
    }

    public function test_parses_explicit_group(): void
    {
        $sql = "-- @name ListUsers\n-- @group User\n-- @returns :many\nSELECT * FROM users;";
        $this->assertSame('User', $this->parser->parse($sql)[0]->group);
    }

    public function test_infers_group_from_table_when_missing(): void
    {
        $sql = "-- @name ListUsers\n-- @returns :many\nSELECT * FROM users;";
        $this->assertSame('User', $this->parser->parse($sql)[0]->group);
    }

    public function test_infers_group_from_joined_table_name(): void
    {
        $sql = "-- @name ListOrders\n-- @returns :many\nSELECT * FROM orders;";
        $this->assertSame('Order', $this->parser->parse($sql)[0]->group);
    }

    // -------------------------------------------------------------------------
    // ReturnType variants
    // -------------------------------------------------------------------------

    public function test_parses_many_return_type(): void
    {
        $queries = $this->parser->parse($this->makeQuery('@name List', ':many', 'SELECT * FROM users'));
        $this->assertSame(ReturnType::Many, $queries[0]->returns);
    }

    public function test_parses_one_return_type(): void
    {
        $queries = $this->parser->parse($this->makeQuery('@name Get', ':one', 'SELECT * FROM users WHERE id = :id'));
        $this->assertSame(ReturnType::One, $queries[0]->returns);
    }

    public function test_parses_opt_return_type(): void
    {
        $queries = $this->parser->parse($this->makeQuery('@name Find', ':opt', 'SELECT * FROM users WHERE email = :email'));
        $this->assertSame(ReturnType::Opt, $queries[0]->returns);
    }

    public function test_parses_exec_return_type(): void
    {
        $queries = $this->parser->parse($this->makeQuery('@name Delete', ':exec', 'DELETE FROM users WHERE id = :id'));
        $this->assertSame(ReturnType::Exec, $queries[0]->returns);
    }

    // -------------------------------------------------------------------------
    // SQL extraction
    // -------------------------------------------------------------------------

    public function test_strips_annotation_comments_from_sql(): void
    {
        $sql     = "-- @name List\n-- @returns :many\nSELECT * FROM users;";
        $queries = $this->parser->parse($sql);

        $this->assertStringNotContainsString('@name', $queries[0]->sql);
        $this->assertStringContainsString('SELECT', $queries[0]->sql);
    }

    public function test_extracts_from_table(): void
    {
        $sql = "-- @name List\n-- @returns :many\nSELECT * FROM users;";
        $this->assertSame('users', $this->parser->parse($sql)[0]->fromTable);
    }

    public function test_from_table_is_null_for_non_select(): void
    {
        $sql = "-- @name Del\n-- @returns :exec\nDELETE FROM users WHERE id = :id;";
        $this->assertSame('users', $this->parser->parse($sql)[0]->fromTable);
    }

    // -------------------------------------------------------------------------
    // Multiple queries in one file
    // -------------------------------------------------------------------------

    public function test_parses_multiple_queries(): void
    {
        $sql = <<<SQL
            -- @name ListUsers
            -- @returns :many
            SELECT * FROM users;

            -- @name GetUser
            -- @returns :one
            SELECT * FROM users WHERE id = :id;
        SQL;

        $queries = $this->parser->parse($sql);
        $this->assertCount(2, $queries);
        $this->assertSame('listUsers', $queries[0]->name);
        $this->assertSame('getUser', $queries[1]->name);
    }

    public function test_skips_blocks_without_name_annotation(): void
    {
        $sql = "-- @returns :many\nSELECT * FROM users;";
        $this->assertCount(0, $this->parser->parse($sql));
    }

    public function test_skips_blocks_without_returns_annotation(): void
    {
        $sql = "-- @name ListUsers\nSELECT * FROM users;";
        $this->assertCount(0, $this->parser->parse($sql));
    }

    // -------------------------------------------------------------------------
    // Blank lines inside query body
    // -------------------------------------------------------------------------

    public function test_blank_line_inside_select_list_is_tolerated(): void
    {
        $sql = <<<SQL
            -- @name GetUser
            -- @returns :one
            SELECT
                users.id,
                users.email,

                users.username
            FROM users
            WHERE users.id = :id;
        SQL;

        $queries = $this->parser->parse($sql);
        $this->assertCount(1, $queries);
        $this->assertSame('getUser', $queries[0]->name);
    }

    public function test_blank_line_between_annotations_and_sql_is_tolerated(): void
    {
        $sql = <<<SQL
            -- @name ListUsers
            -- @returns :many

            SELECT * FROM users;
        SQL;

        $queries = $this->parser->parse($sql);
        $this->assertCount(1, $queries);
    }

    public function test_multiple_blank_lines_inside_query_body_are_tolerated(): void
    {
        $sql = <<<SQL
            -- @name GetUser
            -- @returns :one
            SELECT
                users.id,

                users.email,

                users.username
            FROM users

            WHERE users.id = :id;
        SQL;

        $queries = $this->parser->parse($sql);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('FROM users', $queries[0]->sql);
    }

    public function test_blank_lines_in_query_do_not_affect_subsequent_query(): void
    {
        $sql = <<<SQL
            -- @name GetUser
            -- @returns :one
            SELECT
                users.id,

                users.email
            FROM users
            WHERE users.id = :id;

            -- @name ListUsers
            -- @returns :many
            SELECT * FROM users;
        SQL;

        $queries = $this->parser->parse($sql);
        $this->assertCount(2, $queries);
        $this->assertSame('getUser',   $queries[0]->name);
        $this->assertSame('listUsers', $queries[1]->name);
    }

    public function test_sql_content_is_preserved_across_blank_lines(): void
    {
        $sql = <<<SQL
            -- @name GetUser
            -- @returns :one
            SELECT users.id, users.email

            FROM users
            WHERE users.id = :id;
        SQL;

        $queries = $this->parser->parse($sql);
        $this->assertStringContainsString('SELECT users.id', $queries[0]->sql);
        $this->assertStringContainsString('FROM users',      $queries[0]->sql);
        $this->assertStringContainsString('WHERE users.id',  $queries[0]->sql);
    }

    // -------------------------------------------------------------------------
    // @param annotations
    // -------------------------------------------------------------------------

    public function test_parses_param_annotation(): void
    {
        $sql = "-- @name Get\n-- @returns :one\n-- @param userId users.id\nSELECT * FROM users WHERE id = :userId;";
        $query = $this->parser->parse($sql)[0];

        $this->assertArrayHasKey('userId', $query->paramAnnotations);
        $this->assertSame('users.id', $query->paramAnnotations['userId']);
    }

    public function test_parses_multiple_param_annotations(): void
    {
        $sql = "-- @name Upd\n-- @returns :exec\n-- @param userId users.id\n-- @param roleId roles.id\nUPDATE users SET role_id = :roleId WHERE id = :userId;";
        $query = $this->parser->parse($sql)[0];

        $this->assertCount(2, $query->paramAnnotations);
        $this->assertArrayHasKey('userId', $query->paramAnnotations);
        $this->assertArrayHasKey('roleId', $query->paramAnnotations);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function test_to_singular_regular_plural(): void
    {
        $this->assertSame('user',    $this->parser->toSingular('users'));
        $this->assertSame('role',    $this->parser->toSingular('roles'));
        $this->assertSame('country', $this->parser->toSingular('countries'));
        $this->assertSame('status',  $this->parser->toSingular('statuses'));
    }

    public function test_to_pascal_case(): void
    {
        $this->assertSame('User', $this->parser->toPascalCase('user'));
        $this->assertSame('Role', $this->parser->toPascalCase('role'));
    }

    // -------------------------------------------------------------------------
    // Fixture helper
    // -------------------------------------------------------------------------

    private function makeQuery(string $nameLine, string $returns, string $sql): string
    {
        return "-- {$nameLine}\n-- @returns {$returns}\n{$sql}";
    }
}
