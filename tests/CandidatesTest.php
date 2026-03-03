<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Candidates;
use PHPUnit\Framework\TestCase;

/**
 * Candidates tests.
 */
final class CandidatesTest extends TestCase
{
    public function testExtractCapabilityCandidates(): void
    {
        $params = [
            'transcript' => <<<TEXT
User: Fix the login error
Assistant: I'll analyze the error and fix it.
[Fixed the authentication bug in login.php]
User: Thanks!
TEXT,
            'recent_events' => [],
        ];

        $candidates = Candidates::extractCapabilityCandidates($params);

        $this->assertIsArray($candidates);
    }

    public function testExtractFromEmptyTranscript(): void
    {
        $params = [
            'transcript' => '',
            'recent_events' => [],
        ];

        $candidates = Candidates::extractCapabilityCandidates($params);

        $this->assertIsArray($candidates);
    }

    public function testRenderCandidatesPreview(): void
    {
        $candidates = [
            [
                'id' => 'cap_auth_fix',
                'title' => 'Fix authentication bug',
                'shape' => [
                    'input' => 'error signals',
                    'output' => 'fixed code',
                    'invariants' => 'no side effects',
                    'params' => 'file paths',
                    'failure_points' => 'edge cases',
                    'evidence' => 'Fixed login.php authentication issue',
                ],
            ],
        ];

        $preview = Candidates::renderCandidatesPreview($candidates);

        $this->assertIsString($preview);
        $this->assertStringContainsString('cap_auth_fix', $preview);
    }

    public function testRenderEmptyCandidatesPreview(): void
    {
        $preview = Candidates::renderCandidatesPreview([]);

        $this->assertIsString($preview);
    }
}
