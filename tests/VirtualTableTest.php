<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\Config;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

class VirtualTableTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-vt-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->removeDir($f) : unlink($f);
        }
        if (is_dir($dir)) rmdir($dir);
    }

    private function writeFile(string $relPath, string $content): string
    {
        $full = $this->tmpDir . '/' . $relPath;
        $dir  = dirname($full);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        file_put_contents($full, $content);
        return $full;
    }

    private function writeMain(string $yaml): string
    {
        return $this->writeFile('sqlc.yaml', $yaml);
    }

    private function minimalYaml(string $extra = ''): string
    {
        return "version: \"2\"\n" .
               "schema: schema.sql\n" .
               "targets:\n" .
               "  - namespace: \"App\\\\Db\"\n" .
               "    out: gen\n" .
               "    queries: q.sql\n" .
               $extra;
    }

    // =========================================================================
    // Config — virtual_tables: parsing
    // =========================================================================

    public function test_virtual_tables_defaults_to_empty_array(): void
    {
        $config = Config::fromFile($this->writeMain($this->minimalYaml()));
        $this->assertSame([], $config->virtualTables);
    }

    public function test_virtual_table_is_parsed(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id,    type: INT }\n" .
            "      - { name: email, type: VARCHAR }\n";

        $config = Config::fromFile($this->writeMain($yaml));

        $this->assertCount(1, $config->virtualTables);
        $this->assertSame('user_summary', $config->virtualTables[0]->name);
    }

    public function test_virtual_table_columns_are_parsed(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id,    type: INT }\n" .
            "      - { name: email, type: VARCHAR }\n";

        $config  = Config::fromFile($this->writeMain($yaml));
        $columns = $config->virtualTables[0]->columns;

        $this->assertCount(2, $columns);
        $this->assertSame('id',    $columns[0]->name);
        $this->assertSame('email', $columns[1]->name);
    }

    public function test_virtual_table_columns_are_not_null_by_default(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n";

        $config = Config::fromFile($this->writeMain($yaml));
        $col    = $config->virtualTables[0]->columns[0];

        $this->assertFalse($col->nullable, 'Columns should be NOT NULL by default');
    }

    public function test_virtual_table_column_nullable_true_is_respected(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: role_name, type: VARCHAR, nullable: true }\n";

        $config = Config::fromFile($this->writeMain($yaml));
        $col    = $config->virtualTables[0]->columns[0];

        $this->assertTrue($col->nullable);
    }

    public function test_virtual_table_sql_type_is_uppercased(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: t\n" .
            "    columns:\n" .
            "      - { name: c, type: varchar }\n";

        $config = Config::fromFile($this->writeMain($yaml));
        $this->assertSame('VARCHAR', $config->virtualTables[0]->columns[0]->sqlType);
    }

    public function test_virtual_table_is_marked_as_virtual(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n";

        $config = Config::fromFile($this->writeMain($yaml));
        $this->assertTrue($config->virtualTables[0]->virtual);
    }

    public function test_multiple_virtual_tables_are_all_parsed(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: view_a\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n" .
            "  - name: view_b\n" .
            "    columns:\n" .
            "      - { name: name, type: VARCHAR }\n";

        $config = Config::fromFile($this->writeMain($yaml));

        $this->assertCount(2, $config->virtualTables);
        $this->assertSame('view_a', $config->virtualTables[0]->name);
        $this->assertSame('view_b', $config->virtualTables[1]->name);
    }

    // =========================================================================
    // SchemaCatalog — virtual tables are registered
    // =========================================================================

    public function test_virtual_table_is_registered_in_catalog(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id,    type: INT }\n" .
            "      - { name: email, type: VARCHAR }\n";

        $config  = Config::fromFile($this->writeMain($yaml));
        $catalog = new SchemaCatalog($config->virtualTables);

        $this->assertNotNull($catalog->getTable('user_summary'));
    }

    public function test_catalog_can_find_virtual_table_columns(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id,    type: INT }\n" .
            "      - { name: email, type: VARCHAR }\n";

        $config  = Config::fromFile($this->writeMain($yaml));
        $catalog = new SchemaCatalog($config->virtualTables);
        $cols    = $catalog->getColumns('user_summary');

        $this->assertCount(2, $cols);
        $this->assertSame('id',    $cols[0]->name);
        $this->assertSame('email', $cols[1]->name);
    }

    public function test_virtual_table_columns_resolve_to_correct_php_types(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: stats\n" .
            "    columns:\n" .
            "      - { name: id,    type: INT }\n" .
            "      - { name: total, type: DECIMAL, nullable: true }\n";

        $config  = Config::fromFile($this->writeMain($yaml));
        $catalog = new SchemaCatalog($config->virtualTables);
        $mapper  = new MySQLTypeMapper();

        $idCol    = $catalog->getColumns('stats')[0];
        $totalCol = $catalog->getColumns('stats')[1];

        $this->assertSame('int',    $mapper->toPhpType($idCol->sqlType,    $idCol->nullable));
        $this->assertSame('?float', $mapper->toPhpType($totalCol->sqlType, $totalCol->nullable));
    }

    // =========================================================================
    // Virtual table marked as virtual — real tables are not
    // =========================================================================

    public function test_regular_table_is_not_virtual(): void
    {
        $tables = (new SchemaParser())->parse(
            'CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(100) NOT NULL);'
        );
        $this->assertFalse($tables[0]->virtual);
    }

    // =========================================================================
    // Query analysis — virtual table queries produce Row DTOs
    // =========================================================================

    private function makeAnalyzer(SchemaCatalog $catalog): QueryAnalyzer
    {
        $mapper = new MySQLTypeMapper();
        $pr     = new ParamResolver($catalog, $mapper);
        $er     = new ExpressionTypeResolver($catalog, $mapper);
        $cr     = new ColumnResolver($catalog, $mapper, $pr, $er);
        return new QueryAnalyzer($pr, $cr, new QueryParser(), new SqlRewriter(), $catalog);
    }

    public function test_query_on_virtual_table_does_not_return_model_directly(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id,    type: INT }\n" .
            "      - { name: email, type: VARCHAR }\n";

        $config  = Config::fromFile($this->writeMain($yaml));
        $catalog = new SchemaCatalog($config->virtualTables);
        $az      = $this->makeAnalyzer($catalog);
        $parser  = new QueryParser();

        $queries = $az->analyze($parser->parse(
            "-- @name ListSummaries\n-- @returns :many\nSELECT * FROM user_summary;"
        ));

        $this->assertFalse($queries[0]->returnsModelDirectly,
            'Virtual table queries must never use returnsModelDirectly');
    }

    public function test_query_on_virtual_table_resolves_columns(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id,          type: INT }\n" .
            "      - { name: email,       type: VARCHAR }\n" .
            "      - { name: order_count, type: INT }\n";

        $config  = Config::fromFile($this->writeMain($yaml));
        $catalog = new SchemaCatalog($config->virtualTables);
        $az      = $this->makeAnalyzer($catalog);
        $parser  = new QueryParser();

        $queries = $az->analyze($parser->parse(
            "-- @name ListSummaries\n-- @returns :many\nSELECT * FROM user_summary;"
        ));

        $aliases = array_map(fn($c) => $c->alias, $queries[0]->resultColumns);
        $this->assertContains('id',          $aliases);
        $this->assertContains('email',       $aliases);
        $this->assertContains('order_count', $aliases);
    }

    public function test_virtual_table_query_generates_row_dto(): void
    {
        $yaml = $this->minimalYaml() .
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id,    type: INT }\n" .
            "      - { name: email, type: VARCHAR }\n";

        $config  = Config::fromFile($this->writeMain($yaml));
        $catalog = new SchemaCatalog($config->virtualTables);
        $az      = $this->makeAnalyzer($catalog);
        $parser  = new QueryParser();
        $dg      = new ResultDtoGenerator('App\\Db');

        $queries = $az->analyze($parser->parse(
            "-- @name ListSummaries\n-- @returns :many\nSELECT * FROM user_summary;"
        ));

        ['className' => $cls, 'code' => $code] = $dg->generate($queries[0]);

        $this->assertSame('ListSummariesRow', $cls);
        $this->assertStringContainsString('class ListSummariesRow', $code);
    }

    // =========================================================================
    // includes: — single file
    // =========================================================================

    public function test_includes_virtual_tables_from_one_file(): void
    {
        $this->writeFile('config/views.yaml',
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n"
        );

        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/views.yaml\n";

        $config = Config::fromFile($this->writeMain($main));

        $this->assertCount(1, $config->virtualTables);
        $this->assertSame('user_summary', $config->virtualTables[0]->name);
    }

    public function test_includes_type_overrides_from_one_file(): void
    {
        $this->writeFile('config/overrides.yaml',
            "type_overrides:\n" .
            "  - db_type: \"TINYINT\"\n" .
            "    php_type: \"bool\"\n"
        );

        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/overrides.yaml\n";

        $config = Config::fromFile($this->writeMain($main));

        $this->assertCount(1, $config->typeOverrides);
        $this->assertSame('bool', $config->typeOverrides[0]->phpType);
    }

    // =========================================================================
    // includes: — multiple files, all sections accumulated
    // =========================================================================

    public function test_includes_multiple_virtual_table_files(): void
    {
        $this->writeFile('config/user_views.yaml',
            "virtual_tables:\n" .
            "  - name: user_summary\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n"
        );

        $this->writeFile('config/order_views.yaml',
            "virtual_tables:\n" .
            "  - name: order_summary\n" .
            "    columns:\n" .
            "      - { name: id,     type: INT }\n" .
            "      - { name: total,  type: DECIMAL }\n"
        );

        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/user_views.yaml\n" .
            "  - config/order_views.yaml\n";

        $config = Config::fromFile($this->writeMain($main));

        $this->assertCount(2, $config->virtualTables);
        $names = array_map(fn($t) => $t->name, $config->virtualTables);
        $this->assertContains('user_summary',  $names);
        $this->assertContains('order_summary', $names);
    }

    public function test_includes_multiple_override_files_accumulated(): void
    {
        $this->writeFile('config/timestamps.yaml',
            "type_overrides:\n" .
            "  - db_type: \"TIMESTAMP\"\n" .
            "    php_type: \"\\\\DateTimeImmutable\"\n"
        );

        $this->writeFile('config/booleans.yaml',
            "type_overrides:\n" .
            "  - db_type: \"TINYINT\"\n" .
            "    php_type: \"bool\"\n"
        );

        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/timestamps.yaml\n" .
            "  - config/booleans.yaml\n";

        $config = Config::fromFile($this->writeMain($main));

        $this->assertCount(2, $config->typeOverrides);
    }

    public function test_includes_mixed_sections_in_same_file(): void
    {
        $this->writeFile('config/shared.yaml',
            "virtual_tables:\n" .
            "  - name: active_users\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n" .
            "type_overrides:\n" .
            "  - db_type: \"TINYINT\"\n" .
            "    php_type: \"bool\"\n"
        );

        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/shared.yaml\n";

        $config = Config::fromFile($this->writeMain($main));

        $this->assertCount(1, $config->virtualTables);
        $this->assertCount(1, $config->typeOverrides);
    }

    public function test_include_virtual_tables_merged_with_main_file_virtual_tables(): void
    {
        $this->writeFile('config/views.yaml',
            "virtual_tables:\n" .
            "  - name: from_include\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n"
        );

        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/views.yaml\n" .
            "virtual_tables:\n" .
            "  - name: from_main\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n";

        $config = Config::fromFile($this->writeMain($main));

        $this->assertCount(2, $config->virtualTables);
        $names = array_map(fn($t) => $t->name, $config->virtualTables);
        $this->assertContains('from_include', $names);
        $this->assertContains('from_main',    $names);
    }

    // =========================================================================
    // includes: — error handling
    // =========================================================================

    public function test_missing_include_throws_runtime_exception(): void
    {
        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/does_not_exist.yaml\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/include file not found/');

        Config::fromFile($this->writeMain($main));
    }

    public function test_include_error_message_contains_path(): void
    {
        $main = $this->minimalYaml() .
            "includes:\n" .
            "  - config/missing.yaml\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing\.yaml/');

        Config::fromFile($this->writeMain($main));
    }

    public function test_include_scalar_engine_is_ignored(): void
    {
        // Engine in include file is silently ignored — main file wins
        $this->writeFile('config/override_attempt.yaml',
            "engine: postgres\n" .
            "virtual_tables:\n" .
            "  - name: v\n" .
            "    columns:\n" .
            "      - { name: id, type: INT }\n"
        );

        $main = "version: \"2\"\n" .
                "schema: schema.sql\n" .
                "engine: mysql\n" .
                "includes:\n" .
                "  - config/override_attempt.yaml\n" .
                "targets:\n" .
                "  - namespace: \"App\\\\Db\"\n" .
                "    out: gen\n" .
                "    queries: q.sql\n";

        $config = Config::fromFile($this->writeMain($main));

        // Engine from main file wins — include cannot override scalar globals
        $this->assertSame('mysql', $config->engine);
        // But virtual_tables from include are still loaded
        $this->assertCount(1, $config->virtualTables);
    }
}
