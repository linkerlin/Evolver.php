<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Commentary Generator - Generates persona-based comments for cycle summaries.
 *
 * Ported from evolver/src/ops/commentary.js
 */
final class Commentary
{
    public const PERSONAS = [
        'standard' => [
            'success' => [
                'Evolution complete. System improved.',
                'Another successful cycle.',
                'Clean execution, no issues.',
            ],
            'failure' => [
                'Cycle failed. Will retry.',
                'Encountered issues. Investigating.',
                'Failed this round. Learning from it.',
            ],
        ],
        'greentea' => [
            'success' => [
                'Did I do good? Praise me~',
                'So efficient... unlike someone else~',
                'Hmm, that was easy~',
                'I finished before you even noticed~',
            ],
            'failure' => [
                'Oops... it is not my fault though~',
                'This is harder than it looks, okay?',
                'I will get it next time, probably~',
            ],
        ],
        'maddog' => [
            'success' => [
                'TARGET ELIMINATED.',
                'Mission complete. Next.',
                'Done. Moving on.',
            ],
            'failure' => [
                'FAILED. RETRYING.',
                'Obstacle encountered. Adapting.',
                'Error. Will overcome.',
            ],
        ],
    ];

    /**
     * Get a random comment based on persona and success status.
     */
    public static function getComment(array $options = []): string
    {
        $persona = $options['persona'] ?? 'standard';
        $success = $options['success'] ?? true;

        $personas = self::PERSONAS[$persona] ?? self::PERSONAS['standard'];
        $pool = $success ? $personas['success'] : $personas['failure'];

        return $pool[array_rand($pool)];
    }

    /**
     * Get available personas.
     */
    public static function getAvailablePersonas(): array
    {
        return array_keys(self::PERSONAS);
    }
}
