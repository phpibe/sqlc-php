<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\TypeMapper;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\TypeOverride;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class MySQLTypeMapperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Default type mappings — integers
    // -------------------------------------------------------------------------

    public function test_int_maps_to_int(): void
    {
        $this->assertSame('int', (new MySQLTypeMapper())->toPhpType('INT', false));
    }

    public function test_integer_maps_to_int(): void
    {
        $this->assertSame('int', (new MySQLTypeMapper())->toPhpType('INTEGER', false));
    }

    public function test_bigint_maps_to_int(): void
    {
        $this->assertSame('int', (new MySQLTypeMapper())->toPhpType('BIGINT', false));
    }

    public function test_mediumint_maps_to_int(): void
    {
        $this->assertSame('int', (new MySQLTypeMapper())->toPhpType('MEDIUMINT', false));
    }

    public function test_tinyint_maps_to_int(): void
    {
        $this->assertSame('int', (new MySQLTypeMapper())->toPhpType('TINYINT', false));
    }

    public function test_smallint_maps_to_int(): void
    {
        $this->assertSame('int', (new MySQLTypeMapper())->toPhpType('SMALLINT', false));
    }

    // -------------------------------------------------------------------------
    // Default type mappings — floats
    // -------------------------------------------------------------------------

    public function test_float_maps_to_float(): void
    {
        $this->assertSame('float', (new MySQLTypeMapper())->toPhpType('FLOAT', false));
    }

    public function test_double_maps_to_float(): void
    {
        $this->assertSame('float', (new MySQLTypeMapper())->toPhpType('DOUBLE', false));
    }

    public function test_decimal_maps_to_float(): void
    {
        $this->assertSame('float', (new MySQLTypeMapper())->toPhpType('DECIMAL', false));
    }

    public function test_numeric_maps_to_float(): void
    {
        $this->assertSame('float', (new MySQLTypeMapper())->toPhpType('NUMERIC', false));
    }

    // -------------------------------------------------------------------------
    // Default type mappings — strings
    // -------------------------------------------------------------------------

    public function test_varchar_maps_to_string(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('VARCHAR', false));
    }

    public function test_char_maps_to_string(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('CHAR', false));
    }

    public function test_text_maps_to_string(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('TEXT', false));
    }


    public function test_json_maps_to_array(): void
    {
        $this->assertSame('array', (new MySQLTypeMapper())->toPhpType('JSON', false));
    }

    public function test_json_nullable_maps_to_nullable_array(): void
    {
        $this->assertSame('?array', (new MySQLTypeMapper())->toPhpType('JSON', true));
    }

    public function test_unknown_type_falls_back_to_string(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('BLOB', false));
    }

    // -------------------------------------------------------------------------
    // Nullability
    // -------------------------------------------------------------------------

    public function test_nullable_int_has_question_mark_prefix(): void
    {
        $this->assertSame('?int', (new MySQLTypeMapper())->toPhpType('INT', true));
    }

    public function test_non_nullable_has_no_question_mark(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('VARCHAR', false));
    }

    public function test_nullable_string(): void
    {
        $this->assertSame('?string', (new MySQLTypeMapper())->toPhpType('VARCHAR', true));
    }

    // -------------------------------------------------------------------------
    // PDO param constants
    // -------------------------------------------------------------------------

    public function test_int_uses_pdo_param_int(): void
    {
        $this->assertSame('PDO::PARAM_INT', (new MySQLTypeMapper())->toPdoParam('INT'));
    }

    public function test_tinyint_uses_pdo_param_int(): void
    {
        $this->assertSame('PDO::PARAM_INT', (new MySQLTypeMapper())->toPdoParam('TINYINT'));
    }

    public function test_smallint_uses_pdo_param_int(): void
    {
        $this->assertSame('PDO::PARAM_INT', (new MySQLTypeMapper())->toPdoParam('SMALLINT'));
    }

    public function test_bigint_uses_pdo_param_int(): void
    {
        $this->assertSame('PDO::PARAM_INT', (new MySQLTypeMapper())->toPdoParam('BIGINT'));
    }

    public function test_varchar_uses_param_str(): void
    {
        $this->assertSame('PDO::PARAM_STR', (new MySQLTypeMapper())->toPdoParam('VARCHAR'));
    }

    public function test_timestamp_uses_param_str(): void
    {
        $this->assertSame('PDO::PARAM_STR', (new MySQLTypeMapper())->toPdoParam('TIMESTAMP'));
    }

    // -------------------------------------------------------------------------
    // Type overrides — column-specific
    // -------------------------------------------------------------------------

    public function test_column_override_replaces_default_type(): void
    {
        $mapper = new MySQLTypeMapper([TypeOverride::fromArray([
            'column' => 'users.active', 'php_type' => 'bool',
        ])]);

        $this->assertSame('bool', $mapper->toPhpType('TINYINT', false, 'users', 'active'));
    }

    public function test_column_override_respects_nullability(): void
    {
        $mapper = new MySQLTypeMapper([TypeOverride::fromArray([
            'column' => 'users.active', 'php_type' => 'bool',
        ])]);

        $this->assertSame('?bool', $mapper->toPhpType('TINYINT', true, 'users', 'active'));
    }

    public function test_column_override_is_case_insensitive(): void
    {
        $mapper = new MySQLTypeMapper([TypeOverride::fromArray([
            'column' => 'Users.Active', 'php_type' => 'bool',
        ])]);

        $this->assertSame('bool', $mapper->toPhpType('TINYINT', false, 'users', 'active'));
    }

    public function test_column_override_does_not_affect_other_columns(): void
    {
        $mapper = new MySQLTypeMapper([TypeOverride::fromArray([
            'column' => 'users.active', 'php_type' => 'bool',
        ])]);

        $this->assertSame('int', $mapper->toPhpType('TINYINT', false, 'users', 'type_id'));
    }

    // -------------------------------------------------------------------------
    // Type overrides — db_type
    // -------------------------------------------------------------------------

    public function test_db_type_override_applies_to_all_matching_columns(): void
    {
        $mapper = new MySQLTypeMapper([TypeOverride::fromArray([
            'db_type' => 'TINYINT', 'php_type' => 'bool',
        ])]);

        $this->assertSame('bool', $mapper->toPhpType('TINYINT', false, 'users', 'active'));
        $this->assertSame('bool', $mapper->toPhpType('TINYINT', false, 'users', 'type_id'));
    }

    public function test_db_type_override_timestamp_to_datetime(): void
    {
        $mapper = new MySQLTypeMapper([TypeOverride::fromArray([
            'db_type' => 'TIMESTAMP', 'php_type' => '\DateTimeImmutable',
        ])]);

        $this->assertSame('\DateTimeImmutable', $mapper->toPhpType('TIMESTAMP', false, 'users', 'created_at'));
    }

    // -------------------------------------------------------------------------
    // Override precedence: column wins over db_type
    // -------------------------------------------------------------------------

    public function test_column_override_takes_precedence_over_db_type(): void
    {
        $mapper = new MySQLTypeMapper([
            TypeOverride::fromArray(['column'  => 'users.type_id', 'php_type' => 'int']),
            TypeOverride::fromArray(['db_type' => 'TINYINT',       'php_type' => 'bool']),
        ]);

        $this->assertSame('int', $mapper->toPhpType('TINYINT', false, 'users', 'type_id'));
    }

    // -------------------------------------------------------------------------
    // Date types → \DateTimeImmutable
    // -------------------------------------------------------------------------

    public function test_date_maps_to_datetime_immutable(): void
    {
        $mapper = new MySQLTypeMapper();
        $this->assertSame('\DateTimeImmutable', $mapper->toPhpType('DATE', false));
    }

    public function test_datetime_maps_to_datetime_immutable(): void
    {
        $mapper = new MySQLTypeMapper();
        $this->assertSame('\DateTimeImmutable', $mapper->toPhpType('DATETIME', false));
    }

    public function test_timestamp_maps_to_datetime_immutable(): void
    {
        $mapper = new MySQLTypeMapper();
        $this->assertSame('\DateTimeImmutable', $mapper->toPhpType('TIMESTAMP', false));
    }

    public function test_nullable_date_maps_to_nullable_datetime_immutable(): void
    {
        $mapper = new MySQLTypeMapper();
        $this->assertSame('?\DateTimeImmutable', $mapper->toPhpType('DATE', true));
    }

    public function test_time_maps_to_string_not_datetime(): void
    {
        // TIME (interval, e.g. "08:30:00") has no standard PHP object — stays string
        $mapper = new MySQLTypeMapper();
        $this->assertSame('string', $mapper->toPhpType('TIME', false));
    }

    public function test_date_type_override_to_string_is_respected(): void
    {
        // Users who need string for date columns can use type_overrides
        $mapper = new MySQLTypeMapper([
            TypeOverride::fromArray(['db_type' => 'DATE', 'php_type' => 'string']),
        ]);
        $this->assertSame('string', $mapper->toPhpType('DATE', false));
    }

    // -------------------------------------------------------------------------
    // fromRowCast
    // -------------------------------------------------------------------------

    public function test_from_row_cast_int(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("(int) \$row['id']", $m->fromRowCast('int', 'id', false));
    }

    public function test_from_row_cast_nullable_int(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("isset(\$row['count']) ? (int) \$row['count'] : null", $m->fromRowCast('?int', 'count', true));
    }

    public function test_from_row_cast_float(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("(float) \$row['price']", $m->fromRowCast('float', 'price', false));
    }

    public function test_from_row_cast_bool(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("(bool) \$row['active']", $m->fromRowCast('bool', 'active', false));
    }

    public function test_from_row_cast_string(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("(string) \$row['email']", $m->fromRowCast('string', 'email', false));
    }

    public function test_from_row_cast_array_json(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("json_decode((string) \$row['tags'], true) ?? []", $m->fromRowCast('array', 'tags', false));
    }

    public function test_from_row_cast_nullable_array(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("isset(\$row['meta']) ? json_decode((string) \$row['meta'], true) : null", $m->fromRowCast('?array', 'meta', true));
    }

    public function test_from_row_cast_datetime_immutable(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame("new \\DateTimeImmutable((string) \$row['created_at'])", $m->fromRowCast('\DateTimeImmutable', 'created_at', false));
    }

    public function test_from_row_cast_nullable_datetime_immutable(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame(
            "isset(\$row['deleted_at']) ? new \\DateTimeImmutable((string) \$row['deleted_at']) : null",
            $m->fromRowCast('?\DateTimeImmutable', 'deleted_at', true)
        );
    }

    public function test_from_row_cast_backed_enum(): void
    {
        $m = new MySQLTypeMapper();
        // Non-primitive type with no special mapping → treated as backed enum
        $this->assertSame("OrderStatus::from((string) \$row['status'])", $m->fromRowCast('OrderStatus', 'status', false));
    }

    public function test_from_row_cast_nullable_backed_enum(): void
    {
        $m = new MySQLTypeMapper();
        $this->assertSame(
            "isset(\$row['status']) ? OrderStatus::tryFrom((string) \$row['status']) : null",
            $m->fromRowCast('?OrderStatus', 'status', true)
        );
    }
}


