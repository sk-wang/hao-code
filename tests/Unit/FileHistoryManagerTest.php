<?php

namespace Tests\Unit;

use App\Services\FileHistory\FileHistoryManager;
use PHPUnit\Framework\TestCase;

class FileHistoryManagerTest extends TestCase
{
    private string $sessionId;
    private FileHistoryManager $manager;

    protected function setUp(): void
    {
        $this->sessionId = 'test_' . uniqid();
        $this->manager = new FileHistoryManager($this->sessionId);
    }

    protected function tearDown(): void
    {
        $path = sys_get_temp_dir() . '/haocode_file_history/' . $this->sessionId;
        if (is_dir($path)) {
            array_map('unlink', glob("{$path}/*") ?: []);
            rmdir($path);
        }
    }

    private function makeTmpFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'fhm_');
        file_put_contents($file, $content);
        return $file;
    }

    public function test_record_before_stores_snapshot(): void
    {
        $file = $this->makeTmpFile('original content');

        $this->manager->recordBefore($file);

        $snapshots = $this->manager->getAllSnapshots();
        $this->assertCount(1, $snapshots);
        $this->assertSame('original content', reset($snapshots)->content);

        unlink($file);
    }

    public function test_record_before_does_nothing_for_nonexistent_file(): void
    {
        $this->manager->recordBefore('/nonexistent/path/file.txt');

        $this->assertEmpty($this->manager->getAllSnapshots());
    }

    public function test_record_before_skips_duplicate_content_for_same_file(): void
    {
        $file = $this->makeTmpFile('same content');

        $this->manager->recordBefore($file);
        $this->manager->recordBefore($file); // same content, same path

        $this->assertCount(1, $this->manager->getAllSnapshots());

        unlink($file);
    }

    public function test_record_before_stores_new_snapshot_when_content_changes(): void
    {
        $file = $this->makeTmpFile('version 1');
        $this->manager->recordBefore($file);

        file_put_contents($file, 'version 2');
        $this->manager->recordBefore($file);

        $this->assertCount(2, $this->manager->getAllSnapshots());

        unlink($file);
    }

    public function test_get_latest_returns_most_recent_snapshot(): void
    {
        $file = $this->makeTmpFile('first');
        $this->manager->recordBefore($file);

        file_put_contents($file, 'second');
        $this->manager->recordBefore($file);

        $latest = $this->manager->getLatest();
        $this->assertNotNull($latest);
        $this->assertSame('second', $latest->content);

        unlink($file);
    }

    public function test_get_latest_returns_null_when_no_snapshots(): void
    {
        $this->assertNull($this->manager->getLatest());
    }

    public function test_get_snapshots_for_file_filters_by_path(): void
    {
        $file1 = $this->makeTmpFile('file one');
        $file2 = $this->makeTmpFile('file two');

        $this->manager->recordBefore($file1);
        $this->manager->recordBefore($file2);

        $forFile1 = $this->manager->getSnapshotsForFile($file1);
        $this->assertCount(1, $forFile1);
        $this->assertSame('file one', reset($forFile1)->content);

        unlink($file1);
        unlink($file2);
    }

    public function test_restore_writes_snapshot_content_back_to_file(): void
    {
        $file = $this->makeTmpFile('original');
        $this->manager->recordBefore($file);

        $snapshots = $this->manager->getAllSnapshots();
        $id = reset($snapshots)->id;

        file_put_contents($file, 'modified');
        $this->assertSame('modified', file_get_contents($file));

        $result = $this->manager->restore($id);
        $this->assertTrue($result);
        $this->assertSame('original', file_get_contents($file));

        unlink($file);
    }

    public function test_restore_returns_false_for_unknown_id(): void
    {
        $result = $this->manager->restore(99999);
        $this->assertFalse($result);
    }

    public function test_snapshot_ids_are_unique_across_trim_boundary(): void
    {
        // Fill past MAX_SNAPSHOTS (100) to trigger the trim. We use many different files
        // to bypass the duplicate-content dedup logic.
        $files = [];
        for ($i = 0; $i <= 102; $i++) {
            $file = $this->makeTmpFile("content_{$i}");
            $files[] = $file;
            $this->manager->recordBefore($file);
        }

        $allIds = array_map(fn($s) => $s->id, $this->manager->getAllSnapshots());
        $this->assertSame(count($allIds), count(array_unique($allIds)), 'Snapshot IDs must be unique after trim');

        foreach ($files as $f) {
            unlink($f);
        }
    }

    public function test_get_summary_reflects_tracked_files(): void
    {
        $file = $this->makeTmpFile('content');
        $this->manager->recordBefore($file);

        $summary = $this->manager->getSummary();

        $this->assertStringContainsString('1 snapshots', $summary);
        $this->assertStringContainsString(basename($file), $summary);

        unlink($file);
    }

    public function test_get_summary_with_no_snapshots(): void
    {
        $summary = $this->manager->getSummary();
        $this->assertStringContainsString('No file changes tracked', $summary);
    }

    public function test_get_diff_returns_null_when_backup_file_missing(): void
    {
        // Simulate a case where the snapshot exists in memory but the backup
        // file on disk was deleted (e.g., cleaned up by a separate process).
        $file = $this->makeTmpFile("original\n");
        $this->manager->recordBefore($file);

        $snapshots = $this->manager->getAllSnapshots();
        $id1 = reset($snapshots)->id;

        // Delete the backup file from disk
        $backupFile = sys_get_temp_dir() . '/haocode_file_history/' . $this->sessionId;
        $files = glob("{$backupFile}/*");
        if ($files) {
            unlink($files[0]);
        }

        // Now record a second snapshot (the backup file for id1 is gone)
        file_put_contents($file, "modified\n");
        $this->manager->recordBefore($file);
        $id2 = $this->manager->getLatest()->id;

        // getDiff should return null when the from backup file is missing
        $this->assertNull($this->manager->getDiff($id1, $id2));

        unlink($file);
    }

    public function test_get_diff_returns_no_differences_for_same_content(): void
    {
        $file = $this->makeTmpFile("same\ncontent\n");
        $this->manager->recordBefore($file);
        $id1 = $this->manager->getLatest()->id;

        // Same content, but since dedup prevents a second snapshot,
        // we compare the snapshot to itself via a roundabout way
        $diff = $this->manager->getDiff($id1, $id1);
        // Comparing same id means same file → diff sees no differences
        $this->assertNotNull($diff);
        $this->assertStringContainsString('No differences', $diff);

        unlink($file);
    }

    public function test_record_before_deduplicates_same_content_even_with_interleaved_files(): void
    {
        // Reproduces the bug: interleaving file edits should still deduplicate
        // when the same file's content hasn't changed.
        $fileA = $this->makeTmpFile('content A');
        $fileB = $this->makeTmpFile('content B');

        $this->manager->recordBefore($fileA); // snapshot 1 for fileA
        $this->manager->recordBefore($fileB); // snapshot 2 for fileB
        $this->manager->recordBefore($fileA); // fileA with SAME content — should NOT add snapshot

        $forA = array_values($this->manager->getSnapshotsForFile($fileA));
        $this->assertCount(1, $forA, 'Same content for fileA should not create a second snapshot');

        unlink($fileA);
        unlink($fileB);
    }

    public function test_get_diff_returns_null_for_unknown_ids(): void
    {
        $this->assertNull($this->manager->getDiff(0, 1));
    }

    public function test_get_diff_returns_diff_between_two_snapshots(): void
    {
        $file = $this->makeTmpFile("line1\nline2\n");
        $this->manager->recordBefore($file);
        $id1 = $this->manager->getLatest()->id;

        file_put_contents($file, "line1\nline2\nline3\n");
        $this->manager->recordBefore($file);
        $id2 = $this->manager->getLatest()->id;

        $diff = $this->manager->getDiff($id1, $id2);

        $this->assertNotNull($diff);
        $this->assertStringContainsString('line3', $diff);

        unlink($file);
    }
}
