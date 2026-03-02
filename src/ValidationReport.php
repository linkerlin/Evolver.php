<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Standardized ValidationReport type for GEP.
 * Machine-readable, self-contained, and interoperable.
 * Can be consumed by external Hubs or Judges for automated assessment.
 * PHP port of validationReport.js from EvoMap/evolver.
 */
final class ValidationReport
{
    /**
     * Build a standardized ValidationReport from raw validation results.
     *
     * @param array<string, mixed> $opts Options: geneId, commands, results, envFp, startedAt, finishedAt
     * @return array<string, mixed>
     */
    public static function buildValidationReport(array $opts): array
    {
        $geneId = $opts['geneId'] ?? null;
        $commands = $opts['commands'] ?? [];
        $results = $opts['results'] ?? [];
        $envFp = $opts['envFp'] ?? null;
        $startedAt = $opts['startedAt'] ?? null;
        $finishedAt = $opts['finishedAt'] ?? null;

        $env = $envFp ?? EnvFingerprint::capture();
        $resultsList = is_array($results) ? $results : [];
        $cmdsList = is_array($commands) && !empty($commands)
            ? $commands
            : array_map(fn($r) => $r['cmd'] ?? '', $resultsList);

        $overallOk = count($resultsList) > 0 && array_reduce($resultsList, function ($carry, $r) {
            return $carry && !empty($r['ok']);
        }, true);

        $durationMs = is_numeric($startedAt) && is_numeric($finishedAt)
            ? (int)$finishedAt - (int)$startedAt
            : null;

        $reportCommands = [];
        foreach ($cmdsList as $i => $cmd) {
            $r = $resultsList[$i] ?? [];
            $reportCommands[] = [
                'command' => (string)($cmd ?? ''),
                'ok' => !empty($r['ok']),
                'stdout' => substr((string)($r['out'] ?? $r['stdout'] ?? ''), 0, 4000),
                'stderr' => substr((string)($r['err'] ?? $r['stderr'] ?? ''), 0, 4000),
            ];
        }

        $report = [
            'type' => 'ValidationReport',
            'schema_version' => ContentHash::SCHEMA_VERSION,
            'id' => 'vr_' . time(),
            'gene_id' => $geneId,
            'env_fingerprint' => $env,
            'env_fingerprint_key' => EnvFingerprint::envFingerprintKey($env),
            'commands' => $reportCommands,
            'overall_ok' => $overallOk,
            'duration_ms' => $durationMs,
            'created_at' => date('c'),
        ];

        $report['asset_id'] = ContentHash::computeAssetId($report);
        return $report;
    }

    /**
     * Validate that an object is a well-formed ValidationReport.
     */
    public static function isValidValidationReport(mixed $obj): bool
    {
        if (!is_array($obj)) {
            return false;
        }
        if (($obj['type'] ?? null) !== 'ValidationReport') {
            return false;
        }
        if (!is_string($obj['id'] ?? null) || $obj['id'] === '') {
            return false;
        }
        if (!is_array($obj['commands'] ?? null)) {
            return false;
        }
        if (!is_bool($obj['overall_ok'] ?? null)) {
            return false;
        }
        return true;
    }
}
