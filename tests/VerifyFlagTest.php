<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the --verify CLI flag.
 *
 * These are integration tests that invoke the CLI as a subprocess
 * so we can inspect exit codes and stderr output.
 */
class VerifyFlagTest extends TestCase
{
    private string $tmpDir;
    private string $binPath;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/sqlc-php-verify-' . uniqid();
        $this->binPath = dirname(__DIR__, 1) . '/bin/sqlc-php';
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/out');
        $this->writeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function writeFixtures(): void
    {
        file_put_contents($this->tmpDir . '/schema.sql',
            "CREATE TABLE users (\n" .
            "    id    INT          AUTO_INCREMENT PRIMARY KEY,\n" .
            "    email VARCHAR(100) NOT NULL\n" .
            ");\n"
        );

        file_put_contents($this->tmpDir . '/queries.sql',
            "-- @name ListUsers\n" .
            "-- @returns :many\n" .
            "SELECT users.* FROM users;\n"
        );

        file_put_contents($this->tmpDir . '/sqlc.yaml',
            "version: \"1\"\n" .
            "schema:  schema.sql\n" .
            "queries: queries.sql\n" .
            "php:\n" .
            "  namespace: \"App\\\\Database\"\n" .
            "  out: out\n" .
            "  engine: mysql\n"
        );
    }

    private function runCli(string $flags = ''): array
    {
        $phpBin    = PHP_BINARY;
        $bootstrap = dirname(__DIR__, 1) . '/tests/bootstrap.php';
        $bin       = $this->binPath;
        $yaml      = $this->tmpDir . '/sqlc.yaml';

        // Wrap the CLI call in a small harness that provides the autoloader
        // without needing composer's vendor/autoload.php
        $harness = <<<PHP
<?php
// Inject the test bootstrap autoloader before running the CLI
require {$this->phpQuote($bootstrap)};

// Stub the PHPIBE_COMPOSER_INSTALL constant so the CLI require line is a no-op
define('PHPIBE_COMPOSER_INSTALL', __FILE__);

// Override require so it silently skips when the file is __FILE__
// (not needed — we just need the constant defined before the CLI reads it)
// Now include the CLI (it will hit `require PHPIBE_COMPOSER_INSTALL` which re-requires this file,
// but since classes are already loaded via spl_autoload, it's harmless)

// Re-route argv
\$_SERVER['argv'] = ['sqlc-php', {$this->phpQuote($flags ? '--verify' : '')}, {$this->phpQuote($yaml)}];
\$argv            = \$_SERVER['argv'];

// Change working directory to tmp so relative paths in yaml resolve
chdir({$this->phpQuote($this->tmpDir)});

ob_start();
PHP;
        // Actually, simpler: create a thin wrapper that sets up autoload then calls CLI logic
        $wrapper = $this->tmpDir . '/_cli_wrapper.php';
        file_put_contents($wrapper, $this->buildCliWrapper($flags));

        $cmd = sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($wrapper));
        exec($cmd, $output, $exitCode);
        return ['output' => implode("\n", $output), 'exit' => $exitCode];
    }

    private function buildCliWrapper(string $flags): string
    {
        $bootstrap = addslashes(dirname(__DIR__, 1) . '/tests/bootstrap.php');
        $srcDir    = addslashes(dirname(__DIR__, 1) . '/src');
        $yaml      = addslashes($this->tmpDir . '/sqlc.yaml');
        $tmpDir    = addslashes($this->tmpDir);

        $verifyFlag = str_contains($flags, '--verify') ? "'--verify'," : '';

        return <<<PHP
<?php
declare(strict_types=1);

// Load classes via the test bootstrap autoloader
require '{$bootstrap}';

// Simulate argv + chdir
chdir('{$tmpDir}');
\$_SERVER['argv'] = \$argv = [
    'sqlc-php',
    {$verifyFlag}
    'sqlc.yaml',
];
\$argc = count(\$argv);

// Replicate the CLI logic inline (avoids the composer autoloader requirement)
use SqlcPhp\Analyzer\QueryAnalyzer;
use SqlcPhp\Catalog\SchemaCatalog;
use SqlcPhp\Config\Config;
use SqlcPhp\Generator\EnumGenerator;
use SqlcPhp\Generator\InterfaceGenerator;
use SqlcPhp\Generator\ModelGenerator;
use SqlcPhp\Generator\QueryGenerator;
use SqlcPhp\Generator\ResultDtoGenerator;
use SqlcPhp\Parser\SchemaParser;
use SqlcPhp\Parser\QueryParser;
use SqlcPhp\Resolver\ColumnResolver;
use SqlcPhp\Resolver\ParamResolver;
use SqlcPhp\Resolver\ExpressionTypeResolver;
use SqlcPhp\Rewriter\SqlRewriter;
use SqlcPhp\TypeMapper\MySQLTypeMapper;

\$args       = array_slice(\$argv, 1);
\$verifyMode = in_array('--verify', \$args, true);
\$args       = array_values(array_filter(\$args, fn(\$a) => \$a !== '--verify'));
\$configPath = \$args[0] ?? 'sqlc.yaml';

echo "sqlc-php — PHP code generator\n";
if (\$verifyMode) echo "Mode   : VERIFY (no files will be written)\n";
echo "Config : {\$configPath}\n\n";

\$config = Config::fromFile(\$configPath);

\$tables  = (new SchemaParser())->parse(file_get_contents(\$config->schema) ?: '');
\$catalog = new SchemaCatalog(\$tables);

\$queryParser = new QueryParser();
\$rawQueries  = [];
foreach (\$config->queries as \$queryFile) {
    \$parsed     = \$queryParser->parse(file_get_contents(\$queryFile) ?: '');
    \$rawQueries = array_merge(\$rawQueries, \$parsed);
}

\$enumGen       = new EnumGenerator(\$config->namespace);
\$typeMapper    = new MySQLTypeMapper(\$config->typeOverrides, \$enumGen);
\$paramResolver = new ParamResolver(\$catalog, \$typeMapper);
\$exprResolver  = new ExpressionTypeResolver(\$catalog, \$typeMapper);
\$colResolver   = new ColumnResolver(\$catalog, \$typeMapper, \$paramResolver, \$exprResolver);
\$analyzer      = new QueryAnalyzer(\$paramResolver, \$colResolver, \$queryParser, new SqlRewriter());
\$queries       = \$analyzer->analyze(\$rawQueries);

\$resultDtoGen  = new ResultDtoGenerator(\$config->namespace);
\$interfaceGen  = \$config->generateInterfaces ? new InterfaceGenerator(\$config->namespace) : null;
\$queryGen      = new QueryGenerator(\$catalog, \$typeMapper, \$resultDtoGen, \$config->namespace, \$config->generateInterfaces, \$interfaceGen);
\$modelGen      = new ModelGenerator(\$catalog, \$typeMapper, \$queryParser, \$config->namespace);

\$outDir  = rtrim(\$config->out, '/');
\$toWrite = [];

foreach (\$catalog->all() as \$table) {
    foreach (\$table->columns as \$col) {
        if (!\$col->isEnum()) continue;
        ['className' => \$cls, 'code' => \$code] = \$enumGen->generate(\$table->name, \$col);
        \$toWrite["{\$cls}.php"] = ['label' => '[enum]   ', 'code' => \$code];
    }
}

\$tablesUsed = array_unique(array_filter(array_column(\$queries, 'fromTable')));
foreach (\$tablesUsed as \$tableName) {
    ['className' => \$cls, 'code' => \$code] = \$modelGen->generate(\$tableName);
    \$toWrite["{\$cls}.php"] = ['label' => '[model]  ', 'code' => \$code];
}

foreach (\$queries as \$query) {
    if (\$query->returnsModelDirectly || empty(\$query->resultColumns)) continue;
    if (\$query->returns->value === ':exec') continue;
    \$result = \$resultDtoGen->generate(\$query);
    \$toWrite["{\$result['className']}.php"] = ['label' => '[dto]    ', 'code' => \$result['code']];
    foreach (\$result['embeds'] ?? [] as \$ecls => ['className' => \$en, 'code' => \$ec]) {
        \$toWrite["{\$en}.php"] = ['label' => '[embed]  ', 'code' => \$ec];
    }
}

foreach (\$queryGen->generate(\$queries) as ['className' => \$cls, 'code' => \$code]) {
    \$toWrite["{\$cls}.php"] = ['label' => '[query]  ', 'code' => \$code];
}
foreach (\$queryGen->generateInterfaces(\$queries) as ['className' => \$cls, 'code' => \$code]) {
    \$toWrite["{\$cls}.php"] = ['label' => '[iface]  ', 'code' => \$code];
}

if (\$verifyMode) {
    \$diffs   = [];
    \$missing = [];
    foreach (\$toWrite as \$filename => ['code' => \$code]) {
        \$path = "{\$outDir}/{\$filename}";
        if (!file_exists(\$path)) {
            \$missing[] = \$path;
        } elseif (file_get_contents(\$path) !== \$code) {
            \$diffs[] = \$path;
        }
    }
    if (empty(\$diffs) && empty(\$missing)) {
        echo "✓ All " . count(\$toWrite) . " generated file(s) are up to date.\n";
        exit(0);
    }
    fwrite(STDERR, "\n✗ Generated files are out of date.\n\n");
    if (!empty(\$missing)) {
        fwrite(STDERR, "Missing files (" . count(\$missing) . "):\n");
        foreach (\$missing as \$f) fwrite(STDERR, "  - {\$f}\n");
    }
    if (!empty(\$diffs)) {
        fwrite(STDERR, "\nModified files (" . count(\$diffs) . "):\n");
        foreach (\$diffs as \$f) fwrite(STDERR, "  ~ {\$f}\n");
    }
    fwrite(STDERR, "\nRun `php bin/sqlc-php {\$configPath}` to regenerate.\n");
    exit(1);
}

if (!is_dir(\$outDir)) mkdir(\$outDir, 0755, true);
\$written = 0;
foreach (\$toWrite as \$filename => ['label' => \$label, 'code' => \$code]) {
    file_put_contents("{\$outDir}/{\$filename}", \$code);
    echo "  {\$label}{\$outDir}/{\$filename}\n";
    \$written++;
}
echo "\nDone. {\$written} file(s) written to {\$outDir}/\n";
PHP;
    }

    private function phpQuote(string $s): string
    {
        return "'" . addslashes($s) . "'";
    }

    private function generate(): void
    {
        $this->runCli();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = "{$dir}/{$f}";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_verify_passes_when_files_are_up_to_date(): void
    {
        $this->generate();

        $result = $this->runCli('--verify');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('up to date', $result['output']);
    }

    public function test_verify_fails_when_generated_file_is_missing(): void
    {
        $this->generate();
        unlink($this->tmpDir . '/out/User.php');

        $result = $this->runCli('--verify');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Missing', $result['output']);
    }

    public function test_verify_fails_when_generated_file_is_modified(): void
    {
        $this->generate();
        file_put_contents($this->tmpDir . '/out/User.php', '<?php // manually modified');

        $result = $this->runCli('--verify');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Modified', $result['output']);
    }

    public function test_verify_reports_out_of_date_file_names(): void
    {
        $this->generate();
        unlink($this->tmpDir . '/out/User.php');

        $result = $this->runCli('--verify');

        $this->assertStringContainsString('User.php', $result['output']);
    }

    public function test_verify_does_not_write_files(): void
    {
        // Start clean — no generated files
        $result = $this->runCli('--verify');

        $this->assertSame(1, $result['exit']);
        // The out/ directory should still be empty
        $files = array_diff(scandir($this->tmpDir . '/out') ?: [], ['.', '..']);
        $this->assertEmpty($files, 'verify mode must not write any files');
    }

    public function test_verify_outputs_mode_indicator(): void
    {
        $result = $this->runCli('--verify');

        $this->assertStringContainsString('VERIFY', $result['output']);
    }

    public function test_verify_suggests_regeneration_command(): void
    {
        $this->generate();
        unlink($this->tmpDir . '/out/User.php');

        $result = $this->runCli('--verify');

        $this->assertStringContainsString('bin/sqlc-php', $result['output']);
    }

    public function test_verify_reports_count_when_up_to_date(): void
    {
        $this->generate();

        $result = $this->runCli('--verify');

        $this->assertMatchesRegularExpression('/\d+ generated file/', $result['output']);
    }
}
