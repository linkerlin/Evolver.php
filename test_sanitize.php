<?php
require_once 'vendor/autoload.php';
use Evolver\Sanitize;

// Test 1: testRedactMultiplePatternsInOneString
$input = 'User: admin@example.com with token: Bearer secret123 and key sk-proj-test123';
$result = Sanitize::redactString($input);
echo "Test 1 - Redact multiple patterns:\n";
echo "Input: $input\n";
echo "Output: $result\n";
echo "Contains [REDACTED]: " . (str_contains($result, '[REDACTED]') ? 'YES' : 'NO') . "\n";
echo "Contains admin@example.com: " . (str_contains($result, 'admin@example.com') ? 'YES' : 'NO') . "\n";
echo "Contains secret123: " . (str_contains($result, 'secret123') ? 'YES' : 'NO') . "\n";
echo "Contains sk-proj-test123: " . (str_contains($result, 'sk-proj-test123') ? 'YES' : 'NO') . "\n\n";

// Test 2: testSanitizePayloadReturnsCopyNotReference
$input = ['data' => 'test'];
$result = Sanitize::sanitizePayload($input);
echo "Test 2 - Copy not reference:\n";
echo "Input === Result (value compare): " . ($input === $result ? 'YES' : 'NO') . "\n";
// Check if they are the same reference
$input['data'] = 'modified';
echo "After modifying input, result is: " . $result['data'] . "\n";
echo "Original unchanged: " . ($input['data'] === 'modified' ? 'YES' : 'NO') . "\n";
echo "Result unchanged: " . ($result['data'] === 'test' ? 'YES' : 'NO') . "\n\n";

// Test 3: testSanitizePayloadCleansNestedStrings
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
echo "Test 3 - Cleans nested strings:\n";
echo "user contains [REDACTED]: " . (str_contains($result['user'], '[REDACTED]') ? 'YES' : 'NO') . "\n";
echo "nested.token contains [REDACTED]: " . (str_contains($result['nested']['token'], '[REDACTED]') ? 'YES' : 'NO') . "\n";
echo "nested.deep.key contains [REDACTED]: " . (str_contains($result['nested']['deep']['key'], '[REDACTED]') ? 'YES' : 'NO') . "\n";
