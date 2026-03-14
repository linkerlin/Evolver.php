<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\IssueReporter;
use Evolver\Paths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IssueReporterTest extends TestCase
{
    private ?string $originalEvolutionDir = null;
    private ?string $originalAutoIssue = null;
    private ?string $originalIssueRepo = null;
    private ?string $originalCooldown = null;
    private ?string $originalMinStreak = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEvolutionDir = getenv('EVOLUTION_DIR') ?: null;
        $this->originalAutoIssue = getenv('EVOLVER_AUTO_ISSUE') ?: null;
        $this->originalIssueRepo = getenv('EVOLVER_ISSUE_REPO') ?: null;
        $this->originalCooldown = getenv('EVOLVER_ISSUE_COOLDOWN_MS') ?: null;
        $this->originalMinStreak = getenv('EVOLVER_ISSUE_MIN_STREAK') ?: null;
        putenv('EVOLUTION_DIR=' . sys_get_temp_dir() . '/evolver_issuereporter_test_' . uniqid());
        $this->cleanupTestFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        if ($this->originalEvolutionDir !== null) {
            putenv('EVOLUTION_DIR=' . $this->originalEvolutionDir);
        } else {
            putenv('EVOLUTION_DIR');
        }
        if ($this->originalAutoIssue !== null) {
            putenv('EVOLVER_AUTO_ISSUE=' . $this->originalAutoIssue);
        } else {
            putenv('EVOLVER_AUTO_ISSUE');
        }
        if (isset($this->originalIssueRepo)) {
            if ($this->originalIssueRepo !== null) {
                putenv('EVOLVER_ISSUE_REPO=' . $this->originalIssueRepo);
            } else {
                putenv('EVOLVER_ISSUE_REPO');
            }
        }
        if (isset($this->originalCooldown)) {
            if ($this->originalCooldown !== null) {
                putenv('EVOLVER_ISSUE_COOLDOWN_MS=' . $this->originalCooldown);
            } else {
                putenv('EVOLVER_ISSUE_COOLDOWN_MS');
            }
        }
        if (isset($this->originalMinStreak)) {
            if ($this->originalMinStreak !== null) {
                putenv('EVOLVER_ISSUE_MIN_STREAK=' . $this->originalMinStreak);
            } else {
                putenv('EVOLVER_ISSUE_MIN_STREAK');
            }
        }
        parent::tearDown();
    }

    private function cleanupTestFiles(): void
    {
        $stateFile = IssueReporter::getStatePath();
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }
        if (file_exists($stateFile . '.tmp')) {
            unlink($stateFile . '.tmp');
        }
    }

    #[Test]
    public function getConfigReturnsNullWhenDisabled(): void
    {
        putenv('EVOLVER_AUTO_ISSUE=false');
        $reporter = new IssueReporter();
        $this->assertNull($reporter->getConfig());
    }

    #[Test]
    public function getConfigReturnsNullWhenZero(): void
    {
        // Unset other env vars that might affect this test
        putenv('EVOLVER_ISSUE_REPO');
        putenv('EVOLVER_ISSUE_COOLDOWN_MS');
        putenv('EVOLVER_ISSUE_MIN_STREAK');
        putenv('EVOLVER_AUTO_ISSUE=0');
        $reporter = new IssueReporter();
        $this->assertNull($reporter->getConfig());
    }

    #[Test]
    public function getConfigReturnsDefaultsWhenEnabled(): void
    {
        putenv('EVOLVER_AUTO_ISSUE=true');
        putenv('EVOLVER_ISSUE_REPO'); // Unset
        putenv('EVOLVER_ISSUE_COOLDOWN_MS'); // Unset
        putenv('EVOLVER_ISSUE_MIN_STREAK'); // Unset
        $reporter = new IssueReporter();
        $config = $reporter->getConfig();

        $this->assertNotNull($config);
        $this->assertEquals('autogame-17/capability-evolver', $config['repo']);
        $this->assertEquals(86400000, $config['cooldownMs']);
        $this->assertEquals(5, $config['minStreak']);
    }

    #[Test]
    public function getConfigUsesEnvOverrides(): void
    {
        putenv('EVOLVER_AUTO_ISSUE=true');
        putenv('EVOLVER_ISSUE_REPO=myorg/myrepo');
        putenv('EVOLVER_ISSUE_COOLDOWN_MS=3600000');
        putenv('EVOLVER_ISSUE_MIN_STREAK=3');
        $reporter = new IssueReporter();
        $config = $reporter->getConfig();

        $this->assertNotNull($config);
        $this->assertEquals('myorg/myrepo', $config['repo']);
        $this->assertEquals(3600000, $config['cooldownMs']);
        $this->assertEquals(3, $config['minStreak']);
    }

    #[Test]
    public function shouldReportReturnsFalseWhenDisabled(): void
    {
        putenv('EVOLVER_AUTO_ISSUE=false');
        $reporter = new IssueReporter();
        $this->assertFalse($reporter->shouldReport(['failure_loop_detected']));
    }

    #[Test]
    public function shouldReportReturnsFalseWithoutFailureLoop(): void
    {
        $reporter = new IssueReporter();
        $this->assertFalse($reporter->shouldReport(['some_other_signal']));
    }

    #[Test]
    public function shouldReportReturnsTrueWithFailureLoop(): void
    {
        $reporter = new IssueReporter();
        $this->assertTrue($reporter->shouldReport(['failure_loop_detected']));
    }

    #[Test]
    public function shouldReportReturnsTrueWithRecurringAndHigh(): void
    {
        $reporter = new IssueReporter();
        $this->assertTrue($reporter->shouldReport(['recurring_error', 'high_failure_ratio']));
    }

    #[Test]
    public function shouldReportReturnsFalseWithLowStreak(): void
    {
        // Reset env vars to defaults
        putenv('EVOLVER_AUTO_ISSUE=true');
        putenv('EVOLVER_ISSUE_REPO'); // Unset
        putenv('EVOLVER_ISSUE_COOLDOWN_MS'); // Unset
        putenv('EVOLVER_ISSUE_MIN_STREAK'); // Unset, use default 5
        $reporter = new IssueReporter();
        $signals = ['failure_loop_detected', 'consecutive_failure_streak_3'];
        $this->assertFalse($reporter->shouldReport($signals));
    }

    #[Test]
    public function shouldReportReturnsTrueWithHighStreak(): void
    {
        $reporter = new IssueReporter();
        $signals = ['failure_loop_detected', 'consecutive_failure_streak_7'];
        $this->assertTrue($reporter->shouldReport($signals));
    }

    #[Test]
    public function extractStreakCountReturnsZeroWhenNotFound(): void
    {
        $reporter = new IssueReporter();
        $this->assertEquals(0, $reporter->extractStreakCount(['some_signal']));
    }

    #[Test]
    public function extractStreakCountReturnsCorrectValue(): void
    {
        $reporter = new IssueReporter();
        $this->assertEquals(5, $reporter->extractStreakCount(['consecutive_failure_streak_5']));
        $this->assertEquals(10, $reporter->extractStreakCount(['consecutive_failure_streak_10']));
    }

    #[Test]
    public function extractErrorSignatureReturnsDefaultWhenNotFound(): void
    {
        $reporter = new IssueReporter();
        $this->assertEquals('Persistent evolution failure', $reporter->extractErrorSignature([]));
    }

    #[Test]
    public function extractErrorSignatureReturnsBanGeneMessage(): void
    {
        $reporter = new IssueReporter();
        $result = $reporter->extractErrorSignature(['ban_gene:gene_123']);
        $this->assertStringContainsString('Repeated failures with gene:', $result);
        $this->assertStringContainsString('gene_123', $result);
    }

    #[Test]
    public function extractErrorSignatureReturnsErrsig(): void
    {
        $reporter = new IssueReporter();
        $result = $reporter->extractErrorSignature(['recurring_errsig(3x): TypeError in foo()']);
        $this->assertStringContainsString('TypeError in foo()', $result);
    }

    #[Test]
    public function computeErrorKeyReturnsConsistentHash(): void
    {
        $reporter = new IssueReporter();
        $key1 = $reporter->computeErrorKey(['failure_loop_detected', 'high_failure_ratio']);
        $key2 = $reporter->computeErrorKey(['high_failure_ratio', 'failure_loop_detected']);

        $this->assertEquals(16, strlen($key1));
        $this->assertEquals($key1, $key2); // Order shouldn't matter
    }

    #[Test]
    public function buildIssueBodyIncludesEnvironment(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody(['signals' => []]);

        $this->assertStringContainsString('## Environment', $body);
        $this->assertStringContainsString('**PHP:**', $body);
        $this->assertStringContainsString('**Platform:**', $body);
    }

    #[Test]
    public function buildIssueBodyIncludesFailureSummary(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody([
            'signals' => ['failure_loop_detected', 'consecutive_failure_streak_5'],
        ]);

        $this->assertStringContainsString('## Failure Summary', $body);
        $this->assertStringContainsString('Consecutive failures', $body);
        $this->assertStringContainsString('5', $body);
    }

    #[Test]
    public function buildIssueBodyIncludesErrorSignature(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody([
            'signals' => ['ban_gene:gene_123'],
        ]);

        $this->assertStringContainsString('## Error Signature', $body);
        $this->assertStringContainsString('gene_123', $body);
    }

    #[Test]
    public function buildIssueBodyIncludesRecentEvents(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody([
            'signals' => [],
            'recentEvents' => [
                ['intent' => 'fix bug', 'outcome' => ['status' => 'failed', 'reason' => 'test error']],
            ],
        ]);

        $this->assertStringContainsString('## Recent Evolution Events', $body);
        $this->assertStringContainsString('fix bug', $body);
    }

    #[Test]
    public function buildIssueBodyIncludesSessionLog(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody([
            'signals' => [],
            'sessionLog' => 'This is a test session log',
        ]);

        $this->assertStringContainsString('## Session Log Excerpt', $body);
        $this->assertStringContainsString('This is a test session log', $body);
    }

    #[Test]
    public function buildIssueBodyIncludesReportId(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody(['signals' => []]);

        $this->assertStringContainsString('Report ID:', $body);
    }

    #[Test]
    public function buildIssueBodyHandlesEmptyEvents(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody([
            'signals' => [],
            'recentEvents' => [],
        ]);

        $this->assertStringContainsString('_No recent events available._', $body);
    }

    #[Test]
    public function buildIssueBodyHandlesNoFailedEvents(): void
    {
        $reporter = new IssueReporter();
        $body = $reporter->buildIssueBody([
            'signals' => [],
            'recentEvents' => [
                ['intent' => 'fix bug', 'outcome' => ['status' => 'success']],
            ],
        ]);

        $this->assertStringContainsString('_No failed events in recent history._', $body);
    }

    #[Test]
    public function getGithubTokenReturnsEmptyWhenNotSet(): void
    {
        putenv('GITHUB_TOKEN=');
        putenv('GH_TOKEN=');
        putenv('GITHUB_PAT=');
        $reporter = new IssueReporter();
        $this->assertEquals('', $reporter->getGithubToken());
    }

    #[Test]
    public function getGithubTokenReturnsFirstAvailable(): void
    {
        putenv('GITHUB_TOKEN=token1');
        putenv('GH_TOKEN=token2');
        putenv('GITHUB_PAT=token3');
        $reporter = new IssueReporter();
        $this->assertEquals('token1', $reporter->getGithubToken());
    }

    #[Test]
    public function getGithubTokenFallsBackToGhToken(): void
    {
        putenv('GITHUB_TOKEN=');
        putenv('GH_TOKEN=token2');
        putenv('GITHUB_PAT=token3');
        $reporter = new IssueReporter();
        $this->assertEquals('token2', $reporter->getGithubToken());
    }

    #[Test]
    public function getStatePathReturnsCorrectPath(): void
    {
        $path = IssueReporter::getStatePath();
        $this->assertStringEndsWith('issue_reporter_state.json', $path);
        $this->assertStringContainsString(Paths::getEvolutionDir(), $path);
    }

    #[Test]
    public function maybeReportIssueReturnsErrorWhenDisabled(): void
    {
        putenv('EVOLVER_AUTO_ISSUE=false');
        $reporter = new IssueReporter();
        $result = $reporter->maybeReportIssue([]);

        $this->assertFalse($result['reported']);
        $this->assertEquals('disabled', $result['error']);
    }

    #[Test]
    public function maybeReportIssueReturnsErrorWhenConditionsNotMet(): void
    {
        $reporter = new IssueReporter();
        $result = $reporter->maybeReportIssue(['signals' => ['some_signal']]);

        $this->assertFalse($result['reported']);
        $this->assertEquals('conditions_not_met', $result['error']);
    }

    #[Test]
    public function maybeReportIssueReturnsErrorWhenNoToken(): void
    {
        putenv('GITHUB_TOKEN=');
        putenv('GH_TOKEN=');
        putenv('GITHUB_PAT=');
        $reporter = new IssueReporter();
        $result = $reporter->maybeReportIssue(['signals' => ['failure_loop_detected']]);

        $this->assertFalse($result['reported']);
        $this->assertEquals('no_github_token', $result['error']);
    }
}
