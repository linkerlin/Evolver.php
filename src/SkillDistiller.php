<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Skill Distiller - Auto-distills new Genes from successful capsules.
 *
 * Pipeline: collectData → analyzePatterns → prepareDistillation → completeDistillation
 *
 * PHP port of skillDistiller.js from EvoMap/evolver.
 */
final class SkillDistiller
{
    private const DISTILLER_MIN_CAPSULES = 10;
    private const DISTILLER_INTERVAL_HOURS = 24;
    private const DISTILLER_MIN_SUCCESS_RATE = 0.7;
    private const DISTILLED_MAX_FILES = 12;
    private const DISTILLED_ID_PREFIX = 'gene_distilled_';

    private string $memoryDir;
    private string $assetsDir;
    private string $evolutionDir;

    public function __construct(?string $baseDir = null)
    {
        $baseDir = $baseDir ?? getcwd();
        $this->memoryDir = $baseDir . '/memory';
        $this->assetsDir = $baseDir . '/gep/assets';
        $this->evolutionDir = $baseDir . '/evolution';
    }

    // -------------------------------------------------------------------------
    // Step 1: collectDistillationData
    // -------------------------------------------------------------------------

    /**
     * Collect successful capsules and group by gene.
     */
    public function collectDistillationData(): array
    {
        $capsulesJson = $this->readJsonIfExists($this->assetsDir . '/capsules.json', ['capsules' => []]);
        $capsulesJsonl = $this->readJsonlIfExists($this->assetsDir . '/capsules.jsonl');

        $allCapsules = array_merge($capsulesJson['capsules'] ?? [], $capsulesJsonl);

        // Deduplicate by ID
        $unique = [];
        foreach ($allCapsules as $c) {
            if ($c && isset($c['id'])) {
                $unique[(string)$c['id']] = $c;
            }
        }
        $allCapsules = array_values($unique);

        // Filter successful capsules
        $successCapsules = array_filter($allCapsules, function ($c) {
            if (!$c || !isset($c['outcome'])) return false;
            $status = is_string($c['outcome']) ? $c['outcome'] : ($c['outcome']['status'] ?? '');
            if ($status !== 'success') return false;
            $score = isset($c['outcome']['score']) && is_numeric($c['outcome']['score'])
                ? (float)$c['outcome']['score']
                : 1;
            return $score >= self::DISTILLER_MIN_SUCCESS_RATE;
        });

        $events = $this->readJsonlIfExists($this->assetsDir . '/events.jsonl');

        $memGraphPath = getenv('MEMORY_GRAPH_PATH') ?: $this->evolutionDir . '/memory_graph.jsonl';
        $graphEntries = $this->readJsonlIfExists($memGraphPath);

        // Group by gene
        $grouped = [];
        foreach ($successCapsules as $c) {
            $geneId = $c['gene'] ?? $c['gene_id'] ?? 'unknown';
            if (!isset($grouped[$geneId])) {
                $grouped[$geneId] = [
                    'gene_id' => $geneId,
                    'capsules' => [],
                    'total_count' => 0,
                    'total_score' => 0,
                    'triggers' => [],
                    'summaries' => [],
                ];
            }
            $g = &$grouped[$geneId];
            $g['capsules'][] = $c;
            $g['total_count']++;
            $g['total_score'] += isset($c['outcome']['score']) && is_numeric($c['outcome']['score'])
                ? (float)$c['outcome']['score']
                : 0.8;
            if (is_array($c['trigger'] ?? null)) {
                $g['triggers'][] = $c['trigger'];
            }
            if (isset($c['summary'])) {
                $g['summaries'][] = (string)$c['summary'];
            }
        }

        // Calculate averages
        foreach ($grouped as &$g) {
            $g['avg_score'] = $g['total_count'] > 0 ? $g['total_score'] / $g['total_count'] : 0;
        }

        return [
            'successCapsules' => array_values($successCapsules),
            'allCapsules' => $allCapsules,
            'events' => $events,
            'graphEntries' => $graphEntries,
            'grouped' => $grouped,
            'dataHash' => $this->computeDataHash(array_values($successCapsules)),
        ];
    }

    // -------------------------------------------------------------------------
    // Step 2: analyzePatterns
    // -------------------------------------------------------------------------

    /**
     * Analyze patterns in successful capsules.
     */
    public function analyzePatterns(array $data): array
    {
        $grouped = $data['grouped'];
        $report = [
            'high_frequency' => [],
            'strategy_drift' => [],
            'coverage_gaps' => [],
            'total_success' => count($data['successCapsules']),
            'total_capsules' => count($data['allCapsules']),
            'success_rate' => count($data['allCapsules']) > 0
                ? count($data['successCapsules']) / count($data['allCapsules'])
                : 0,
        ];

        foreach ($grouped as $geneId => $g) {
            // High frequency genes
            if ($g['total_count'] >= 5) {
                $flat = [];
                foreach ($g['triggers'] as $t) {
                    if (is_array($t)) {
                        $flat = array_merge($flat, $t);
                    }
                }
                $freq = [];
                foreach ($flat as $trigger) {
                    $k = strtolower((string)$trigger);
                    $freq[$k] = ($freq[$k] ?? 0) + 1;
                }
                arsort($freq);
                $top = array_slice(array_keys($freq), 0, 5);
                $report['high_frequency'][] = [
                    'gene_id' => $geneId,
                    'count' => $g['total_count'],
                    'avg_score' => round($g['avg_score'], 2),
                    'top_triggers' => $top,
                ];
            }

            // Strategy drift detection
            if (count($g['summaries']) >= 3) {
                $first = $g['summaries'][0];
                $last = $g['summaries'][count($g['summaries']) - 1];
                if ($first !== $last) {
                    $fw = array_flip(preg_split('/\s+/', strtolower($first)));
                    $lw = array_flip(preg_split('/\s+/', strtolower($last)));
                    $inter = count(array_intersect_key($fw, $lw));
                    $union = count($fw) + count($lw) - $inter;
                    $sim = $union > 0 ? $inter / $union : 1;
                    if ($sim < 0.6) {
                        $report['strategy_drift'][] = [
                            'gene_id' => $geneId,
                            'similarity' => round($sim, 2),
                            'early_summary' => substr($first, 0, 120),
                            'recent_summary' => substr($last, 0, 120),
                        ];
                    }
                }
            }
        }

        // Coverage gaps detection
        $signalFreq = [];
        foreach ($data['events'] ?? [] as $evt) {
            if (isset($evt['signals']) && is_array($evt['signals'])) {
                foreach ($evt['signals'] as $s) {
                    $k = strtolower((string)$s);
                    $signalFreq[$k] = ($signalFreq[$k] ?? 0) + 1;
                }
            }
        }

        $covered = [];
        foreach ($grouped as $gene) {
            foreach ($gene['triggers'] as $t) {
                if (is_array($t)) {
                    foreach ($t as $s) {
                        $covered[strtolower((string)$s)] = true;
                    }
                }
            }
        }

        $gaps = [];
        foreach ($signalFreq as $s => $count) {
            if ($count >= 3 && !isset($covered[$s])) {
                $gaps[$s] = $count;
            }
        }
        arsort($gaps);
        $gaps = array_slice($gaps, 0, 10, true);

        if (!empty($gaps)) {
            foreach ($gaps as $s => $frequency) {
                $report['coverage_gaps'][] = ['signal' => $s, 'frequency' => $frequency];
            }
        }

        return $report;
    }

    // -------------------------------------------------------------------------
    // Step 3: LLM response parsing
    // -------------------------------------------------------------------------

    /**
     * Extract JSON from LLM response text.
     */
    public function extractJsonFromLlmResponse(string $text): ?array
    {
        $str = $text;
        $buffer = '';
        $depth = 0;

        for ($i = 0; $i < strlen($str); $i++) {
            $ch = $str[$i];
            if ($ch === '{') {
                if ($depth === 0) $buffer = '';
                $depth++;
                $buffer .= $ch;
            } elseif ($ch === '}') {
                $depth--;
                $buffer .= $ch;
                if ($depth === 0 && strlen($buffer) > 2) {
                    try {
                        $obj = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
                        if ($obj && is_array($obj) && ($obj['type'] ?? '') === 'Gene') {
                            return $obj;
                        }
                    } catch (\JsonException $e) {}
                    $buffer = '';
                }
                if ($depth < 0) $depth = 0;
            } elseif ($depth > 0) {
                $buffer .= $ch;
            }
        }

        return null;
    }

    /**
     * Build distillation prompt for LLM.
     */
    public function buildDistillationPrompt(array $analysis, array $existingGenes, array $sampleCapsules): string
    {
        $genesRef = array_map(function ($g) {
            return [
                'id' => $g['id'] ?? null,
                'category' => $g['category'] ?? null,
                'signals_match' => $g['signals_match'] ?? [],
            ];
        }, $existingGenes);

        $samples = array_slice(array_map(function ($c) {
            return [
                'gene' => $c['gene'] ?? $c['gene_id'] ?? null,
                'trigger' => $c['trigger'] ?? [],
                'summary' => substr($c['summary'] ?? '', 0, 200),
                'outcome' => $c['outcome'] ?? null,
            ];
        }, $sampleCapsules), 0, 8);

        return implode("\n", [
            'You are a Gene synthesis engine for the GEP (Gene Expression Protocol).',
            '',
            'Analyze the following successful evolution capsules and extract a reusable Gene.',
            '',
            'RULES:',
            '- Strategy steps MUST be actionable operations, NOT summaries',
            '- Each step must be a concrete instruction an AI agent can execute',
            '- Do NOT describe what happened; describe what TO DO next time',
            '- The Gene MUST have a unique id starting with "' . self::DISTILLED_ID_PREFIX . '"',
            '- constraints.max_files MUST be <= ' . self::DISTILLED_MAX_FILES,
            '- constraints.forbidden_paths MUST include at least [".git", "vendor"]',
            '- Output valid Gene JSON only (no markdown, no explanation)',
            '',
            'SUCCESSFUL CAPSULES (grouped by pattern):',
            json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            '',
            'EXISTING GENES (avoid duplication):',
            json_encode($genesRef, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            '',
            'ANALYSIS:',
            json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            '',
            'Output a single Gene JSON object with these fields:',
            '{ "type": "Gene", "id": "gene_distilled_...", "category": "...", "signals_match": [...], "preconditions": [...], "strategy": [...], "constraints": { "max_files": N, "forbidden_paths": [...] }, "validation": [...] }',
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 4: validateSynthesizedGene
    // -------------------------------------------------------------------------

    /**
     * Validate a synthesized gene for security and correctness.
     */
    public function validateSynthesizedGene(array $gene, array $existingGenes): array
    {
        $errors = [];

        if (empty($gene) || !is_array($gene)) {
            return ['valid' => false, 'errors' => ['gene is not an object']];
        }

        if (($gene['type'] ?? '') !== 'Gene') {
            $errors[] = 'missing or wrong type (must be "Gene")';
        }
        if (empty($gene['id']) || !is_string($gene['id'])) {
            $errors[] = 'missing id';
        }
        if (empty($gene['category'])) {
            $errors[] = 'missing category';
        }
        if (empty($gene['signals_match']) || !is_array($gene['signals_match'])) {
            $errors[] = 'missing or empty signals_match';
        }
        if (empty($gene['strategy']) || !is_array($gene['strategy'])) {
            $errors[] = 'missing or empty strategy';
        }

        // Ensure ID prefix
        if (isset($gene['id']) && !str_starts_with((string)$gene['id'], self::DISTILLED_ID_PREFIX)) {
            $gene['id'] = self::DISTILLED_ID_PREFIX . preg_replace('/^gene_/', '', (string)$gene['id']);
        }

        // Ensure constraints
        if (!isset($gene['constraints']) || !is_array($gene['constraints'])) {
            $gene['constraints'] = [];
        }
        if (empty($gene['constraints']['forbidden_paths'])) {
            $gene['constraints']['forbidden_paths'] = ['.git', 'vendor'];
        }
        $hasForbidden = false;
        foreach ($gene['constraints']['forbidden_paths'] as $p) {
            if ($p === '.git' || $p === 'vendor') {
                $hasForbidden = true;
                break;
            }
        }
        if (!$hasForbidden) {
            $errors[] = 'constraints.forbidden_paths must include .git or vendor';
        }
        if (empty($gene['constraints']['max_files']) || $gene['constraints']['max_files'] > self::DISTILLED_MAX_FILES) {
            $gene['constraints']['max_files'] = self::DISTILLED_MAX_FILES;
        }

        // Validate commands (PHP-specific allowed prefixes)
        $allowedPrefixes = ['php ', 'composer ', 'vendor/bin/', './vendor/bin/'];
        if (isset($gene['validation']) && is_array($gene['validation'])) {
            $gene['validation'] = array_filter($gene['validation'], function ($cmd) use ($allowedPrefixes) {
                $c = trim((string)$cmd);
                if (empty($c)) return false;
                $hasPrefix = false;
                foreach ($allowedPrefixes as $prefix) {
                    if (str_starts_with($c, $prefix)) {
                        $hasPrefix = true;
                        break;
                    }
                }
                if (!$hasPrefix) return false;
                // Block dangerous patterns
                if (preg_match('/`|\$\(|\$\{/', $c)) return false;
                $stripped = preg_replace('/"[^"]*"/', '', preg_replace("/'[^']*'/", '', $c));
                return !preg_match('/[;&|><]/', $stripped);
            });
        }

        // Ensure unique ID
        $existingIds = array_flip(array_column($existingGenes, 'id'));
        if (isset($gene['id']) && isset($existingIds[$gene['id']])) {
            $gene['id'] = $gene['id'] . '_' . base_convert((string)time(), 10, 36);
        }

        // Check for full signal overlap with existing genes
        if (!empty($gene['signals_match']) && !empty($existingGenes)) {
            $newSet = array_flip(array_map('strtolower', array_map('strval', $gene['signals_match'])));
            foreach ($existingGenes as $eg) {
                $egSet = array_flip(array_map('strtolower', array_map('strval', $eg['signals_match'] ?? [])));
                if (!empty($newSet) && !empty($egSet)) {
                    $overlap = count(array_intersect_key($newSet, $egSet));
                    if ($overlap === count($newSet) && $overlap === count($egSet)) {
                        $errors[] = 'signals_match fully overlaps with existing gene: ' . ($eg['id'] ?? '?');
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'gene' => $gene,
        ];
    }

    // -------------------------------------------------------------------------
    // Step 5a: prepareDistillation
    // -------------------------------------------------------------------------

    /**
     * Prepare distillation: collect data, build prompt, write to file.
     */
    public function prepareDistillation(): array
    {
        echo "[Distiller] Preparing skill distillation...\n";

        $data = $this->collectDistillationData();
        echo "[Distiller] Collected " . count($data['successCapsules']) . " successful capsules across " .
            count($data['grouped']) . " gene groups.\n";

        if (count($data['successCapsules']) < self::DISTILLER_MIN_CAPSULES) {
            echo "[Distiller] Not enough successful capsules (" . count($data['successCapsules']) .
                " < " . self::DISTILLER_MIN_CAPSULES . "). Skipping.\n";
            return ['ok' => false, 'reason' => 'insufficient_data'];
        }

        $state = $this->readDistillerState();
        if (($state['last_data_hash'] ?? '') === $data['dataHash']) {
            echo "[Distiller] Data unchanged since last distillation (hash: {$data['dataHash']}). Skipping.\n";
            return ['ok' => false, 'reason' => 'idempotent_skip'];
        }

        $analysis = $this->analyzePatterns($data);
        echo "[Distiller] Analysis: high_freq=" . count($analysis['high_frequency']) .
            " drift=" . count($analysis['strategy_drift']) .
            " gaps=" . count($analysis['coverage_gaps']) . "\n";

        $existingGenesJson = $this->readJsonIfExists($this->assetsDir . '/genes.json', ['genes' => []]);
        $existingGenes = $existingGenesJson['genes'] ?? [];

        $prompt = $this->buildDistillationPrompt($analysis, $existingGenes, $data['successCapsules']);

        $this->ensureDir($this->memoryDir);
        $promptFileName = 'distill_prompt_' . time() . '.txt';
        $promptPath = $this->memoryDir . '/' . $promptFileName;
        file_put_contents($promptPath, $prompt);

        $reqPath = $this->distillRequestPath();
        $requestData = [
            'type' => 'DistillationRequest',
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'prompt_path' => $promptPath,
            'data_hash' => $data['dataHash'],
            'input_capsule_count' => count($data['successCapsules']),
            'analysis_summary' => [
                'high_frequency_count' => count($analysis['high_frequency']),
                'drift_count' => count($analysis['strategy_drift']),
                'gap_count' => count($analysis['coverage_gaps']),
                'success_rate' => round($analysis['success_rate'], 2),
            ],
        ];
        file_put_contents($reqPath, json_encode($requestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

        echo "[Distiller] Prompt written to: {$promptPath}\n";
        return ['ok' => true, 'promptPath' => $promptPath, 'requestPath' => $reqPath, 'dataHash' => $data['dataHash']];
    }

    // -------------------------------------------------------------------------
    // Step 5b: completeDistillation
    // -------------------------------------------------------------------------

    /**
     * Complete distillation: validate LLM response and save gene.
     */
    public function completeDistillation(string $responseText): array
    {
        $reqPath = $this->distillRequestPath();
        $request = $this->readJsonIfExists($reqPath, null);

        if (!$request) {
            echo "[Distiller] WARN: No pending distillation request found.\n";
            return ['ok' => false, 'reason' => 'no_request'];
        }

        $rawGene = $this->extractJsonFromLlmResponse($responseText);
        if (!$rawGene) {
            $this->appendJsonl($this->distillerLogPath(), [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'data_hash' => $request['data_hash'] ?? null,
                'status' => 'error',
                'error' => 'LLM response did not contain a valid Gene JSON',
            ]);
            echo "[Distiller] ERROR: LLM response did not contain a valid Gene JSON.\n";
            return ['ok' => false, 'reason' => 'no_gene_in_response'];
        }

        $existingGenesJson = $this->readJsonIfExists($this->assetsDir . '/genes.json', ['genes' => []]);
        $existingGenes = $existingGenesJson['genes'] ?? [];

        $validation = $this->validateSynthesizedGene($rawGene, $existingGenes);

        $logEntry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'data_hash' => $request['data_hash'] ?? null,
            'input_capsule_count' => $request['input_capsule_count'] ?? 0,
            'analysis_summary' => $request['analysis_summary'] ?? null,
            'synthesized_gene_id' => $validation['gene']['id'] ?? null,
            'validation_passed' => $validation['valid'],
            'validation_errors' => $validation['errors'],
        ];

        if (!$validation['valid']) {
            $logEntry['status'] = 'validation_failed';
            $this->appendJsonl($this->distillerLogPath(), $logEntry);
            echo "[Distiller] WARN: Gene failed validation: " . implode(', ', $validation['errors']) . "\n";
            return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $validation['errors']];
        }

        $gene = $validation['gene'];
        $gene['_distilled_meta'] = [
            'distilled_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'source_capsule_count' => $request['input_capsule_count'] ?? 0,
            'data_hash' => $request['data_hash'] ?? null,
        ];

        // Upsert gene using GepAssetStore if available
        if (class_exists(GepAssetStore::class)) {
            GepAssetStore::upsertGene($gene);
        } else {
            // Fallback: directly update genes.json
            $existingGenes[] = $gene;
            $existingGenesJson['genes'] = $existingGenes;
            file_put_contents(
                $this->assetsDir . '/genes.json',
                json_encode($existingGenesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
            );
        }
        echo "[Distiller] Gene \"{$gene['id']}\" written to genes.json.\n";

        // Update state
        $state = $this->readDistillerState();
        $state['last_distillation_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $state['last_data_hash'] = $request['data_hash'] ?? null;
        $state['last_gene_id'] = $gene['id'];
        $state['distillation_count'] = ($state['distillation_count'] ?? 0) + 1;
        $this->writeDistillerState($state);

        $logEntry['status'] = 'success';
        $logEntry['gene'] = $gene;
        $this->appendJsonl($this->distillerLogPath(), $logEntry);

        // Cleanup
        @unlink($reqPath);
        if (!empty($request['prompt_path'])) {
            @unlink($request['prompt_path']);
        }

        echo "[Distiller] Distillation complete. New gene: {$gene['id']}\n";
        return ['ok' => true, 'gene' => $gene];
    }

    // -------------------------------------------------------------------------
    // Gate check
    // -------------------------------------------------------------------------

    /**
     * Check if distillation should run.
     */
    public function shouldDistill(): bool
    {
        if (strtolower(getenv('SKILL_DISTILLER') ?: 'true') === 'false') {
            return false;
        }

        $state = $this->readDistillerState();
        if (!empty($state['last_distillation_at'])) {
            $elapsed = time() - strtotime($state['last_distillation_at']);
            if ($elapsed < self::DISTILLER_INTERVAL_HOURS * 3600) {
                return false;
            }
        }

        $capsulesJson = $this->readJsonIfExists($this->assetsDir . '/capsules.json', ['capsules' => []]);
        $capsulesJsonl = $this->readJsonlIfExists($this->assetsDir . '/capsules.jsonl');
        $all = array_merge($capsulesJson['capsules'] ?? [], $capsulesJsonl);

        $recent = array_slice($all, -10);
        $recentSuccess = count(array_filter($recent, function ($c) {
            $status = is_string($c['outcome'] ?? null) ? $c['outcome'] : ($c['outcome']['status'] ?? '');
            return $status === 'success';
        }));
        if ($recentSuccess < 7) return false;

        $totalSuccess = count(array_filter($all, function ($c) {
            $status = is_string($c['outcome'] ?? null) ? $c['outcome'] : ($c['outcome']['status'] ?? '');
            return $status === 'success';
        }));
        if ($totalSuccess < self::DISTILLER_MIN_CAPSULES) return false;

        return true;
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function computeDataHash(array $capsules): string
    {
        $ids = array_map(fn($c) => $c['id'] ?? '', $capsules);
        sort($ids);
        return substr(hash('sha256', implode('|', $ids)), 0, 16);
    }

    private function distillerLogPath(): string
    {
        return $this->memoryDir . '/distiller_log.jsonl';
    }

    private function distillerStatePath(): string
    {
        return $this->memoryDir . '/distiller_state.json';
    }

    private function distillRequestPath(): string
    {
        return $this->memoryDir . '/distill_request.json';
    }

    private function readDistillerState(): array
    {
        return $this->readJsonIfExists($this->distillerStatePath(), []);
    }

    private function writeDistillerState(array $state): void
    {
        $this->ensureDir(dirname($this->distillerStatePath()));
        $tmp = $this->distillerStatePath() . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        rename($tmp, $this->distillerStatePath());
    }

    private function readJsonIfExists(string $path, $fallback)
    {
        try {
            if (!file_exists($path)) return $fallback;
            $raw = file_get_contents($path);
            if (empty(trim($raw))) return $fallback;
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? $fallback;
        } catch (\JsonException $e) {
            return $fallback;
        }
    }

    private function readJsonlIfExists(string $path): array
    {
        try {
            if (!file_exists($path)) return [];
            $raw = file_get_contents($path);
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            $result = [];
            foreach ($lines as $line) {
                try {
                    $result[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {}
            }
            return array_filter($result);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function appendJsonl(string $path, array $obj): void
    {
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($obj, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    // Constants accessor
    public static function getDistilledIdPrefix(): string
    {
        return self::DISTILLED_ID_PREFIX;
    }

    public static function getDistilledMaxFiles(): int
    {
        return self::DISTILLED_MAX_FILES;
    }
}
