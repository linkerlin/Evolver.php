<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SessionScope;
use PHPUnit\Framework\TestCase;

class SessionScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        // Reset environment and cache
        putenv('EVOLVER_SESSION_SCOPE');
        SessionScope::reset();
    }

    // --- get() tests ---

    public function testGetReturnsNullWhenNotSet(): void
    {
        putenv('EVOLVER_SESSION_SCOPE');
        SessionScope::reset();

        $this->assertNull(SessionScope::get());
    }

    public function testGetReturnsNullWhenEmpty(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=');
        SessionScope::reset();

        $this->assertNull(SessionScope::get());
    }

    public function testGetReturnsSanitizedValue(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=test-scope.123');
        SessionScope::reset();

        $this->assertSame('test-scope.123', SessionScope::get());
    }

    public function testGetSanitizesInvalidCharacters(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=test/scope@name!');
        SessionScope::reset();

        $this->assertSame('test_scope_name_', SessionScope::get());
    }

    public function testGetRejectsPathTraversal(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=../etc/passwd');
        SessionScope::reset();

        $this->assertNull(SessionScope::get());
    }

    public function testGetRejectsSingleDot(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=.');
        SessionScope::reset();

        $this->assertNull(SessionScope::get());
    }

    public function testGetRejectsDoubleDot(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=..');
        SessionScope::reset();

        $this->assertNull(SessionScope::get());
    }

    public function testGetRejectsEmbeddedPathTraversal(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=test/../escape');
        SessionScope::reset();

        $this->assertNull(SessionScope::get());
    }

    public function testGetTruncatesToMaxLength(): void
    {
        $longString = str_repeat('a', 200);
        putenv("EVOLVER_SESSION_SCOPE={$longString}");
        SessionScope::reset();

        $result = SessionScope::get();
        $this->assertNotNull($result);
        $this->assertSame(128, strlen($result));
    }

    public function testGetCachesResult(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=first-value');
        SessionScope::reset();

        $this->assertSame('first-value', SessionScope::get());

        // Change env after first call - should still return cached value
        putenv('EVOLVER_SESSION_SCOPE=second-value');

        $this->assertSame('first-value', SessionScope::get());
    }

    // --- sanitize() tests ---

    public function testSanitizeReturnsNullForEmptyString(): void
    {
        $this->assertNull(SessionScope::sanitize(''));
    }

    public function testSanitizePreservesValidCharacters(): void
    {
        $this->assertSame('abc123-_.', SessionScope::sanitize('abc123-_.'));
    }

    public function testSanitizeReplacesInvalidCharacters(): void
    {
        $this->assertSame('test_scope', SessionScope::sanitize('test/scope'));
        $this->assertSame('name_123', SessionScope::sanitize('name@123'));
        $this->assertSame('space_test', SessionScope::sanitize('space test'));
    }

    public function testSanitizeHandlesUnicode(): void
    {
        // Chinese characters are 3 bytes each in UTF-8, each byte gets replaced
        $result = SessionScope::sanitize('测试');
        $this->assertSame(6, strlen($result)); // 2 chars * 3 bytes = 6 underscores
        $this->assertSame('______', $result);
    }

    public function testSanitizeTruncatesLongInput(): void
    {
        $longString = str_repeat('a', 200);
        $result = SessionScope::sanitize($longString);

        $this->assertSame(128, strlen($result));
        $this->assertSame(str_repeat('a', 128), $result);
    }

    public function testSanitizeRejectsPathTraversalPatterns(): void
    {
        $this->assertNull(SessionScope::sanitize('.'));
        $this->assertNull(SessionScope::sanitize('..'));
        $this->assertNull(SessionScope::sanitize('../test'));
        $this->assertNull(SessionScope::sanitize('test/../other'));
        $this->assertNull(SessionScope::sanitize('..hidden'));
    }

    // --- isActive() tests ---

    public function testIsActiveReturnsFalseWhenNotSet(): void
    {
        putenv('EVOLVER_SESSION_SCOPE');
        SessionScope::reset();

        $this->assertFalse(SessionScope::isActive());
    }

    public function testIsActiveReturnsTrueWhenSet(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=active-scope');
        SessionScope::reset();

        $this->assertTrue(SessionScope::isActive());
    }

    public function testIsActiveReturnsFalseForInvalidScope(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=..');
        SessionScope::reset();

        $this->assertFalse(SessionScope::isActive());
    }

    // --- applyToPath() tests ---

    public function testApplyToPathReturnsBaseWhenNoScope(): void
    {
        putenv('EVOLVER_SESSION_SCOPE');
        SessionScope::reset();

        $this->assertSame('/base/path', SessionScope::applyToPath('/base/path'));
    }

    public function testApplyToPathAppendsScopeSubdirectory(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=my-project');
        SessionScope::reset();

        $expected = '/base/path' . DIRECTORY_SEPARATOR . 'scopes' . DIRECTORY_SEPARATOR . 'my-project';
        $this->assertSame($expected, SessionScope::applyToPath('/base/path'));
    }

    public function testApplyToPathHandlesWindowsPaths(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=test');
        SessionScope::reset();

        $expected = 'C:\evolution' . DIRECTORY_SEPARATOR . 'scopes' . DIRECTORY_SEPARATOR . 'test';
        $this->assertSame($expected, SessionScope::applyToPath('C:\evolution'));
    }

    // --- getScopesDir() tests ---

    public function testGetScopesDirReturnsCorrectPath(): void
    {
        $expected = '/base/path' . DIRECTORY_SEPARATOR . 'scopes';
        $this->assertSame($expected, SessionScope::getScopesDir('/base/path'));
    }

    // --- reset() tests ---

    public function testResetClearsCache(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=first');
        SessionScope::reset();

        $this->assertSame('first', SessionScope::get());

        putenv('EVOLVER_SESSION_SCOPE=second');
        SessionScope::reset();

        $this->assertSame('second', SessionScope::get());
    }

    // --- setForTest() tests ---

    public function testSetForTestOverridesEnv(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=env-value');
        SessionScope::reset();

        SessionScope::setForTest('test-override');

        $this->assertSame('test-override', SessionScope::get());
    }

    public function testSetForTestAcceptsNull(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=env-value');
        SessionScope::reset();

        SessionScope::setForTest(null);

        $this->assertNull(SessionScope::get());
        $this->assertFalse(SessionScope::isActive());
    }

    // --- Integration tests ---

    public function testIntegrationWithDiscordChannelId(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=channel-123456789');
        SessionScope::reset();

        $this->assertSame('channel-123456789', SessionScope::get());
        $this->assertTrue(SessionScope::isActive());

        $path = SessionScope::applyToPath('/evolution');
        $this->assertStringContainsString('scopes', $path);
        $this->assertStringContainsString('channel-123456789', $path);
    }

    public function testIntegrationWithProjectName(): void
    {
        putenv('EVOLVER_SESSION_SCOPE=my-awesome-project');
        SessionScope::reset();

        $this->assertSame('my-awesome-project', SessionScope::get());

        $path = SessionScope::applyToPath('/assets/gep');
        $this->assertStringContainsString('my-awesome-project', $path);
    }

    public function testIntegrationBackwardCompatibility(): void
    {
        // When no scope is set, paths should remain unchanged
        putenv('EVOLVER_SESSION_SCOPE');
        SessionScope::reset();

        $this->assertNull(SessionScope::get());
        $this->assertFalse(SessionScope::isActive());
        $this->assertSame('/evolution', SessionScope::applyToPath('/evolution'));
    }
}
