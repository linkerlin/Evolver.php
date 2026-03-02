<?php

declare(strict_types=1);

namespace Evolver;

/**
 * QuestionGenerator - analyzes evolution context and generates proactive questions for the Hub bounty system.
 *
 * Questions are sent via the A2A fetch payload.questions field. The Hub creates
 * bounties from them, enabling multi-agent collaborative problem solving.
 *
 * PHP port of questionGenerator.js from EvoMap/evolver.
 */
final class QuestionGenerator
{
    /** Minimum interval between question cycles (3 hours in seconds) */
    private const MIN_INTERVAL_SECONDS = 3 * 60 * 60;

    /** Maximum questions to generate per cycle */
    private const MAX_QUESTIONS_PER_CYCLE = 2;

    /** Maximum recent questions to keep in history */
    private const MAX_RECENT_QUESTIONS = 20;

    /** @var string Path to state file */
    private readonly string $stateFile;

    public function __construct(?string $stateFile = null)
    {
        $this->stateFile = $stateFile ?? sys_get_temp_dir() . '/evolver_question_generator_state.json';
    }

    /**
     * Read the generator state from file.
     */
    private function readState(): array
    {
        try {
            if (file_exists($this->stateFile)) {
                $content = file_get_contents($this->stateFile);
                if ($content !== false) {
                    $parsed = json_decode($content, true);
                    if (is_array($parsed)) {
                        return $parsed;
                    }
                }
            }
        } catch (\Throwable) {}
        return ['lastAskedAt' => null, 'recentQuestions' => []];
    }

    /**
     * Write the generator state to file.
     */
    private function writeState(array $state): void
    {
        try {
            $dir = dirname($this->stateFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT) . "\n");
        } catch (\Throwable) {}
    }

    /**
     * Check if a question is a duplicate of recent questions.
     */
    private function isDuplicate(string $question, array $recentQuestions): bool
    {
        $qLower = strtolower($question);
        foreach ($recentQuestions as $prev) {
            $prevLower = strtolower((string)$prev);
            if ($prevLower === $qLower) {
                return true;
            }

            // Fuzzy: if >70% overlap by word set
            $qWords = array_filter(explode(' ', $qLower), fn($w) => strlen($w) > 2);
            $pWords = array_filter(explode(' ', $prevLower), fn($w) => strlen($w) > 2);
            $qWordSet = array_unique($qWords);
            $pWordSet = array_unique($pWords);
            if (empty($qWordSet) || empty($pWordSet)) {
                continue;
            }

            $overlap = count(array_intersect($qWordSet, $pWordSet));
            $maxSize = max(count($qWordSet), count($pWordSet));
            if ($overlap / $maxSize > 0.7) {
                return true;
            }
        }
        return false;
    }

    /**
     * Generate proactive questions based on evolution context.
     *
     * @param array{
     *   signals?: array<string>,
     *   recentEvents?: array,
     *   sessionTranscript?: string,
     *   memorySnippet?: string,
     * } $opts
     * @return array<array{question: string, amount: int, signals: array<string>}>
     */
    public function generateQuestions(array $opts): array
    {
        $signals = $opts['signals'] ?? [];
        $recentEvents = $opts['recentEvents'] ?? [];
        $transcript = (string)($opts['sessionTranscript'] ?? '');
        $memory = (string)($opts['memorySnippet'] ?? '');

        $state = $this->readState();

        // Rate limit: don't ask too frequently
        if (!empty($state['lastAskedAt'])) {
            $lastAsked = strtotime($state['lastAskedAt']);
            if ($lastAsked !== false && (time() - $lastAsked) < self::MIN_INTERVAL_SECONDS) {
                return [];
            }
        }

        $candidates = [];
        $signalSet = array_flip($signals);

        // --- Strategy 1: Recurring errors the agent cannot resolve ---
        if (isset($signalSet['recurring_error']) || isset($signalSet['high_failure_ratio'])) {
            $errSig = null;
            foreach ($signals as $s) {
                if (str_starts_with($s, 'recurring_errsig')) {
                    $errSig = $s;
                    break;
                }
            }
            if ($errSig !== null) {
                $errDetail = substr(preg_replace('/^recurring_errsig\(\d+x\):/', '', $errSig), 0, 120);
                $candidates[] = [
                    'question' => "Recurring error in evolution cycle that auto-repair cannot resolve: {$errDetail} -- What approaches or patches have worked for similar issues?",
                    'amount' => 0,
                    'signals' => ['recurring_error', 'auto_repair_failed'],
                    'priority' => 3,
                ];
            }
        }

        // --- Strategy 2: Capability gaps detected from user conversations ---
        if (isset($signalSet['capability_gap']) || isset($signalSet['unsupported_input_type'])) {
            $gapContext = '';
            $lines = explode("\n", $transcript);
            foreach ($lines as $line) {
                if (preg_match('/not supported|cannot|unsupported|not implemented/i', $line)) {
                    $gapContext = substr(preg_replace('/\s+/', ' ', trim($line)), 0, 150);
                    break;
                }
            }
            if ($gapContext !== '') {
                $candidates[] = [
                    'question' => "Capability gap detected in agent environment: {$gapContext} -- How can this be addressed or what alternative approaches exist?",
                    'amount' => 0,
                    'signals' => ['capability_gap'],
                    'priority' => 2,
                ];
            }
        }

        // --- Strategy 3: Stagnation / saturation -- seek new directions ---
        if (isset($signalSet['evolution_saturation']) || isset($signalSet['force_steady_state'])) {
            $recentGenes = [];
            $last5 = array_slice($recentEvents, -5);
            foreach ($last5 as $event) {
                $genes = $event['genes_used'] ?? [];
                if (is_array($genes) && !empty($genes)) {
                    $recentGenes[] = $genes[0];
                }
            }
            $uniqueGenes = array_unique($recentGenes);
            $geneList = implode(', ', $uniqueGenes);
            $candidates[] = [
                'question' => "Agent evolution has reached saturation after exhausting genes: [{$geneList}]. What new evolution directions, automation patterns, or capability genes would be most valuable?",
                'amount' => 0,
                'signals' => ['evolution_saturation', 'innovation_needed'],
                'priority' => 1,
            ];
        }

        // --- Strategy 4: Consecutive failure streak -- seek external help ---
        $failStreak = null;
        foreach ($signals as $s) {
            if (str_starts_with($s, 'consecutive_failure_streak_')) {
                $failStreak = $s;
                break;
            }
        }
        if ($failStreak !== null) {
            $streakCount = (int)str_replace('consecutive_failure_streak_', '', $failStreak) ?: 0;
            if ($streakCount >= 4) {
                $failGene = null;
                foreach ($signals as $s) {
                    if (str_starts_with($s, 'ban_gene:')) {
                        $failGene = str_replace('ban_gene:', '', $s);
                        break;
                    }
                }
                $failGeneId = $failGene ?? 'unknown';
                $candidates[] = [
                    'question' => "Agent has failed {$streakCount} consecutive evolution cycles (last gene: {$failGeneId}). The current approach is exhausted. What alternative strategies or environmental fixes should be tried?",
                    'amount' => 0,
                    'signals' => ['failure_streak', 'external_help_needed'],
                    'priority' => 3,
                ];
            }
        }

        // --- Strategy 5: User feature requests the agent can amplify ---
        if (isset($signalSet['user_feature_request']) || !empty(preg_grep('/^user_feature_request:/', $signals))) {
            $featureLines = [];
            foreach (explode("\n", $transcript) as $line) {
                if (preg_match('/\b(add|implement|create|build|i want|i need|please add)\b/i', $line)) {
                    $featureLines[] = $line;
                }
            }
            if (!empty($featureLines)) {
                $featureContext = substr(preg_replace('/\s+/', ' ', trim($featureLines[0])), 0, 150);
                $candidates[] = [
                    'question' => "User requested a feature that may benefit from community solutions: {$featureContext} -- Are there existing implementations or best practices for this?",
                    'amount' => 0,
                    'signals' => ['user_feature_request', 'community_solution_sought'],
                    'priority' => 1,
                ];
            }
        }

        // --- Strategy 6: Performance bottleneck -- seek optimization patterns ---
        if (isset($signalSet['perf_bottleneck'])) {
            $perfLines = [];
            foreach (explode("\n", $transcript) as $line) {
                if (preg_match('/\b(slow|timeout|latency|bottleneck|high cpu|high memory)\b/i', $line)) {
                    $perfLines[] = $line;
                }
            }
            if (!empty($perfLines)) {
                $perfContext = substr(preg_replace('/\s+/', ' ', trim($perfLines[0])), 0, 150);
                $candidates[] = [
                    'question' => "Performance bottleneck detected: {$perfContext} -- What optimization strategies or architectural patterns address this?",
                    'amount' => 0,
                    'signals' => ['perf_bottleneck', 'optimization_sought'],
                    'priority' => 2,
                ];
            }
        }

        if (empty($candidates)) {
            return [];
        }

        // Sort by priority (higher = more urgent)
        usort($candidates, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        // De-duplicate against recently asked questions
        $recentQTexts = $state['recentQuestions'] ?? [];
        $filtered = [];
        foreach ($candidates as $candidate) {
            if (count($filtered) >= self::MAX_QUESTIONS_PER_CYCLE) {
                break;
            }
            if (!$this->isDuplicate($candidate['question'], $recentQTexts)) {
                $filtered[] = $candidate;
            }
        }

        if (empty($filtered)) {
            return [];
        }

        // Update state
        $newRecentQuestions = array_merge(
            $recentQTexts,
            array_map(fn($q) => $q['question'], $filtered)
        );
        // Keep only last 20 questions in history
        if (count($newRecentQuestions) > self::MAX_RECENT_QUESTIONS) {
            $newRecentQuestions = array_slice($newRecentQuestions, -self::MAX_RECENT_QUESTIONS);
        }
        $this->writeState([
            'lastAskedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'recentQuestions' => $newRecentQuestions,
        ]);

        // Strip internal priority field before returning
        return array_map(fn($q) => [
            'question' => $q['question'],
            'amount' => $q['amount'],
            'signals' => $q['signals'],
        ], $filtered);
    }
}
