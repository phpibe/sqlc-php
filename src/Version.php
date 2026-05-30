<?php

declare(strict_types=1);

namespace SqlcPhp;

/**
 * Single source of truth for the sqlc-php version.
 * Used by the CLI (--version flag) and displayed in generated file headers.
 */
final class Version
{
    public const VERSION = '2.6.2';

    public const BANNER = <<<TEXT
sqlc-php v2.6.2 — PHP code generator inspired by sqlc
https://github.com/phpibe/sqlc-php
TEXT;

    private function __construct() {}

    public static function get(): string
    {
        return self::VERSION;
    }
}
