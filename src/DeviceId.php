<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Stable device identifier for node identity.
 *
 * Generates a hardware-based fingerprint that persists across directory changes,
 * reboots, and evolver upgrades. Used by getNodeId() and env_fingerprint.
 *
 * Priority chain:
 *   1. EVOMAP_DEVICE_ID env var        (explicit override, recommended for containers)
 *   2. ~/.evomap/device_id file        (persisted from previous run)
 *   3. <project>/.evomap_device_id     (fallback persist path for containers w/o $HOME)
 *   4. /etc/machine-id                 (Linux, set at OS install)
 *   5. IOPlatformUUID                  (macOS hardware UUID)
 *   6. Docker/OCI container ID         (from /proc/self/cgroup or /proc/self/mountinfo)
 *   7. hostname + MAC addresses        (network-based fallback)
 *   8. random 128-bit hex              (last resort, persisted immediately)
 *
 * PHP port of deviceId.js from EvoMap/evolver.
 */
final class DeviceId
{
    private const DEVICE_ID_RE = '/^[a-f0-9]{16,64}$/';

    private static ?string $cachedDeviceId = null;

    /**
     * Get the stable device ID.
     */
    public static function getDeviceId(): string
    {
        if (self::$cachedDeviceId !== null) {
            return self::$cachedDeviceId;
        }

        // 1. Env var override (validated)
        $envId = getenv('EVOMAP_DEVICE_ID');
        if ($envId !== false && $envId !== '') {
            $envId = strtolower(trim($envId));
            if (preg_match(self::DEVICE_ID_RE, $envId)) {
                self::$cachedDeviceId = $envId;
                return self::$cachedDeviceId;
            }
        }

        // 2. Previously persisted (checks both ~/.evomap/ and project-local)
        $persisted = self::loadPersistedDeviceId();
        if ($persisted !== null) {
            self::$cachedDeviceId = $persisted;
            return self::$cachedDeviceId;
        }

        // 3. Generate from hardware / container metadata and persist
        $inContainer = self::isContainer();
        $generated = self::generateDeviceId();
        self::persistDeviceId($generated);
        self::$cachedDeviceId = $generated;

        if ($inContainer && !$envId) {
            error_log(
                '[evolver] NOTE: running in a container without EVOMAP_DEVICE_ID.' .
                ' A device_id was auto-generated and persisted, but for guaranteed' .
                ' cross-restart stability, set EVOMAP_DEVICE_ID as an env var' .
                ' or mount a persistent volume at ~/.evomap/'
            );
        }

        return self::$cachedDeviceId;
    }

    /**
     * Check if running inside a container.
     */
    public static function isContainer(): bool
    {
        // Check for Docker
        if (file_exists('/.dockerenv')) {
            return true;
        }

        // Check cgroup for container signatures
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if (preg_match('/docker|kubepods|containerd|cri-o|lxc|ecs/i', $cgroup)) {
                return true;
            }
        }

        // Check for Podman
        if (file_exists('/run/.containerenv')) {
            return true;
        }

        return false;
    }

    /**
     * Read machine ID from /etc/machine-id or macOS IOPlatformUUID.
     */
    private static function readMachineId(): ?string
    {
        // Linux machine-id
        if (file_exists('/etc/machine-id')) {
            $mid = trim(file_get_contents('/etc/machine-id'));
            if (!empty($mid) && strlen($mid) >= 16) {
                return $mid;
            }
        }

        // macOS IOPlatformUUID
        if (PHP_OS_FAMILY === 'Darwin') {
            $output = [];
            $returnCode = 0;
            @exec(
                'ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null',
                $output,
                $returnCode
            );
            if ($returnCode === 0 && !empty($output)) {
                $raw = implode("\n", $output);
                if (preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', $raw, $match)) {
                    return $match[1];
                }
            }
        }

        return null;
    }

    /**
     * Extract Docker/OCI container ID from cgroup or mountinfo.
     */
    private static function readContainerId(): ?string
    {
        // Method 1: /proc/self/cgroup (works for cgroup v1 and most Docker setups)
        if (file_exists('/proc/self/cgroup')) {
            $cgroup = file_get_contents('/proc/self/cgroup');
            if (preg_match('/[a-f0-9]{64}/', $cgroup, $match)) {
                return $match[0];
            }
        }

        // Method 2: /proc/self/mountinfo (works for cgroup v2 / containerd)
        if (file_exists('/proc/self/mountinfo')) {
            $mountinfo = file_get_contents('/proc/self/mountinfo');
            if (preg_match('/[a-f0-9]{64}/', $mountinfo, $match)) {
                return $match[0];
            }
        }

        // Method 3: hostname in Docker defaults to short container ID (12 hex chars)
        if (self::isContainer()) {
            $hostname = gethostname();
            if ($hostname !== false && preg_match('/^[a-f0-9]{12,64}$/', $hostname)) {
                return $hostname;
            }
        }

        return null;
    }

    /**
     * Get MAC addresses from network interfaces.
     */
    private static function getMacAddresses(): array
    {
        $macs = [];

        // Try using netstat or ifconfig
        $output = [];
        $returnCode = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            @exec('getmac /v /fo csv 2>nul', $output, $returnCode);
            if ($returnCode === 0) {
                foreach ($output as $line) {
                    if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})/', $line, $match)) {
                        $mac = strtoupper(str_replace('-', ':', $match[0]));
                        if ($mac !== '00:00:00:00:00:00') {
                            $macs[] = $mac;
                        }
                    }
                }
            }
        } else {
            // Linux/macOS
            if (file_exists('/sys/class/net')) {
                $interfaces = scandir('/sys/class/net');
                foreach ($interfaces as $iface) {
                    if ($iface === '.' || $iface === '..' || $iface === 'lo') {
                        continue;
                    }
                    $addrFile = '/sys/class/net/' . $iface . '/address';
                    if (file_exists($addrFile)) {
                        $mac = trim(file_get_contents($addrFile));
                        if (!empty($mac) && $mac !== '00:00:00:00:00:00') {
                            $macs[] = strtoupper($mac);
                        }
                    }
                }
            }

            // Fallback: use ifconfig/ip
            if (empty($macs)) {
                @exec('ip link 2>/dev/null || ifconfig 2>/dev/null', $output, $returnCode);
                if ($returnCode === 0) {
                    foreach ($output as $line) {
                        if (preg_match('/([0-9a-f]{2}[:-]){5}([0-9a-f]{2})/i', $line, $match)) {
                            $mac = strtoupper($match[0]);
                            if ($mac !== '00:00:00:00:00:00' && !in_array($mac, $macs)) {
                                $macs[] = $mac;
                            }
                        }
                    }
                }
            }
        }

        sort($macs);
        return $macs;
    }

    /**
     * Generate a device ID from hardware metadata.
     */
    private static function generateDeviceId(): string
    {
        $machineId = self::readMachineId();
        if ($machineId !== null) {
            return substr(hash('sha256', 'evomap:' . $machineId), 0, 32);
        }

        // Container ID: stable for the container's lifetime
        $containerId = self::readContainerId();
        if ($containerId !== null) {
            return substr(hash('sha256', 'evomap:container:' . $containerId), 0, 32);
        }

        // Network-based fallback
        $macs = self::getMacAddresses();
        if (!empty($macs)) {
            $hostname = gethostname() ?: 'unknown';
            $raw = $hostname . '|' . implode(',', $macs);
            return substr(hash('sha256', 'evomap:' . $raw), 0, 32);
        }

        // Last resort: random
        return bin2hex(random_bytes(16));
    }

    /**
     * Persist device ID to file.
     */
    private static function persistDeviceId(string $id): void
    {
        // Try primary path (~/.evomap/device_id)
        $homeDir = self::getHomeDirectory();
        if ($homeDir !== null) {
            $deviceDir = $homeDir . '/.evomap';
            $deviceFile = $deviceDir . '/device_id';

            try {
                if (!is_dir($deviceDir)) {
                    @mkdir($deviceDir, 0700, true);
                }
                if (@file_put_contents($deviceFile, $id) !== false) {
                    @chmod($deviceFile, 0600);
                    return;
                }
            } catch (\Throwable $e) {}
        }

        // Fallback: project-local file
        $localFile = self::getProjectDeviceIdPath();
        try {
            $localDir = dirname($localFile);
            if (!is_dir($localDir)) {
                @mkdir($localDir, 0755, true);
            }
            @file_put_contents($localFile, $id);
            @chmod($localFile, 0600);
        } catch (\Throwable $e) {
            error_log(
                '[evolver] WARN: failed to persist device_id to ' . $deviceFile .
                ' or ' . $localFile .
                ' -- node identity may change on restart.' .
                ' Set EVOMAP_DEVICE_ID env var for stable identity in containers.'
            );
        }
    }

    /**
     * Load persisted device ID from file.
     */
    private static function loadPersistedDeviceId(): ?string
    {
        // Try primary path
        $homeDir = self::getHomeDirectory();
        if ($homeDir !== null) {
            $deviceFile = $homeDir . '/.evomap/device_id';
            if (file_exists($deviceFile)) {
                $id = trim(file_get_contents($deviceFile));
                if (!empty($id) && preg_match(self::DEVICE_ID_RE, $id)) {
                    return $id;
                }
            }
        }

        // Try project-local fallback
        $localFile = self::getProjectDeviceIdPath();
        if (file_exists($localFile)) {
            $id = trim(file_get_contents($localFile));
            if (!empty($id) && preg_match(self::DEVICE_ID_RE, $id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Get home directory cross-platform.
     */
    private static function getHomeDirectory(): ?string
    {
        // Unix-like systems
        $home = getenv('HOME');
        if ($home !== false && !empty($home)) {
            return $home;
        }

        // Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $homeDrive = getenv('HOMEDRIVE');
            $homePath = getenv('HOMEPATH');
            if ($homeDrive !== false && $homePath !== false) {
                return $homeDrive . $homePath;
            }
            $userProfile = getenv('USERPROFILE');
            if ($userProfile !== false) {
                return $userProfile;
            }
        }

        return null;
    }

    /**
     * Get project-local device ID file path.
     */
    private static function getProjectDeviceIdPath(): string
    {
        // Try to find project root by looking for composer.json or .git
        $dir = dirname(__DIR__); // src directory
        $maxLevels = 5;

        for ($i = 0; $i < $maxLevels; $i++) {
            if (file_exists($dir . '/composer.json') || is_dir($dir . '/.git')) {
                return $dir . '/.evomap_device_id';
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }

        // Fallback to current working directory
        return getcwd() . '/.evomap_device_id';
    }

    /**
     * Reset cached device ID (useful for testing).
     */
    public static function reset(): void
    {
        self::$cachedDeviceId = null;
    }
}
