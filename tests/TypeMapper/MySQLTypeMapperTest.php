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

    public function test_timestamp_maps_to_string(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('TIMESTAMP', false));
    }

    public function test_date_maps_to_string(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('DATE', false));
    }

    public function test_datetime_maps_to_string(): void
    {
        $this->assertSame('string', (new MySQLTypeMapper())->toPhpType('DATETIME', false));
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
}


