<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for two v2.13.0 bugs found when running the CLI end-to-end
 * with real config files on disk (not reproducible via unit tests alone,
 * since they only manifest when bin/sqlc-php itself runs):
 *
 *  1. `$baseDir` was referenced in runGeneration() but never defined — caused
 *     "Undefined variable $baseDir" warning + TypeError on every run, even
 *     when `ctes:` was not configured at all.
 *
 *  2. The QueryDefinition reconstruction (for queries using @use) referenced
 *     a non-existent `returnType` named parameter (the real property is
 *     `returns`) and omitted several required properties, causing a fatal
 *     "Unknown named parameter" error whenever a query actually used @use.
 *
 * These tests invoke the actual bin/sqlc-php CLI as a subprocess against a
 * real temporary project directory, exactly as a user would.
 */
class CliCteRegressionTest extends TestCase
{
    private string $projectDir;
    private string $binPath;

    protected function setUp(): void
    {
        $this->binPath    = dirname(__DIR__) . '/bin/sqlc-php';
        $this->projectDir = sys_get_temp_dir() . '/sqlc-php-cli-test-' . uniqid();

        mkdir($this->projectDir . '/database/queries', 0755, true);
        mkdir($this->projectDir . '/database/ctes', 0755, true);
        mkdir($this->projectDir . '/generated', 0755, true);

        file_put_contents($this->projectDir . '/database/schema.sql', <<<SQL
            CREATE TABLE users   (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100) NOT NULL);
            CREATE TABLE reserve (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, status VARCHAR(20) NOT NULL);
        SQL);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function runCli(string $configPath): string
    {
        $cmd = sprintf(
            'cd %s && php %s %s 2>&1',
            escapeshellarg($this->projectDir),
            escapeshellarg($this->binPath),
            escapeshellarg(basename($configPath))
        );
        return shell_exec($cmd) ?? '';
    }

    // =========================================================================
    // Bug 1 — $baseDir undefined when ctes: is absent entirely
    // =========================================================================

    public function test_cli_runs_without_baseDir_warning_when_no_ctes_configured(): void
    {
        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            SELECT * FROM users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $output = $this->runCli($this->projectDir . '/sqlc.yaml');

        $this->assertStringNotContainsString('Undefined variable', $output);
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringNotContainsString('TypeError', $output);
        $this->assertStringContainsString('Done.', $output);
    }

    public function test_cli_generates_files_without_ctes_configured(): void
    {
        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            SELECT * FROM users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $this->runCli($this->projectDir . '/sqlc.yaml');

        $this->assertFileExists($this->projectDir . '/generated/User.php');
        $this->assertFileExists($this->projectDir . '/generated/UsersQuery.php');
    }

    // =========================================================================
    // Bug 2 — QueryDefinition reconstruction fatal error when @use is present
    // =========================================================================

    public function test_cli_runs_without_fatal_error_when_query_uses_cte(): void
    {
        file_put_contents($this->projectDir . '/database/ctes/shared.sql', <<<SQL
            -- @cte active_users
            SELECT id, email FROM users WHERE id > 0;
        SQL);

        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListActiveUsers
            -- @class Users
            -- @returns :many
            -- @use active_users
            SELECT active_users.* FROM active_users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        ctes:
          - database/ctes/shared.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $output = $this->runCli($this->projectDir . '/sqlc.yaml');

        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringNotContainsString('Unknown named parameter', $output);
        $this->assertStringNotContainsString('Undefined property', $output);
        $this->assertStringContainsString('Done.', $output);
    }

    public function test_cli_generates_correct_files_when_query_uses_cte(): void
    {
        file_put_contents($this->projectDir . '/database/ctes/shared.sql', <<<SQL
            -- @cte active_users
            SELECT id, email FROM users WHERE id > 0;
        SQL);

        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListActiveUsers
            -- @class Users
            -- @returns :many
            -- @use active_users
            SELECT active_users.* FROM active_users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        ctes:
          - database/ctes/shared.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $output = $this->runCli($this->projectDir . '/sqlc.yaml');

        // CLI must report the loaded CTE
        $this->assertStringContainsString('CTEs', $output);
        $this->assertStringContainsString('active_users', $output);

        // Query class is grouped by @class — both queries share "Users"
        $this->assertFileExists($this->projectDir . '/generated/UsersQuery.php');
    }

    public function test_cli_reports_loaded_ctes_in_output(): void
    {
        file_put_contents($this->projectDir . '/database/ctes/shared.sql', <<<SQL
            -- @cte active_users
            SELECT id FROM users WHERE id > 0;

            -- @cte recent_reserves
            SELECT id FROM reserve WHERE status = 'pending';
        SQL);

        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            SELECT * FROM users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        ctes:
          - database/ctes/shared.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $output = $this->runCli($this->projectDir . '/sqlc.yaml');

        $this->assertStringContainsString('active_users', $output);
        $this->assertStringContainsString('recent_reserves', $output);
    }

    // =========================================================================
    // Multiple queries, only some using @use — mixed scenario from the bug report
    // =========================================================================

    public function test_cli_handles_mixed_queries_with_and_without_use(): void
    {
        file_put_contents($this->projectDir . '/database/ctes/shared.sql', <<<SQL
            -- @cte active_users
            SELECT id, email FROM users WHERE id > 0;
        SQL);

        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListUsers
            -- @class Users
            -- @returns :many
            SELECT * FROM users;

            -- @name ListReserves
            -- @class Reserves
            -- @returns :many
            SELECT * FROM reserve;

            -- @name ListActiveUsers
            -- @class Users
            -- @returns :many
            -- @use active_users
            SELECT active_users.* FROM active_users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        ctes:
          - database/ctes/shared.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $output = $this->runCli($this->projectDir . '/sqlc.yaml');

        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringContainsString('3 total', $output);
        $this->assertFileExists($this->projectDir . '/generated/UsersQuery.php');
        $this->assertFileExists($this->projectDir . '/generated/ReservesQuery.php');
    }

    // =========================================================================
    // Bug fix — CTE paths resolved from CWD, not from config file directory
    //
    // Reproduction: ./bin/sqlc-php config/sqlc.yaml
    //   config is in a subdirectory; CTEs declared as 'database/ctes/shared.sql'
    //   should be resolved relative to CWD (/app), not to config dir (/app/config).
    // =========================================================================

    public function test_cte_paths_resolved_from_cwd_not_config_directory(): void
    {
        // Create a subdirectory for the config file, simulating:
        //   CWD: $projectDir
        //   Config: $projectDir/config/sqlc.yaml
        //   CTEs: $projectDir/database/ctes/shared.sql  ← relative to CWD, not config dir
        mkdir($this->projectDir . '/config', 0777, true);

        file_put_contents($this->projectDir . '/database/ctes/shared.sql', <<<SQL
            -- @cte active_users
            SELECT id, email FROM users WHERE id > 0;
        SQL);

        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListActiveUsers
            -- @class Users
            -- @returns :many
            -- @use active_users
            SELECT active_users.* FROM active_users;
        SQL);

        // Config is in config/ subdir — CTEs path is relative to CWD (project root)
        file_put_contents($this->projectDir . '/config/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        ctes:
          - database/ctes/shared.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        // Run CLI from project root (CWD=$projectDir) with config path in subdir
        $cmd = sprintf(
            'cd %s && php %s %s 2>&1',
            escapeshellarg($this->projectDir),
            escapeshellarg($this->binPath),
            escapeshellarg('config/sqlc.yaml')  // config in subdir, CTE paths relative to CWD
        );
        $output = shell_exec($cmd) ?? '';

        // Before fix: "file not found: /path/to/project/config/database/ctes/shared.sql"
        // After fix:  resolves from CWD → "/path/to/project/database/ctes/shared.sql" ✓
        $this->assertStringNotContainsString('Fatal error', $output, $output);
        $this->assertStringNotContainsString('not found', $output, $output);
        $this->assertStringContainsString('active_users', $output);
        $this->assertStringContainsString('Done.', $output);
        $this->assertFileExists($this->projectDir . '/generated/UsersQuery.php');
    }
    // =========================================================================
    // Bug fix — @with stream/count/exists/paginated flags lost after CTE injection
    // =========================================================================

    public function test_stream_companion_generated_when_query_uses_cte(): void
    {
        file_put_contents($this->projectDir . '/database/ctes/shared.sql', <<<SQL
            -- @cte active_users
            SELECT id, email FROM users WHERE id > 0;
        SQL);

        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListActiveUsers
            -- @class Users
            -- @returns :many
            -- @with stream
            -- @use active_users
            SELECT active_users.* FROM active_users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        ctes:
          - database/ctes/shared.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $output = $this->runCli($this->projectDir . '/sqlc.yaml');
        $this->assertStringContainsString('Done.', $output, $output);

        $code = file_get_contents($this->projectDir . '/generated/UsersQuery.php');

        // Before fix: stream flag lost during QueryDefinition reconstruction after @use
        $this->assertStringContainsString('function streamListActiveUsers', $code,
            'stream companion must be generated even when @use is present');
        $this->assertStringContainsString('\\Generator', $code);
    }

    public function test_all_with_flags_preserved_after_cte_injection(): void
    {
        file_put_contents($this->projectDir . '/database/ctes/shared.sql', <<<SQL
            -- @cte active_users
            SELECT id, email FROM users WHERE id > 0;
        SQL);

        file_put_contents($this->projectDir . '/database/queries/queries.sql', <<<SQL
            -- @name ListActiveUsers
            -- @class Users
            -- @returns :many
            -- @with stream, count, exists
            -- @use active_users
            SELECT active_users.* FROM active_users;
        SQL);

        file_put_contents($this->projectDir . '/sqlc.yaml', <<<YAML
        version: 1
        engine: mysql
        schema: database/schema.sql
        ctes:
          - database/ctes/shared.sql
        targets:
          - namespace: App\\Database
            queries:
              - database/queries/queries.sql
            out: generated/
        YAML);

        $output = $this->runCli($this->projectDir . '/sqlc.yaml');
        $this->assertStringContainsString('Done.', $output, $output);

        $code = file_get_contents($this->projectDir . '/generated/UsersQuery.php');

        $this->assertStringContainsString('function streamListActiveUsers', $code);
        $this->assertStringContainsString('function listActiveUsersCount',  $code);
        $this->assertStringContainsString('function listActiveUsersExists', $code);
    }
}
