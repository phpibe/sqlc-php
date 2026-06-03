<?php

declare(strict_types=1);

namespace SqlcPhp\Tests\Config;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\Config;

class ConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-config-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    private function write(string $yaml): string
    {
        $path = $this->tmpDir . '/sqlc.yaml';
        file_put_contents($path, $yaml);
        return $path;
    }

    private function minimal(string $extra = ''): string
    {
        return "version: \"2\"\n" .
               "schema: schema.sql\n" .
               "targets:\n" .
               "  - namespace: \"App\\\\Db\"\n" .
               "    out: gen\n" .
               "    queries: queries.sql\n" .
               $extra;
    }

    // =========================================================================
    // Basic loading
    // =========================================================================

    public function test_loads_basic_config(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));

        $this->assertSame('2', $config->version);
        $this->assertSame(['schema.sql'], $config->schemas);
    }

    public function test_defaults_are_applied(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));

        $this->assertSame('mysql',   $config->engine);
        $this->assertSame('english', $config->language);
        $this->assertSame([],        $config->typeOverrides);
    }

    public function test_global_engine_is_parsed(): void
    {
        $config = Config::fromFile($this->write($this->minimal("engine: mysql\n")));
        $this->assertSame('mysql', $config->engine);
    }

    public function test_global_language_is_parsed(): void
    {
        $config = Config::fromFile($this->write($this->minimal("language: spanish\n")));
        $this->assertSame('spanish', $config->language);
    }

    public function test_global_engine_lowercased(): void
    {
        $config = Config::fromFile($this->write($this->minimal("engine: MySQL\n")));
        $this->assertSame('mysql', $config->engine);
    }

    // =========================================================================
    // schema — scalar or list
    // =========================================================================

    public function test_scalar_schema_wrapped_in_array(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));
        $this->assertIsArray($config->schemas);
        $this->assertSame(['schema.sql'], $config->schemas);
    }

    public function test_list_schema_multiple_entries(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema:\n" .
                "  - schema/users.sql\n" .
                "  - schema/orders.sql\n" .
                "targets:\n" .
                "  - namespace: \"App\\\\Db\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n";

        $config = Config::fromFile($this->write($yaml));

        $this->assertCount(2, $config->schemas);
        $this->assertSame('schema/users.sql',  $config->schemas[0]);
        $this->assertSame('schema/orders.sql', $config->schemas[1]);
    }

    // =========================================================================
    // Missing required fields — throw
    // =========================================================================

    public function test_missing_schema_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required.*schema/i');

        $yaml = "version: \"2\"\ntargets:\n  - namespace: \"App\"\n    out: gen\n    queries: q.sql\n";
        Config::fromFile($this->write($yaml));
    }

    public function test_missing_targets_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required.*targets/i');

        $yaml = "version: \"2\"\nschema: schema.sql\n";
        Config::fromFile($this->write($yaml));
    }

    public function test_empty_targets_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        $yaml = "version: \"2\"\nschema: schema.sql\ntargets:\n";
        Config::fromFile($this->write($yaml));
    }

    // =========================================================================
    // type_overrides — global
    // =========================================================================

    public function test_parses_column_type_override(): void
    {
        $yaml = $this->minimal() .
                "type_overrides:\n" .
                "  - column: \"users.active\"\n" .
                "    php_type: \"bool\"\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertCount(1, $config->typeOverrides);
        $this->assertSame('bool', $config->typeOverrides[0]->phpType);
    }

    public function test_parses_db_type_override(): void
    {
        $yaml = $this->minimal() .
                "type_overrides:\n" .
                "  - db_type: \"TINYINT\"\n" .
                "    php_type: \"bool\"\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertCount(1, $config->typeOverrides);
    }

    public function test_parses_multiple_overrides(): void
    {
        $yaml = $this->minimal() .
                "type_overrides:\n" .
                "  - db_type: \"TINYINT\"\n" .
                "    php_type: \"bool\"\n" .
                "  - db_type: \"TIMESTAMP\"\n" .
                "    php_type: \"\\\\DateTimeImmutable\"\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertCount(2, $config->typeOverrides);
    }

    public function test_no_overrides_returns_empty_array(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));
        $this->assertSame([], $config->typeOverrides);
    }

    // =========================================================================
    // targets
    // =========================================================================

    public function test_targets_always_populated(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));
        $this->assertNotEmpty($config->targets);
    }

    public function test_target_namespace_is_parsed(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));
        $this->assertSame('App\\Db', $config->targets[0]->namespace);
    }

    public function test_target_out_is_parsed(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));
        $this->assertSame('gen', $config->targets[0]->out());
    }

    public function test_target_queries_as_scalar_wrapped_in_array(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));
        $this->assertSame(['queries.sql'], $config->targets[0]->queries);
    }

    public function test_target_queries_as_list(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "targets:\n" .
                "  - namespace: \"App\\\\Db\"\n" .
                "    out: gen\n" .
                "    queries:\n" .
                "      - a.sql\n" .
                "      - b.sql\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertSame(['a.sql', 'b.sql'], $config->targets[0]->queries);
    }

    public function test_generate_interfaces_defaults_to_true(): void
    {
        $config = Config::fromFile($this->write($this->minimal()));
        $this->assertTrue($config->targets[0]->generateInterfaces);
    }

    public function test_generate_interfaces_can_be_set_to_false(): void
    {
        $yaml = $this->minimal() . "    generate_interfaces: false\n";
        // Need to write properly indented YAML
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "targets:\n" .
                "  - namespace: \"App\\\\Db\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n" .
                "    generate_interfaces: false\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertFalse($config->targets[0]->generateInterfaces);
    }

    public function test_multiple_targets(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "targets:\n" .
                "  - namespace: \"App\\\\Read\"\n" .
                "    out: gen/read\n" .
                "    queries: read.sql\n" .
                "  - namespace: \"App\\\\Write\"\n" .
                "    out: gen/write\n" .
                "    queries: write.sql\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertCount(2, $config->targets);
        $this->assertSame('App\\Read',  $config->targets[0]->namespace);
        $this->assertSame('App\\Write', $config->targets[1]->namespace);
    }

    // =========================================================================
    // Per-target engine and language overrides
    // =========================================================================

    public function test_target_inherits_global_engine(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "engine: mysql\n" .
                "targets:\n" .
                "  - namespace: \"App\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertSame('mysql', $config->targets[0]->engine);
    }

    public function test_target_can_override_engine(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "engine: mysql\n" .
                "targets:\n" .
                "  - namespace: \"App\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n" .
                "    engine: postgres\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertSame('postgres', $config->targets[0]->engine);
    }

    public function test_target_inherits_global_language(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "language: spanish\n" .
                "targets:\n" .
                "  - namespace: \"App\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertSame('spanish', $config->targets[0]->language);
    }

    public function test_target_can_override_language(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "language: english\n" .
                "targets:\n" .
                "  - namespace: \"App\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n" .
                "    language: french\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertSame('french', $config->targets[0]->language);
    }

    // =========================================================================
    // Global overrides inherited by targets
    // =========================================================================

    public function test_global_overrides_are_inherited_by_targets(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "type_overrides:\n" .
                "  - db_type: \"TINYINT\"\n" .
                "    php_type: \"bool\"\n" .
                "targets:\n" .
                "  - namespace: \"App\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n";

        $config = Config::fromFile($this->write($yaml));
        $this->assertCount(1, $config->targets[0]->typeOverrides);
    }

    public function test_local_overrides_merged_on_top_of_global(): void
    {
        $yaml = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "type_overrides:\n" .
                "  - db_type: \"TINYINT\"\n" .
                "    php_type: \"bool\"\n" .
                "targets:\n" .
                "  - namespace: \"App\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n" .
                "    type_overrides:\n" .
                "      - db_type: \"TIMESTAMP\"\n" .
                "        php_type: \"string\"\n";

        $config = Config::fromFile($this->write($yaml));
        // 1 global + 1 local = 2 total
        $this->assertCount(2, $config->targets[0]->typeOverrides);
    }
}
