<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\QuestionGenerator;
use PHPUnit\Framework\TestCase;

/**
 * QuestionGenerator tests.
 */
final class QuestionGeneratorTest extends TestCase
{
    private QuestionGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new QuestionGenerator();
    }

    public function testGenerateQuestions(): void
    {
        $opts = [
            'context' => [
                'recent_errors' => ['PHP parse error', 'Database connection failed'],
                'current_files' => ['src/app.php', 'src/config.php'],
            ],
            'signals' => ['error', 'php_error'],
        ];

        $questions = $this->generator->generateQuestions($opts);

        $this->assertIsArray($questions);
    }

    public function testGenerateWithEmptyContext(): void
    {
        $opts = [
            'context' => [],
        ];

        $questions = $this->generator->generateQuestions($opts);

        $this->assertIsArray($questions);
    }

    public function testGenerateWithSignals(): void
    {
        $opts = [
            'signals' => ['error', 'php_error', 'recurring'],
        ];

        $questions = $this->generator->generateQuestions($opts);

        $this->assertIsArray($questions);
    }

    public function testGenerateWithEvents(): void
    {
        $opts = [
            'recent_events' => [
                ['type' => 'EvolutionEvent', 'intent' => 'repair', 'summary' => 'Fixed error'],
                ['type' => 'EvolutionEvent', 'intent' => 'optimize', 'summary' => 'Improved performance'],
            ],
        ];

        $questions = $this->generator->generateQuestions($opts);

        $this->assertIsArray($questions);
    }
}
