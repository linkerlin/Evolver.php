<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\Commentary;
use PHPUnit\Framework\TestCase;

/**
 * Commentary tests.
 */
final class CommentaryTest extends TestCase
{
    public function testGetComment(): void
    {
        $options = [
            'intent' => 'repair',
            'signals' => ['error', 'php_error'],
        ];

        $comment = Commentary::getComment($options);

        $this->assertIsString($comment);
    }

    public function testGetCommentWithEmptyOptions(): void
    {
        $comment = Commentary::getComment();

        $this->assertIsString($comment);
    }

    public function testGetAvailablePersonas(): void
    {
        $personas = Commentary::getAvailablePersonas();

        $this->assertIsArray($personas);
        $this->assertNotEmpty($personas);
    }
}
