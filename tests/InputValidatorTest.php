<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\InputValidator;
use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // evolver_run validation
    // -------------------------------------------------------------------------

    public function testValidateEvolverRunDefaults(): void
    {
        $result = InputValidator::validateEvolverRun([]);

        $this->assertEquals('', $result['context']);
        $this->assertEquals('balanced', $result['strategy']);
        $this->assertFalse($result['driftEnabled']);
        $this->assertNull($result['cycleId']);
    }

    public function testValidateEvolverRunWithValidParams(): void
    {
        $result = InputValidator::validateEvolverRun([
            'context' => 'test context',
            'strategy' => 'innovate',
            'driftEnabled' => true,
            'cycleId' => 'cycle123',
        ]);

        $this->assertEquals('test context', $result['context']);
        $this->assertEquals('innovate', $result['strategy']);
        $this->assertTrue($result['driftEnabled']);
        $this->assertEquals('cycle123', $result['cycleId']);
    }

    public function testValidateEvolverRunWithInvalidStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InputValidator::validateEvolverRun(['strategy' => 'invalid']);
    }

    // -------------------------------------------------------------------------
    // evolver_solidify validation
    // -------------------------------------------------------------------------

    public function testValidateEvolverSolidifyDefaults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('summary');
        InputValidator::validateEvolverSolidify([]);
    }

    public function testValidateEvolverSolidifyWithValidParams(): void
    {
        $result = InputValidator::validateEvolverSolidify([
            'intent' => 'repair',
            'summary' => 'Test summary',
            'signals' => ['signal1'],
            'blastRadius' => ['files' => 1, 'lines' => 10],
        ]);

        $this->assertEquals('repair', $result['intent']);
        $this->assertEquals('Test summary', $result['summary']);
    }

    public function testValidateEvolverSolidifyWithInvalidIntent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InputValidator::validateEvolverSolidify([
            'intent' => 'invalid',
            'summary' => 'Test',
        ]);
    }

    // -------------------------------------------------------------------------
    // evolver_extract_signals validation
    // -------------------------------------------------------------------------

    public function testValidateEvolverExtractSignalsDefaults(): void
    {
        $result = InputValidator::validateEvolverExtractSignals([]);

        $this->assertEquals('', $result['logContent']);
        $this->assertTrue($result['includeHistory']);
    }

    public function testValidateEvolverExtractSignalsWithContext(): void
    {
        $result = InputValidator::validateEvolverExtractSignals([
            'logContent' => 'log content',
            'includeHistory' => false,
        ]);

        $this->assertEquals('log content', $result['logContent']);
        $this->assertFalse($result['includeHistory']);
    }

    // -------------------------------------------------------------------------
    // evolver_list_genes validation
    // -------------------------------------------------------------------------

    public function testValidateEvolverListGenesDefaults(): void
    {
        $result = InputValidator::validateEvolverListGenes([]);

        $this->assertNull($result['category']);
    }

    public function testValidateEvolverListGenesWithCategory(): void
    {
        $result = InputValidator::validateEvolverListGenes(['category' => 'repair']);

        $this->assertEquals('repair', $result['category']);
    }

    public function testValidateEvolverListGenesWithInvalidCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InputValidator::validateEvolverListGenes(['category' => 'invalid']);
    }

    // -------------------------------------------------------------------------
    // evolver_list_capsules validation
    // -------------------------------------------------------------------------

    public function testValidateEvolverListCapsulesDefaults(): void
    {
        $result = InputValidator::validateEvolverListCapsules([]);

        $this->assertEquals(20, $result['limit']);
    }

    public function testValidateEvolverListCapsulesWithLimit(): void
    {
        $result = InputValidator::validateEvolverListCapsules(['limit' => 50]);

        $this->assertEquals(50, $result['limit']);
    }

    public function testValidateEvolverListCapsulesWithLimitTooHigh(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InputValidator::validateEvolverListCapsules(['limit' => 200]);
    }

    // -------------------------------------------------------------------------
    // evolver_delete_gene validation
    // -------------------------------------------------------------------------

    public function testValidateEvolverDeleteGeneRequiresGeneId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InputValidator::validateEvolverDeleteGene([]);
    }

    public function testValidateEvolverDeleteGeneWithValidId(): void
    {
        $result = InputValidator::validateEvolverDeleteGene(['geneId' => 'gene_test']);

        $this->assertEquals('gene_test', $result['geneId']);
    }

    // -------------------------------------------------------------------------
    // evolver_upsert_gene validation
    // -------------------------------------------------------------------------

    public function testValidateEvolverUpsertGeneRequiresGene(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InputValidator::validateEvolverUpsertGene([]);
    }

    public function testValidateEvolverUpsertGeneWithValidGene(): void
    {
        $result = InputValidator::validateEvolverUpsertGene([
            'gene' => [
                'id' => 'gene_test',
                'category' => 'repair',
            ],
        ]);

        $this->assertEquals('gene_test', $result['gene']['id']);
    }

    public function testValidateEvolverUpsertGeneRequiresGeneId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InputValidator::validateEvolverUpsertGene(['gene' => ['category' => 'repair']]);
    }

    // -------------------------------------------------------------------------
    // Path sanitization
    // -------------------------------------------------------------------------

    public function testSanitizePathRemovesNullBytes(): void
    {
        $path = "file\0name.txt";
        $result = InputValidator::sanitizePath($path);

        $this->assertEquals('filename.txt', $result);
    }

    public function testSanitizePathRemovesTraversal(): void
    {
        $path = '../../../etc/passwd';
        $result = InputValidator::sanitizePath($path);

        $this->assertStringNotContainsString('..', $result);
    }

    public function testSanitizePathNormalizesSeparators(): void
    {
        $path = 'path\\to\\file.txt';
        $result = InputValidator::sanitizePath($path);

        $this->assertEquals('path/to/file.txt', $result);
    }
}
