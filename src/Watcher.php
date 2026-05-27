<?php

declare(strict_types=1);

namespace SqlcPhp;

/**
 * File system watcher for --watch mode.
 *
 * Tracks a set of files by their last-modified timestamp (filemtime).
 * On each poll() call it returns any paths whose mtime has changed since
 * the previous call.
 *
 * Usage:
 *   $watcher = new Watcher(['/path/to/schema.sql', '/path/to/queries.sql']);
 *   while (true) {
 *       $changed = $watcher->poll();
 *       if (!empty($changed)) { ... regenerate ... }
 *       usleep(500_000);
 *   }
 */
class Watcher
{
    /** @var array<string, int|false>  path → last known mtime */
    private array $mtimes = [];

    /**
     * @param string[] $paths  Absolute or relative file paths to watch
     */
    public function __construct(array $paths = [])
    {
        foreach ($paths as $path) {
            $this->add($path);
        }
    }

    /**
     * Add a path to the watch list and record its current mtime.
     */
    public function add(string $path): void
    {
        $this->mtimes[$path] = file_exists($path) ? filemtime($path) : false;
    }

    /**
     * Replace the entire watch list with new paths.
     *
     * @param string[] $paths
     */
    public function setAll(array $paths): void
    {
        $this->mtimes = [];
        foreach ($paths as $path) {
            $this->add($path);
        }
    }

    /**
     * Returns all paths that have been created, modified, or deleted
     * since the last poll() call. Clears the change flag after returning.
     *
     * @return string[]
     */
    public function poll(): array
    {
        $changed = [];

        // Clear stat cache so filemtime reflects current disk state
        clearstatcache();

        foreach ($this->mtimes as $path => $knownMtime) {
            $currentMtime = file_exists($path) ? filemtime($path) : false;

            if ($currentMtime !== $knownMtime) {
                $changed[]           = $path;
                $this->mtimes[$path] = $currentMtime;
            }
        }

        return $changed;
    }

    /**
     * All currently watched paths.
     *
     * @return string[]
     */
    public function paths(): array
    {
        return array_keys($this->mtimes);
    }
}
