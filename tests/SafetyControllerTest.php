<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SafetyController;
use PHPUnit\Framework\TestCase;

/**
 * Safety Controller tests - extracted from EvolverTest.php
 */
final class SafetyControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up environment
        putenv('EVOLVE_ALLOW_SELF_MODIFY');
    }

    public function testSafetyModeNever(): void
    {
        $controller = new SafetyController('never');

        $this->assertFalse($controller->isSelfModifyAllowed());
        $this->assertFalse($controller->isReviewRequired());
        $this->assertEquals('never', $controller->getMode());
    }

    public function testSafetyModeAlways(): void
    {
        $controller = new SafetyController('always');

        $this->assertTrue($controller->isSelfModifyAllowed());
        $this->assertFalse($controller->isReviewRequired());
        $this->assertEquals('always', $controller->getMode());
    }

    public function testSafetyModeReview(): void
    {
        $controller = new SafetyController('review');

        $this->assertTrue($controller->isSelfModifyAllowed());
        $this->assertTrue($controller->isReviewRequired());
        $this->assertEquals('review', $controller->getMode());
    }

    public function testIsOperationAllowed(): void
    {
        $controller = new SafetyController('never');

        // Read and diagnose always allowed
        $this->assertTrue($controller->isOperationAllowed('read'));
        $this->assertTrue($controller->isOperationAllowed('diagnose'));

        // Propose and modify not allowed in never mode
        $this->assertFalse($controller->isOperationAllowed('propose'));
        $this->assertFalse($controller->isOperationAllowed('modify'));
    }

    public function testIsOperationAllowedInAlwaysMode(): void
    {
        $controller = new SafetyController('always');

        $this->assertTrue($controller->isOperationAllowed('read'));
        $this->assertTrue($controller->isOperationAllowed('diagnose'));
        $this->assertTrue($controller->isOperationAllowed('propose'));
        $this->assertTrue($controller->isOperationAllowed('modify'));
    }

    public function testValidateModificationBlockedInNeverMode(): void
    {
        $controller = new SafetyController('never');

        $result = $controller->validateModification(['files' => ['test.php']]);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('disabled', $result['reason']);
    }

    public function testValidateModificationChecksBlastRadius(): void
    {
        $controller = new SafetyController('always');

        $result = $controller->validateModification([
            'files' => ['test.php'],
            'lines' => 25000, // Exceeds limit
        ]);

        $this->assertFalse($result['allowed']);
        $this->assertArrayHasKey('violations', $result);
    }

    public function testValidateModificationAllowsSafe(): void
    {
        $controller = new SafetyController('always');

        $result = $controller->validateModification([
            'files' => ['user_file.php'],
            'lines' => 100,
        ]);

        $this->assertTrue($result['allowed']);
    }

    public function testAssertModificationAllowedThrows(): void
    {
        $controller = new SafetyController('never');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('blocked');

        $controller->assertModificationAllowed(['files' => ['test.php']]);
    }

    public function testAssertModificationAllowedPasses(): void
    {
        $controller = new SafetyController('always');

        // Should not throw for safe modifications
        $controller->assertModificationAllowed(['files' => ['user_file.php']]);
        $this->assertTrue(true);
    }

    public function testCreateReviewRequest(): void
    {
        $controller = new SafetyController('review');

        $request = $controller->createReviewRequest([
            'files' => ['test.php'],
            'lines' => 50,
        ]);

        $this->assertArrayHasKey('type', $request);
        $this->assertArrayHasKey('timestamp', $request);
        $this->assertArrayHasKey('modification', $request);
        $this->assertTrue($request['requires_approval']);
    }

    public function testGetStatusReport(): void
    {
        $controller = new SafetyController('always');

        $status = $controller->getStatusReport();

        $this->assertArrayHasKey('mode', $status);
        $this->assertArrayHasKey('self_modify_allowed', $status);
        $this->assertArrayHasKey('review_required', $status);
        $this->assertArrayHasKey('source_protection', $status);
        $this->assertArrayHasKey('operations', $status);
        $this->assertTrue($status['self_modify_allowed']);
    }

    public function testFromEnvironment(): void
    {
        putenv('EVOLVE_ALLOW_SELF_MODIFY=review');

        $controller = SafetyController::fromEnvironment();

        $this->assertEquals('review', $controller->getMode());

        putenv('EVOLVE_ALLOW_SELF_MODIFY');
    }

    public function testDefaultModeIsAlways(): void
    {
        putenv('EVOLVE_ALLOW_SELF_MODIFY');

        $controller = new SafetyController();

        $this->assertEquals('always', $controller->getMode());
    }
}
