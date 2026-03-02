<?php

declare(strict_types=1);

namespace Evolver;

/**
 * GEP Protocol Validator.
 * Validates GEP (Gene Expression Protocol) output format compliance.
 */
final class GepValidator
{
    private const REQUIRED_FIELDS_MUTATION = ['type', 'description', 'risk_level', 'rationale'];
    private const REQUIRED_FIELDS_PERSONALITY = ['type', 'rigor', 'creativity', 'verbosity', 'risk_tolerance', 'obedience'];
    private const REQUIRED_FIELDS_EVENT = ['type', 'id', 'intent', 'signals', 'parent_id', 'genes_used', 'blast_radius'];
    private const REQUIRED_FIELDS_GENE = ['type', 'id', 'category', 'signals_match', 'prompt_template'];
    private const REQUIRED_FIELDS_CAPSULE = ['type', 'id', 'trigger', 'gene', 'summary', 'confidence', 'blast_radius'];

    /**
     * Validate GEP output (5 objects in order).
     */
    public function validateGepOutput(string $output): array
    {
        $result = [
            'valid' => false,
            'objects' => [],
            'errors' => [],
            'warnings' => [],
        ];

        // Try to parse the output as 5 JSON objects
        $objects = $this->parseGepObjects($output);

        if (count($objects) < 5) {
            $result['errors'][] = 'Expected 5 GEP objects, found ' . count($objects);
            return $result;
        }

        // Validate each object in order
        $validationResults = [
            $this->validateMutation($objects[0]),
            $this->validatePersonalityState($objects[1]),
            $this->validateEvolutionEvent($objects[2]),
            $this->validateGene($objects[3]),
            $this->validateCapsule($objects[4]),
        ];

        $typeOrder = ['Mutation', 'PersonalityState', 'EvolutionEvent', 'Gene', 'Capsule'];

        foreach ($validationResults as $i => $vr) {
            $result['objects'][$typeOrder[$i]] = [
                'valid' => $vr['valid'],
                'errors' => $vr['errors'] ?? [],
                'warnings' => $vr['warnings'] ?? [],
            ];

            if (!$vr['valid']) {
                $result['errors'] = array_merge($result['errors'], $vr['errors']);
            }
            $result['warnings'] = array_merge($result['warnings'], $vr['warnings'] ?? []);
        }

        $result['valid'] = empty($result['errors']);

        return $result;
    }

    /**
     * Parse GEP objects from output.
     */
    public function parseGepObjects(string $output): array
    {
        $objects = [];

        // Try to find JSON objects in the output
        $jsonObjects = [];
        if (preg_match_all('/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $output, $matches)) {
            foreach ($matches[0] as $match) {
                $decoded = json_decode($match, true);
                if (is_array($decoded) && isset($decoded['type'])) {
                    $jsonObjects[] = $decoded;
                }
            }
        }

        // If no objects found, try line by line
        if (empty($jsonObjects)) {
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if (str_starts_with($line, '{') && str_ends_with($line, '}')) {
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $jsonObjects[] = $decoded;
                    }
                }
            }
        }

        // Also try to parse directly (might be concatenated JSON)
        if (empty($jsonObjects)) {
            $decoded = json_decode('[' . $output . ']', true);
            if (is_array($decoded)) {
                $jsonObjects = array_filter($decoded, fn($d) => is_array($d) && isset($d['type']));
            }
        }

        return array_values($jsonObjects);
    }

    /**
     * Validate Mutation object.
     */
    public function validateMutation(array $obj): array
    {
        return $this->validateObject($obj, 'Mutation', self::REQUIRED_FIELDS_MUTATION);
    }

    /**
     * Validate PersonalityState object.
     */
    public function validatePersonalityState(array $obj): array
    {
        $result = $this->validateObject($obj, 'PersonalityState', self::REQUIRED_FIELDS_PERSONALITY);

        if ($result['valid']) {
            // Additional validation
            $warnings = $result['warnings'] ?? [];

            foreach (['rigor', 'creativity', 'verbosity', 'risk_tolerance', 'obedience'] as $field) {
                $value = $obj[$field] ?? null;
                if ($value !== null && ($value < 0 || $value > 1)) {
                    $warnings[] = "{$field} should be between 0 and 1, got {$value}";
                }
            }

            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Validate EvolutionEvent object.
     */
    public function validateEvolutionEvent(array $obj): array
    {
        $result = $this->validateObject($obj, 'EvolutionEvent', self::REQUIRED_FIELDS_EVENT);

        if ($result['valid']) {
            $warnings = $result['warnings'] ?? [];

            // Validate blast_radius
            $blastRadius = $obj['blast_radius'] ?? [];
            if (isset($blastRadius['files']) && $blastRadius['files'] > 60) {
                $warnings[] = 'blast_radius.files exceeds recommended limit of 60';
            }
            if (isset($blastRadius['lines']) && $blastRadius['lines'] > 20000) {
                $warnings[] = 'blast_radius.lines exceeds recommended limit of 20000';
            }

            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Validate Gene object.
     */
    public function validateGene(array $obj): array
    {
        $result = $this->validateObject($obj, 'Gene', self::REQUIRED_FIELDS_GENE);

        if ($result['valid']) {
            $warnings = $result['warnings'] ?? [];

            // Check for asset_id
            if (!isset($obj['asset_id'])) {
                $warnings[] = 'Gene should have asset_id for content-addressable storage';
            }

            // Check for constraints
            if (!isset($obj['constraints'])) {
                $warnings[] = 'Gene should have constraints for safety';
            }

            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Validate Capsule object.
     */
    public function validateCapsule(array $obj): array
    {
        $result = $this->validateObject($obj, 'Capsule', self::REQUIRED_FIELDS_CAPSULE);

        if ($result['valid']) {
            $warnings = $result['warnings'] ?? [];

            // Check confidence range
            if (isset($obj['confidence'])) {
                $confidence = $obj['confidence'];
                if ($confidence < 0 || $confidence > 1) {
                    $warnings[] = 'confidence should be between 0 and 1';
                }
            }

            // Check for outcome
            if (!isset($obj['outcome'])) {
                $warnings[] = 'Capsule should have outcome for validation';
            }

            // Check for asset_id
            if (!isset($obj['asset_id'])) {
                $warnings[] = 'Capsule should have asset_id for content-addressable storage';
            }

            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Validate a GEP object against required fields.
     */
    private function validateObject(array $obj, string $expectedType, array $requiredFields): array
    {
        $result = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
        ];

        // Check type
        if (!isset($obj['type']) || $obj['type'] !== $expectedType) {
            $result['valid'] = false;
            $result['errors'][] = "Expected type '{$expectedType}', got '" . ($obj['type'] ?? 'missing') . "'";
            return $result;
        }

        // Check required fields
        foreach ($requiredFields as $field) {
            if (!isset($obj[$field])) {
                $result['valid'] = false;
                $result['errors'][] = "Missing required field: {$field}";
            }
        }

        return $result;
    }

    /**
     * Validate asset_id computation.
     */
    public function validateAssetId(array $obj): array
    {
        if (!isset($obj['asset_id'])) {
            return [
                'valid' => false,
                'error' => 'asset_id is missing',
            ];
        }

        $computed = ContentHash::computeAssetId($obj);
        $claimed = $obj['asset_id'];

        if ($computed !== $claimed) {
            return [
                'valid' => false,
                'error' => 'asset_id mismatch',
                'claimed' => $claimed,
                'computed' => $computed,
            ];
        }

        return [
            'valid' => true,
            'asset_id' => $claimed,
        ];
    }

    /**
     * Get validation summary.
     */
    public function getSummary(array $result): string
    {
        $lines = [];

        if ($result['valid']) {
            $lines[] = '✅ GEP output is valid';
        } else {
            $lines[] = '❌ GEP output has errors';
        }

        foreach ($result['objects'] as $type => $objResult) {
            $status = $objResult['valid'] ? '✅' : '❌';
            $lines[] = "{$status} {$type}";

            foreach ($objResult['errors'] ?? [] as $error) {
                $lines[] = "   - {$error}";
            }
        }

        if (!empty($result['warnings'])) {
            $lines[] = '';
            $lines[] = '⚠️  Warnings:';
            foreach ($result['warnings'] as $warning) {
                $lines[] = "   - {$warning}";
            }
        }

        return implode("\n", $lines);
    }
}
