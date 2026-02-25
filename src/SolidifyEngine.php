<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Solidify evolution results - validate and record evolution events.
 * PHP port of solidify.js from EvoMap/evolver.
 */
final class SolidifyEngine
{
    /** Validation command prefix whitelist (security) */
    private const ALLOWED_COMMAND_PREFIXES = ['php', 'composer', 'phpunit', 'phpcs', 'phpstan'];

    /** Forbidden shell operators */
    private const FORBIDDEN_SHELL_OPERATORS = [';', '&&', '||', '|', '>', '<', '`', '$(' ];

    /** Max validation timeout in seconds */
    private const VALIDATION_TIMEOUT = 60;

    /** Max files per evolution (hard limit) */
    private const MAX_FILES_HARD_LIMIT = 60;

    /** Max lines per evolution (hard limit) */
    private const MAX_LINES_HARD_LIMIT = 20000;

    public function __construct(
        private readonly GepAssetStore $store,
        private readonly SignalExtractor $signalExtractor,
        private readonly GeneSelector $selector,
    ) {}

    /**
     * Solidify an evolution result.
     *
     * @param array{
     *   intent: string,
     *   summary: string,
     *   signals?: array,
     *   gene?: array,
     *   capsule?: array,
     *   event?: array,
     *   mutation?: array,
     *   personalityState?: array,
     *   blastRadius?: array,
     *   dryRun?: bool,
     *   context?: string
     * } $input
     */
    public function solidify(array $input): array
    {
        $intent = $input['intent'] ?? 'repair';
        $summary = $input['summary'] ?? '(no summary)';
        $signals = $input['signals'] ?? [];
        $gene = $input['gene'] ?? null;
        $capsule = $input['capsule'] ?? null;
        $event = $input['event'] ?? null;
        $mutation = $input['mutation'] ?? null;
        $personalityState = $input['personalityState'] ?? null;
        $blastRadius = $input['blastRadius'] ?? ['files' => 0, 'lines' => 0];
        $dryRun = (bool)($input['dryRun'] ?? false);
        $context = $input['context'] ?? '';

        $nowIso = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $timestamp = time();
        $randomSuffix = bin2hex(random_bytes(4));

        $violations = [];
        $warnings = [];

        // Check blast radius hard limits
        $filesCount = (int)($blastRadius['files'] ?? 0);
        $linesCount = (int)($blastRadius['lines'] ?? 0);
        if ($filesCount > self::MAX_FILES_HARD_LIMIT) {
            $violations[] = "blast_radius.files ({$filesCount}) exceeds hard limit (" . self::MAX_FILES_HARD_LIMIT . ")";
        }
        if ($linesCount > self::MAX_LINES_HARD_LIMIT) {
            $violations[] = "blast_radius.lines ({$linesCount}) exceeds hard limit (" . self::MAX_LINES_HARD_LIMIT . ")";
        }

        // Validate gene constraints
        if ($gene !== null) {
            $constraints = $gene['constraints'] ?? [];
            $maxFiles = (int)($constraints['max_files'] ?? 25);
            if ($filesCount > $maxFiles) {
                $violations[] = "blast_radius.files ({$filesCount}) exceeds gene.constraints.max_files ({$maxFiles})";
            }
        }

        if (!empty($violations)) {
            return [
                'ok' => false,
                'violations' => $violations,
                'warnings' => $warnings,
                'dryRun' => $dryRun,
            ];
        }

        // Validate gene validation commands
        $validationResults = [];
        if ($gene !== null && !empty($gene['validation']) && !$dryRun) {
            foreach ($gene['validation'] as $cmd) {
                $validationResult = $this->runValidationCommand((string)$cmd);
                $validationResults[] = $validationResult;
                if (!$validationResult['ok']) {
                    $warnings[] = "Validation failed: {$cmd} - " . $validationResult['err'];
                }
            }
        }

        // Build event ID
        $parentEventId = $this->store->getLastEventId();
        $eventId = "evt_{$timestamp}_{$randomSuffix}";
        $mutationId = $mutation['id'] ?? "mut_{$timestamp}_{$randomSuffix}";
        $geneId = $gene['id'] ?? null;
        $capsuleId = "capsule_{$timestamp}_{$randomSuffix}";

        // Capture environment fingerprint for the event record
        $envFingerprint = EnvFingerprint::capture();

        // Build evolution event
        $evolutionEvent = array_merge($event ?? [], [
            'type' => 'EvolutionEvent',
            'schema_version' => '1.5.0',
            'id' => $eventId,
            'parent' => $parentEventId,
            'intent' => $intent,
            'signals' => $signals,
            'genes_used' => $geneId ? [$geneId] : [],
            'mutation_id' => $mutationId,
            'personality_state' => $personalityState ?? ['rigor' => 0.8, 'creativity' => 0.3, 'verbosity' => 0.5, 'risk_tolerance' => 0.2, 'obedience' => 0.9],
            'blast_radius' => $blastRadius,
            'outcome' => [
                'status' => empty($warnings) ? 'success' : 'partial',
                'score' => empty($warnings) ? 0.8 : 0.5,
            ],
            'env_fingerprint' => $envFingerprint,
            'created_at' => $nowIso,
            'summary' => $summary,
        ]);

        // Build gene update
        $geneToStore = null;
        if ($gene !== null) {
            $geneToStore = array_merge($gene, [
                'type' => 'Gene',
                'schema_version' => '1.5.0',
                'updated_at' => $nowIso,
            ]);
        }

        // Build capsule (on success)
        $capsuleToStore = null;
        if ($capsule !== null || (empty($warnings) && $intent !== 'repair')) {
            $capsuleToStore = array_merge($capsule ?? [], [
                'type' => 'Capsule',
                'schema_version' => '1.5.0',
                'id' => $capsule['id'] ?? $capsuleId,
                'trigger' => $signals,
                'gene' => $geneId,
                'summary' => $summary,
                'confidence' => empty($warnings) ? 0.8 : 0.5,
                'blast_radius' => $blastRadius,
                'created_at' => $nowIso,
            ]);
        }

        if (!$dryRun) {
            // Store event
            $this->store->appendEvent($evolutionEvent);

            // Update gene
            if ($geneToStore !== null) {
                $this->store->upsertGene($geneToStore);
            }

            // Store capsule
            if ($capsuleToStore !== null) {
                $this->store->appendCapsule($capsuleToStore);
            }
        }

        return [
            'ok' => true,
            'eventId' => $eventId,
            'geneId' => $geneId,
            'capsuleId' => $capsuleToStore ? $capsuleToStore['id'] : null,
            'event' => $evolutionEvent,
            'gene' => $geneToStore,
            'capsule' => $capsuleToStore,
            'violations' => $violations,
            'warnings' => $warnings,
            'validationResults' => $validationResults,
            'dryRun' => $dryRun,
        ];
    }

    /**
     * Record a failed evolution attempt.
     */
    public function recordFailure(array $input): void
    {
        $gene = $input['gene'] ?? null;
        $signals = $input['signals'] ?? [];
        $failureReason = $input['failureReason'] ?? 'unknown';
        $diffSnapshot = $input['diffSnapshot'] ?? null;

        $timestamp = time();
        $randomSuffix = bin2hex(random_bytes(4));

        $failedCapsule = [
            'id' => "failed_{$timestamp}_{$randomSuffix}",
            'gene' => $gene['id'] ?? null,
            'trigger' => $signals,
            'failure_reason' => $failureReason,
            'diff_snapshot' => $diffSnapshot,
        ];

        $this->store->appendFailedCapsule($failedCapsule);
    }

    /**
     * Check if a validation command is safe to run.
     */
    public function isValidationCommandAllowed(string $cmd): bool
    {
        $cmd = trim($cmd);

        // Check prefix whitelist
        $allowed = false;
        foreach (self::ALLOWED_COMMAND_PREFIXES as $prefix) {
            if (str_starts_with($cmd, $prefix . ' ') || $cmd === $prefix) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return false;
        }

        // No backtick substitution
        if (str_contains($cmd, '`')) {
            return false;
        }

        // No $() substitution
        if (str_contains($cmd, '$(')) {
            return false;
        }

        // Strip quoted content and check for shell operators
        $stripped = preg_replace('/"[^"]*"|\'[^\']*\'/', '', $cmd);
        foreach (self::FORBIDDEN_SHELL_OPERATORS as $op) {
            if (str_contains($stripped, $op)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run a validation command safely.
     */
    private function runValidationCommand(string $cmd): array
    {
        if (!$this->isValidationCommandAllowed($cmd)) {
            return ['ok' => false, 'out' => '', 'err' => 'Command not allowed by safety policy'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, getcwd() ?: '/tmp');
        if (!is_resource($process)) {
            return ['ok' => false, 'out' => '', 'err' => 'Failed to start process'];
        }

        fclose($pipes[0]);

        // Set non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + self::VALIDATION_TIMEOUT;

        while (time() < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }
            $chunk = fread($pipes[1], 4096);
            if ($chunk !== false) $stdout .= $chunk;
            $chunk = fread($pipes[2], 4096);
            if ($chunk !== false) $stderr .= $chunk;
            usleep(100000); // 100ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'ok' => $exitCode === 0,
            'out' => $stdout,
            'err' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Parse GEP objects from LLM output text.
     * Extracts JSON objects from raw text output.
     */
    public function parseGepObjects(string $text): array
    {
        $objects = [];
        $depth = 0;
        $start = null;

        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $fragment = substr($text, $start, $i - $start + 1);
                    $parsed = json_decode($fragment, true);
                    if (is_array($parsed) && isset($parsed['type'])) {
                        $objects[] = $parsed;
                    }
                    $start = null;
                }
            }
        }

        return $objects;
    }
}
