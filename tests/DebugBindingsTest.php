<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Query\QueryObject;

class DebugBindingsTest extends TestCase
{
    // =========================================================================
    // toDebugBindings() — basic behaviour
    // =========================================================================

    public function test_returns_flat_indexed_array(): void
    {
        $q = new QueryObject(
            'SELECT * FROM users WHERE id = :id AND active = :active',
            [
                ':id'     => [1, \PDO::PARAM_INT],
                ':active' => [1, \PDO::PARAM_INT],
            ],
            'getUser',
        );
        $result = $q->toDebugBindings();
        $this->assertSame([1, 1], $result);
    }

    public function test_empty_bindings_returns_empty_array(): void
    {
        $q = new QueryObject('SELECT * FROM users', [], 'listAll');
        $this->assertSame([], $q->toDebugBindings());
    }

    public function test_string_values_included(): void
    {
        $q = new QueryObject(
            "SELECT * FROM users WHERE email = :email",
            [':email' => ['test@example.com', \PDO::PARAM_STR]],
            'findByEmail',
        );
        $this->assertSame(['test@example.com'], $q->toDebugBindings());
    }

    public function test_null_values_included(): void
    {
        $q = new QueryObject(
            'SELECT * FROM users WHERE name = :name',
            [':name' => [null, \PDO::PARAM_NULL]],
            'findByName',
        );
        $this->assertSame([null], $q->toDebugBindings());
    }

    // =========================================================================
    // _chk params filtered out (@optional queries)
    // =========================================================================

    public function test_chk_params_are_filtered_out(): void
    {
        // @optional queries generate :param and :param_chk bindings
        $q = new QueryObject(
            'SELECT * FROM users WHERE (:active_chk IS NULL OR active = :active)',
            [
                ':active'     => [1, \PDO::PARAM_INT],
                ':active_chk' => [1, \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $result = $q->toDebugBindings();
        // Only :active should appear — :active_chk is an internal implementation detail
        $this->assertSame([1], $result);
        $this->assertCount(1, $result);
    }

    public function test_chk_filtering_with_multiple_optional_params(): void
    {
        $q = new QueryObject(
            'SELECT * FROM users WHERE (:active_chk IS NULL OR active = :active) AND (:country_id_chk IS NULL OR country_id = :country_id)',
            [
                ':active'         => [1, \PDO::PARAM_INT],
                ':active_chk'     => [1, \PDO::PARAM_INT],
                ':country_id'     => [164, \PDO::PARAM_INT],
                ':country_id_chk' => [164, \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $result = $q->toDebugBindings();
        $this->assertSame([1, 164], $result);
        $this->assertCount(2, $result);
    }

    public function test_chk_filtered_even_when_value_is_null(): void
    {
        // When @optional param is null, both :param and :param_chk are null
        $q = new QueryObject(
            'SELECT * FROM users WHERE (:active_chk IS NULL OR active = :active)',
            [
                ':active'     => [null, \PDO::PARAM_INT],
                ':active_chk' => [null, \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $result = $q->toDebugBindings();
        $this->assertSame([null], $result);
        $this->assertCount(1, $result);
    }

    // =========================================================================
    // :limit and :offset filtered out (:many-paginated)
    // =========================================================================

    public function test_limit_and_offset_filtered_out(): void
    {
        $q = new QueryObject(
            'SELECT * FROM users WHERE active = :active LIMIT :limit OFFSET :offset',
            [
                ':active' => [1, \PDO::PARAM_INT],
                ':limit'  => [20, \PDO::PARAM_INT],
                ':offset' => [0,  \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $result = $q->toDebugBindings();
        // :limit and :offset are pagination internals — filtered out
        $this->assertSame([1], $result);
        $this->assertCount(1, $result);
    }

    public function test_limit_and_offset_filtered_without_other_params(): void
    {
        $q = new QueryObject(
            'SELECT * FROM users LIMIT :limit OFFSET :offset',
            [
                ':limit'  => [20, \PDO::PARAM_INT],
                ':offset' => [0,  \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $this->assertSame([], $q->toDebugBindings());
    }

    // =========================================================================
    // Combined: _chk + :limit/:offset filtered simultaneously
    // =========================================================================

    public function test_chk_and_pagination_filtered_together(): void
    {
        // A :many-paginated query with an @optional param generates all three
        $q = new QueryObject(
            'SELECT * FROM users WHERE (:active_chk IS NULL OR active = :active) LIMIT :limit OFFSET :offset',
            [
                ':active'     => [1,  \PDO::PARAM_INT],
                ':active_chk' => [1,  \PDO::PARAM_INT],
                ':limit'      => [20, \PDO::PARAM_INT],
                ':offset'     => [40, \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $result = $q->toDebugBindings();
        // Only :active survives
        $this->assertSame([1], $result);
    }

    // =========================================================================
    // Order preservation
    // =========================================================================

    public function test_value_order_matches_sql_param_order(): void
    {
        $q = new QueryObject(
            'SELECT * FROM users WHERE country_id = :country_id AND active = :active',
            [
                ':country_id' => [164,   \PDO::PARAM_INT],
                ':active'     => [1,     \PDO::PARAM_INT],
            ],
            'listByCountry',
        );
        // Order must match SQL parameter order (as PHP preserves insertion order)
        $this->assertSame([164, 1], $q->toDebugBindings());
    }

    // =========================================================================
    // Return type is a list (indexed), not associative
    // =========================================================================

    public function test_returns_list_not_associative(): void
    {
        $q = new QueryObject(
            'SELECT * FROM users WHERE id = :id',
            [':id' => [42, \PDO::PARAM_INT]],
            'getUser',
        );
        $result = $q->toDebugBindings();
        $this->assertArrayHasKey(0, $result);
        $this->assertArrayNotHasKey(':id', $result);
    }

    // =========================================================================
    // Debugbar usage pattern — both approaches work
    // =========================================================================

    public function test_to_debug_sql_with_empty_bindings_interpolates_correctly(): void
    {
        // Approach A: toDebugSql() + [] — SQL already interpolated
        $q = new QueryObject(
            'SELECT * FROM users WHERE (:active_chk IS NULL OR active = :active)',
            [
                ':active'     => [1, \PDO::PARAM_INT],
                ':active_chk' => [1, \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $debugSql = $q->toDebugSql();
        // Both :active and :active_chk are replaced with their values
        $this->assertStringContainsString('1 IS NULL OR active = 1', $debugSql);
        $this->assertStringNotContainsString(':active', $debugSql);
    }

    public function test_to_debug_bindings_approach_b(): void
    {
        // Approach B: toString() + toDebugBindings() — bindings without _chk
        $q = new QueryObject(
            'SELECT * FROM users WHERE active = :active AND country_id = :country_id',
            [
                ':active'     => [1,   \PDO::PARAM_INT],
                ':country_id' => [164, \PDO::PARAM_INT],
            ],
            'listUsers',
        );
        $this->assertSame('SELECT * FROM users WHERE active = :active AND country_id = :country_id',
            $q->toString());
        $this->assertSame([1, 164], $q->toDebugBindings());
    }

    // =========================================================================
    // Regression: the original bug — bindings() was being passed directly
    // =========================================================================

    public function test_bindings_raw_contains_pdo_type_arrays(): void
    {
        // Verify that bindings() still returns the full [value, PDO_TYPE] format
        // This is what caused the [,1] display bug when passed directly to Debugbar
        $q = new QueryObject(
            'SELECT * FROM users WHERE id = :id',
            [':id' => [42, \PDO::PARAM_INT]],
            'getUser',
        );
        $raw = $q->bindings();
        // bindings() returns [value, pdo_type] tuples — NOT suitable for Debugbar directly
        $this->assertSame([42, \PDO::PARAM_INT], $raw[':id']);
    }

    public function test_to_debug_bindings_strips_pdo_type(): void
    {
        // toDebugBindings() returns plain values — suitable for Debugbar
        $q = new QueryObject(
            'SELECT * FROM users WHERE id = :id',
            [':id' => [42, \PDO::PARAM_INT]],
            'getUser',
        );
        $debug = $q->toDebugBindings();
        // Values only, no PDO type constant
        $this->assertSame([42], $debug);
        $this->assertNotSame([[42, \PDO::PARAM_INT]], $debug);
    }

    // =========================================================================
    // Version
    // =========================================================================

    public function test_version_is_2_7_7(): void
    {
        $this->assertSame('2.9.2', \SqlcPhp\Version::VERSION);
    }
}
