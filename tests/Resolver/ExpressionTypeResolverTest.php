<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Resolver;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class ExpressionTypeResolverTest extends TestCase
{
    private ExpressionTypeResolver $resolver;
    private array $aliases;

    protected function setUp(): void
    {
        $schema = <<<SQL
            CREATE TABLE users (
                id       INT           AUTO_INCREMENT PRIMARY KEY,
                email    VARCHAR(100)  NOT NULL,
                active   TINYINT       DEFAULT 1 null,
                price    DECIMAL       null,
                role_id  SMALLINT      NOT NULL,
                created_at TIMESTAMP   null
            );
        SQL;

        $catalog        = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper();
        $this->resolver = new ExpressionTypeResolver($catalog, $mapper);
        $this->aliases  = ['users' => 'users'];
    }

    // -------------------------------------------------------------------------
    // COUNT
    // -------------------------------------------------------------------------

    public function test_count_star_is_int_not_nullable(): void
    {
        $result = $this->resolver->resolve('COUNT(*)', $this->aliases);
        $this->assertSame('int', $result['phpType']);
    }

    public function test_count_column_is_int_not_nullable(): void
    {
        $result = $this->resolver->resolve('COUNT(id)', $this->aliases);
        $this->assertSame('int', $result['phpType']);
    }

    public function test_count_star_alias_is_count(): void
    {
        $result = $this->resolver->resolve('COUNT(*)', $this->aliases);
        $this->assertSame('count', $result['alias']);
    }

    // -------------------------------------------------------------------------
    // SUM
    // -------------------------------------------------------------------------

    public function test_sum_int_column_is_nullable_int(): void
    {
        $result = $this->resolver->resolve('SUM(id)', $this->aliases);
        $this->assertSame('?int', $result['phpType']);
    }

    public function test_sum_decimal_column_is_nullable_float(): void
    {
        $result = $this->resolver->resolve('SUM(price)', $this->aliases);
        $this->assertSame('?float', $result['phpType']);
    }

    public function test_sum_alias_is_prefixed_camel_case(): void
    {
        $result = $this->resolver->resolve('SUM(role_id)', $this->aliases);
        $this->assertSame('sumRoleId', $result['alias']);
    }

    // -------------------------------------------------------------------------
    // AVG
    // -------------------------------------------------------------------------

    public function test_avg_is_always_nullable_float(): void
    {
        $result = $this->resolver->resolve('AVG(id)', $this->aliases);
        $this->assertSame('?float', $result['phpType']);
    }

    public function test_avg_alias_is_prefixed(): void
    {
        $result = $this->resolver->resolve('AVG(price)', $this->aliases);
        $this->assertSame('avgPrice', $result['alias']);
    }

    // -------------------------------------------------------------------------
    // MIN / MAX
    // -------------------------------------------------------------------------

    public function test_min_inherits_column_type_as_nullable(): void
    {
        $result = $this->resolver->resolve('MIN(id)', $this->aliases);
        $this->assertSame('?int', $result['phpType']);
    }

    public function test_max_inherits_column_type_as_nullable(): void
    {
        $result = $this->resolver->resolve('MAX(id)', $this->aliases);
        $this->assertSame('?int', $result['phpType']);
    }

    public function test_min_alias_is_prefixed(): void
    {
        $result = $this->resolver->resolve('MIN(created_at)', $this->aliases);
        $this->assertSame('minCreatedAt', $result['alias']);
    }

    public function test_max_does_not_produce_double_nullable(): void
    {
        // created_at is already nullable — MAX should produce ?string, not ??string
        $result = $this->resolver->resolve('MAX(created_at)', $this->aliases);
        $this->assertSame('?string', $result['phpType']);
        $this->assertStringStartsWith('?', $result['phpType']);
        $this->assertStringNotContainsString('??', $result['phpType']);
    }

    // -------------------------------------------------------------------------
    // COALESCE / IFNULL / NULLIF
    // -------------------------------------------------------------------------

    public function test_coalesce_removes_nullability(): void
    {
        // email is NOT NULL (non-nullable), but even if it were nullable,
        // COALESCE guarantees a non-null result
        $result = $this->resolver->resolve('COALESCE(email, \'unknown\')', $this->aliases);
        $this->assertStringStartsNotWith('?', $result['phpType']);
    }

    public function test_ifnull_removes_nullability(): void
    {
        $result = $this->resolver->resolve('IFNULL(active, 0)', $this->aliases);
        $this->assertStringStartsNotWith('?', $result['phpType']);
    }

    public function test_nullif_adds_nullability(): void
    {
        $result = $this->resolver->resolve('NULLIF(active, 0)', $this->aliases);
        $this->assertStringStartsWith('?', $result['phpType']);
    }

    public function test_coalesce_alias_uses_first_arg(): void
    {
        $result = $this->resolver->resolve('COALESCE(email, \'unknown\')', $this->aliases);
        $this->assertSame('coalesceEmail', $result['alias']);
    }

    // -------------------------------------------------------------------------
    // CAST
    // -------------------------------------------------------------------------

    public function test_cast_as_unsigned_is_int(): void
    {
        $result = $this->resolver->resolve('CAST(price AS UNSIGNED)', $this->aliases);
        $this->assertSame('int', $result['phpType']);
    }

    public function test_cast_as_decimal_is_float(): void
    {
        $result = $this->resolver->resolve('CAST(id AS DECIMAL)', $this->aliases);
        $this->assertSame('float', $result['phpType']);
    }

    public function test_cast_as_char_is_string(): void
    {
        $result = $this->resolver->resolve('CAST(id AS CHAR)', $this->aliases);
        $this->assertSame('string', $result['phpType']);
    }

    // -------------------------------------------------------------------------
    // String functions
    // -------------------------------------------------------------------------

    public function test_concat_is_nullable_string(): void
    {
        $result = $this->resolver->resolve("CONCAT(email, ' ', email)", $this->aliases);
        $this->assertSame('?string', $result['phpType']);
        $this->assertSame('concat', $result['alias']);
    }

    public function test_upper_is_non_nullable_string(): void
    {
        $result = $this->resolver->resolve('UPPER(email)', $this->aliases);
        $this->assertSame('string', $result['phpType']);
    }

    public function test_length_is_int(): void
    {
        $result = $this->resolver->resolve('LENGTH(email)', $this->aliases);
        $this->assertSame('int', $result['phpType']);
    }

    // -------------------------------------------------------------------------
    // CASE WHEN
    // -------------------------------------------------------------------------

    public function test_case_when_is_nullable_string(): void
    {
        $result = $this->resolver->resolve("CASE WHEN active = 1 THEN 'yes' ELSE 'no' END", $this->aliases);
        $this->assertSame('?string', $result['phpType']);
        $this->assertSame('case', $result['alias']);
    }

    // -------------------------------------------------------------------------
    // Unknown / fallback
    // -------------------------------------------------------------------------

    public function test_unknown_expression_returns_mixed_with_null_alias(): void
    {
        $result = $this->resolver->resolve('SOME_UNKNOWN_FUNC(x, y)', $this->aliases);
        $this->assertSame('mixed', $result['phpType']);
        $this->assertNull($result['alias']);
    }

    // -------------------------------------------------------------------------
    // Regression: table alias case sensitivity (uppercase inner from matchesFunction)
    // -------------------------------------------------------------------------

    public function test_max_with_table_alias_resolves_correctly(): void
    {
        // Regression: MAX(u.id) was resolving to ?string because matchesFunction()
        // uppercases the inner — resolveInnerType received 'U.ID' but alias map
        // had lowercase key 'u', so the lookup silently fell through to 'string'.
        $aliases = ['u' => 'users', 'users' => 'users'];
        $result  = $this->resolver->resolve('MAX(u.id)', $aliases);
        $this->assertSame('?int', $result['phpType']);
    }

    public function test_min_with_table_alias_resolves_correctly(): void
    {
        $aliases = ['u' => 'users', 'users' => 'users'];
        $result  = $this->resolver->resolve('MIN(u.price)', $aliases);
        $this->assertSame('?float', $result['phpType']);
    }

    public function test_sum_with_table_alias_resolves_correctly(): void
    {
        $aliases = ['u' => 'users', 'users' => 'users'];
        $result  = $this->resolver->resolve('SUM(u.price)', $aliases);
        $this->assertSame('?float', $result['phpType']);
    }

    public function test_max_without_table_alias_still_resolves_correctly(): void
    {
        $result = $this->resolver->resolve('MAX(id)', $this->aliases);
        $this->assertSame('?int', $result['phpType']);
    }

    public function test_max_with_multi_table_join_aliases(): void
    {
        // Simulates a real-world LEFT JOIN with aggregate:
        // MAX(m.voucher_number) where m → memory table (alias for another table)
        $schema = <<<SQL
            CREATE TABLE memory (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                voucher_number INT NOT NULL
            );
        SQL;
        $catalog  = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper   = new MySQLTypeMapper();
        $resolver = new ExpressionTypeResolver($catalog, $mapper);

        $aliases = ['m' => 'memory', 'memory' => 'memory'];
        $result  = $resolver->resolve('MAX(m.voucher_number)', $aliases);

        $this->assertSame('?int', $result['phpType']);
    }
}

