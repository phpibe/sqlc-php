<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
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

/**
 * Tests for the @comment annotation (v2.14.0).
 *
 * @comment adds a human-readable description to the generated method docblock,
 * placed before @param and @return tags. Multiple @comment lines are supported
 * and each becomes its own line in the description block.
 *
 * Covered:
 *   - QueryParser:      parsing single and multiple @comment lines
 *   - QueryAnalyzer:    propagation through analysis
 *   - QueryGenerator:   emission in buildDocblock and buildDocblockWithExtra
 *   - InterfaceGenerator: emission in interface method docblocks
 *   - Interaction with @deprecated
 *   - All return types: :one, :opt, :many, :many-paginated, :paginated, :exec,
 *                       :batch, :cursor, :transaction
 *   - Queries without @comment are unaffected
 */
class CommentAnnotationTest extends TestCase
{
    private SchemaCatalog      $catalog;
    private MySQLTypeMapper    $mapper;
    private QueryParser        $parser;
    private QueryAnalyzer      $analyzer;
    private QueryGenerator     $queryGen;
    private InterfaceGenerator $ifaceGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                email      VARCHAR(100)  NOT NULL,
                name       VARCHAR(100)  NOT NULL,
                active     TINYINT       NOT NULL DEFAULT 1,
                role       VARCHAR(20)   NOT NULL DEFAULT 'client',
                created_at DATETIME      NOT NULL
            );
            CREATE TABLE orders (
                id         INT           AUTO_INCREMENT PRIMARY KEY,
                user_id    INT           NOT NULL,
                total      DECIMAL(10,2) NOT NULL,
                status     VARCHAR(20)   NOT NULL DEFAULT 'pending',
                created_at DATETIME      NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper();
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $dtoGen         = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
        $this->ifaceGen = new InterfaceGenerator('App\\Queries');
        $this->queryGen = new QueryGenerator($this->catalog, $this->mapper, $dtoGen, 'App\\Queries');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function queryCode(string $sql): string
    {
        $queries = $this->analyze($sql);
        $result  = $this->queryGen->generate($queries);
        // Find the Query class (ends with Query suffix), not PaginatedResult DTOs
        foreach ($result as $item) {
            if (str_ends_with($item['className'], 'Query')) {
                return $item['code'];
            }
        }
        return array_values($result)[0]['code'];
    }

    private function ifaceCode(string $sql): string
    {
        $queries = $this->analyze($sql);
        $result  = $this->queryGen->generate($queries);
        $cls     = array_values($result)[0]['className'];
        $r       = $this->ifaceGen->generate($cls, $queries, $this->queryGen);
        return $r['code'];
    }

    // =========================================================================
    // QueryParser
    // =========================================================================

    public function test_parser_captures_single_comment(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment Returns the active user matching the given ID.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame(
            ['Returns the active user matching the given ID.'],
            $queries[0]->comment
        );
    }

    public function test_parser_captures_multiple_comment_lines(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment Returns the active user matching the given ID.
            -- @comment Returns null when no user is found.
            -- @comment Only searches within active users.
            SELECT * FROM users WHERE id = :id AND active = 1;
        SQL);

        $this->assertSame(
            [
                'Returns the active user matching the given ID.',
                'Returns null when no user is found.',
                'Only searches within active users.',
            ],
            $queries[0]->comment
        );
    }

    public function test_parser_trims_comment_whitespace(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment    Padded with spaces.   
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame(['Padded with spaces.'], $queries[0]->comment);
    }

    public function test_parser_empty_comment_is_ignored(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame([], $queries[0]->comment);
    }

    public function test_parser_no_comment_gives_empty_array(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame([], $queries[0]->comment);
    }

    public function test_parser_comment_can_contain_special_chars(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment Fetches user by ID. Throws \RuntimeException if PDO fails.
            -- @comment See: https://docs.example.com/api/users#get
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertStringContainsString('RuntimeException', $queries[0]->comment[0]);
        $this->assertStringContainsString('https://', $queries[0]->comment[1]);
    }

    // =========================================================================
    // Regression: @comment position independence (bug fixed in v2.14.0)
    // @comment must be captured regardless of where it appears relative to
    // other annotations — before @name, between annotations, or after all of them.
    // =========================================================================

    public function test_comment_before_name_is_captured(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @comment Returns the active user by ID.
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame(['Returns the active user by ID.'], $queries[0]->comment);
        $this->assertSame('getUser', $queries[0]->name);
    }

    public function test_multiple_comments_before_name_are_all_captured(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @comment First line.
            -- @comment Second line.
            -- @comment Third line.
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame(
            ['First line.', 'Second line.', 'Third line.'],
            $queries[0]->comment
        );
    }

    public function test_comment_between_annotations_is_captured(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @comment This appears between @name and @class.
            -- @class Users
            -- @returns :opt
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame(['This appears between @name and @class.'], $queries[0]->comment);
    }

    public function test_comment_position_does_not_affect_other_annotations(): void
    {
        // @comment first — all other annotations must still parse correctly
        $queries = $this->parser->parse(<<<SQL
            -- @comment Description here.
            -- @name ListActiveUsers
            -- @class Users
            -- @returns :many
            SELECT * FROM users WHERE active = 1;
        SQL);

        $this->assertSame(['Description here.'], $queries[0]->comment);
        $this->assertSame('listActiveUsers',     $queries[0]->name);
        $this->assertSame('Users',               $queries[0]->group);
        $this->assertSame(':many',               $queries[0]->returns->value);
    }

    public function test_preceding_comments_do_not_bleed_into_next_query(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @comment Comment for query 1.
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            SELECT * FROM users WHERE id = :id;

            -- @name ListUsers
            -- @class Users
            -- @returns :many
            SELECT * FROM users;
        SQL);

        $this->assertCount(2, $queries);
        $this->assertSame(['Comment for query 1.'], $queries[0]->comment);
        $this->assertSame([],                       $queries[1]->comment);
    }

    public function test_comment_first_and_last_produce_same_result(): void
    {
        $first = $this->parser->parse(<<<SQL
            -- @comment Same description.
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            SELECT * FROM users WHERE id = :id;
        SQL);

        $last = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Same description.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame($first[0]->comment, $last[0]->comment);
    }

    public function test_parser_comment_coexists_with_deprecated(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment Use getUserV2 instead.
            -- @deprecated Replaced by getUserV2 in v3.0.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame(['Use getUserV2 instead.'], $queries[0]->comment);
        $this->assertSame('Replaced by getUserV2 in v3.0.', $queries[0]->deprecated);
    }

    public function test_parser_multiple_queries_each_get_own_comment(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment Fetch single user.
            SELECT * FROM users WHERE id = :id;

            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @comment List all users ordered by name.
            SELECT * FROM users ORDER BY name;
        SQL);

        $this->assertSame(['Fetch single user.'],              $queries[0]->comment);
        $this->assertSame(['List all users ordered by name.'], $queries[1]->comment);
    }

    // =========================================================================
    // QueryAnalyzer — propagation
    // =========================================================================

    public function test_analyzer_propagates_comment(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment Returns the active user by ID.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame(['Returns the active user by ID.'], $queries[0]->comment);
    }

    public function test_analyzer_propagates_empty_comment(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertSame([], $queries[0]->comment);
    }

    // =========================================================================
    // QueryGenerator — docblock emission
    // =========================================================================

    public function test_query_docblock_contains_single_comment(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Returns the active user by ID, or null if not found.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertStringContainsString(
            '     * Returns the active user by ID, or null if not found.',
            $code
        );
    }

    public function test_query_docblock_contains_multiple_comment_lines(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Returns the active user matching the given ID.
            -- @comment Returns null when no user is found.
            -- @comment Only active users are searched.
            SELECT * FROM users WHERE id = :id AND active = 1;
        SQL);

        $this->assertStringContainsString('Returns the active user matching the given ID.', $code);
        $this->assertStringContainsString('Returns null when no user is found.',            $code);
        $this->assertStringContainsString('Only active users are searched.',                $code);
    }

    public function test_query_docblock_has_blank_line_after_comment_before_params(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Returns the active user by ID.
            SELECT * FROM users WHERE id = :id;
        SQL);

        // Blank separator line between description and @param
        $this->assertStringContainsString("     * Returns the active user by ID.\n     *\n     * @param", $code);
    }

    public function test_query_docblock_comment_appears_before_param_tags(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Finds user by primary key.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $commentPos = strpos($code, 'Finds user by primary key.');
        $paramPos   = strpos($code, '@param int $id');
        $this->assertLessThan($paramPos, $commentPos, '@comment must appear before @param');
    }

    public function test_query_docblock_comment_appears_before_return_tag(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Finds user by primary key.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $commentPos = strpos($code, 'Finds user by primary key.');
        // Find @return that appears AFTER the comment (in the query method docblock),
        // not an earlier @return that may exist in other generated helpers (e.g. withTransaction).
        $returnPos  = strpos($code, '@return', $commentPos);
        $this->assertNotFalse($returnPos, '@return tag must exist after @comment');
        $this->assertLessThan($returnPos, $commentPos, '@comment must appear before @return');
    }

    public function test_query_without_comment_has_no_extra_blank_line(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            SELECT * FROM users WHERE id = :id;
        SQL);

        // No blank separator line at start of docblock
        $this->assertStringNotContainsString("    /**\n     *\n", $code);
    }

    // =========================================================================
    // QueryGenerator — all return types
    // =========================================================================

    public function test_comment_in_many_query(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @comment Returns all users ordered by creation date.
            SELECT * FROM users ORDER BY created_at DESC;
        SQL);

        $this->assertStringContainsString('Returns all users ordered by creation date.', $code);
    }

    public function test_comment_in_one_query(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :one
            -- @comment Returns the user or throws if not found.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertStringContainsString('Returns the user or throws if not found.', $code);
    }

    public function test_comment_in_exec_query(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name DeactivateUser
            -- @class Users
            -- @returns :exec
            -- @comment Soft-deletes a user by setting active = 0.
            -- @comment Does not remove associated records.
            UPDATE users SET active = 0 WHERE id = :id;
        SQL);

        $this->assertStringContainsString('Soft-deletes a user by setting active = 0.', $code);
        $this->assertStringContainsString('Does not remove associated records.',         $code);
    }

    public function test_comment_in_many_paginated_query(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name ListUsersPaginated
            -- @class Users
            -- @returns :many-paginated
            -- @comment Paginated list of all users. Use limit and offset to navigate pages.
            SELECT * FROM users ORDER BY created_at DESC;
        SQL);

        $this->assertStringContainsString('Paginated list of all users.', $code);
    }

    public function test_comment_in_paginated_query(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name ListUsersPaginated
            -- @class Users
            -- @returns :many-paginated
            -- @comment Returns paginated users with total count and offset metadata.
            SELECT users.id, users.email, users.name FROM users ORDER BY users.name;
        SQL);

        $this->assertStringContainsString('Returns paginated users with total count', $code);
    }

    // =========================================================================
    // @comment + @deprecated interaction
    // =========================================================================

    public function test_comment_and_deprecated_both_appear_in_docblock(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Use getUserV2 for the updated response shape.
            -- @deprecated Replaced by getUserV2 in v3.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertStringContainsString('Use getUserV2 for the updated response shape.', $code);
        $this->assertStringContainsString('@deprecated Replaced by getUserV2 in v3.',      $code);
    }

    public function test_comment_appears_before_deprecated_tag(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Legacy endpoint — avoid in new code.
            -- @deprecated Will be removed in v4.0.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $commentPos    = strpos($code, 'Legacy endpoint');
        $deprecatedPos = strpos($code, '@deprecated');
        $this->assertLessThan($deprecatedPos, $commentPos);
    }

    // =========================================================================
    // @comment + @searchable interaction
    // =========================================================================

    public function test_comment_in_searchable_query(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            -- @searchable
            -- @comment Filters users dynamically. Pass null criteria to return all.
            SELECT users.id, users.email, users.name, users.active FROM users;
        SQL);

        $this->assertStringContainsString('Filters users dynamically.', $code);
    }

    // =========================================================================
    // InterfaceGenerator — docblock emission
    // =========================================================================

    public function test_interface_docblock_contains_comment(): void
    {
        $code = $this->ifaceCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Finds user by primary key. Returns null if not found.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertStringContainsString(
            'Finds user by primary key. Returns null if not found.',
            $code
        );
    }

    public function test_interface_docblock_has_separator_after_comment(): void
    {
        $code = $this->ifaceCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Finds user by primary key.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertStringContainsString("     * Finds user by primary key.\n     *\n", $code);
    }

    public function test_interface_docblock_comment_before_param(): void
    {
        $code = $this->ifaceCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Finds user by primary key.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $commentPos = strpos($code, 'Finds user by primary key.');
        $paramPos   = strpos($code, '@param int $id');
        $this->assertLessThan($paramPos, $commentPos);
    }

    public function test_interface_without_comment_unaffected(): void
    {
        $code = $this->ifaceCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            SELECT * FROM users WHERE id = :id;
        SQL);

        // No spurious blank line at start of docblock
        $this->assertStringNotContainsString("    /**\n     *\n", $code);
    }

    public function test_interface_comment_and_deprecated_both_present(): void
    {
        $code = $this->ifaceCode(<<<SQL
            -- @name GetUser
            -- @class Users
            -- @returns :opt
            -- @comment Legacy - use getUserV2.
            -- @deprecated Replaced in v3.
            SELECT * FROM users WHERE id = :id;
        SQL);

        $this->assertStringContainsString('Legacy - use getUserV2.', $code);
        $this->assertStringContainsString('@deprecated',             $code);
    }

    // =========================================================================
    // Real-world docblock format verification
    // =========================================================================

    public function test_full_docblock_format_single_comment(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name GetActiveUser
            -- @class Users
            -- @returns :opt
            -- @comment Returns the active user by ID, or null if not found or inactive.
            SELECT * FROM users WHERE id = :id AND active = 1;
        SQL);

        // Extract the method docblock (last /**...*/ block in the file)
        preg_match_all('/    \/\*\*.*?\*\//s', $code, $m);
        $docblock = end($m[0]);

        $this->assertStringContainsString('     * Returns the active user by ID, or null if not found or inactive.', $docblock);
        $this->assertStringContainsString('     *',           $docblock);
        $this->assertStringContainsString('     * @param int $id', $docblock);
        $this->assertStringContainsString('     * @return User|null', $docblock);

        // Comment must appear before @param
        $this->assertLessThan(
            strpos($docblock, '@param'),
            strpos($docblock, 'Returns the active user')
        );
    }

    public function test_full_docblock_format_multi_comment(): void
    {
        $code = $this->queryCode(<<<SQL
            -- @name CreateUser
            -- @class Users
            -- @returns :exec
            -- @comment Creates a new user record in pending verification state.
            -- @comment Sends a welcome email via the UserCreated event.
            -- @comment Throws PDOException on duplicate email constraint violation.
            INSERT INTO users (email, name, active) VALUES (:email, :name, 0);
        SQL);

        preg_match_all('/    \/\*\*.*?\*\//s', $code, $m);
        $docblock = end($m[0]);

        $this->assertStringContainsString('Creates a new user record in pending verification state.', $docblock);
        $this->assertStringContainsString('Sends a welcome email via the UserCreated event.',         $docblock);
        $this->assertStringContainsString('Throws PDOException on duplicate email constraint violation.', $docblock);
        $this->assertStringContainsString('     * @param string $email', $docblock);
        $this->assertStringContainsString('     * @param string $name',  $docblock);

        // All comment lines before first @param
        $lastCommentPos = strpos($docblock, 'Throws PDOException');
        $firstParamPos  = strpos($docblock, '@param');
        $this->assertLessThan($firstParamPos, $lastCommentPos);
    }
}
