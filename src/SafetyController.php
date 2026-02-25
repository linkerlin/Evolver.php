<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Safety controller for Evolver self-modification.
 * Implements the EVOLVE_ALLOW_SELF_MODIFY environment variable control
 * as specified in the GEP protocol.
 * 
 * Modes:
 * - never: Completely disable self-modification, only diagnostics allowed
 * - review: All modifications require human confirmation
 * - always: Full automation (default)
 */
final class SafetyController
{
    public const MODE_NEVER = 'never';
    public const MODE_REVIEW = 'review';
    public const MODE_ALWAYS = 'always';

    private string $mode;
    private SourceProtector $sourceProtector;

    public function __construct(?string $mode = null, ?SourceProtector $sourceProtector = null)
    {
        $this->mode = $this->resolveMode($mode);
        $this->sourceProtector = $sourceProtector ?? new SourceProtector();
    }

    /**
     * Get the current safety mode.
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Check if self-modification is allowed at all.
     */
    public function isSelfModifyAllowed(): bool
    {
        return $this->mode !== self::MODE_NEVER;
    }

    /**
     * Check if review mode is required.
     */
    public function isReviewRequired(): bool
    {
        return $this->mode === self::MODE_REVIEW;
    }

    /**
     * Check if a specific operation is allowed.
     * 
     * @param string $operation 'read', 'diagnose', 'propose', 'modify'
     * @return bool
     */
    public function isOperationAllowed(string $operation): bool
    {
        return match ($operation) {
            'read', 'diagnose' => true, // Always allowed
            'propose' => $this->mode !== self::MODE_NEVER,
            'modify' => $this->mode === self::MODE_ALWAYS,
            default => false,
        };
    }

    /**
     * Validate a modification request.
     * Returns validation result with any violations.
     * 
     * @param array{
     *   files?: array<string>,
     *   lines?: int,
     *   gene?: array,
     *   mutation?: array,
     * } $modification
     * @return array{allowed: bool, reason?: string, violations?: array<string>}
     */
    public function validateModification(array $modification): array
    {
        // Check mode
        if ($this->mode === self::MODE_NEVER) {
            return [
                'allowed' => false,
                'reason' => 'Self-modification is disabled (EVOLVE_ALLOW_SELF_MODIFY=never)',
            ];
        }

        $violations = [];

        // Check source protection
        if (isset($modification['files']) && is_array($modification['files'])) {
            $protectionResult = $this->sourceProtector->validateFiles($modification['files']);
            if (!$protectionResult['ok']) {
                $violations[] = 'Protected files: ' . implode(', ', $protectionResult['violations']);
            }
        }

        // Check blast radius limits
        $lines = $modification['lines'] ?? 0;
        if ($lines > 20000) {
            $violations[] = 'Blast radius exceeds 20,000 lines limit';
        }

        // Check gene constraints
        if (isset($modification['gene']['constraints'])) {
            $constraints = $modification['gene']['constraints'];
            $maxFiles = $constraints['max_files'] ?? 25;
            $files = count($modification['files'] ?? []);
            if ($files > $maxFiles) {
                $violations[] = "File count ({$files}) exceeds gene constraint ({$maxFiles})";
            }
        }

        // Check mutation risk level
        if (isset($modification['mutation']['risk_level'])) {
            $riskLevel = $modification['mutation']['risk_level'];
            if ($riskLevel === 'high' && $this->mode !== self::MODE_ALWAYS) {
                $violations[] = 'High-risk mutations require always mode or manual review';
            }
        }

        if (!empty($violations)) {
            return [
                'allowed' => false,
                'reason' => 'Safety violations detected',
                'violations' => $violations,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Assert that modification is allowed (throws exception if not).
     * 
     * @throws \RuntimeException
     */
    public function assertModificationAllowed(array $modification): void
    {
        $result = $this->validateModification($modification);
        if (!$result['allowed']) {
            throw new \RuntimeException(
                'Modification blocked: ' . ($result['reason'] ?? 'Unknown reason')
            );
        }
    }

    /**
     * Create a review request for review mode.
     * 
     * @param array $modification
     * @return array Review request data
     */
    public function createReviewRequest(array $modification): array
    {
        return [
            'type' => 'modification_review_request',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'mode' => $this->mode,
            'modification' => $modification,
            'requires_approval' => true,
            'auto_approve_deadline' => null, // Could be set for delayed auto-approval
        ];
    }

    /**
     * Get safety status report.
     */
    public function getStatusReport(): array
    {
        return [
            'mode' => $this->mode,
            'self_modify_allowed' => $this->isSelfModifyAllowed(),
            'review_required' => $this->isReviewRequired(),
            'source_protection' => $this->sourceProtector->getProtectionReport(),
            'operations' => [
                'read' => $this->isOperationAllowed('read'),
                'diagnose' => $this->isOperationAllowed('diagnose'),
                'propose' => $this->isOperationAllowed('propose'),
                'modify' => $this->isOperationAllowed('modify'),
            ],
        ];
    }

    /**
     * Resolve mode from environment or parameter.
     */
    private function resolveMode(?string $mode): string
    {
        // Priority: parameter > environment variable > default
        if ($mode !== null && $this->isValidMode($mode)) {
            return $mode;
        }

        $envMode = getenv('EVOLVE_ALLOW_SELF_MODIFY');
        if ($envMode !== false && $this->isValidMode($envMode)) {
            return strtolower($envMode);
        }

        return self::MODE_ALWAYS; // Default
    }

    /**
     * Check if a mode string is valid.
     */
    private function isValidMode(string $mode): bool
    {
        $validModes = [self::MODE_NEVER, self::MODE_REVIEW, self::MODE_ALWAYS];
        return in_array(strtolower($mode), $validModes, true);
    }

    /**
     * Create controller from environment (factory method).
     */
    public static function fromEnvironment(?SourceProtector $sourceProtector = null): self
    {
        return new self(null, $sourceProtector);
    }
}
