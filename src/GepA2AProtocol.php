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
     * Generate a unique message ID.
     */
    public static function generateMessageId(): string
    {
        return 'msg_' . time() . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Get or generate a stable node ID.
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
     * Build a base protocol message.
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
     * Build a hello message for node registration.
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
     * Build a publish message for a single asset.
     */
    public function buildPublish(array $asset, ?string $nodeId = null): array
    {
        if (!isset($asset['type']) || !isset($asset['id'])) {
            throw new \InvalidArgumentException('publish: asset must have type and id');
        }

        // Generate signature: HMAC-SHA256 of asset_id with node secret
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
     * Build a bundle publish message containing Gene + Capsule (+ optional EvolutionEvent).
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
     * Build a fetch message for requesting assets.
     */
    public function buildFetch(array $opts = []): array
    {
        $payload = [
            'asset_type' => $opts['assetType'] ?? null,
            'local_id' => $opts['localId'] ?? null,
            'content_hash' => $opts['contentHash'] ?? null,
            'signals' => $opts['signals'] ?? null,
        ];

        // Remove null values
        $payload = array_filter($payload, fn($v) => $v !== null);

        return $this->buildMessage('fetch', $payload, $opts['nodeId'] ?? null);
    }

    /**
     * Build a report message for validation results.
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
     * Build a decision message for asset acceptance/rejection.
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
     * Build a revoke message for withdrawing assets.
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
     * Validate a protocol message.
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
     * Build a heartbeat message.
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
}

/**
 * Helper function to sort array values (for signature input).
 */
function array_sort(array $arr): array
{
    sort($arr, SORT_STRING);
    return $arr;
}
