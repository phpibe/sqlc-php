<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

/**
 * Parses SQL files that contain one or more @cte annotated blocks.
 *
 * File format:
 *
 *   -- @cte active_users
 *   SELECT id, email, role
 *   FROM users
 *   WHERE active = 1 AND role = 'client';
 *
 *   -- @cte recent_orders
 *   SELECT id, user_id, total
 *   FROM orders
 *   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);
 *
 * Rules:
 *  - Each @cte block ends at the next @cte annotation or at EOF.
 *  - The trailing semicolon of each block is stripped.
 *  - Blank lines and SQL comments within the body are preserved.
 *  - Lines that are only a comment containing @cte are the block separator.
 *  - A file may contain any number of @cte blocks.
 *  - Blocks without a @cte header are silently ignored.
 *
 * @see CteDefinition
 */
class CteParser
{
    /**
     * Parse a CTE SQL file and return all defined CTEs.
     *
     * @param  string            $sql        Full content of the .sql file
     * @param  string            $sourceFile Path used in error messages only
     * @return CteDefinition[]
     *
     * @throws \RuntimeException When a @cte block has no name or an empty body
     */
    public function parse(string $sql, string $sourceFile = ''): array
    {
        $definitions = [];
        $lines       = explode("\n", $sql);
        $total       = count($lines);

        $currentName = null;
        $bodyLines   = [];

        $flush = function () use (&$definitions, &$currentName, &$bodyLines, $sourceFile): void {
            if ($currentName === null) return;

            $body = trim(implode("\n", $bodyLines));
            // Strip trailing semicolon(s)
            $body = rtrim(rtrim($body), ';');
            $body = rtrim($body);

            if ($body === '') {
                throw new \RuntimeException(
                    "CTE '@cte {$currentName}' in '{$sourceFile}' has an empty body."
                );
            }

            $definitions[] = new CteDefinition(
                name:       $currentName,
                sql:        $body,
                sourceFile: $sourceFile,
            );

            $currentName = null;
            $bodyLines   = [];
        };

        for ($i = 0; $i < $total; $i++) {
            $line    = $lines[$i];
            $trimmed = trim($line);

            // Detect @cte annotation line: -- @cte name
            if (preg_match('/^--\s*@cte\s+(\w+)\s*$/i', $trimmed, $m)) {
                // Flush previous block before starting a new one
                $flush();
                $currentName = $m[1];
                continue;
            }

            // Inside a block — collect body lines
            if ($currentName !== null) {
                $bodyLines[] = $line;
            }
            // Lines before any @cte header are silently ignored
        }

        // Flush the last block
        $flush();

        return $definitions;
    }

    /**
     * Parse a CTE file from disk.
     *
     * @return CteDefinition[]
     * @throws \RuntimeException When the file cannot be read or a block is invalid
     */
    public function parseFile(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("CTE file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read CTE file: {$path}");
        }

        return $this->parse($content, $path);
    }
}
