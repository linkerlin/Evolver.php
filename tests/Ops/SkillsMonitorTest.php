<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\SkillsMonitor;
use PHPUnit\Framework\TestCase;

/**
 * SkillsMonitor tests.
 */
final class SkillsMonitorTest extends TestCase
{
    public function testCheckSkillReturnsNullForNonexistentSkill(): void
    {
        // checkSkill returns null if skill directory doesn't exist
        $result = SkillsMonitor::checkSkill('nonexistent_skill_xyz');

        $this->assertNull($result);
    }

    public function testCheckUnknownSkillReturnsNull(): void
    {
        $result = SkillsMonitor::checkSkill('another_nonexistent_skill');

        $this->assertNull($result);
    }

    public function testAutoHealReturnsEmptyArrayForEmptyIssues(): void
    {
        // autoHeal expects issues as array of strings, returns healed issues
        $result = SkillsMonitor::autoHeal('test_skill', []);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testAutoHealHandlesMissingVendorIssue(): void
    {
        // Test with proper issue format (string array)
        $result = SkillsMonitor::autoHeal('test_skill', ['Missing vendor directory']);

        // Returns empty since skill doesn't exist to heal
        $this->assertIsArray($result);
    }
}
