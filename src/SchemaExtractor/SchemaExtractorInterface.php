<?php

declare(strict_types=1);

namespace SqlcPhp\SchemaExtractor;

use SqlcPhp\Config\DatabaseConfig;

/**
 * Contract for extracting a CREATE TABLE schema from a live database.
 * Used by the --generate-schema CLI flag.
 */
interface SchemaExtractorInterface
{
    /**
     * Connect to the database and extract all (or filtered) table definitions
     * as a SQL string suitable for use as the schema: file in sqlc.yaml.
     *
     * @param  \PDO           $pdo  Active PDO connection
     * @param  DatabaseConfig $cfg  Config with include/exclude table filters
     * @return string               Complete SQL schema (CREATE TABLE statements)
     *
     * @throws \RuntimeException on connection or extraction failure
     */
    public function extract(\PDO $pdo, DatabaseConfig $cfg): string;
}
