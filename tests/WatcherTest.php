<?php

declare(strict_types=1);

namespace SqlcPhp\Tests;

use PHPUnit\Framework\TestCase;
use SqlcPhp\Watcher;

class WatcherTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sqlc-watcher-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    private function file(string $name, string $content = ''): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    // =========================================================================
    // Construction and path registration
    // =========================================================================

    public function test_instantiates_with_no_paths(): void
    {
        $w = new Watcher();
        $this->assertSame([], $w->paths());
    }

    public function test_instantiates_with_paths(): void
    {
        $a = $this->file('a.sql', 'schema');
        $b = $this->file('b.sql', 'queries');
        $w = new Watcher([$a, $b]);

        $this->assertCount(2, $w->paths());
        $this->assertContains($a, $w->paths());
        $this->assertContains($b, $w->paths());
    }

    public function test_add_registers_new_path(): void
    {
        $w = new Watcher();
        $a = $this->file('a.sql');
        $w->add($a);

        $this->assertContains($a, $w->paths());
    }

    public function test_set_all_replaces_existing_paths(): void
    {
        $a = $this->file('a.sql');
        $b = $this->file('b.sql');
        $c = $this->file('c.sql');

        $w = new Watcher([$a, $b]);
        $w->setAll([$c]);

        $this->assertSame([$c], $w->paths());
    }

    // =========================================================================
    // poll() — no changes
    // =========================================================================

    public function test_poll_returns_empty_when_nothing_changed(): void
    {
        $a = $this->file('a.sql', 'content');
        $w = new Watcher([$a]);

        $this->assertSame([], $w->poll());
    }

    public function test_poll_returns_empty_on_second_call_with_no_change(): void
    {
        $a = $this->file('a.sql', 'content');
        $w = new Watcher([$a]);
        $w->poll(); // first poll

        $this->assertSame([], $w->poll()); // second poll — nothing changed
    }

    // =========================================================================
    // poll() — file modified
    // =========================================================================

    public function test_poll_detects_file_modification(): void
    {
        $a = $this->file('a.sql', 'original');
        $w = new Watcher([$a]);
        $w->poll(); // baseline

        // Simulate modification — change mtime by touching with future timestamp
        touch($a, time() + 2);

        $changed = $w->poll();
        $this->assertContains($a, $changed);
    }

    public function test_poll_clears_change_after_detection(): void
    {
        $a = $this->file('a.sql', 'original');
        $w = new Watcher([$a]);
        $w->poll(); // baseline

        touch($a, time() + 2);
        $w->poll(); // detect change

        // Next poll should return empty — already recorded new mtime
        $this->assertSame([], $w->poll());
    }

    public function test_poll_detects_only_changed_files(): void
    {
        $a = $this->file('a.sql', 'content a');
        $b = $this->file('b.sql', 'content b');
        $w = new Watcher([$a, $b]);
        $w->poll(); // baseline

        touch($a, time() + 2); // only a changes

        $changed = $w->poll();
        $this->assertContains($a,    $changed);
        $this->assertNotContains($b, $changed);
    }

    public function test_poll_detects_multiple_simultaneous_changes(): void
    {
        $a = $this->file('a.sql', 'a');
        $b = $this->file('b.sql', 'b');
        $w = new Watcher([$a, $b]);
        $w->poll();

        touch($a, time() + 2);
        touch($b, time() + 2);

        $changed = $w->poll();
        $this->assertContains($a, $changed);
        $this->assertContains($b, $changed);
    }

    // =========================================================================
    // poll() — file created / deleted
    // =========================================================================

    public function test_poll_detects_file_deletion(): void
    {
        $a = $this->file('a.sql', 'content');
        $w = new Watcher([$a]);
        $w->poll();

        unlink($a);

        $changed = $w->poll();
        $this->assertContains($a, $changed);
    }

    public function test_poll_detects_file_creation(): void
    {
        $path = $this->tmpDir . '/new.sql';
        $w    = new Watcher([$path]); // registered before file exists
        $w->poll();

        // File created after initial poll
        file_put_contents($path, 'new content');

        $changed = $w->poll();
        $this->assertContains($path, $changed);
    }

    public function test_polls_nonexistent_file_returns_no_change_if_still_missing(): void
    {
        $path = $this->tmpDir . '/missing.sql';
        $w    = new Watcher([$path]);
        $w->poll(); // records as missing

        // Still missing — no change
        $this->assertSame([], $w->poll());
    }

    // =========================================================================
    // setAll updates watch list — new paths polled fresh
    // =========================================================================

    public function test_set_all_tracks_new_paths(): void
    {
        $a = $this->file('a.sql', 'a');
        $b = $this->file('b.sql', 'b');

        $w = new Watcher([$a]);
        $w->poll();

        $w->setAll([$b]);
        touch($b, time() + 2);

        $changed = $w->poll();
        $this->assertContains($b, $changed);
    }

    public function test_set_all_forgets_removed_paths(): void
    {
        $a = $this->file('a.sql', 'a');
        $b = $this->file('b.sql', 'b');

        $w = new Watcher([$a, $b]);
        $w->poll();

        $w->setAll([$b]); // a is removed
        touch($a, time() + 2);

        // a is no longer tracked
        $this->assertNotContains($a, $w->poll());
    }

    // =========================================================================
    // Version constant — ensure it's 2.4.0
    // =========================================================================

    public function test_version_is_updated_to_reflect_watch_mode(): void
    {
        // Watch mode ships in v2.4.0
        $this->assertSame('2.7.0', \SqlcPhp\Version::VERSION);
    }
}
