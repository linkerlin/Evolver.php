<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\Innovation;
use PHPUnit\Framework\TestCase;

/**
 * Innovation tests.
 */
final class InnovationTest extends TestCase
{
    public function testGenerateInnovationIdeas(): void
    {
        $ideas = Innovation::generateInnovationIdeas();

        $this->assertIsArray($ideas);
    }
}
