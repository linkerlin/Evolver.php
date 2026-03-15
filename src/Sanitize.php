<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Pre-publish payload sanitization.
 * Removes sensitive tokens, local paths, emails, and env references
 * from capsule payloads before broadcasting to the hub.
 * PHP port of sanitize.js from EvoMap/evolver.
 */
final class Sanitize
{
    private const REDACTED = '[REDACTED]';

    /** @var string[] Patterns to redact (replaced with placeholder) */
    private const REDACT_PATTERNS = [
        // API keys & tokens (generic)
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+/',
        '/sk-[A-Za-z0-9]{20,}/',
        '/token[=:]\s*["\']?[A-Za-z0-9\-._~+\/]{16,}["\']?/i',
        '/api[_-]?key[=:]\s*["\']?[A-Za-z0-9\-._~+\/]{16,}["\']?/i',
        '/secret[=:]\s*["\']?[A-Za-z0-9\-._~+\/]{16,}["\']?/i',
        '/password[=:]\s*["\']?[^\s"\',;)}\]]{6,}["\']?/i',
        // GitHub tokens (ghp_, gho_, ghu_, ghs_, github_pat_)
        '/ghp_[A-Za-z0-9]{36,}/',
        '/gho_[A-Za-z0-9]{36,}/',
        '/ghu_[A-Za-z0-9]{36,}/',
        '/ghs_[A-Za-z0-9]{36,}/',
        '/github_pat_[A-Za-z0-9_]{22,}/',
        // AWS access keys
        '/AKIA[0-9A-Z]{16}/',
        // OpenAI / Anthropic tokens
        '/sk-proj-[A-Za-z0-9\-_]+/',
        '/sk-ant-[A-Za-z0-9\-_]+/',
        // npm tokens
        '/npm_[A-Za-z0-9]{36,}/',
        // Private keys
        '/-----BEGIN\s+(?:RSA\s+|EC\s+|DSA\s+|OPENSSH\s+)?PRIVATE\s+KEY-----[\s\S]*?-----END\s+(?:RSA\s+|EC\s+|DSA\s+|OPENSSH\s+)?PRIVATE\s+KEY-----/',
        // Basic auth in URLs
        '/(?<=:\/\/)[^@\s]+:[^@\s]+(?=@)/',
        // Local filesystem paths
        '/\/home\/[^\s"\',;)}\]]+/',
        '/\/Users\/[^\s"\',;)}\]]+/',
        '/[A-Z]:\\\\[^\s"\',;)}\]]+/',
        // Email addresses
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        // .env file references
        '/\.env(?:\.[a-zA-Z]+)?/',
    ];

    /**
     * Redact sensitive patterns from a string.
     */
    public static function redactString(string $str): string
    {
        $result = $str;
        foreach (self::REDACT_PATTERNS as $pattern) {
            $result = preg_replace($pattern, self::REDACTED, $result);
        }
        return $result;
    }

    /**
     * Deep-clone and sanitize a capsule payload.
     * Returns a new object with sensitive values redacted.
     * Does NOT modify the original.
     *
     * @param array<string, mixed>|null $capsule
     * @return array<string, mixed>|null
     */
    public static function sanitizePayload(?array $capsule): ?array
    {
        if ($capsule === null) {
            return null;
        }
        return self::sanitizeValue($capsule);
    }

    /**
     * Recursively sanitize a value.
     */
    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redactString($value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = self::sanitizeValue($v);
            }
            return $result;
        }
        return $value;
    }
}
