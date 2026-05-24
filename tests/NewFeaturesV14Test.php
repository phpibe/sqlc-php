<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\Config;
use SqlcPhp\Config\Target;
use SqlcPhp\Config\TypeOverride;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Parser\ReturnType;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

// =============================================================================
// ManyPaginatedTest
// =============================================================================

class ManyPaginatedTest extends TestCase
{
    private QueryParser   $parser;
    private QueryAnalyzer $analyzer;
    private QueryGenerator $queryGen;

    protected function setUp(): void
    {
        $schema = "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL, active TINYINT DEFAULT 1 null);";

        $catalog          = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper           = new MySQLTypeMapper([], new EnumGenerator('App\\Db'));
        $this->parser     = new QueryParser();
        $pr               = new ParamResolver($catalog, $mapper);
        $er               = new ExpressionTypeResolver($catalog, $mapper);
        $cr               = new ColumnResolver($catalog, $mapper, $pr, $er);
        $this->analyzer   = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter());
        $this->queryGen   = new QueryGenerator($catalog, $mapper, new ResultDtoGenerator('App\\Db'), 'App\\Db');
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    // -------------------------------------------------------------------------
    // Parser
    // -------------------------------------------------------------------------

    public function test_parser_accepts_many_paginated_return_type(): void
    {
        $queries = $this->parser->parse(
            "-- @name List\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        $this->assertSame(':many-paginated', $queries[0]->returns->value);
    }

    // -------------------------------------------------------------------------
    // Analyzer — SQL injection
    // -------------------------------------------------------------------------

    public function test_analyzer_appends_limit_offset_to_sql(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        $this->assertStringContainsString('LIMIT :limit', $queries[0]->sql);
        $this->assertStringContainsString('OFFSET :offset', $queries[0]->sql);
    }

    public function test_analyzer_strips_trailing_semicolon_before_injecting(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        // There should be no semicolon before LIMIT
        $this->assertStringNotContainsString(";\nLIMIT", $queries[0]->sql);
    }

    public function test_paginated_query_resolves_columns(): void
    {
        $queries = $this->analyze(
            "-- @name List\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        $this->assertNotEmpty($queries[0]->resultColumns);
    }

    // -------------------------------------------------------------------------
    // Generator
    // -------------------------------------------------------------------------

    public function test_generated_method_has_limit_and_offset_params(): void
    {
        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        $code = $this->queryGen->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString('int $limit = 20',  $code);
        $this->assertStringContainsString('int $offset = 0',  $code);
    }

    public function test_generated_method_binds_limit_and_offset(): void
    {
        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        $code = $this->queryGen->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString("bindValue(':limit'",  $code);
        $this->assertStringContainsString("bindValue(':offset'", $code);
        $this->assertStringContainsString('PDO::PARAM_INT',      $code);
    }

    public function test_generated_method_returns_array(): void
    {
        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        $code = $this->queryGen->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString('public function listUsers(', $code);
        $this->assertStringContainsString('): array', $code);
    }

    public function test_generated_method_has_docblock_for_limit_and_offset(): void
    {
        $queries = $this->analyze(
            "-- @name ListUsers\n-- @returns :many-paginated\nSELECT users.* FROM users;"
        );
        $code = $this->queryGen->generate($queries)['UserQuery']['code'];

        $this->assertStringContainsString('@param int $limit',  $code);
        $this->assertStringContainsString('@param int $offset', $code);
    }

    public function test_user_params_appear_before_limit_and_offset(): void
    {
        $queries = $this->analyze(
            "-- @name ListByActive\n-- @returns :many-paginated\nSELECT users.* FROM users WHERE active = :active;"
        );
        $code = $this->queryGen->generate($queries)['UserQuery']['code'];

        $activePos = strpos($code, '$active');
        $limitPos  = strpos($code, '$limit');
        $this->assertLessThan($limitPos, $activePos);
    }
}

// =============================================================================
// NillableOnDirectModelTest
// =============================================================================

class NillableOnDirectModelTest extends TestCase
{
    private QueryParser   $parser;
    private QueryAnalyzer $analyzer;

    protected function setUp(): void
    {
        $schema = "CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL, username VARCHAR(45) NOT NULL);";

        $catalog        = new SchemaCatalog((new SchemaParser())->parse($schema));
        $mapper         = new MySQLTypeMapper([], new EnumGenerator('App\\Db'));
        $this->parser   = new QueryParser();
        $pr             = new ParamResolver($catalog, $mapper);
        $er             = new ExpressionTypeResolver($catalog, $mapper);
        $cr             = new ColumnResolver($catalog, $mapper, $pr, $er);
        $this->analyzer = new QueryAnalyzer($pr, $cr, $this->parser, new SqlRewriter());
    }

    private function analyze(string $sql): array
    {
        return $this->analyzer->analyze($this->parser->parse($sql));
    }

    public function test_nillable_on_direct_model_forces_dto_generation(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\n-- @nillable email\nSELECT users.* FROM users WHERE id = :id;"
        );

        // With @nillable, returnsModelDirectly must be false — a DTO will be generated
        $this->assertFalse($queries[0]->returnsModelDirectly);
    }

    public function test_nillable_on_direct_model_forces_nullable_column(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\n-- @nillable email\nSELECT users.* FROM users WHERE id = :id;"
        );

        $emailCol = null;
        foreach ($queries[0]->resultColumns as $col) {
            if ($col->alias === 'email') $emailCol = $col;
        }

        $this->assertNotNull($emailCol, 'email column should be in result columns');
        $this->assertTrue($emailCol->nullable);
        $this->assertStringStartsWith('?', $emailCol->phpType);
    }

    public function test_without_nillable_direct_model_is_preserved(): void
    {
        $queries = $this->analyze(
            "-- @name Get\n-- @returns :one\nSELECT users.* FROM users WHERE id = :id;"
        );

        $this->assertTrue($queries[0]->returnsModelDirectly);
        $this->assertSame('User', $queries[0]->modelClass);
    }

    public function test_nillable_generates_row_dto_not_model(): void
    {
        $queries = $this->analyze(
            "-- @name GetProfile\n-- @returns :one\n-- @nillable username\nSELECT users.* FROM users WHERE id = :id;"
        );

        $dtoGen = new ResultDtoGenerator('App\\Db');
        $name   = $dtoGen->dtoClassName($queries[0]);

        $this->assertSame('GetProfileRow', $name);
    }
}

// =============================================================================
// MultipleTargetsTest
// =============================================================================

class MultipleTargetsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-targets-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "{$dir}/{$f}";
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }

    private function writeConfig(string $yaml): string
    {
        $path = $this->tmpDir . '/sqlc.yaml';
        file_put_contents($path, $yaml);
        return $path;
    }

    // -------------------------------------------------------------------------
    // Target value object
    // -------------------------------------------------------------------------

    public function test_target_from_array_parses_namespace_and_out(): void
    {
        $t = Target::fromArray(['namespace' => 'App\\Read', 'out' => 'gen/read', 'queries' => 'q.sql']);
        $this->assertSame('App\\Read', $t->namespace);
        $this->assertSame('gen/read',  $t->out);
    }

    public function test_target_queries_as_scalar(): void
    {
        $t = Target::fromArray(['namespace' => 'App\\Db', 'out' => 'gen', 'queries' => 'queries.sql']);
        $this->assertSame(['queries.sql'], $t->queries);
    }

    public function test_target_queries_as_list(): void
    {
        $t = Target::fromArray([
            'namespace' => 'App\\Db',
            'out'       => 'gen',
            'queries'   => ['a.sql', 'b.sql'],
        ]);
        $this->assertSame(['a.sql', 'b.sql'], $t->queries);
    }

    public function test_target_generate_interfaces_default_false(): void
    {
        $t = Target::fromArray(['namespace' => 'App\\Db', 'out' => 'gen', 'queries' => 'q.sql']);
        $this->assertFalse($t->generateInterfaces);
    }

    public function test_target_inherits_global_overrides(): void
    {
        $global = TypeOverride::fromArray(['db_type' => 'TINYINT', 'php_type' => 'bool']);
        $t      = Target::fromArray(['namespace' => 'App\\Db', 'out' => 'gen', 'queries' => 'q.sql'], [$global]);
        $this->assertCount(1, $t->typeOverrides);
    }

    // -------------------------------------------------------------------------
    // Config parsing
    // -------------------------------------------------------------------------

    public function test_config_parses_targets_block(): void
    {
        $path = $this->writeConfig(
            "version: \"1\"\n" .
            "schema: schema.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\\\\Read\"\n" .
            "    out: gen/read\n" .
            "    queries: queries/read.sql\n" .
            "  - namespace: \"App\\\\Write\"\n" .
            "    out: gen/write\n" .
            "    queries: queries/write.sql\n"
        );

        $config = Config::fromFile($path);
        $this->assertCount(2, $config->targets);
        $this->assertSame('App\\Read',  $config->targets[0]->namespace);
        $this->assertSame('App\\Write', $config->targets[1]->namespace);
    }

    public function test_config_empty_targets_when_not_specified(): void
    {
        $path   = $this->writeConfig("version: \"1\"\nschema: s.sql\nqueries: q.sql\n");
        $config = Config::fromFile($path);
        $this->assertSame([], $config->targets);
    }

    public function test_config_targets_each_have_correct_queries(): void
    {
        $path = $this->writeConfig(
            "version: \"1\"\n" .
            "schema: schema.sql\n" .
            "targets:\n" .
            "  - namespace: \"App\\\\Read\"\n" .
            "    out: gen/read\n" .
            "    queries: read.sql\n" .
            "  - namespace: \"App\\\\Write\"\n" .
            "    out: gen/write\n" .
            "    queries: write.sql\n"
        );

        $config = Config::fromFile($path);
        $this->assertSame(['read.sql'],  $config->targets[0]->queries);
        $this->assertSame(['write.sql'], $config->targets[1]->queries);
    }
}

// =============================================================================
// DryRunDiffTest — CLI integration (using the same wrapper approach as VerifyFlagTest)
// =============================================================================

class DryRunDiffTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-drdiff-' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/out');
        $this->writeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "{$dir}/{$f}";
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }

    private function writeFixtures(): void
    {
        file_put_contents($this->tmpDir . '/schema.sql',
            "CREATE TABLE users (\n" .
            "    id    INT          AUTO_INCREMENT PRIMARY KEY,\n" .
            "    email VARCHAR(100) NOT NULL\n" .
            ");\n"
        );
        file_put_contents($this->tmpDir . '/queries.sql',
            "-- @name ListUsers\n-- @returns :many\nSELECT users.* FROM users;\n"
        );
        file_put_contents($this->tmpDir . '/sqlc.yaml',
            "version: \"1\"\n" .
            "schema: schema.sql\n" .
            "queries: queries.sql\n" .
            "php:\n" .
            "  namespace: \"App\\\\Database\"\n" .
            "  out: out\n" .
            "  engine: mysql\n"
        );
    }

    private function runCli(string $flag): array
    {
        $bootstrap  = dirname(__DIR__, 1) . '/tests/bootstrap.php';
        $wrapper    = $this->buildWrapper($flag);
        $wrapperFile = $this->tmpDir . '/_wrapper.php';
        file_put_contents($wrapperFile, $wrapper);

        exec(PHP_BINARY . ' ' . escapeshellarg($wrapperFile) . ' 2>&1', $output, $exit);
        return ['output' => implode("\n", $output), 'exit' => $exit];
    }

    private function generate(): void
    {
        $this->runCli('');
    }

    private function buildWrapper(string $flag): string
    {
        $bootstrap = addslashes(dirname(__DIR__, 1) . '/tests/bootstrap.php');
        $tmpDir    = addslashes($this->tmpDir);
        $flagLine  = $flag ? "'{$flag}'," : '';

        return <<<PHP
<?php
declare(strict_types=1);
require '{$bootstrap}';
chdir('{$tmpDir}');
\$_SERVER['argv'] = \$argv = ['sqlc-php', {$flagLine} 'sqlc.yaml'];
\$argc = count(\$argv);

use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\Config;
use SqlcPhp\Config\Target;
use SqlcPhp\Generator\{EnumGenerator, InterfaceGenerator, ModelGenerator, QueryGenerator, ResultDtoGenerator};
use SqlcPhp\Parser\{SchemaParser, QueryParser};
use SqlcPhp\Resolver\{ColumnResolver, ParamResolver, ExpressionTypeResolver};
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

\$args       = array_slice(\$argv, 1);
\$verifyMode = in_array('--verify',  \$args, true);
\$dryRun     = in_array('--dry-run', \$args, true);
\$diffMode   = in_array('--diff',    \$args, true);
\$args       = array_values(array_filter(\$args, fn(\$a) => !in_array(\$a, ['--verify','--dry-run','--diff'])));
\$configPath = \$args[0] ?? 'sqlc.yaml';

if (\$verifyMode) echo "Mode   : VERIFY\n";
if (\$dryRun)     echo "Mode   : DRY-RUN\n";
if (\$diffMode)   echo "Mode   : DIFF\n";

\$config = Config::fromFile(\$configPath);

\$allSql = '';
foreach (\$config->schemas as \$f) \$allSql .= "\n" . file_get_contents(\$f);
\$catalog = new SchemaCatalog((new SchemaParser())->parse(\$allSql));

if (!empty(\$config->targets)) {
    \$passes = \$config->targets;
} else {
    \$passes = [new Target(\$config->namespace, \$config->out, \$config->queries, \$config->generateInterfaces, \$config->typeOverrides)];
}

\$allToWrite = [];
foreach (\$passes as \$target) {
    \$qp = new QueryParser();
    \$rq = [];
    foreach (\$target->queries as \$f) \$rq = array_merge(\$rq, \$qp->parse(file_get_contents(\$f)));
    \$eg  = new EnumGenerator(\$target->namespace);
    \$tm  = new MySQLTypeMapper(\$target->typeOverrides, \$eg);
    \$pr  = new ParamResolver(\$catalog, \$tm);
    \$er  = new ExpressionTypeResolver(\$catalog, \$tm);
    \$cr  = new ColumnResolver(\$catalog, \$tm, \$pr, \$er);
    \$az  = new QueryAnalyzer(\$pr, \$cr, \$qp, new SqlRewriter());
    \$qs  = \$az->analyze(\$rq);
    \$dg  = new ResultDtoGenerator(\$target->namespace);
    \$ig  = \$target->generateInterfaces ? new InterfaceGenerator(\$target->namespace) : null;
    \$qg  = new QueryGenerator(\$catalog, \$tm, \$dg, \$target->namespace, \$target->generateInterfaces, \$ig);
    \$mg  = new ModelGenerator(\$catalog, \$tm, \$qp, \$target->namespace);
    \$toW = [];
    foreach (\$catalog->all() as \$t) foreach (\$t->columns as \$c) { if (!\$c->isEnum()) continue; ['className'=>\$cl,'code'=>\$co] = \$eg->generate(\$t->name,\$c); \$toW["\$cl.php"]=['label'=>'[enum]','code'=>\$co]; }
    foreach (array_unique(array_filter(array_column(\$qs,'fromTable'))) as \$tn) { ['className'=>\$cl,'code'=>\$co] = \$mg->generate(\$tn); \$toW["\$cl.php"]=['label'=>'[model]','code'=>\$co]; }
    foreach (\$qs as \$q) { if (\$q->returnsModelDirectly||empty(\$q->resultColumns)||\$q->returns->value===':exec') continue; ['className'=>\$cl,'code'=>\$co]=\$dg->generate(\$q); \$toW["\$cl.php"]=['label'=>'[dto]','code'=>\$co]; }
    foreach (\$qg->generate(\$qs) as ['className'=>\$cl,'code'=>\$co]) \$toW["\$cl.php"]=['label'=>'[query]','code'=>\$co];
    foreach (\$qg->generateInterfaces(\$qs) as ['className'=>\$cl,'code'=>\$co]) \$toW["\$cl.php"]=['label'=>'[iface]','code'=>\$co];
    foreach (\$toW as \$fn => \$e) \$allToWrite["\$target->out/\$fn"] = \$e['code'];
    if (!\$verifyMode && !\$dryRun && !\$diffMode) {
        if (!is_dir(\$target->out)) mkdir(\$target->out, 0755, true);
        foreach (\$toW as \$fn => ['code'=>\$co]) file_put_contents("\$target->out/\$fn", \$co);
    }
}

if (\$verifyMode) {
    \$miss=[]; \$diffs=[];
    foreach (\$allToWrite as \$p => \$c) { if (!file_exists(\$p)) \$miss[]=\$p; elseif (file_get_contents(\$p)!==\$c) \$diffs[]=\$p; }
    if (empty(\$miss)&&empty(\$diffs)) { echo "✓ All ".count(\$allToWrite)." generated file(s) are up to date.\n"; exit(0); }
    foreach (\$miss as \$f) echo "Missing: \$f\n";
    foreach (\$diffs as \$f) echo "Modified: \$f\n";
    exit(1);
}

if (\$dryRun) {
    foreach (\$allToWrite as \$p => \$c) { echo "// \$p\n"; echo \$c; echo "\n"; }
    echo "✓ Dry run complete. ".count(\$allToWrite)." file(s) would be written.\n";
    exit(0);
}

if (\$diffMode) {
    \$changed = false;
    foreach (\$allToWrite as \$p => \$c) {
        \$old = file_exists(\$p) ? file_get_contents(\$p) : null;
        if (\$old === \$c) continue;
        \$changed = true;
        if (\$old === null) echo "+++ \$p (new file)\n";
        else { echo "--- \$p\n+++ \$p\n"; }
    }
    if (!\$changed) { echo "✓ No changes. ".count(\$allToWrite)." file(s) are up to date.\n"; exit(0); }
    exit(1);
}

echo "Done. ".count(\$allToWrite)." file(s) written.\n";
PHP;
    }

    // -------------------------------------------------------------------------
    // --dry-run
    // -------------------------------------------------------------------------

    public function test_dry_run_does_not_write_files(): void
    {
        $result = $this->runCli('--dry-run');

        $files = array_diff(scandir($this->tmpDir . '/out') ?: [], ['.', '..']);
        $this->assertEmpty($files, '--dry-run must not write any files');
    }

    public function test_dry_run_prints_generated_code(): void
    {
        $result = $this->runCli('--dry-run');

        $this->assertStringContainsString('<?php', $result['output']);
        $this->assertStringContainsString('class User', $result['output']);
    }

    public function test_dry_run_prints_file_paths(): void
    {
        $result = $this->runCli('--dry-run');

        $this->assertStringContainsString('User.php', $result['output']);
    }

    public function test_dry_run_exits_zero(): void
    {
        $result = $this->runCli('--dry-run');

        $this->assertSame(0, $result['exit']);
    }

    public function test_dry_run_shows_summary_count(): void
    {
        $result = $this->runCli('--dry-run');

        $this->assertMatchesRegularExpression('/\d+ file\(s\) would be written/', $result['output']);
    }

    public function test_dry_run_reports_mode(): void
    {
        $result = $this->runCli('--dry-run');
        $this->assertStringContainsString('DRY-RUN', $result['output']);
    }

    // -------------------------------------------------------------------------
    // --diff
    // -------------------------------------------------------------------------

    public function test_diff_exits_zero_when_no_changes(): void
    {
        $this->generate();
        $result = $this->runCli('--diff');

        $this->assertSame(0, $result['exit']);
    }

    public function test_diff_exits_one_when_files_differ(): void
    {
        $this->generate();
        file_put_contents($this->tmpDir . '/out/User.php', '<?php // modified');

        $result = $this->runCli('--diff');
        $this->assertSame(1, $result['exit']);
    }

    public function test_diff_does_not_write_files(): void
    {
        $result = $this->runCli('--diff');

        $files = array_diff(scandir($this->tmpDir . '/out') ?: [], ['.', '..']);
        $this->assertEmpty($files, '--diff must not write any files');
    }

    public function test_diff_reports_modified_file(): void
    {
        $this->generate();
        file_put_contents($this->tmpDir . '/out/User.php', '<?php // modified');

        $result = $this->runCli('--diff');
        $this->assertStringContainsString('User.php', $result['output']);
    }

    public function test_diff_reports_new_file(): void
    {
        // No generation first — all files are new
        $result = $this->runCli('--diff');
        $this->assertStringContainsString('new file', $result['output']);
    }

    public function test_diff_reports_mode(): void
    {
        $result = $this->runCli('--diff');
        $this->assertStringContainsString('DIFF', $result['output']);
    }
}
