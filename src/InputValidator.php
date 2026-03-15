<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Input validation for MCP tool parameters.
 * Provides type checking, range validation, and security filters.
 */
final class InputValidator
{
    /** Maximum allowed string length for input fields */
    private const MAX_STRING_LENGTH = 100000;
    
    /** Maximum allowed array size */
    private const MAX_ARRAY_SIZE = 1000;
    
    /** Maximum allowed recursion depth for nested arrays */
    private const MAX_RECURSION_DEPTH = 10;

    /**
     * Validate evolver_run tool parameters.
     */
    public static function validateEvolverRun(array $args): array
    {
        $validated = [];
        
        // context: optional string
        $validated['context'] = self::validateString($args['context'] ?? '', 'context', 0, self::MAX_STRING_LENGTH);
        
        // strategy: optional enum
        $validated['strategy'] = self::validateEnum(
            $args['strategy'] ?? 'balanced',
            'strategy',
            ['balanced', 'innovate', 'harden', 'repair-only']
        );
        
        // driftEnabled: optional bool
        $validated['driftEnabled'] = self::validateBool($args['driftEnabled'] ?? false, 'driftEnabled');
        
        // cycleId: optional string or null
        $validated['cycleId'] = isset($args['cycleId']) 
            ? self::validateString((string)$args['cycleId'], 'cycleId', 1, 64)
            : null;
        
        return $validated;
    }

    /**
     * Validate evolver_solidify tool parameters.
     */
    public static function validateEvolverSolidify(array $args): array
    {
        $validated = [];
        
        // intent: required enum
        $validated['intent'] = self::validateEnum(
            $args['intent'] ?? 'repair',
            'intent',
            ['repair', 'optimize', 'innovate']
        );
        
        // summary: required string
        $validated['summary'] = self::validateString($args['summary'] ?? '', 'summary', 1, 1000);
        
        // signals: optional string array
        $validated['signals'] = self::validateStringArray($args['signals'] ?? [], 'signals', 0, 100);
        
        // blastRadius: optional object with files and lines
        $validated['blastRadius'] = self::validateBlastRadius($args['blastRadius'] ?? ['files' => 0, 'lines' => 0]);
        
        // modifiedFiles: optional string array
        $validated['modifiedFiles'] = self::validateStringArray($args['modifiedFiles'] ?? [], 'modifiedFiles', 0, 100);
        
        // gene: optional object
        $validated['gene'] = isset($args['gene']) 
            ? self::validateGene($args['gene'])
            : null;
        
        // capsule: optional object
        if (isset($args['capsule']) && !is_array($args['capsule'])) {
            throw new \InvalidArgumentException('capsule must be an object');
        }
        $validated['capsule'] = isset($args['capsule'])
            ? self::validateArray($args['capsule'], 'capsule', 0, self::MAX_ARRAY_SIZE)
            : null;
        
        // dryRun: optional bool
        $validated['dryRun'] = self::validateBool($args['dryRun'] ?? false, 'dryRun');
        
        // approved: optional bool or null
        $validated['approved'] = isset($args['approved']) 
            ? self::validateBool($args['approved'], 'approved')
            : null;
        
        return $validated;
    }

    /**
     * Validate evolver_extract_signals tool parameters.
     */
    public static function validateEvolverExtractSignals(array $args): array
    {
        $validated = [];
        
        // logContent: optional string
        $validated['logContent'] = self::validateString($args['logContent'] ?? '', 'logContent', 0, self::MAX_STRING_LENGTH);
        
        // context: optional string (falls back to logContent)
        $validated['context'] = isset($args['context'])
            ? self::validateString($args['context'], 'context', 0, self::MAX_STRING_LENGTH)
            : $validated['logContent'];
        
        // includeHistory: optional bool
        $validated['includeHistory'] = self::validateBool($args['includeHistory'] ?? true, 'includeHistory');
        
        return $validated;
    }

    /**
     * Validate evolver_list_genes tool parameters.
     */
    public static function validateEvolverListGenes(array $args): array
    {
        $validated = [];
        
        // category: optional enum
        $validated['category'] = isset($args['category'])
            ? self::validateEnum($args['category'], 'category', ['repair', 'optimize', 'innovate'])
            : null;
        
        return $validated;
    }

    /**
     * Validate evolver_list_capsules tool parameters.
     */
    public static function validateEvolverListCapsules(array $args): array
    {
        $validated = [];
        
        // limit: optional int (max 100)
        $validated['limit'] = self::validateInt($args['limit'] ?? 20, 'limit', 1, 100);
        
        return $validated;
    }

    /**
     * Validate evolver_list_events tool parameters.
     */
    public static function validateEvolverListEvents(array $args): array
    {
        $validated = [];
        
        // limit: optional int (max 100)
        $validated['limit'] = self::validateInt($args['limit'] ?? 20, 'limit', 1, 100);
        
        return $validated;
    }

    /**
     * Validate evolver_metrics tool parameters (E1 后馈指标).
     */
    public static function validateEvolverMetrics(array $args): array
    {
        $validated = [];
        $validated['limit'] = self::validateInt($args['limit'] ?? 50, 'limit', 1, 500);
        return $validated;
    }

    /**
     * Validate evolver_upsert_gene tool parameters.
     */
    public static function validateEvolverUpsertGene(array $args): array
    {
        $validated = [];
        
        // gene: required array with id
        if (!isset($args['gene']) || !is_array($args['gene'])) {
            throw new \InvalidArgumentException('gene is required and must be an object');
        }
        
        $validated['gene'] = self::validateGene($args['gene'], true);
        
        return $validated;
    }

    /**
     * Validate evolver_delete_gene tool parameters.
     */
    public static function validateEvolverDeleteGene(array $args): array
    {
        $validated = [];
        
        // geneId: required non-empty string
        $validated['geneId'] = self::validateString($args['geneId'] ?? '', 'geneId', 1, 256);
        
        return $validated;
    }

    /**
     * Validate evolver_recall tool parameters (memory retrieval).
     */
    public static function validateEvolverRecall(array $args): array
    {
        $validated = [];
        
        // query: required string
        $validated['query'] = self::validateString($args['query'] ?? '', 'query', 1, 10000);
        
        // limit: optional int (max 50)
        $validated['limit'] = self::validateInt($args['limit'] ?? 10, 'limit', 1, 50);
        
        // scope: optional string
        $validated['scope'] = isset($args['scope'])
            ? self::validateString($args['scope'], 'scope', 1, 256)
            : null;
        
        // category: optional string
        $validated['category'] = isset($args['category'])
            ? self::validateString($args['category'], 'category', 1, 64)
            : null;
        
        // minScore: optional float
        $validated['minScore'] = isset($args['minScore'])
            ? self::validateFloat($args['minScore'], 'minScore', 0.0, 1.0)
            : 0.3;
        
        return $validated;
    }

    /**
     * Validate evolver_remember tool parameters (memory storage).
     */
    public static function validateEvolverRemember(array $args): array
    {
        $validated = [];
        
        // text: required string
        $validated['text'] = self::validateString($args['text'] ?? '', 'text', 1, self::MAX_STRING_LENGTH);
        
        // type: required enum (gene, capsule, event)
        $validated['type'] = self::validateEnum(
            $args['type'] ?? 'capsule',
            'type',
            ['gene', 'capsule', 'event']
        );
        
        // id: optional string (will generate if not provided)
        $validated['id'] = isset($args['id'])
            ? self::validateString($args['id'], 'id', 1, 256)
            : null;
        
        // importance: optional float
        $validated['importance'] = isset($args['importance'])
            ? self::validateFloat($args['importance'], 'importance', 0.0, 1.0)
            : 0.7;
        
        // category: optional string
        $validated['category'] = isset($args['category'])
            ? self::validateString($args['category'], 'category', 1, 64)
            : null;
        
        // scope: optional string
        $validated['scope'] = isset($args['scope'])
            ? self::validateString($args['scope'], 'scope', 1, 256)
            : null;
        
        // metadata: optional array
        $validated['metadata'] = isset($args['metadata'])
            ? self::validateArray($args['metadata'], 'metadata', 0, self::MAX_ARRAY_SIZE)
            : [];
        
        return $validated;
    }

    /**
     * Validate evolver_decay_status tool parameters.
     */
    public static function validateEvolverDecayStatus(array $args): array
    {
        $validated = [];
        
        // id: optional string (if not provided, returns all stats)
        $validated['id'] = isset($args['id'])
            ? self::validateString($args['id'], 'id', 1, 256)
            : null;
        
        // tier: optional enum
        $validated['tier'] = isset($args['tier'])
            ? self::validateEnum($args['tier'], 'tier', ['core', 'working', 'peripheral'])
            : null;
        
        // includePrunable: optional bool
        $validated['includePrunable'] = self::validateBool($args['includePrunable'] ?? false, 'includePrunable');
        
        return $validated;
    }

    // -------------------------------------------------------------------------
    // Generic validation helpers
    // -------------------------------------------------------------------------

    /**
     * Validate a string value.
     */
    private static function validateString(mixed $value, string $field, int $minLength, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} must be a string");
        }
        
        $length = strlen($value);
        if ($length < $minLength) {
            throw new \InvalidArgumentException("{$field} must be at least {$minLength} characters");
        }
        if ($length > $maxLength) {
            throw new \InvalidArgumentException("{$field} must be at most {$maxLength} characters");
        }
        
        // Check for null bytes
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException("{$field} contains invalid null bytes");
        }
        
        return $value;
    }

    /**
     * Validate an integer value.
     */
    private static function validateInt(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_int($value) && (!is_numeric($value) || (float)$value !== (int)(float)$value)) {
            throw new \InvalidArgumentException("{$field} must be an integer");
        }
        
        $intValue = (int)$value;
        if ($intValue < $min) {
            throw new \InvalidArgumentException("{$field} must be at least {$min}");
        }
        if ($intValue > $max) {
            throw new \InvalidArgumentException("{$field} must be at most {$max}");
        }
        
        return $intValue;
    }

    /**
     * Validate a boolean value.
     */
    private static function validateBool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$field} must be a boolean");
        }
        return $value;
    }

    /**
     * Validate a float value.
     */
    private static function validateFloat(mixed $value, string $field, float $min, float $max): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("{$field} must be a number");
        }
        
        $floatValue = (float)$value;
        if ($floatValue < $min) {
            throw new \InvalidArgumentException("{$field} must be at least {$min}");
        }
        if ($floatValue > $max) {
            throw new \InvalidArgumentException("{$field} must be at most {$max}");
        }
        
        return $floatValue;
    }

    /**
     * Validate an enum value.
     */
    private static function validateEnum(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$field} must be a string");
        }
        
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("{$field} must be one of: " . implode(', ', $allowed));
        }
        
        return $value;
    }

    /**
     * Validate an array value.
     */
    private static function validateArray(mixed $value, string $field, int $minSize, int $maxSize): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("{$field} must be an array");
        }
        
        $size = count($value);
        if ($size < $minSize) {
            throw new \InvalidArgumentException("{$field} must have at least {$minSize} elements");
        }
        if ($size > $maxSize) {
            throw new \InvalidArgumentException("{$field} must have at most {$maxSize} elements");
        }
        
        return $value;
    }

    /**
     * Validate a string array.
     */
    private static function validateStringArray(mixed $value, string $field, int $minSize, int $maxSize): array
    {
        $array = self::validateArray($value, $field, $minSize, $maxSize);
        
        foreach ($array as $i => $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException("{$field}[{$i}] must be a string");
            }
        }
        
        return $array;
    }

    /**
     * Validate blast radius structure.
     */
    private static function validateBlastRadius(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('blastRadius must be an array');
        }
        
        $validated = [
            'files' => 0,
            'lines' => 0,
        ];
        
        if (isset($value['files'])) {
            $validated['files'] = self::validateInt($value['files'], 'blastRadius.files', 0, 100000);
        }
        
        if (isset($value['lines'])) {
            $validated['lines'] = self::validateInt($value['lines'], 'blastRadius.lines', 0, 10000000);
        }
        
        return $validated;
    }

    /**
     * Validate gene structure.
     */
    private static function validateGene(mixed $value, bool $requireId = false): ?array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('gene must be an object');
        }
        
        if ($requireId && (!isset($value['id']) || !is_string($value['id']) || $value['id'] === '')) {
            throw new \InvalidArgumentException('gene.id is required and must be a non-empty string');
        }
        
        // Validate gene id if present
        if (isset($value['id'])) {
            $value['id'] = self::validateString($value['id'], 'gene.id', 1, 256);
        }
        
        // Validate category if present
        if (isset($value['category'])) {
            $value['category'] = self::validateEnum($value['category'], 'gene.category', ['repair', 'optimize', 'innovate']);
        }
        
        // Validate signals_match if present
        if (isset($value['signals_match'])) {
            $value['signals_match'] = self::validateStringArray($value['signals_match'], 'gene.signals_match', 0, 100);
        }
        
        return $value;
    }

    /**
     * Sanitize a file path to prevent path traversal attacks.
     */
    public static function sanitizePath(string $path): string
    {
        // Remove null bytes
        $path = str_replace("\0", '', $path);
        
        // Normalize path separators
        $path = str_replace('\\', '/', $path);
        
        // Remove .. sequences to prevent path traversal
        $parts = explode('/', $path);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                // Ignore or throw exception
                continue;
            }
            if ($part !== '' && $part !== '.') {
                $safeParts[] = $part;
            }
        }
        
        return implode('/', $safeParts);
    }
}
