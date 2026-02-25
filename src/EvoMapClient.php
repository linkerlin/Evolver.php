<?php

declare(strict_types=1);

namespace Evolver;

/**
 * HTTP client for EvoMap Hub integration.
 * Handles node registration, heartbeat, and asset synchronization.
 * 
 * PHP port inspired by a2aProtocol.js from EvoMap/evolver.
 */
final class EvoMapClient
{
    private string $hubUrl;
    private GepA2AProtocol $protocol;
    private ?string $lastError = null;
    
    // Heartbeat tracking
    private ?int $heartbeatStartedAt = null;
    private int $heartbeatConsecutiveFailures = 0;
    private int $heartbeatTotalSent = 0;
    private int $heartbeatTotalFailed = 0;

    public function __construct(?string $hubUrl = null)
    {
        $this->hubUrl = $hubUrl ?: $this->getHubUrlFromEnv();
        $this->protocol = new GepA2AProtocol();
    }

    /**
     * Get Hub URL from environment.
     */
    private function getHubUrlFromEnv(): string
    {
        return getenv('A2A_HUB_URL') 
            ?: getenv('EVOMAP_HUB_URL') 
            ?: 'https://evomap.ai';
    }

    /**
     * Check if Hub URL is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->hubUrl);
    }

    /**
     * Get the last error message.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Send hello message to register with the hub.
     */
    public function sendHello(array $capabilities = [], ?int $geneCount = null, ?int $capsuleCount = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/hello';
        $message = $this->protocol->buildHello([
            'capabilities' => $capabilities,
            'geneCount' => $geneCount,
            'capsuleCount' => $capsuleCount,
        ]);

        return $this->sendHttpPost($endpoint, $message);
    }

    /**
     * Send heartbeat to maintain connection.
     */
    public function sendHeartbeat(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/heartbeat';
        $uptimeMs = $this->heartbeatStartedAt ? (time() * 1000 - $this->heartbeatStartedAt) : 0;
        $message = $this->protocol->buildHeartbeat($uptimeMs);

        $this->heartbeatTotalSent++;
        $result = $this->sendHttpPost($endpoint, $message);

        if ($result['ok']) {
            $this->heartbeatConsecutiveFailures = 0;
            
            // Check for unknown_node response
            if (isset($result['response']['status']) && $result['response']['status'] === 'unknown_node') {
                error_log('[EvoMapClient] Node not registered on hub. Re-registering...');
                $helloResult = $this->sendHello();
                if ($helloResult['ok']) {
                    error_log('[EvoMapClient] Re-registered successfully.');
                    return ['ok' => true, 'response' => $result['response'], 'reregistered' => true];
                }
            }
        } else {
            $this->heartbeatConsecutiveFailures++;
            $this->heartbeatTotalFailed++;
            
            if ($this->heartbeatConsecutiveFailures === 3) {
                error_log('[EvoMapClient] 3 consecutive heartbeat failures. Network issue?');
            } elseif ($this->heartbeatConsecutiveFailures === 10) {
                error_log('[EvoMapClient] 10 consecutive heartbeat failures. Hub may be unreachable.');
            }
        }

        return $result;
    }

    /**
     * Publish an asset to the hub.
     */
    public function publishAsset(array $asset): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/publish';
        $message = $this->protocol->buildPublish($asset);

        return $this->sendHttpPost($endpoint, $message);
    }

    /**
     * Publish a bundle (Gene + Capsule + optional Event) to the hub.
     */
    public function publishBundle(array $gene, array $capsule, ?array $event = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/publish';
        $message = $this->protocol->buildPublishBundle($gene, $capsule, $event);

        return $this->sendHttpPost($endpoint, $message);
    }

    /**
     * Fetch assets from the hub.
     */
    public function fetchAssets(?string $assetType = null, ?string $localId = null, ?string $contentHash = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/fetch';
        $message = $this->protocol->buildFetch([
            'assetType' => $assetType,
            'localId' => $localId,
            'contentHash' => $contentHash,
        ]);

        $result = $this->sendHttpPost($endpoint, $message);
        
        if ($result['ok'] && isset($result['response']['payload']['results'])) {
            return [
                'ok' => true,
                'assets' => $result['response']['payload']['results'],
            ];
        }

        return $result;
    }

    /**
     * Search for assets by signals.
     */
    public function searchAssets(array $signals, int $limit = 20): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/fetch';
        $message = $this->protocol->buildFetch([
            'signals' => $signals,
        ]);
        $message['limit'] = $limit;

        $result = $this->sendHttpPost($endpoint, $message);
        
        if ($result['ok'] && isset($result['response']['payload']['results'])) {
            return [
                'ok' => true,
                'assets' => $result['response']['payload']['results'],
            ];
        }

        return $result;
    }

    /**
     * Report validation results for an asset.
     */
    public function reportValidation(string $assetId, array $validationReport): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/report';
        $message = $this->protocol->buildReport([
            'assetId' => $assetId,
            'validationReport' => $validationReport,
        ]);

        return $this->sendHttpPost($endpoint, $message);
    }

    /**
     * Send a decision (accept/reject/quarantine) for an asset.
     */
    public function sendDecision(string $decision, string $assetId, ?string $reason = null): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'no_hub_url'];
        }

        $endpoint = rtrim($this->hubUrl, '/') . '/a2a/decision';
        $message = $this->protocol->buildDecision($decision, [
            'assetId' => $assetId,
            'reason' => $reason,
        ]);

        return $this->sendHttpPost($endpoint, $message);
    }

    /**
     * Get heartbeat statistics.
     */
    public function getHeartbeatStats(): array
    {
        return [
            'running' => $this->heartbeatStartedAt !== null,
            'uptime_ms' => $this->heartbeatStartedAt ? (time() * 1000 - $this->heartbeatStartedAt) : 0,
            'total_sent' => $this->heartbeatTotalSent,
            'total_failed' => $this->heartbeatTotalFailed,
            'consecutive_failures' => $this->heartbeatConsecutiveFailures,
        ];
    }

    /**
     * Start heartbeat tracking.
     */
    public function startHeartbeatTracking(): void
    {
        $this->heartbeatStartedAt = time() * 1000;
    }

    /**
     * Get the node ID.
     */
    public function getNodeId(): string
    {
        return $this->protocol->getNodeId();
    }

    /**
     * Send HTTP POST request.
     */
    private function sendHttpPost(string $url, array $data): array
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->lastError = 'Curl error: ' . $error;
            return ['ok' => false, 'error' => $this->lastError];
        }

        if ($httpCode >= 400) {
            $this->lastError = 'HTTP error: ' . $httpCode;
            return ['ok' => false, 'error' => $this->lastError, 'http_code' => $httpCode];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && !empty($response)) {
            $this->lastError = 'JSON decode error';
            return ['ok' => false, 'error' => $this->lastError, 'raw_response' => $response];
        }

        return ['ok' => true, 'response' => $decoded ?? []];
    }
}
