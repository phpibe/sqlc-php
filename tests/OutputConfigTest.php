<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Config\Config;
use SqlcPhp\Config\OutputConfig;
use SqlcPhp\Config\Target;

class OutputConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-outconfig-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->tmpDir);
    }

    private function writeConfig(string $yaml): string
    {
        $path = $this->tmpDir . '/sqlc.yaml';
        file_put_contents($path, $yaml);
        return $path;
    }

    // =========================================================================
    // OutputConfig::fromRaw — string form
    // =========================================================================

    public function test_string_form_is_not_map(): void
    {
        $cfg = OutputConfig::fromRaw('generated', 'App\\Database');
        $this->assertFalse($cfg->isMap());
    }

    public function test_string_form_default_dir(): void
    {
        $cfg = OutputConfig::fromRaw('generated', 'App\\Database');
        $this->assertSame('generated', $cfg->defaultDir());
    }

    public function test_string_form_trailing_slash_stripped(): void
    {
        $cfg = OutputConfig::fromRaw('generated/', 'App\\Database');
        $this->assertSame('generated', $cfg->defaultDir());
    }

    public function test_string_form_all_types_return_same_dir(): void
    {
        $cfg = OutputConfig::fromRaw('generated', 'App\\Database');
        foreach (OutputConfig::TYPES as $type) {
            $this->assertSame('generated', $cfg->dirFor($type));
        }
    }

    public function test_string_form_all_types_return_base_namespace(): void
    {
        $cfg = OutputConfig::fromRaw('generated', 'App\\Database');
        foreach (OutputConfig::TYPES as $type) {
            $this->assertSame('App\\Database', $cfg->namespaceFor($type));
        }
    }

    // =========================================================================
    // OutputConfig::fromRaw — map form
    // =========================================================================

    public function test_map_form_is_map(): void
    {
        $cfg = OutputConfig::fromRaw([
            'queries' => 'database/Repositories',
        ], 'App\\Database');
        $this->assertTrue($cfg->isMap());
    }

    public function test_map_form_dir_for_declared_type(): void
    {
        $cfg = OutputConfig::fromRaw([
            'queries' => 'database/Repositories',
            'models'  => 'database/Models',
        ], 'App\\Database');

        $this->assertSame('database/Repositories', $cfg->dirFor('queries'));
        $this->assertSame('database/Models',       $cfg->dirFor('models'));
    }

    public function test_map_form_trailing_slash_stripped(): void
    {
        $cfg = OutputConfig::fromRaw(['queries' => 'database/Repositories/'], 'App\\Database');
        $this->assertSame('database/Repositories', $cfg->dirFor('queries'));
    }

    public function test_map_form_dir_for_undeclared_type_throws(): void
    {
        $cfg = OutputConfig::fromRaw(['queries' => 'database/Repositories'], 'App\\Database');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/out\.models/");
        $cfg->dirFor('models');
    }

    // =========================================================================
    // Namespace derivation — map form
    // =========================================================================

    public function test_namespace_derived_from_last_path_segment(): void
    {
        $cfg = OutputConfig::fromRaw([
            'queries' => 'database/Repositories',
            'models'  => 'database/Models',
            'dtos'    => 'database/DTOs',
            'enums'   => 'database/Enums',
        ], 'App\\Database');

        $this->assertSame('App\\Database\\Repositories', $cfg->namespaceFor('queries'));
        $this->assertSame('App\\Database\\Models',       $cfg->namespaceFor('models'));
        $this->assertSame('App\\Database\\DTOs',         $cfg->namespaceFor('dtos'));
        $this->assertSame('App\\Database\\Enums',        $cfg->namespaceFor('enums'));
    }

    public function test_namespace_preserves_case_of_last_segment(): void
    {
        $cfg = OutputConfig::fromRaw(['queries' => 'src/domain/QUERIES'], 'App\\Database');
        $this->assertSame('App\\Database\\QUERIES', $cfg->namespaceFor('queries'));
    }

    public function test_namespace_single_segment_path(): void
    {
        $cfg = OutputConfig::fromRaw(['queries' => 'Repositories'], 'App\\Database');
        $this->assertSame('App\\Database\\Repositories', $cfg->namespaceFor('queries'));
    }

    public function test_namespaces_differ_when_types_have_different_dirs(): void
    {
        $cfg = OutputConfig::fromRaw([
            'queries' => 'database/Repositories',
            'models'  => 'database/Models',
        ], 'App\\Database');

        $this->assertTrue($cfg->namespacesDiffer('queries', 'models'));
    }

    public function test_namespaces_same_when_types_have_same_dir(): void
    {
        $cfg = OutputConfig::fromRaw([
            'queries'    => 'database/Repositories',
            'interfaces' => 'database/Repositories',
        ], 'App\\Database');

        $this->assertFalse($cfg->namespacesDiffer('queries', 'interfaces'));
    }

    // =========================================================================
    // assertAllDeclared
    // =========================================================================

    public function test_assert_all_declared_passes_when_all_present(): void
    {
        $cfg = OutputConfig::fromRaw([
            'queries' => 'a', 'models' => 'b',
        ], 'App');
        $cfg->assertAllDeclared(['queries', 'models']); // no exception
        $this->assertTrue(true);
    }

    public function test_assert_all_declared_throws_for_missing_type(): void
    {
        $cfg = OutputConfig::fromRaw(['queries' => 'a'], 'App');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/out\.enums/");
        $cfg->assertAllDeclared(['queries', 'enums']);
    }

    public function test_assert_all_declared_is_no_op_in_string_form(): void
    {
        $cfg = OutputConfig::fromRaw('generated', 'App');
        $cfg->assertAllDeclared(['queries', 'models', 'dtos', 'enums', 'interfaces', 'criterias']);
        $this->assertTrue(true); // no exception
    }

    // =========================================================================
    // Target::fromArray — string and map form
    // =========================================================================

    public function test_target_from_array_string_out(): void
    {
        $t = Target::fromArray(['namespace' => 'App', 'out' => 'generated', 'queries' => 'q.sql']);
        $this->assertFalse($t->output->isMap());
        $this->assertSame('generated', $t->output->defaultDir());
        $this->assertSame('generated', $t->out()); // backward compat accessor
    }

    public function test_target_from_array_map_out(): void
    {
        $t = Target::fromArray([
            'namespace' => 'App\\Database',
            'queries'   => 'q.sql',
            'out' => [
                'queries' => 'database/Repositories',
                'models'  => 'database/Models',
                'dtos'    => 'database/DTOs',
            ],
        ]);

        $this->assertTrue($t->output->isMap());
        $this->assertSame('database/Repositories', $t->output->dirFor('queries'));
        $this->assertSame('database/Models',       $t->output->dirFor('models'));
        $this->assertSame('App\\Database\\Repositories', $t->output->namespaceFor('queries'));
        $this->assertSame('App\\Database\\Models',       $t->output->namespaceFor('models'));
    }

    // =========================================================================
    // Config::fromFile — map form parsed from YAML
    // =========================================================================

    public function test_config_parses_map_form_out(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema: schema.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\\\\Database\"\n" .
            "    queries: q.sql\n" .
            "    out:\n" .
            "      queries:    database/Repositories\n" .
            "      models:     database/Models\n" .
            "      dtos:       database/DTOs\n" .
            "      enums:      database/Enums\n" .
            "      interfaces: database/Contracts\n" .
            "      criterias:  database/Criterias\n"
        );

        $cfg = Config::fromFile($path);
        $out = $cfg->targets[0]->output;

        $this->assertTrue($out->isMap());
        $this->assertSame('database/Repositories', $out->dirFor('queries'));
        $this->assertSame('database/Models',       $out->dirFor('models'));
        $this->assertSame('database/DTOs',         $out->dirFor('dtos'));
        $this->assertSame('database/Enums',        $out->dirFor('enums'));
        $this->assertSame('database/Contracts',    $out->dirFor('interfaces'));
        $this->assertSame('database/Criterias',    $out->dirFor('criterias'));
    }

    public function test_config_parses_string_form_out_backward_compat(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema: schema.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\"\n" .
            "    out: generated\n" .
            "    queries: q.sql\n"
        );

        $cfg = Config::fromFile($path);
        $this->assertFalse($cfg->targets[0]->output->isMap());
        $this->assertSame('generated', $cfg->targets[0]->output->defaultDir());
    }

    public function test_config_partial_map_out_declares_subset(): void
    {
        // User only declares queries and models — no error at parse time
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema: schema.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\"\n" .
            "    queries: q.sql\n" .
            "    out:\n" .
            "      queries: gen/Queries\n" .
            "      models:  gen/Models\n"
        );

        $cfg = Config::fromFile($path);
        $out = $cfg->targets[0]->output;
        $this->assertTrue($out->isMap());
        $this->assertSame('gen/Queries', $out->dirFor('queries'));
        $this->assertSame('gen/Models',  $out->dirFor('models'));

        // Undeclared type throws at dirFor time
        $this->expectException(\RuntimeException::class);
        $out->dirFor('enums');
    }

    // =========================================================================
    // Namespace derivation in YAML-parsed config
    // =========================================================================

    public function test_namespaces_derived_from_yaml_map_paths(): void
    {
        $path = $this->writeConfig(
            "version: \"2\"\n" .
            "schema: schema.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\\\\Database\"\n" .
            "    queries: q.sql\n" .
            "    out:\n" .
            "      queries:    src/Database/Repositories\n" .
            "      models:     src/Database/Models\n" .
            "      dtos:       src/Database/DTOs\n" .
            "      enums:      src/Database/Enums\n" .
            "      interfaces: src/Database/Contracts\n" .
            "      criterias:  src/Database/Criterias\n"
        );

        $out = Config::fromFile($path)->targets[0]->output;

        $this->assertSame('App\\Database\\Repositories', $out->namespaceFor('queries'));
        $this->assertSame('App\\Database\\Models',       $out->namespaceFor('models'));
        $this->assertSame('App\\Database\\DTOs',         $out->namespaceFor('dtos'));
        $this->assertSame('App\\Database\\Enums',        $out->namespaceFor('enums'));
        $this->assertSame('App\\Database\\Contracts',    $out->namespaceFor('interfaces'));
        $this->assertSame('App\\Database\\Criterias',    $out->namespaceFor('criterias'));
    }
}
