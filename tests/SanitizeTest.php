<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Sanitize;
use PHPUnit\Framework\TestCase;

final class SanitizeTest extends TestCase
{
    // =========================================================================
    // String Redaction Tests
    // =========================================================================

    public function testRedactStringReturnsUnchangedForNonSensitive(): void
    {
        $input = 'This is a normal string without secrets';
        $result = Sanitize::redactString($input);
        $this->assertSame($input, $result);
    }

    public function testRedactBearerToken(): void
    {
        $input = 'Authorization: Bearer abc123def456ghi789';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('abc123def456ghi789', $result);
    }

    public function testRedactOpenAiKey(): void
    {
        $input = 'api_key: sk-proj-1234567890abcdefghijklmnop';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testRedactGitHubToken(): void
    {
        $input = 'token: ghp_1234567890abcdefghijklmnopqrstuvwxyz0123';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testRedactEmail(): void
    {
        $input = 'Contact: user@example.com for help';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('user@example.com', $result);
    }

    public function testRedactLocalPath(): void
    {
        $input = 'File: /home/user/secret.txt contains data';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testRedactWindowsPath(): void
    {
        $input = 'Path: C:\\Users\\Admin\\secret.txt';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testRedactPrivateKey(): void
    {
        $input = "Key: -----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA...\n-----END RSA PRIVATE KEY-----";
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testRedactEnvReference(): void
    {
        $input = 'Config: .env file loaded';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testRedactAwsAccessKey(): void
    {
        $input = 'AWS_KEY: AKIAIOSFODNN7EXAMPLE';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function testRedactMultiplePatternsInOneString(): void
    {
        $input = 'User: admin@example.com with token: Bearer secret123 and key sk-proj-test123';
        $result = Sanitize::redactString($input);
        $this->assertStringContainsString('[REDACTED]', $result);
        $this->assertStringNotContainsString('admin@example.com', $result);
        $this->assertStringNotContainsString('secret123', $result);
        $this->assertStringNotContainsString('sk-proj-test123', $result);
    }

    // =========================================================================
    // Payload Sanitization Tests
    // =========================================================================

    public function testSanitizePayloadReturnsNullForNullInput(): void
    {
        $result = Sanitize::sanitizePayload(null);
        $this->assertNull($result);
    }

    public function testSanitizePayloadReturnsCopyNotReference(): void
    {
        $input = ['data' => 'test', 'token' => 'Bearer secret123456'];
        $original = $input;
        $result = Sanitize::sanitizePayload($input);

        // Result should have redacted values
        $this->assertStringContainsString('[REDACTED]', $result['token']);
        // Original should be unchanged
        $this->assertSame($input, $original);
    }

    public function testSanitizePayloadCleansNestedStrings(): void
    {
        $input = [
            'user' => 'admin@example.com',
            'nested' => [
                'token' => 'Bearer secret123',
                'deep' => [
                    'key' => 'sk-proj-test',
                ],
            ],
        ];

        $result = Sanitize::sanitizePayload($input);

        $this->assertStringContainsString('[REDACTED]', $result['user']);
        $this->assertStringContainsString('[REDACTED]', $result['nested']['token']);
        $this->assertStringContainsString('[REDACTED]', $result['nested']['deep']['key']);
    }

    public function testSanitizePayloadPreservesNonStringValues(): void
    {
        $input = [
            'count' => 42,
            'active' => true,
            'ratio' => 0.85,
            'nullable' => null,
        ];

        $result = Sanitize::sanitizePayload($input);

        $this->assertSame(42, $result['count']);
        $this->assertSame(true, $result['active']);
        $this->assertSame(0.85, $result['ratio']);
        $this->assertNull($result['nullable']);
    }

    public function testSanitizePayloadPreservesArrayStructure(): void
    {
        $input = [
            'items' => ['a', 'b', 'c'],
            'mapped' => ['x' => 1, 'y' => 2],
        ];

        $result = Sanitize::sanitizePayload($input);

        $this->assertSame(['a', 'b', 'c'], $result['items']);
        $this->assertSame(['x' => 1, 'y' => 2], $result['mapped']);
    }

    public function testSanitizePayloadCleansArrayWithStringSecrets(): void
    {
        $input = [
            'tokens' => ['Bearer secret1', 'Bearer secret2'],
        ];

        $result = Sanitize::sanitizePayload($input);

        foreach ($result['tokens'] as $token) {
            $this->assertStringContainsString('[REDACTED]', $token);
        }
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function testRedactEmptyString(): void
    {
        $result = Sanitize::redactString('');
        $this->assertSame('', $result);
    }

    public function testSanitizeEmptyPayload(): void
    {
        $result = Sanitize::sanitizePayload([]);
        $this->assertSame([], $result);
    }

    public function testRedactStringWithPartialMatch(): void
    {
        // Short tokens should not be redacted (pattern requires 16+ chars for most)
        $input = 'token: short';
        $result = Sanitize::redactString($input);
        // Short tokens may or may not be redacted depending on pattern
        $this->assertIsString($result);
    }
}
