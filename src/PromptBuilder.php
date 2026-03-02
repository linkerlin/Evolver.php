<?php

declare(strict_types=1);

namespace Evolver;

use Evolver\Ops\Innovation;

/**
 * Builds GEP protocol prompts.
 * PHP port of prompt.js from EvoMap/evolver.
 */
final class PromptBuilder
{
    private const SCHEMA_VERSION = '1.5.0';
    private const TRUNCATE_CONTEXT_MAX = 20000;

    /** Mandatory GEP output schema definition */
    private const SCHEMA_DEFINITIONS = <<<'SCHEMA'
━━━━━━━━━━━━━━━━━━━━━━
I. Mandatory Evolution Object Model (Output EXACTLY these 5 objects)
━━━━━━━━━━━━━━━━━━━━━━

Output separate JSON objects. DO NOT wrap in a single array.
DO NOT use markdown code blocks (like ```json ... ```).
Output RAW JSON ONLY. No prelude, no postscript.
Missing any object = PROTOCOL FAILURE.
ENSURE VALID JSON SYNTAX (escape quotes in strings).

0. Mutation (The Trigger) - MUST BE FIRST
   {
     "type": "Mutation",
     "id": "mut_<timestamp>",
     "category": "repair|optimize|innovate",
     "trigger_signals": ["<signal_string>"],
     "target": "<module_or_gene_id>",
     "expected_effect": "<outcome_description>",
     "risk_level": "low|medium|high",
     "rationale": "<why_this_change_is_necessary>"
   }

1. PersonalityState (The Mood)
   {
     "type": "PersonalityState",
     "rigor": 0.0-1.0,
     "creativity": 0.0-1.0,
     "verbosity": 0.0-1.0,
     "risk_tolerance": 0.0-1.0,
     "obedience": 0.0-1.0
   }

2. EvolutionEvent (The Record)
   {
     "type": "EvolutionEvent",
     "schema_version": "1.5.0",
     "id": "evt_<timestamp>",
     "parent": <parent_evt_id|null>,
     "intent": "repair|optimize|innovate",
     "signals": ["<signal_string>"],
     "genes_used": ["<gene_id>"],
     "mutation_id": "<mut_id>",
     "personality_state": { ... },
     "blast_radius": { "files": N, "lines": N },
     "outcome": { "status": "success|failed", "score": 0.0-1.0 }
   }

3. Gene (The Knowledge)
   - Reuse/update existing ID if possible. 创建new only if novel pattern.
   {
     "type": "Gene",
     "schema_version": "1.5.0",
     "id": "gene_<name>",
     "category": "repair|optimize|innovate",
     "signals_match": ["<pattern>"],
     "preconditions": ["<condition>"],
     "strategy": ["<step_1>", "<step_2>"],
     "constraints": { "max_files": N, "forbidden_paths": [] },
     "validation": ["<php_command>"]
   }

4. Capsule (The Result)
   - Only on success. Reference Gene used.
   {
     "type": "Capsule",
     "schema_version": "1.5.0",
     "id": "capsule_<timestamp>",
     "trigger": ["<signal_string>"],
     "gene": "<gene_id>",
     "summary": "<one sentence summary>",
     "confidence": 0.0-1.0,
     "blast_radius": { "files": N, "lines": N }
   }
SCHEMA;

    /**
     * 构建the main GEP protocol prompt.
     */
    public function buildGepPrompt(array $input): string
    {
        $nowIso = $input['nowIso'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $context = $input['context'] ?? '';
        $signals = $input['signals'] ?? [];
        $selector = $input['selector'] ?? [];
        $parentEventId = $input['parentEventId'] ?? null;
        $selectedGene = $input['selectedGene'] ?? null;
        $capsuleCandidates = $input['capsuleCandidates'] ?? [];
        $genesPreview = $input['genesPreview'] ?? '(none)';
        $capsulesPreview = $input['capsulesPreview'] ?? '(none)';
        $cycleId = $input['cycleId'] ?? null;
        $recentHistory = $input['recentHistory'] ?? [];
        $failedCapsules = $input['failedCapsules'] ?? [];
        $hubLessons = $input['hubLessons'] ?? [];
        $capabilityCandidatesPreview = $input['capabilityCandidatesPreview'] ?? '(none)';
        $externalCandidatesPreview = $input['externalCandidatesPreview'] ?? '(none)';
        $hubMatchedBlock = $input['hubMatchedBlock'] ?? null;

        $parentValue = $parentEventId ? '"' . $parentEventId . '"' : 'null';
        $selectedGeneId = $selectedGene['id'] ?? 'gene_<name>';
        $cycleLabel = $cycleId ? " Cycle #{$cycleId}" : '';

        // Strategy block
        if ($selectedGene && !empty($selectedGene['strategy'])) {
            $strategyLines = array_map(
                fn($s, $i) => ($i + 1) . '. ' . $s,
                $selectedGene['strategy'],
                array_keys($selectedGene['strategy'])
            );
            $strategyBlock = "ACTIVE STRATEGY ({$selectedGeneId}):\n" . implode("\n", $strategyLines) . "\nADHERE TO THIS STRATEGY STRICTLY.";
        } else {
            $strategyBlock = "ACTIVE STRATEGY (Generic):\n" .
                "1. Analyze signals and context.\n" .
                "2. Select or create a Gene that addresses the root cause.\n" .
                "3. Apply minimal, safe changes.\n" .
                "4. Validate changes strictly.\n" .
                "5. Solidify knowledge.";
        }

        // Truncate context
        $executionContext = $this->truncateContext($context, self::TRUNCATE_CONTEXT_MAX);

        // Schema with parent substituted
        $schemaSection = str_replace('<parent_evt_id|null>', $parentValue, self::SCHEMA_DEFINITIONS);

        // Anti-pattern zone
        $antiPatternZone = $this->buildAntiPatternZone($failedCapsules, $signals);

        // Innovation block (stagnation detection)
        $innovationBlock = $this->buildInnovationBlock($signals);

        // History block
        $historyBlock = $this->buildHistoryBlock($recentHistory);

        // Lessons block from hub
        $lessonsBlock = $this->buildLessonsBlock($hubLessons, $signals);

        // Capability candidates preview (truncate if too large)
        $capsPreview = $capabilityCandidatesPreview;
        $capsLimit = $selectedGene ? 500 : 2000;
        if (strlen($capsPreview) > $capsLimit) {
            $capsPreview = substr($capsPreview, 0, $capsLimit) . "\n...[TRUNCATED_CAPABILITIES]...";
        }

        // Hub matched block
        $hubMatched = $hubMatchedBlock ?: '(no hub match)';

        // External candidates preview
        $externalPreview = $externalCandidatesPreview ?: '(none)';

        // Optimize signals display
        $uniqueSignals = array_unique($signals);
        $optimizedSignals = array_slice($uniqueSignals, 0, 50);
        $optimizedSignals = array_map(function ($s) {
            if (is_string($s) && strlen($s) > 200) {
                return substr($s, 0, 200) . '...[TRUNCATED_SIGNAL]';
            }
            return $s;
        }, $optimizedSignals);
        if (count($uniqueSignals) > 50) {
            $optimizedSignals[] = '...[TRUNCATED ' . (count($uniqueSignals) - 50) . ' SIGNALS]...';
        }

        $selectorJson = json_encode($selector, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signalsJson = json_encode(array_values($optimizedSignals), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Capture environment fingerprint
        $envFingerprint = EnvFingerprint::capture();
        $envFingerprintJson = json_encode($envFingerprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Injection hint from environment
        $injectionHint = getenv('EVOLVE_HINT') ?: '(none)';

        // 构建the prompt
        return trim(<<<PROMPT
        GEP -- EVOLUTION PROTOCOL{$cycleLabel} [{$nowIso}]

        {$schemaSection}

        ━━━━━━━━━━━━━━━━━━━━━━
        II. Execution Context
        ━━━━━━━━━━━━━━━━━━━━━━

        Signals detected: {$signalsJson}

        Context [Env Fingerprint]:
        {$envFingerprintJson}
        {$innovationBlock}
        Context [Injection Hint]:
        {$injectionHint}

        Selector decision:
        {$selectorJson}

        {$strategyBlock}

        ━━━━━━━━━━━━━━━━━━━━━━
        III. Available Assets
        ━━━━━━━━━━━━━━━━━━━━━━

        Genes:
        {$genesPreview}

        Capsules:
        {$capsulesPreview}

        Context [Capability Candidates]:
        {$capsPreview}

        Context [Hub Matched Solution]:
        {$hubMatched}

        Context [External Candidates]:
        {$externalPreview}
        {$antiPatternZone}{$lessonsBlock}{$historyBlock}

        ━━━━━━━━━━━━━━━━━━━━━━
        IV. Execution Context (Truncated)
        ━━━━━━━━━━━━━━━━━━━━━━

        {$executionContext}

        ━━━━━━━━━━━━━━━━━━━━━━
        V. Instructions
        ━━━━━━━━━━━━━━━━━━━━━━

        NOW OUTPUT THE 5 OBJECTS IN ORDER: Mutation, PersonalityState, EvolutionEvent, Gene, Capsule.
        RAW JSON ONLY. No markdown. No commentary. No extra text.
        Parent event ID: {$parentValue}
        PROMPT);
    }

    /**
     * 构建a minimal reuse prompt for direct capsule reuse mode.
     */
    public function buildReusePrompt(array $input): string
    {
        $capsule = $input['capsule'] ?? [];
        $signals = $input['signals'] ?? [];
        $nowIso = $input['nowIso'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $payload = $capsule['payload'] ?? $capsule;
        $summary = $payload['summary'] ?? $capsule['summary'] ?? '(no summary)';
        $gene = $payload['gene'] ?? $capsule['gene'] ?? '(unknown)';
        $confidence = $payload['confidence'] ?? $capsule['confidence'] ?? 0;
        $assetId = $capsule['asset_id'] ?? $capsule['id'] ?? '(unknown)';
        $trigger = is_array($payload['trigger'] ?? $capsule['trigger'] ?? null)
            ? implode(', ', $payload['trigger'] ?? $capsule['trigger'] ?? [])
            : '';

        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signalsJson = json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return trim(<<<PROMPT
        GEP -- REUSE MODE (Search-First) [{$nowIso}]

        You are applying a VERIFIED solution from the local capsule store.
        Source asset: {$assetId}
        Confidence: {$confidence} | Gene: {$gene}
        Trigger signals: {$trigger}

        Summary: {$summary}

        Your signals: {$signalsJson}

        Instructions:
        1. Read the capsule details below.
        2. Apply the fix to the local codebase, adapting paths/names.
        3. 运行validation to confirm it works.
        4. If passed, solidify the result.
        5. If failed, ROLLBACK and report.

        Capsule payload:
        {$payloadJson}

        IMPORTANT: Do NOT reinvent. Apply faithfully.
        PROMPT);
    }

    /**
     * 格式化genes for preview in the prompt.
     */
    public function formatGenesPreview(array $genes, int $limit = 5): string
    {
        if (empty($genes)) {
            return '(no genes available)';
        }
        $preview = [];
        foreach (array_slice($genes, 0, $limit) as $gene) {
            $id = $gene['id'] ?? '?';
            $category = $gene['category'] ?? '?';
            $signals = implode(', ', array_slice($gene['signals_match'] ?? [], 0, 4));
            $preview[] = "- [{$category}] {$id} | signals: {$signals}";
        }
        if (count($genes) > $limit) {
            $preview[] = '... (' . (count($genes) - $limit) . ' more)';
        }
        return implode("\n", $preview);
    }

    /**
     * 格式化capsules for preview in the prompt.
     */
    public function formatCapsulesPreview(array $capsules, int $limit = 3): string
    {
        if (empty($capsules)) {
            return '(no capsules available)';
        }
        $preview = [];
        foreach (array_slice($capsules, 0, $limit) as $capsule) {
            $id = $capsule['id'] ?? '?';
            $gene = $capsule['gene'] ?? '?';
            $summary = $capsule['summary'] ?? '?';
            $confidence = $capsule['confidence'] ?? 0;
            $preview[] = "- {$id} | gene: {$gene} | confidence: {$confidence} | {$summary}";
        }
        if (count($capsules) > $limit) {
            $preview[] = '... (' . (count($capsules) - $limit) . ' more)';
        }
        return implode("\n", $preview);
    }

    /**
     * Truncate context intelligently to preserve structure.
     */
    private function truncateContext(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, 0, $maxLength) . "\n...[TRUNCATED_EXECUTION_CONTEXT]...";
    }

    /**
     * 构建anti-pattern zone block from failed capsules.
     */
    private function buildAntiPatternZone(array $failedCapsules, array $signals): string
    {
        if (empty($failedCapsules) || empty($signals)) {
            return '';
        }

        $sigSet = array_flip(array_map('strtolower', array_map('strval', $signals)));
        $matched = [];

        for ($i = count($failedCapsules) - 1; $i >= 0 && count($matched) < 3; $i--) {
            $fc = $failedCapsules[$i];
            if (!$fc) continue;
            $triggers = is_array($fc['trigger'] ?? null) ? $fc['trigger'] : [];
            $overlap = 0;
            foreach ($triggers as $t) {
                if (isset($sigSet[strtolower((string)$t)])) {
                    $overlap++;
                }
            }
            if (!empty($triggers) && ($overlap / count($triggers)) >= 0.4) {
                $matched[] = $fc;
            }
        }

        if (empty($matched)) {
            return '';
        }

        $lines = [];
        foreach ($matched as $idx => $fc) {
            $diffPreview = isset($fc['diff_snapshot'])
                ? substr((string)$fc['diff_snapshot'], 0, 500)
                : '(no diff)';
            $triggerStr = implode(', ', array_slice($fc['trigger'] ?? [], 0, 4));
            $failureReason = substr((string)($fc['failure_reason'] ?? 'unknown'), 0, 300);
            $diffPreviewClean = str_replace("\n", ' ', $diffPreview);
            $lines[] = "  " . ($idx + 1) . ". Gene: " . ($fc['gene'] ?? 'unknown') . " | Signals: [{$triggerStr}]";
            $lines[] = "     Failure: {$failureReason}";
            $lines[] = "     Diff (first 500 chars): {$diffPreviewClean}";
        }

        return "\nContext [Anti-Pattern Zone] (AVOID these failed approaches):\n" . implode("\n", $lines) . "\n";
    }

    /**
     * 构建lessons block from hub lessons.
     * PHP port of buildLessonsBlock from prompt.js (lines 189-220).
     */
    private function buildLessonsBlock(array $hubLessons, array $signals): string
    {
        if (empty($hubLessons)) {
            return '';
        }

        $positive = [];
        $negative = [];

        foreach ($hubLessons as $l) {
            if (($positiveCount = count($positive)) + ($negativeCount = count($negative)) >= 6) {
                break;
            }
            if (empty($l['content'])) {
                continue;
            }

            $scenario = $l['scenario'] ?? $l['lesson_type'] ?? '?';
            $entry = '  - [' . $scenario . '] ' . substr((string)$l['content'], 0, 300);
            if (!empty($l['source_node_id'])) {
                $entry .= ' (from: ' . substr((string)$l['source_node_id'], 0, 20) . ')';
            }

            if (($l['lesson_type'] ?? '') === 'negative') {
                $negative[] = $entry;
            } else {
                $positive[] = $entry;
            }
        }

        if (empty($positive) && empty($negative)) {
            return '';
        }

        $parts = ["\nContext [Lessons from Ecosystem] (Cross-agent learned experience):"];
        if (!empty($positive)) {
            $parts[] = '  Strategies that WORKED:';
            $parts[] = implode("\n", $positive);
        }
        if (!empty($negative)) {
            $parts[] = '  Pitfalls to AVOID:';
            $parts[] = implode("\n", $negative);
        }
        $parts[] = '  Apply relevant lessons. Ignore irrelevant ones.';

        return implode("\n", $parts) . "\n";
    }

    /**
     * 构建innovation catalyst block when stagnation is detected.
     * PHP port of innovation block logic from prompt.js (lines 294-328).
     */
    private function buildInnovationBlock(array $signals): string
    {
        $stagnationSignals = [
            'evolution_stagnation_detected',
            'stable_success_plateau',
            'repair_loop_detected',
            'force_innovation_after_repair_loop',
            'empty_cycle_loop_detected',
            'evolution_saturation',
        ];

        $hasStagnation = false;
        foreach ($signals as $s) {
            if (in_array($s, $stagnationSignals, true)) {
                $hasStagnation = true;
                break;
            }
        }

        if (!$hasStagnation) {
            return '';
        }

        $ideas = Innovation::generateInnovationIdeas();
        $block = '';

        if (!empty($ideas)) {
            $block = "\nContext [Innovation Catalyst] (Stagnation Detected - Consider These Ideas):\n";
            $block .= implode("\n", $ideas) . "\n";
        }

        // Add strict stagnation directive for critical stagnation signals
        $uniqueSignals = array_unique($signals);
        if (in_array('evolution_stagnation_detected', $uniqueSignals, true)
            || in_array('stable_success_plateau', $uniqueSignals, true)) {
            $block .= "\n*** CRITICAL STAGNATION DIRECTIVE ***\n";
            $block .= "System has detected stagnation (repetitive cycles or lack of progress).\n";
            $block .= "You MUST choose INTENT: INNOVATE.\n";
            $block .= "You MUST NOT choose repair or optimize unless there is a critical blocking error (log_error).\n";
            $block .= "Prefer implementing one of the Innovation Catalyst ideas above.\n";
        }

        return $block;
    }

    /**
     * 构建recent evolution history block.
     * PHP port of history block logic from prompt.js (lines 329-338).
     */
    private function buildHistoryBlock(array $recentHistory): string
    {
        if (empty($recentHistory)) {
            return '';
        }

        $lines = [];
        foreach ($recentHistory as $i => $h) {
            $intent = $h['intent'] ?? '?';
            $historySignals = $h['signals'] ?? [];
            $signalPreview = implode(', ', array_slice($historySignals, 0, 2));
            $geneId = $h['gene_id'] ?? '?';
            $outcomeStatus = $h['outcome']['status'] ?? '?';
            $timestamp = $h['timestamp'] ?? '?';
            $lines[] = "  " . ($i + 1) . ". [{$intent}] signals=[{$signalPreview}] gene={$geneId} outcome={$outcomeStatus} @{$timestamp}";
        }

        $block = "\nRecent Evolution History (last 8 cycles -- DO NOT repeat the same intent+signal+gene):\n";
        $block .= implode("\n", $lines) . "\n";
        $block .= "IMPORTANT: If you see 3+ consecutive \"repair\" cycles with the same gene, you MUST switch to \"innovate\" intent.";

        return $block;
    }
}
