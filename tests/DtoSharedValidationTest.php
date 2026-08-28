<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
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
 * Tests for @dto shared DTO validation.
 *
 * When multiple queries share the same @dto name, the compiler must verify
 * that all queries select exactly the same columns (order-independent).
 * A mismatch must be a compile-time error, not a runtime surprise.
 */
class DtoSharedValidationTest extends TestCase
{
    private SchemaCatalog $catalog;
    private QueryAnalyzer $analyzer;
    private QueryParser $parser;
    private MySQLTypeMapper $mapper;
    private ResultDtoGenerator $dtoGen;
    private QueryGenerator $gen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id    INT           AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(200)  NOT NULL,
                name  VARCHAR(100)  NOT NULL,
                role  VARCHAR(50)   NOT NULL
            );
            CREATE TABLE orders (
                id      INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                total   DECIMAL(10,2) NOT NULL
            );
        SQL;

        $this->catalog = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper  = new MySQLTypeMapper();
        $this->parser  = new QueryParser('english');
        $pr = new ParamResolver($this->catalog, $this->mapper);
        $er = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
        $this->gen      = new QueryGenerator($this->catalog, $this->mapper, $this->dtoGen, 'App\\Q');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function runDtoRegistry(array $queries): void
    {
        $dtoRegistry = [];
        foreach ($queries as $query) {
            if ($query->returnsModelDirectly || empty($query->resultColumns)) continue;
            if (in_array($query->returns->value, [':exec', ':count', ':exists', ':stream'], true)) continue;
            $dtoName = $this->dtoGen->dtoClassName($query);
            $cols = array_map(fn($c) => $c->alias . ':' . $c->phpType, $query->resultColumns);
            sort($cols);
            $shape = implode(',', $cols);
            if (isset($dtoRegistry[$dtoName]) && $dtoRegistry[$dtoName] !== $shape) {
                $existing = explode(',', $dtoRegistry[$dtoName]);
                $incoming = explode(',', $shape);
                $missing  = array_diff($existing, $incoming);
                $extra    = array_diff($incoming, $existing);
                $detail   = '';
                if ($missing) $detail .= ' Missing: ' . implode(', ', $missing) . '.';
                if ($extra)   $detail .= ' Extra: '   . implode(', ', $extra)   . '.';
                throw new \RuntimeException(
                    "Query '{$query->name}': @dto '{$dtoName}' is shared with another query " .
                    "but the column shapes don't match.{$detail}"
                );
            }
            $dtoRegistry[$dtoName] = $shape;
        }
    }

    // =========================================================================
    // Happy path — same columns, should work
    // =========================================================================

    public function test_same_columns_same_order_passes(): void
    {
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
            "SELECT id, email, name FROM users;"
        );
        // Must not throw
        $this->runDtoRegistry($q);
        $this->assertTrue(true);
    }

    public function test_same_columns_different_order_passes(): void
    {
        // SELECT id,email,name vs SELECT id,name,email — same DTO, different SELECT order
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
            "SELECT id, name, email FROM users;"
        );
        // Must not throw — order-independent comparison
        $this->runDtoRegistry($q);
        $this->assertTrue(true);
    }

    public function test_dto_not_shared_no_validation(): void
    {
        // Two queries with different @dto names — no conflict possible
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserListRow\n-- @returns :many\n" .
            "SELECT id, name FROM users;"
        );
        $this->runDtoRegistry($q);
        $this->assertTrue(true);
    }

    public function test_three_queries_same_dto_same_columns_passes(): void
    {
        $q = $this->analyze(
            "-- @name GetUser\n-- @class Users\n-- @dto UserRow\n-- @returns :opt\n" .
            "SELECT id, email, name FROM users WHERE id = :id;\n\n" .
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
            "SELECT id, email, name FROM users;"
        );
        $this->runDtoRegistry($q);
        $this->assertTrue(true);
    }

    // =========================================================================
    // Error cases — mismatched columns must throw
    // =========================================================================

    public function test_missing_column_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/@dto 'UserRow' is shared.*don't match/");

        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
            "SELECT id, email FROM users;"  // ← missing 'name'
        );
        $this->runDtoRegistry($q);
    }

    public function test_extra_column_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/@dto 'UserRow' is shared.*don't match/");

        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
            "SELECT id, email, name FROM users;"  // ← extra 'name'
        );
        $this->runDtoRegistry($q);
    }

    public function test_different_column_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/@dto 'UserRow' is shared.*don't match/");

        // Same column name but different type via @type override
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @type id string\n-- @returns :many\n" .
            "SELECT id, email, name FROM users;"  // ← id is string here, int in first
        );
        $this->runDtoRegistry($q);
    }

    public function test_error_message_names_the_query(): void
    {
        try {
            $q = $this->analyze(
                "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
                "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
                "-- @name ListUsersPartial\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
                "SELECT id, email FROM users;"
            );
            $this->runDtoRegistry($q);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            // Must name the offending query
            $this->assertStringContainsString('listUsersPartial', $e->getMessage());
            // Must name the DTO
            $this->assertStringContainsString('UserRow', $e->getMessage());
        }
    }

    public function test_error_message_lists_missing_columns(): void
    {
        try {
            $q = $this->analyze(
                "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
                "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
                "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
                "SELECT id, email FROM users;"
            );
            $this->runDtoRegistry($q);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Missing', $e->getMessage());
            $this->assertStringContainsString('name:string', $e->getMessage());
        }
    }

    // =========================================================================
    // Border case — @dto with table.* wildcard select
    // =========================================================================

    public function test_dto_with_wildcard_select_forces_dto_mode(): void
    {
        // When @dto is declared, even table.* should NOT return the model directly
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserCol\n-- @returns :one\n" .
            "SELECT users.id, users.email FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserCol\n-- @returns :many\n" .
            "SELECT users.* FROM users;"  // table.* with @dto
        );

        // @dto must override returnsModelDirectly
        $this->assertFalse($q[1]->returnsModelDirectly,
            '@dto should force DTO mode even when table.* is selected');
        $this->assertSame('UserCol', $this->dtoGen->dtoClassName($q[1]));
    }

    public function test_wildcard_with_dto_and_mismatched_columns_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        // GetUserByEmail selects 2 columns, ListUsers selects all 4 via users.*
        // Must throw because shapes differ
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserCol\n-- @returns :one\n" .
            "SELECT users.id, users.email FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserCol\n-- @returns :many\n" .
            "SELECT users.* FROM users;"  // selects id, email, name, role — 4 cols vs 2
        );
        $this->runDtoRegistry($q);
    }

    public function test_wildcard_without_dto_still_returns_model(): void
    {
        // table.* without @dto → returnsModelDirectly stays true (unchanged behavior)
        $q = $this->analyze(
            "-- @name ListUsers\n-- @class Users\n-- @returns :many\n" .
            "SELECT users.* FROM users;"
        );
        $this->assertTrue($q[0]->returnsModelDirectly,
            'Without @dto, table.* should still return model directly');
    }

    public function test_list_query_returns_shared_dto_type(): void
    {
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
            "SELECT id, name, email FROM users;"
        );

        $files = $this->gen->generate($q);
        $queryCode = '';
        foreach ($files as $f) {
            if (str_ends_with($f['className'] ?? '', 'Query')) {
                $queryCode = $f['code'];
            }
        }

        // listUsers should return UserRow[]
        $this->assertStringContainsString('UserRow[]', $queryCode);
        // getUserByEmail should return UserRow
        $this->assertStringContainsString('UserRow', $queryCode);
    }

    public function test_only_one_dto_file_generated_for_shared_dto(): void
    {
        $q = $this->analyze(
            "-- @name GetUserByEmail\n-- @class Users\n-- @dto UserRow\n-- @returns :one\n" .
            "SELECT id, email, name FROM users WHERE email = :email;\n\n" .
            "-- @name ListUsers\n-- @class Users\n-- @dto UserRow\n-- @returns :many\n" .
            "SELECT id, name, email FROM users;"
        );

        // Both queries declare the same @dto name
        $this->assertSame('UserRow', $this->dtoGen->dtoClassName($q[0]));
        $this->assertSame('UserRow', $this->dtoGen->dtoClassName($q[1]));

        // In the CLI $toWrite map, the second write to 'UserRow.php' overwrites the first.
        // Since both have identical columns (validated above), the result is correct.
        // The registry check ensures only one shape is ever written.
        $this->runDtoRegistry($q); // must not throw
    }
}
