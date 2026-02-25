<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Environment fingerprint capture for GEP assets.
 *
 * Records the runtime environment so that cross-environment diffusion
 * success rates (GDI) can be measured scientifically.
 *
 * PHP port of envFingerprint.js + deviceId.js from EvoMap/evolver.
 */
final class EnvFingerprint
{
    /** Device ID persist directory (mirrors ~/.evomap/ from Node.js) */
    private const DEVICE_ID_DIR = '.evomap';
    private const DEVICE_ID_FILE = 'device_id';

    /** Project-local fallback (for containers with ephemeral $HOME) */
    private const LOCAL_DEVICE_ID_FILENAME = '.evomap_device_id';

    /** Device ID regex: 16–64 lowercase hex chars */
    private const DEVICE_ID_RE = '/^[a-f0-9]{16,64}$/';

    /** Runtime-cached device ID to avoid repeated disk reads */
    private static ?string $cachedDeviceId = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Capture a structured environment fingerprint.
     *
     * Embedded into Capsules, EvolutionEvents, and ValidationReports.
     *
     * @return array{
     *   device_id: string,
     *   php_version: string,
     *   platform: string,
     *   arch: string,
     *   os_release: string,
     *   hostname: string,
     *   evolver_version: string|null,
     *   client: string,
     *   client_version: string|null,
     *   region: string|null,
     *   cwd: string,
     *   container: bool
     * }
     */
    public static function capture(): array
    {
        [$clientName, $clientVersion] = self::readComposerInfo();

        $region = self::readRegion();
        $hostname = gethostname();

        return [
            'device_id'      => self::getDeviceId(),
            'php_version'    => 'PHP/' . PHP_VERSION,
            'platform'       => self::getPlatform(),
            'arch'           => self::getArch(),
            'os_release'     => self::getOsRelease(),
            'hostname'       => substr(hash('sha256', (string)$hostname), 0, 12),
            'evolver_version' => $clientVersion,
            'client'         => $clientName ?? 'evolver-php',
            'client_version' => $clientVersion,
            'region'         => $region,
            'cwd'            => (string)(getcwd() ?: ''),
            'container'      => self::isContainer(),
        ];
    }

    /**
     * Compute a short fingerprint key for comparison and grouping.
     *
     * Two nodes with the same key are considered "same environment class".
     * Returns a 16-char hex string.
     */
    public static function key(array $fp): string
    {
        if (empty($fp)) {
            return 'unknown';
        }

        $parts = implode('|', [
            $fp['device_id'] ?? '',
            $fp['php_version'] ?? '',
            $fp['platform'] ?? '',
            $fp['arch'] ?? '',
            $fp['hostname'] ?? '',
            $fp['client'] ?? ($fp['evolver_version'] ?? ''),
            $fp['client_version'] ?? ($fp['evolver_version'] ?? ''),
        ]);

        return substr(hash('sha256', $parts), 0, 16);
    }

    /**
     * Check if two fingerprints are from the same environment class.
     */
    public static function isSameEnvClass(array $fpA, array $fpB): bool
    {
        return self::key($fpA) === self::key($fpB);
    }

    // -------------------------------------------------------------------------
    // Device ID — priority chain (mirrors deviceId.js)
    // -------------------------------------------------------------------------

    /**
     * Get or generate a stable device ID.
     *
     * Priority chain:
     *   1. EVOMAP_DEVICE_ID env var
     *   2. ~/.evomap/device_id file
     *   3. <project>/.evomap_device_id
     *   4. /etc/machine-id (Linux)
     *   5. IOPlatformUUID (macOS)
     *   6. Docker/OCI container ID
     *   7. hostname + MAC addresses
     *   8. random 128-bit hex (persisted)
     */
    public static function getDeviceId(): string
    {
        if (self::$cachedDeviceId !== null) {
            return self::$cachedDeviceId;
        }

        // 1. Environment variable override
        $envId = getenv('EVOMAP_DEVICE_ID');
        if ($envId !== false && $envId !== '' && preg_match(self::DEVICE_ID_RE, $envId)) {
            self::$cachedDeviceId = $envId;
            return $envId;
        }

        // 2. Persisted in ~/.evomap/device_id
        $homePath = self::getHomeDeviceIdPath();
        if ($homePath !== null) {
            $stored = self::readDeviceIdFile($homePath);
            if ($stored !== null) {
                self::$cachedDeviceId = $stored;
                return $stored;
            }
        }

        // 3. Project-local fallback: <project>/.evomap_device_id
        $localPath = self::getLocalDeviceIdPath();
        $stored = self::readDeviceIdFile($localPath);
        if ($stored !== null) {
            self::$cachedDeviceId = $stored;
            return $stored;
        }

        // Generate a new ID from hardware
        $id = self::generateDeviceId();

        // Persist it
        self::persistDeviceId($id);

        self::$cachedDeviceId = $id;
        return $id;
    }

    // -------------------------------------------------------------------------
    // Device ID generation
    // -------------------------------------------------------------------------

    private static function generateDeviceId(): string
    {
        // 4. /etc/machine-id (Linux systemd) or macOS IOPlatformUUID
        $machineId = self::readMachineId();
        if ($machineId !== null) {
            return substr(hash('sha256', 'evomap:' . $machineId), 0, 32);
        }

        // 6. Docker/OCI container ID
        $containerId = self::readContainerId();
        if ($containerId !== null) {
            return substr(hash('sha256', 'evomap:container:' . $containerId), 0, 32);
        }

        // 7. hostname + MAC addresses
        $macs = self::getMacAddresses();
        if (!empty($macs)) {
            $raw = (string)gethostname() . '|' . implode(',', $macs);
            return substr(hash('sha256', 'evomap:' . $raw), 0, 32);
        }

        // 8. Random 128-bit hex (last resort)
        return bin2hex(random_bytes(16));
    }

    /**
     * Read /etc/machine-id (Linux) or IOPlatformUUID (macOS).
     */
    private static function readMachineId(): ?string
    {
        // Linux: /etc/machine-id
        if (file_exists('/etc/machine-id')) {
            $mid = trim((string)@file_get_contents('/etc/machine-id'));
            if (strlen($mid) >= 16) {
                return $mid;
            }
        }

        // macOS: ioreg
        if (PHP_OS_FAMILY === 'Darwin') {
            $raw = @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null');
            if ($raw !== null) {
                if (preg_match('/"IOPlatformUUID"\s*=\s*"([^"]+)"/', $raw, $m)) {
                    return $m[1];
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
        // /proc/self/cgroup (cgroup v1, most Docker setups)
        if (file_exists('/proc/self/cgroup')) {
            $content = @file_get_contents('/proc/self/cgroup');
            if ($content !== false && preg_match('/[a-f0-9]{64}/', $content, $m)) {
                return $m[0];
            }
        }

        // /proc/self/mountinfo (cgroup v2 / containerd)
        if (file_exists('/proc/self/mountinfo')) {
            $content = @file_get_contents('/proc/self/mountinfo');
            if ($content !== false && preg_match('/[a-f0-9]{64}/', $content, $m)) {
                return $m[0];
            }
        }

        // Docker hostname (short container ID: 12 hex chars)
        if (self::isContainer()) {
            $hostname = (string)gethostname();
            if (preg_match('/^[a-f0-9]{12,64}$/', $hostname)) {
                return $hostname;
            }
        }

        return null;
    }

    /**
     * Get non-loopback MAC addresses, sorted for stability.
     */
    private static function getMacAddresses(): array
    {
        $macs = [];

        // Linux: /sys/class/net/*/address
        $netDir = '/sys/class/net';
        if (is_dir($netDir)) {
            foreach (scandir($netDir) ?: [] as $iface) {
                if ($iface === '.' || $iface === '..') {
                    continue;
                }
                $addrFile = "{$netDir}/{$iface}/address";
                if (!file_exists($addrFile)) {
                    continue;
                }
                $mac = trim((string)@file_get_contents($addrFile));
                // Skip loopback (all zeros) and empty
                if ($mac !== '' && $mac !== '00:00:00:00:00:00') {
                    $macs[] = $mac;
                }
            }
        }

        // macOS / fallback: try `ifconfig`
        if (empty($macs) && PHP_OS_FAMILY === 'Darwin') {
            $output = @shell_exec('ifconfig 2>/dev/null');
            if ($output !== null) {
                preg_match_all('/ether\s+([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $output, $matches);
                foreach ($matches[1] as $mac) {
                    if ($mac !== '00:00:00:00:00:00') {
                        $macs[] = strtolower($mac);
                    }
                }
            }
        }

        sort($macs);
        return array_unique($macs);
    }

    // -------------------------------------------------------------------------
    // Device ID persistence
    // -------------------------------------------------------------------------

    private static function getHomeDeviceIdPath(): ?string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            return null;
        }
        return $home . DIRECTORY_SEPARATOR . self::DEVICE_ID_DIR . DIRECTORY_SEPARATOR . self::DEVICE_ID_FILE;
    }

    private static function getLocalDeviceIdPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . self::LOCAL_DEVICE_ID_FILENAME;
    }

    private static function readDeviceIdFile(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }
        $id = trim((string)@file_get_contents($path));
        if (preg_match(self::DEVICE_ID_RE, $id)) {
            return $id;
        }
        return null;
    }

    private static function persistDeviceId(string $id): void
    {
        // Try primary path (~/.evomap/device_id)
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            $dir = $home . DIRECTORY_SEPARATOR . self::DEVICE_ID_DIR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $path = $dir . DIRECTORY_SEPARATOR . self::DEVICE_ID_FILE;
            if (@file_put_contents($path, $id, LOCK_EX) !== false) {
                @chmod($path, 0600);
                return;
            }
        }

        // Fallback: project-local file
        $localPath = self::getLocalDeviceIdPath();
        if (@file_put_contents($localPath, $id, LOCK_EX) !== false) {
            @chmod($localPath, 0600);
        }
    }

    // -------------------------------------------------------------------------
    // Container detection (mirrors isContainer() from deviceId.js)
    // -------------------------------------------------------------------------

    public static function isContainer(): bool
    {
        // Docker indicator file
        if (file_exists('/.dockerenv')) {
            return true;
        }

        // cgroup markers
        if (file_exists('/proc/1/cgroup')) {
            $content = @file_get_contents('/proc/1/cgroup');
            if ($content !== false && preg_match('/docker|kubepods|containerd|cri-o|lxc|ecs/i', $content)) {
                return true;
            }
        }

        // Podman / OCI
        if (file_exists('/run/.containerenv')) {
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // OS / Platform helpers
    // -------------------------------------------------------------------------

    private static function getPlatform(): string
    {
        // Mirror Node.js process.platform values: linux, darwin, win32
        return match (PHP_OS_FAMILY) {
            'Windows' => 'win32',
            'Darwin'  => 'darwin',
            default   => strtolower(PHP_OS_FAMILY),
        };
    }

    private static function getArch(): string
    {
        // php_uname('m') returns e.g. "x86_64", "aarch64", "arm64"
        $machine = php_uname('m');
        // Normalise to common values used by Node.js
        return match (strtolower(trim($machine))) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            'i386', 'i486', 'i586', 'i686' => 'ia32',
            default => strtolower(trim($machine)),
        };
    }

    private static function getOsRelease(): string
    {
        // php_uname('r') returns the kernel release string, e.g. "5.15.0-72-generic"
        return php_uname('r');
    }

    // -------------------------------------------------------------------------
    // Composer.json reader (replaces package.json reader from Node.js)
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string|null, 1: string|null} [$name, $version]
     */
    private static function readComposerInfo(): array
    {
        $composerPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'composer.json';
        if (!file_exists($composerPath)) {
            return [null, null];
        }
        $raw = @file_get_contents($composerPath);
        if ($raw === false) {
            return [null, null];
        }
        $pkg = json_decode($raw, true);
        if (!is_array($pkg)) {
            return [null, null];
        }
        $name = isset($pkg['name']) && is_string($pkg['name']) ? $pkg['name'] : null;
        $version = isset($pkg['version']) && is_string($pkg['version']) ? $pkg['version'] : null;
        return [$name, $version];
    }

    /**
     * Read the EVOLVER_REGION env var (max 5 chars, lowercase).
     */
    private static function readRegion(): ?string
    {
        $region = getenv('EVOLVER_REGION');
        if ($region === false || $region === '') {
            return null;
        }
        $region = strtolower(trim($region));
        return $region !== '' ? substr($region, 0, 5) : null;
    }
}
