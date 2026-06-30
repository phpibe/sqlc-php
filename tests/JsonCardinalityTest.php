<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

/**
 * Tests for @json:one and @json:many cardinality annotation variants.
 *
 * @json:one  alias ClassName  → single DTO object  (JSON_OBJECT columns)
 * @json:many alias ClassName  → typed DTO array    (JSON_ARRAYAGG columns, explicit)
 * @json      alias ClassName  → typed DTO array    (default, backward-compatible)
 */
class JsonCardinalityTest extends TestCase
{
    private SchemaCatalog      $catalog;
    private MySQLTypeMapper    $mapper;
    private QueryParser        $parser;
    private QueryAnalyzer      $analyzer;
    private ResultDtoGenerator $dtoGen;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id    INT          AUTO_INCREMENT PRIMARY KEY,
                name  VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL
            );
            CREATE TABLE addresses (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                street     VARCHAR(100) NOT NULL,
                city       VARCHAR(100) NOT NULL,
                country    VARCHAR(100) NOT NULL,
                user_id    INT          NOT NULL
            );
            CREATE TABLE orders (
                id         INT          AUTO_INCREMENT PRIMARY KEY,
                total      DECIMAL(10,2) NOT NULL,
                user_id    INT           NOT NULL
            );
        SQL;

        $this->catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $this->mapper   = new MySQLTypeMapper([], new EnumGenerator('App'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($this->catalog, $this->mapper);
        $er             = new ExpressionTypeResolver($this->catalog, $this->mapper);
        $cr             = new ColumnResolver($this->catalog, $this->mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter(), $this->catalog);
        $this->dtoGen   = new ResultDtoGenerator('App\\DTOs', $this->mapper, $this->catalog);
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    private function dto(string $sql): string
    {
        return $this->dtoGen->generate($this->analyze($sql)[0])['code'];
    }

    // =========================================================================
    // Parser — @json:one
    // =========================================================================

    public function test_parser_captures_json_one_cardinality(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUserWithAddress
            -- @class User
            -- @returns :one
            -- @json:one address Address
            SELECT users.id, users.name, JSON_OBJECT('id', addresses.id) AS address
            FROM users INNER JOIN addresses ON addresses.user_id = users.id
            WHERE users.id = :id;
        SQL);

        $this->assertSame(
            ['address' => ['class' => 'Address', 'many' => false]],
            $queries[0]->jsonColumns
        );
    }

    public function test_parser_captures_json_many_explicit(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUserWithOrders
            -- @class User
            -- @returns :one
            -- @json:many orders Order
            SELECT users.id, JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users INNER JOIN orders ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $this->assertSame(
            ['orders' => ['class' => 'Order', 'many' => true]],
            $queries[0]->jsonColumns
        );
    }

    public function test_parser_bare_json_defaults_to_many(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUserWithOrders
            -- @class User
            -- @returns :one
            -- @json orders Order
            SELECT users.id, JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users INNER JOIN orders ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $this->assertSame(
            ['orders' => ['class' => 'Order', 'many' => true]],
            $queries[0]->jsonColumns
        );
    }

    public function test_parser_mixed_cardinalities_on_same_query(): void
    {
        $queries = $this->parser->parse(<<<SQL
            -- @name GetUserFull
            -- @class User
            -- @returns :one
            -- @json:one  address Address
            -- @json:many orders  Order
            SELECT users.id,
                   JSON_OBJECT('id', addresses.id, 'street', addresses.street) AS address,
                   JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users
            LEFT JOIN addresses ON addresses.user_id = users.id
            LEFT JOIN orders    ON orders.user_id    = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $this->assertSame(
            [
                'address' => ['class' => 'Address', 'many' => false],
                'orders'  => ['class' => 'Order',   'many' => true],
            ],
            $queries[0]->jsonColumns
        );
    }

    // =========================================================================
    // ResultDtoGenerator — @json:one property and fromRow
    // =========================================================================

    public function test_json_one_generates_typed_property_not_array(): void
    {
        $code = $this->dto(<<<SQL
            -- @name GetUserWithAddress
            -- @class User
            -- @returns :one
            -- @json:one address Address
            SELECT users.id, users.name,
                   JSON_OBJECT('id', addresses.id, 'street', addresses.street) AS address
            FROM users INNER JOIN addresses ON addresses.user_id = users.id
            WHERE users.id = :id;
        SQL);

        // Must be Address $address, not array $address
        $this->assertStringContainsString('public Address $address,', $code);
        $this->assertStringNotContainsString('public array $address,', $code);
    }

    public function test_json_one_does_not_emit_var_docblock(): void
    {
        $code = $this->dto(<<<SQL
            -- @name GetUserWithAddress
            -- @class User
            -- @returns :one
            -- @json:one address Address
            SELECT users.id, users.name,
                   JSON_OBJECT('id', addresses.id, 'street', addresses.street) AS address
            FROM users INNER JOIN addresses ON addresses.user_id = users.id
            WHERE users.id = :id;
        SQL);

        // No @var docblock needed for a scalar typed property
        $this->assertStringNotContainsString('@var Address', $code);
    }

    public function test_json_one_fromrow_uses_single_object_decode(): void
    {
        $code = $this->dto(<<<SQL
            -- @name GetUserWithAddress
            -- @class User
            -- @returns :one
            -- @json:one address Address
            SELECT users.id, users.name,
                   JSON_OBJECT('id', addresses.id, 'street', addresses.street) AS address
            FROM users INNER JOIN addresses ON addresses.user_id = users.id
            WHERE users.id = :id;
        SQL);

        // fromRow: Address::fromRow(json_decode(..., true) ?? [])
        $this->assertStringContainsString(
            "Address::fromRow(json_decode((string) \$row['address'], true) ?? [])",
            $code
        );
        // Must NOT use array_map
        $this->assertStringNotContainsString('array_map', $code);
    }

    // =========================================================================
    // ResultDtoGenerator — @json:many (explicit) and @json (default)
    // =========================================================================

    public function test_json_many_generates_array_property_with_docblock(): void
    {
        $code = $this->dto(<<<SQL
            -- @name GetUserWithOrders
            -- @class User
            -- @returns :one
            -- @json:many orders Order
            SELECT users.id,
                   JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users INNER JOIN orders ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $this->assertStringContainsString('@var Order[]', $code);
        $this->assertStringContainsString('public array $orders,', $code);
    }

    public function test_json_many_fromrow_uses_array_map(): void
    {
        $code = $this->dto(<<<SQL
            -- @name GetUserWithOrders
            -- @class User
            -- @returns :one
            -- @json:many orders Order
            SELECT users.id,
                   JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users INNER JOIN orders ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $this->assertStringContainsString(
            "array_map(fn(array \$r) => Order::fromRow(\$r), json_decode((string) \$row['orders'], true) ?? [])",
            $code
        );
    }

    public function test_bare_json_behaves_identically_to_json_many(): void
    {
        $bare = $this->dto(<<<SQL
            -- @name GetUserWithOrders
            -- @class User
            -- @returns :one
            -- @json orders Order
            SELECT users.id, JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users INNER JOIN orders ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $explicit = $this->dto(<<<SQL
            -- @name GetUserWithOrders
            -- @class User
            -- @returns :one
            -- @json:many orders Order
            SELECT users.id, JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users INNER JOIN orders ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        // Generated code must be identical
        $this->assertSame($bare, $explicit);
    }

    // =========================================================================
    // Mixed cardinality on same query
    // =========================================================================

    public function test_mixed_cardinality_generates_correct_types(): void
    {
        $code = $this->dto(<<<SQL
            -- @name GetUserFull
            -- @class User
            -- @returns :one
            -- @json:one  address Address
            -- @json:many orders  Order
            SELECT users.id, users.name,
                   JSON_OBJECT('id', addresses.id, 'street', addresses.street) AS address,
                   JSON_ARRAYAGG(JSON_OBJECT('id', orders.id, 'total', orders.total)) AS orders
            FROM users
            LEFT JOIN addresses ON addresses.user_id = users.id
            LEFT JOIN orders    ON orders.user_id    = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        // address: single object
        $this->assertStringContainsString('public Address $address,', $code);
        $this->assertStringNotContainsString('public array $address,', $code);
        $this->assertStringContainsString(
            "Address::fromRow(json_decode((string) \$row['address'], true) ?? [])",
            $code
        );

        // orders: typed array
        $this->assertStringContainsString('@var Order[]', $code);
        $this->assertStringContainsString('public array $orders,', $code);
        $this->assertStringContainsString(
            "array_map(fn(array \$r) => Order::fromRow(\$r), json_decode((string) \$row['orders'], true) ?? [])",
            $code
        );
    }

    // =========================================================================
    // JSON DTO files generated for both cardinalities
    // =========================================================================

    public function test_json_one_generates_dto_file(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetUserWithAddress
            -- @class User
            -- @returns :one
            -- @json:one address Address
            SELECT users.id,
                   JSON_OBJECT('id', addresses.id, 'street', addresses.street) AS address
            FROM users INNER JOIN addresses ON addresses.user_id = users.id
            WHERE users.id = :id;
        SQL);

        $r = $this->dtoGen->generate($queries[0]);

        $this->assertArrayHasKey('jsonDtos', $r);
        $this->assertArrayHasKey('Address', $r['jsonDtos']);
        $this->assertStringContainsString('readonly class Address', $r['jsonDtos']['Address']['code']);
    }

    public function test_json_many_generates_dto_file(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetUserWithOrders
            -- @class User
            -- @returns :one
            -- @json:many orders Order
            SELECT users.id,
                   JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users INNER JOIN orders ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $r = $this->dtoGen->generate($queries[0]);

        $this->assertArrayHasKey('Order', $r['jsonDtos']);
        $this->assertStringContainsString('readonly class Order', $r['jsonDtos']['Order']['code']);
    }

    public function test_mixed_cardinality_generates_both_dto_files(): void
    {
        $queries = $this->analyze(<<<SQL
            -- @name GetUserFull
            -- @class User
            -- @returns :one
            -- @json:one  address Address
            -- @json:many orders  Order
            SELECT users.id,
                   JSON_OBJECT('id', addresses.id) AS address,
                   JSON_ARRAYAGG(JSON_OBJECT('id', orders.id)) AS orders
            FROM users
            LEFT JOIN addresses ON addresses.user_id = users.id
            LEFT JOIN orders    ON orders.user_id = users.id
            WHERE users.id = :id GROUP BY users.id;
        SQL);

        $r = $this->dtoGen->generate($queries[0]);

        $this->assertCount(2, $r['jsonDtos']);
        $this->assertArrayHasKey('Address', $r['jsonDtos']);
        $this->assertArrayHasKey('Order',   $r['jsonDtos']);
    }
}
