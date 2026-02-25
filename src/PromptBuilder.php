<?php

declare(strict_types=1);

namespace Evolver;

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
   - Reuse/update existing ID if possible. Create new only if novel pattern.
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
     * Build the main GEP protocol prompt.
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

        // Capsule candidates preview
        $capsPreview = '';
        if (!empty($capsuleCandidates)) {
            $capsPreview = json_encode($capsuleCandidates[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

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

        // Build the prompt
        return trim(<<<PROMPT
        GEP -- EVOLUTION PROTOCOL{$cycleLabel} [{$nowIso}]

        {$schemaSection}

        ━━━━━━━━━━━━━━━━━━━━━━
        II. Execution Context
        ━━━━━━━━━━━━━━━━━━━━━━

        Signals detected: {$signalsJson}

        Context [Env Fingerprint]:
        {$envFingerprintJson}

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

        {$antiPatternZone}

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
     * Build a minimal reuse prompt for direct capsule reuse mode.
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
        3. Run validation to confirm it works.
        4. If passed, solidify the result.
        5. If failed, ROLLBACK and report.

        Capsule payload:
        {$payloadJson}

        IMPORTANT: Do NOT reinvent. Apply faithfully.
        PROMPT);
    }

    /**
     * Format genes for preview in the prompt.
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
     * Format capsules for preview in the prompt.
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
     * Build anti-pattern zone block from failed capsules.
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
}
