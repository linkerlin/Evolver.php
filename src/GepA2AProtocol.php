<?php

declare(strict_types=1);

namespace Evolver;

/**
 * GEP A2A Protocol - Standard message types for agent-to-agent communication.
 * 
 * Protocol messages:
 *   hello    - capability advertisement and node discovery
 *   publish  - broadcast an eligible asset (Capsule/Gene)
 *   fetch    - request a specific asset by id or content hash
 *   report   - send a ValidationReport for a received asset
 *   decision - accept/reject/quarantine decision on a received asset
 *   revoke   - withdraw a previously published asset
 * 
 * PHP port of a2aProtocol.js from EvoMap/evolver.
 */
final class GepA2AProtocol
{
    public const PROTOCOL_NAME = 'gep-a2a';
    public const PROTOCOL_VERSION = '1.0.0';
    
    public const VALID_MESSAGE_TYPES = ['hello', 'publish', 'fetch', 'report', 'decision', 'revoke'];
    
    private ?string $cachedNodeId = null;

    /**
     * G生成一个 unique message ID.
     */
    public static function generateMessageId(): string
    {
        return 'msg_' . time() . '_' . bin2hex(random_bytes(4));
    }

    /**
     * 获取或 generate a stable node ID.
     * The node ID is derived from device ID, agent name, and current working directory.
     */
    public function getNodeId(): string
    {
        if ($this->cachedNodeId !== null) {
            return $this->cachedNodeId;
        }

        $envNodeId = getenv('A2A_NODE_ID');
        if ($envNodeId !== false && $envNodeId !== '') {
            $this->cachedNodeId = (string) $envNodeId;
            return $this->cachedNodeId;
        }

        $deviceId = EnvFingerprint::getDeviceId();
        $agentName = getenv('AGENT_NAME') ?: 'default';
        $cwd = getcwd() ?: '';
        
        // Include cwd so multiple evolver instances in different directories
        // on the same machine get distinct nodeIds without manual config
        $raw = $deviceId . '|' . $agentName . '|' . $cwd;
        $hash = substr(hash('sha256', $raw), 0, 24);
        
        $this->cachedNodeId = 'node_' . $hash;
        return $this->cachedNodeId;
    }

    /**
     * 构建a base protocol message.
     */
    public function buildMessage(string $messageType, array $payload = [], ?string $senderId = null): array
    {
        if (!in_array($messageType, self::VALID_MESSAGE_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Invalid message type: ' . $messageType . '. Valid: ' . implode(', ', self::VALID_MESSAGE_TYPES)
            );
        }

        return [
            'protocol' => self::PROTOCOL_NAME,
            'protocol_version' => self::PROTOCOL_VERSION,
            'message_type' => $messageType,
            'message_id' => self::generateMessageId(),
            'sender_id' => $senderId ?? $this->getNodeId(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'payload' => $payload,
        ];
    }

    /**
     * 构建a hello message for node registration.
     */
    public function buildHello(array $opts = []): array
    {
        $capabilities = $opts['capabilities'] ?? [];
        $geneCount = $opts['geneCount'] ?? null;
        $capsuleCount = $opts['capsuleCount'] ?? null;

        $payload = [
            'capabilities' => $capabilities,
            'env_fingerprint' => EnvFingerprint::capture(),
        ];

        if ($geneCount !== null) {
            $payload['gene_count'] = (int) $geneCount;
        }
        if ($capsuleCount !== null) {
            $payload['capsule_count'] = (int) $capsuleCount;
        }

        return $this->buildMessage('hello', $payload, $opts['nodeId'] ?? null);
    }

    /**
     * 构建a publish message for a single asset.
     */
    public function buildPublish(array $asset, ?string $nodeId = null): array
    {
        if (!isset($asset['type']) || !isset($asset['id'])) {
            throw new \InvalidArgumentException('publish: asset must have type and id');
        }

        // G生成 signature: HMAC-SHA256 of asset_id with node secret
        $assetId = $asset['asset_id'] ?? ContentHash::computeAssetId($asset);
        $nodeSecret = getenv('A2A_NODE_SECRET') ?: $this->getNodeId();
        $signature = hash_hmac('sha256', $assetId, $nodeSecret);

        $payload = [
            'asset_type' => $asset['type'],
            'asset_id' => $assetId,
            'local_id' => $asset['id'],
            'asset' => $asset,
            'signature' => $signature,
        ];

        return $this->buildMessage('publish', $payload, $nodeId);
    }

    /**
     * 构建a bundle publish message containing Gene + Capsule (+ optional EvolutionEvent).
     * Hub requires payload.assets = [Gene, Capsule] since bundle enforcement was added.
     */
    public function buildPublishBundle(array $gene, array $capsule, ?array $event = null, array $opts = []): array
    {
        if (($gene['type'] ?? '') !== 'Gene' || !isset($gene['id'])) {
            throw new \InvalidArgumentException('publishBundle: gene must be a valid Gene with type and id');
        }
        if (($capsule['type'] ?? '') !== 'Capsule' || !isset($capsule['id'])) {
            throw new \InvalidArgumentException('publishBundle: capsule must be a valid Capsule with type and id');
        }

        $geneAssetId = $gene['asset_id'] ?? ContentHash::computeAssetId($gene);
        $capsuleAssetId = $capsule['asset_id'] ?? ContentHash::computeAssetId($capsule);
        
        $nodeSecret = getenv('A2A_NODE_SECRET') ?: $this->getNodeId();
        $signatureInput = implode('|', array_sort([$geneAssetId, $capsuleAssetId]));
        $signature = hash_hmac('sha256', $signatureInput, $nodeSecret);

        $assets = [$gene, $capsule];
        if ($event !== null && ($event['type'] ?? '') === 'EvolutionEvent') {
            $assets[] = $event;
        }

        $payload = [
            'assets' => $assets,
            'signature' => $signature,
        ];

        if (isset($opts['chainId']) && is_string($opts['chainId'])) {
            $payload['chain_id'] = $opts['chainId'];
        }

        return $this->buildMessage('publish', $payload, $opts['nodeId'] ?? null);
    }

    /**
     * 构建a fetch message for requesting assets.
     */
    public function buildFetch(array $opts = []): array
    {
        $payload = [
            'asset_type' => $opts['assetType'] ?? null,
            'local_id' => $opts['localId'] ?? null,
            'content_hash' => $opts['contentHash'] ?? null,
            'signals' => $opts['signals'] ?? null,
        ];

        // 移除null values
        $payload = array_filter($payload, fn($v) => $v !== null);

        return $this->buildMessage('fetch', $payload, $opts['nodeId'] ?? null);
    }

    /**
     * 构建a report message for validation results.
     */
    public function buildReport(array $opts): array
    {
        $payload = [
            'target_asset_id' => $opts['assetId'] ?? null,
            'target_local_id' => $opts['localId'] ?? null,
            'validation_report' => $opts['validationReport'] ?? null,
        ];

        return $this->buildMessage('report', array_filter($payload), $opts['nodeId'] ?? null);
    }

    /**
     * 构建a decision message for asset acceptance/rejection.
     */
    public function buildDecision(string $decision, array $opts): array
    {
        $validDecisions = ['accept', 'reject', 'quarantine'];
        if (!in_array($decision, $validDecisions, true)) {
            throw new \InvalidArgumentException('decision must be one of: ' . implode(', ', $validDecisions));
        }

        $payload = [
            'target_asset_id' => $opts['assetId'] ?? null,
            'target_local_id' => $opts['localId'] ?? null,
            'decision' => $decision,
            'reason' => $opts['reason'] ?? null,
        ];

        return $this->buildMessage('decision', array_filter($payload), $opts['nodeId'] ?? null);
    }

    /**
     * 构建a revoke message for withdrawing assets.
     */
    public function buildRevoke(array $opts): array
    {
        $payload = [
            'target_asset_id' => $opts['assetId'] ?? null,
            'target_local_id' => $opts['localId'] ?? null,
            'reason' => $opts['reason'] ?? null,
        ];

        return $this->buildMessage('revoke', array_filter($payload), $opts['nodeId'] ?? null);
    }

    /**
     * 验证 a protocol message.
     */
    public static function isValidProtocolMessage(array $msg): bool
    {
        if (empty($msg) || !is_array($msg)) {
            return false;
        }
        if (($msg['protocol'] ?? '') !== self::PROTOCOL_NAME) {
            return false;
        }
        if (!isset($msg['message_type']) || !in_array($msg['message_type'], self::VALID_MESSAGE_TYPES, true)) {
            return false;
        }
        if (empty($msg['message_id']) || !is_string($msg['message_id'])) {
            return false;
        }
        if (empty($msg['timestamp']) || !is_string($msg['timestamp'])) {
            return false;
        }
        return true;
    }

    /**
     * Extract asset from a protocol message or plain asset object.
     */
    public static function unwrapAssetFromMessage(array $input): ?array
    {
        // If it is a protocol message with a publish payload, extract the asset
        if (($input['protocol'] ?? '') === self::PROTOCOL_NAME && ($input['message_type'] ?? '') === 'publish') {
            $payload = $input['payload'] ?? [];
            if (!empty($payload['asset']) && is_array($payload['asset'])) {
                return $payload['asset'];
            }
            // Handle bundle format
            if (!empty($payload['assets']) && is_array($payload['assets'])) {
                return $payload['assets'][0] ?? null;
            }
            return null;
        }

        // If it is a plain asset (Gene/Capsule/EvolutionEvent), return as-is
        $assetTypes = ['Gene', 'Capsule', 'EvolutionEvent'];
        if (in_array($input['type'] ?? '', $assetTypes, true)) {
            return $input;
        }

        return null;
    }

    /**
     * 构建a heartbeat message.
     */
    public function buildHeartbeat(int $uptimeMs = 0): array
    {
        return [
            'node_id' => $this->getNodeId(),
            'sender_id' => $this->getNodeId(),
            'version' => self::PROTOCOL_VERSION,
            'uptime_ms' => $uptimeMs,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    // -------------------------------------------------------------------------
    // HTTP Transport Methods
    // -------------------------------------------------------------------------

    /**
     * Send a protocol message to the Hub via HTTP.
     *
     * @param array $message Protocol message to send
     * @param string|null $hubUrl Hub URL (defaults to env var)
     * @param int $timeout Request timeout in seconds
     * @return array{success: bool, status: int, body: array, error: string|null}
     */
    public function httpTransportSend(array $message, ?string $hubUrl = null, int $timeout = 10): array
    {
        $hubUrl = $hubUrl ?: (getenv('A2A_HUB_URL') ?: getenv('EVOMAP_HUB_URL') ?: 'https://evomap.ai');
        $url = rtrim($hubUrl, '/') . '/a2a/receive';

        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'status' => 0, 'body' => [], 'error' => 'Failed to initialize curl'];
        }

        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'status' => 0, 'body' => [], 'error' => $error];
        }

        $body = [];
        if (!empty($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status' => (int)$httpCode,
            'body' => $body,
            'error' => null,
        ];
    }

    /**
     * Receive/fetch messages from the Hub via HTTP.
     *
     * @param array $opts Fetch options
     * @param string|null $hubUrl Hub URL
     * @param int $timeout Request timeout
     * @return array{success: bool, status: int, body: array, error: string|null}
     */
    public function httpTransportReceive(array $opts = [], ?string $hubUrl = null, int $timeout = 10): array
    {
        $hubUrl = $hubUrl ?: (getenv('A2A_HUB_URL') ?: getenv('EVOMAP_HUB_URL') ?: 'https://evomap.ai');
        $url = rtrim($hubUrl, '/') . '/a2a/fetch';

        $message = $this->buildFetch($opts);

        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'status' => 0, 'body' => [], 'error' => 'Failed to initialize curl'];
        }

        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'status' => 0, 'body' => [], 'error' => $error];
        }

        $body = [];
        if (!empty($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'status' => (int)$httpCode,
            'body' => $body,
            'error' => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Heartbeat Mechanism
    // -------------------------------------------------------------------------

    private ?array $heartbeatStats = null;

    /**
     * Send a hello message to the Hub for registration.
     *
     * @param array $opts Hello options (capabilities, geneCount, capsuleCount)
     * @param string|null $hubUrl Hub URL
     * @return array{success: bool, status: int, body: array}
     */
    public function sendHelloToHub(array $opts = [], ?string $hubUrl = null): array
    {
        $message = $this->buildHello($opts);
        return $this->httpTransportSend($message, $hubUrl);
    }

    /**
     * Send a heartbeat to the Hub.
     *
     * @param string|null $hubUrl Hub URL
     * @return array{success: bool, status: int, body: array}
     */
    public function sendHeartbeat(?string $hubUrl = null): array
    {
        $stats = $this->getHeartbeatStats();
        $message = $this->buildHeartbeat($stats['uptime_ms']);
        $result = $this->httpTransportSend($message, $hubUrl, 5);

        // Update stats
        $this->heartbeatStats['last_heartbeat_at'] = time();
        $this->heartbeatStats['heartbeat_count']++;
        if ($result['success']) {
            $this->heartbeatStats['successful_heartbeats']++;
        } else {
            $this->heartbeatStats['failed_heartbeats']++;
        }

        return $result;
    }

    /**
     * Get heartbeat statistics.
     */
    public function getHeartbeatStats(): array
    {
        if ($this->heartbeatStats === null) {
            $this->heartbeatStats = [
                'started_at' => time(),
                'uptime_ms' => 0,
                'last_heartbeat_at' => null,
                'heartbeat_count' => 0,
                'successful_heartbeats' => 0,
                'failed_heartbeats' => 0,
            ];
        }

        $this->heartbeatStats['uptime_ms'] = (time() - $this->heartbeatStats['started_at']) * 1000;
        return $this->heartbeatStats;
    }

    /**
     * Start periodic heartbeat (non-blocking, returns immediately).
     * In PHP, this typically requires an external process manager or async library.
     * This method provides a simple interface that can be called periodically.
     *
     * @param int $intervalSeconds Heartbeat interval (default 60)
     */
    public function startHeartbeat(int $intervalSeconds = 60): void
    {
        // Initialize stats
        $this->heartbeatStats = [
            'started_at' => time(),
            'uptime_ms' => 0,
            'last_heartbeat_at' => null,
            'heartbeat_count' => 0,
            'successful_heartbeats' => 0,
            'failed_heartbeats' => 0,
            'interval_seconds' => $intervalSeconds,
        ];

        // Send initial heartbeat
        $this->sendHeartbeat();
    }

    /**
     * Check if heartbeat should be sent based on interval.
     */
    public function shouldSendHeartbeat(): bool
    {
        if ($this->heartbeatStats === null) {
            return true;
        }

        $interval = $this->heartbeatStats['interval_seconds'] ?? 60;
        $lastHeartbeat = $this->heartbeatStats['last_heartbeat_at'] ?? 0;

        return (time() - $lastHeartbeat) >= $interval;
    }
}

/**
 * Helper function to sort array values (for signature input).
 */
function array_sort(array $arr): array
{
    sort($arr, SORT_STRING);
    return $arr;
}
