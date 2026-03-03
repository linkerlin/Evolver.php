<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\GitSelfRepair;
use PHPUnit\Framework\TestCase;

final class GitSelfRepairTest extends TestCase
{
    private GitSelfRepair $repair;

    protected function setUp(): void
    {
        // Use the actual repo path (this test repo)
        $this->repair = new GitSelfRepair(dirname(__DIR__, 2));
    }

    public function testConstructorSetsRepoPath(): void
    {
        $repair = new GitSelfRepair('/tmp');
        $status = $repair->repair();

        $this->assertEquals('/tmp', $status['repo_path']);
    }

    public function testRepairReturnsCorrectStructure(): void
    {
        $result = $this->repair->repair();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('repo_path', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('fixes', $result);
        $this->assertArrayHasKey('ok', $result);
    }

    public function testRepairChecksGitDir(): void
    {
        $result = $this->repair->repair();

        $this->assertArrayHasKey('git_dir', $result['checks']);
        $this->assertArrayHasKey('ok', $result['checks']['git_dir']);
        $this->assertArrayHasKey('message', $result['checks']['git_dir']);

        // This repo should have a .git directory
        $this->assertTrue($result['checks']['git_dir']['ok']);
    }

    public function testRepairChecksStaleLocks(): void
    {
        $result = $this->repair->repair();

        $this->assertArrayHasKey('stale_locks', $result['checks']);
        $this->assertArrayHasKey('ok', $result['checks']['stale_locks']);

        // Should not have stale locks in a healthy repo
        $this->assertTrue($result['checks']['stale_locks']['ok']);
    }

    public function testRepairChecksIndex(): void
    {
        $result = $this->repair->repair();

        $this->assertArrayHasKey('index', $result['checks']);
        $this->assertArrayHasKey('ok', $result['checks']['index']);

        // Index should be valid in this repo
        $this->assertTrue($result['checks']['index']['ok']);
    }

    public function testRepairChecksDetachedHead(): void
    {
        $result = $this->repair->repair();

        $this->assertArrayHasKey('detached_head', $result['checks']);
        $this->assertArrayHasKey('is_detached', $result['checks']['detached_head']);
    }

    public function testRepairChecksFsck(): void
    {
        $result = $this->repair->repair();

        $this->assertArrayHasKey('fsck', $result['checks']);
        $this->assertArrayHasKey('ok', $result['checks']['fsck']);
    }

    public function testRepairChecksUntracked(): void
    {
        $result = $this->repair->repair();

        $this->assertArrayHasKey('untracked', $result['checks']);
        $this->assertArrayHasKey('count', $result['checks']['untracked']);
        $this->assertArrayHasKey('files', $result['checks']['untracked']);
        $this->assertIsInt($result['checks']['untracked']['count']);
        $this->assertIsArray($result['checks']['untracked']['files']);
    }

    public function testGetLastResultReturnsRepairResult(): void
    {
        $this->repair->repair();
        $lastResult = $this->repair->getLastResult();

        $this->assertIsArray($lastResult);
        $this->assertArrayHasKey('timestamp', $lastResult);
        $this->assertArrayHasKey('checks', $lastResult);
    }

    public function testGetLastResultReturnsEmptyBeforeRepair(): void
    {
        $repair = new GitSelfRepair(dirname(__DIR__, 2));
        $result = $repair->getLastResult();

        $this->assertEmpty($result);
    }

    public function testRepairOnNonGitRepo(): void
    {
        $repair = new GitSelfRepair(sys_get_temp_dir());
        $result = $repair->repair();

        $this->assertFalse($result['checks']['git_dir']['ok']);
        $this->assertStringContainsString('Not a git repository', $result['checks']['git_dir']['message']);
    }

    public function testTimestampFormat(): void
    {
        $result = $this->repair->repair();

        // Verify ATOM format (ISO 8601)
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $result['timestamp']
        );
    }

    public function testUntrackedFilesLimited(): void
    {
        $result = $this->repair->repair();

        // Files array should be limited to 20 items
        $this->assertLessThanOrEqual(20, count($result['checks']['untracked']['files']));
    }

    public function testDetachedHeadCheckReturnsBranchInfo(): void
    {
        $result = $this->repair->repair();

        // Should have branch info if not detached
        if (!$result['checks']['detached_head']['is_detached']) {
            $this->assertArrayHasKey('branch', $result['checks']['detached_head']);
        }
    }

    public function testOverallOkStatus(): void
    {
        $result = $this->repair->repair();

        // Result should have boolean ok status
        $this->assertIsBool($result['ok']);
    }

    public function testDefaultRepoPath(): void
    {
        $repair = new GitSelfRepair();
        $result = $repair->repair();

        // Should use dirname(__DIR__, 2) as default
        $this->assertArrayHasKey('repo_path', $result);
    }
}
