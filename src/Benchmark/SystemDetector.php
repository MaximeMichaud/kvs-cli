<?php

declare(strict_types=1);

namespace KVS\CLI\Benchmark;

/**
 * Advanced system detection for experiment mode
 *
 * Detects:
 * - CPU vendor, model, generation (Intel/AMD, Zen 3, Alder Lake, etc.)
 * - Architecture (x86_64, aarch64, armv7l, etc.)
 * - Device type (VM, Container, Bare Metal)
 * - Storage type (NVMe, SSD, HDD)
 * - Virtualization technology (KVM, VMware, Xen, etc.)
 */
class SystemDetector
{
    /**
     * Detect all system information
     *
     * @return array<string, mixed>
     */
    public function detect(): array
    {
        return [
            'cpu' => $this->detectCpu(),
            'architecture' => $this->detectArchitecture(),
            'device_type' => $this->detectDeviceType(),
            'storage' => $this->detectStorage(),
            'virtualization' => $this->detectVirtualization(),
        ];
    }

    /**
     * Detect CPU information
     *
     * @return array{vendor: string, model: string, generation: string, cores: int, threads: int, family: string}
     */
    public function detectCpu(): array
    {
        $info = [
            'vendor' => 'Unknown',
            'model' => 'Unknown',
            'generation' => 'Unknown',
            'cores' => 1,
            'threads' => 1,
            'family' => 'Unknown',
        ];

        if (!file_exists('/proc/cpuinfo')) {
            return $info;
        }

        $content = file_get_contents('/proc/cpuinfo');
        if ($content === false) {
            return $info;
        }

        // Parse CPU info
        $processors = 0;
        $coreIds = [];
        $vendorId = '';
        $modelName = '';
        $cpuFamily = '';
        $model = '';

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^vendor_id\s*:\s*(.+)/', $line, $m) === 1) {
                $vendorId = trim($m[1]);
            }
            if (preg_match('/^model name\s*:\s*(.+)/', $line, $m) === 1) {
                $modelName = trim($m[1]);
            }
            if (preg_match('/^cpu family\s*:\s*(\d+)/', $line, $m) === 1) {
                $cpuFamily = $m[1];
            }
            if (preg_match('/^model\s*:\s*(\d+)/', $line, $m) === 1) {
                $model = $m[1];
            }
            if (preg_match('/^core id\s*:\s*(\d+)/', $line, $m) === 1) {
                $coreIds[$m[1]] = true;
            }
            if (preg_match('/^processor\s*:/', $line) === 1) {
                $processors++;
            }
        }

        // Determine vendor
        $info['vendor'] = match (true) {
            str_contains($vendorId, 'AMD') => 'AMD',
            str_contains($vendorId, 'Intel') => 'Intel',
            str_contains($vendorId, 'ARM') => 'ARM',
            default => $vendorId !== '' ? $vendorId : 'Unknown',
        };

        $info['model'] = $modelName;
        $info['threads'] = $processors > 0 ? $processors : 1;
        $info['cores'] = count($coreIds) > 0 ? count($coreIds) : $info['threads'];

        // Detect generation
        $info['generation'] = $this->detectCpuGeneration($info['vendor'], $modelName, $cpuFamily, $model);
        $info['family'] = $this->detectCpuFamily($info['vendor'], $modelName);

        return $info;
    }

    /**
     * Detect CPU generation/microarchitecture
     */
    private function detectCpuGeneration(string $vendor, string $modelName, string $cpuFamily, string $model): string
    {
        if ($vendor === 'AMD') {
            return $this->detectAmdGeneration($modelName);
        }

        if ($vendor === 'Intel') {
            return $this->detectIntelGeneration($modelName, $cpuFamily, $model);
        }

        if ($vendor === 'ARM') {
            return $this->detectArmGeneration($modelName);
        }

        return 'Unknown';
    }

    /**
     * Detect AMD CPU generation
     */
    private function detectAmdGeneration(string $modelName): string
    {
        // Zen 5 - Strix Point APUs (Ryzen AI 300 series, Ryzen 7/9 2xx/3xx)
        if (preg_match('/Ryzen\s+(AI\s+)?\d+\s+[23]\d{2}/i', $modelName) === 1) {
            return 'Zen 5 (Strix Point)';
        }

        // Zen 5 (2024+) - Desktop (Ryzen 9000 series)
        if (preg_match('/Ryzen\s+\d+\s+9\d{3}/i', $modelName) === 1) {
            return 'Zen 5';
        }

        // Zen 4 (Ryzen 7000 series, EPYC Genoa)
        if (preg_match('/Ryzen\s+\d+\s+7\d{3}/i', $modelName) === 1) {
            return 'Zen 4';
        }
        if (preg_match('/EPYC\s+9\d{3}/i', $modelName) === 1) {
            return 'Zen 4 (Genoa)';
        }

        // Zen 3 (Ryzen 5000 series, EPYC Milan)
        if (preg_match('/Ryzen\s+\d+\s+5\d{3}/i', $modelName) === 1) {
            return 'Zen 3';
        }
        if (preg_match('/EPYC\s+7\d{3}/i', $modelName) === 1) {
            return 'Zen 3 (Milan)';
        }

        // Zen 2 (Ryzen 3000 series, EPYC Rome)
        if (preg_match('/Ryzen\s+\d+\s+3\d{3}/i', $modelName) === 1) {
            return 'Zen 2';
        }
        if (preg_match('/EPYC\s+7\d{2}2/i', $modelName) === 1) {
            return 'Zen 2 (Rome)';
        }

        // Zen+ (Ryzen 2000 series)
        if (preg_match('/Ryzen\s+\d+\s+2\d{3}/i', $modelName) === 1) {
            return 'Zen+';
        }

        // Zen (Ryzen 1000 series)
        if (preg_match('/Ryzen\s+\d+\s+1\d{3}/i', $modelName) === 1) {
            return 'Zen';
        }

        // Generic EPYC detection
        if (stripos($modelName, 'EPYC') !== false) {
            return 'EPYC';
        }

        return 'Unknown AMD';
    }

    /**
     * Detect Intel CPU generation
     */
    private function detectIntelGeneration(string $modelName, string $cpuFamily, string $model): string
    {
        // Arrow Lake (15th gen, 2024)
        if (preg_match('/Core.*Ultra\s+[579]/i', $modelName) === 1) {
            return 'Arrow Lake (15th Gen)';
        }

        // Raptor Lake (13th/14th gen)
        if (preg_match('/i[3579]-1[34]\d{3}/i', $modelName) === 1) {
            return 'Raptor Lake (13th/14th Gen)';
        }

        // Alder Lake (12th gen)
        if (preg_match('/i[3579]-12\d{3}/i', $modelName) === 1) {
            return 'Alder Lake (12th Gen)';
        }

        // Rocket Lake (11th gen)
        if (preg_match('/i[3579]-11\d{3}/i', $modelName) === 1) {
            return 'Rocket Lake (11th Gen)';
        }

        // Comet Lake (10th gen)
        if (preg_match('/i[3579]-10\d{3}/i', $modelName) === 1) {
            return 'Comet Lake (10th Gen)';
        }

        // Coffee Lake (8th/9th gen)
        if (preg_match('/i[3579]-[89]\d{3}/i', $modelName) === 1) {
            return 'Coffee Lake (8th/9th Gen)';
        }

        // Xeon Scalable generations
        if (stripos($modelName, 'Xeon') !== false) {
            if (stripos($modelName, 'Platinum 8') !== false || stripos($modelName, 'Gold 6') !== false) {
                if (preg_match('/8[45]\d{2}|6[45]\d{2}/i', $modelName) === 1) {
                    return 'Sapphire Rapids (4th Gen Xeon)';
                }
                if (preg_match('/83\d{2}|63\d{2}/i', $modelName) === 1) {
                    return 'Ice Lake (3rd Gen Xeon)';
                }
                return 'Xeon Scalable';
            }
            return 'Xeon';
        }

        return 'Unknown Intel';
    }

    /**
     * Detect ARM CPU generation
     */
    private function detectArmGeneration(string $modelName): string
    {
        if (stripos($modelName, 'Graviton3') !== false) {
            return 'Graviton3 (Neoverse V1)';
        }
        if (stripos($modelName, 'Graviton2') !== false) {
            return 'Graviton2 (Neoverse N1)';
        }
        if (stripos($modelName, 'Ampere') !== false) {
            return 'Ampere Altra';
        }
        if (stripos($modelName, 'Neoverse') !== false) {
            return 'ARM Neoverse';
        }

        return 'ARM';
    }

    /**
     * Detect CPU family/class
     */
    private function detectCpuFamily(string $vendor, string $modelName): string
    {
        if ($vendor === 'AMD') {
            if (stripos($modelName, 'EPYC') !== false) {
                return 'Server';
            }
            if (stripos($modelName, 'Ryzen Threadripper') !== false) {
                return 'HEDT';
            }
            if (stripos($modelName, 'Ryzen') !== false) {
                return 'Desktop';
            }
        }

        if ($vendor === 'Intel') {
            if (stripos($modelName, 'Xeon') !== false) {
                return 'Server';
            }
            if (stripos($modelName, 'Core') !== false) {
                return 'Desktop';
            }
        }

        if ($vendor === 'ARM') {
            if (stripos($modelName, 'Graviton') !== false || stripos($modelName, 'Ampere') !== false) {
                return 'Server';
            }
        }

        return 'Unknown';
    }

    /**
     * Detect system architecture
     *
     * @return array{name: string, bits: int, family: string}
     */
    public function detectArchitecture(): array
    {
        $arch = php_uname('m');

        $bits = match ($arch) {
            'x86_64', 'amd64', 'aarch64', 'arm64' => 64,
            'i386', 'i486', 'i586', 'i686', 'armv7l', 'armv6l' => 32,
            default => PHP_INT_SIZE * 8,
        };

        $family = match (true) {
            in_array($arch, ['x86_64', 'amd64', 'i386', 'i486', 'i586', 'i686'], true) => 'x86',
            in_array($arch, ['aarch64', 'arm64', 'armv7l', 'armv6l'], true) => 'ARM',
            str_starts_with($arch, 'riscv') => 'RISC-V',
            default => 'Unknown',
        };

        return [
            'name' => $arch,
            'bits' => $bits,
            'family' => $family,
        ];
    }

    /**
     * Detect device type (VM, Container, Bare Metal)
     *
     * @return array{type: string, technology: string|null, confidence: string}
     */
    public function detectDeviceType(): array
    {
        // Check for container first
        $container = $this->detectContainer();
        if ($container !== null) {
            return [
                'type' => 'container',
                'technology' => $container,
                'confidence' => 'high',
            ];
        }

        // Check for VM
        $vm = $this->detectVirtualization();
        if ($vm['is_virtual']) {
            return [
                'type' => 'vm',
                'technology' => $vm['technology'],
                'confidence' => $vm['confidence'],
            ];
        }

        return [
            'type' => 'bare_metal',
            'technology' => null,
            'confidence' => $vm['confidence'],
        ];
    }

    /**
     * Detect if running in a container
     */
    private function detectContainer(): ?string
    {
        // Check for Docker
        if (file_exists('/.dockerenv')) {
            return 'Docker';
        }

        // Check cgroup for docker/lxc/podman
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if ($cgroup !== false) {
                if (str_contains($cgroup, 'docker')) {
                    return 'Docker';
                }
                if (str_contains($cgroup, 'lxc')) {
                    return 'LXC';
                }
                if (str_contains($cgroup, 'podman')) {
                    return 'Podman';
                }
                if (str_contains($cgroup, 'kubepods')) {
                    return 'Kubernetes';
                }
            }
        }

        // Check for LXC
        if (file_exists('/dev/lxc')) {
            return 'LXC';
        }

        // Check for systemd-nspawn
        if (getenv('container') === 'systemd-nspawn') {
            return 'systemd-nspawn';
        }

        return null;
    }

    /**
     * Detect virtualization technology
     *
     * @return array{is_virtual: bool, technology: string|null, confidence: string}
     */
    public function detectVirtualization(): array
    {
        $result = [
            'is_virtual' => false,
            'technology' => null,
            'confidence' => 'low',
        ];

        // Method 1: Check DMI/SMBIOS (most reliable)
        $dmiChecks = [
            '/sys/class/dmi/id/product_name',
            '/sys/class/dmi/id/sys_vendor',
            '/sys/class/dmi/id/board_vendor',
            '/sys/class/dmi/id/bios_vendor',
        ];

        foreach ($dmiChecks as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $content = strtolower(trim($content));

            $vmIndicators = [
                'kvm' => 'KVM',
                'qemu' => 'QEMU/KVM',
                'vmware' => 'VMware',
                'virtualbox' => 'VirtualBox',
                'xen' => 'Xen',
                'microsoft' => 'Hyper-V',
                'amazon ec2' => 'AWS EC2',
                'google' => 'Google Cloud',
                'digitalocean' => 'DigitalOcean',
                'hetzner' => 'Hetzner Cloud',
                'linode' => 'Linode',
                'vultr' => 'Vultr',
                'ovh' => 'OVH',
                'openstack' => 'OpenStack',
                'bochs' => 'Bochs',
                'parallels' => 'Parallels',
            ];

            foreach ($vmIndicators as $indicator => $name) {
                if (str_contains($content, $indicator)) {
                    $result['is_virtual'] = true;
                    $result['technology'] = $name;
                    $result['confidence'] = 'high';
                    return $result;
                }
            }
        }

        // Method 2: Check /proc/cpuinfo for hypervisor flag
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false && str_contains($cpuinfo, 'hypervisor')) {
                $result['is_virtual'] = true;
                $result['confidence'] = 'medium';

                // Try to identify from cpuinfo
                if (str_contains(strtolower($cpuinfo), 'kvm')) {
                    $result['technology'] = 'KVM';
                    $result['confidence'] = 'high';
                }
            }
        }

        // Method 3: Check for virt-what style detection
        if (file_exists('/sys/hypervisor/type')) {
            $type = file_get_contents('/sys/hypervisor/type');
            if ($type !== false) {
                $result['is_virtual'] = true;
                $result['technology'] = trim($type);
                $result['confidence'] = 'high';
            }
        }

        // Method 4: Check device tree (for ARM/cloud instances)
        if (file_exists('/sys/firmware/devicetree/base/hypervisor/compatible')) {
            $result['is_virtual'] = true;
            $result['confidence'] = 'high';
        }

        return $result;
    }

    /**
     * Detect storage type
     *
     * @return array{type: string, device: string|null, confidence: string}
     */
    public function detectStorage(): array
    {
        $result = [
            'type' => 'unknown',
            'device' => null,
            'confidence' => 'low',
        ];

        // Find root device
        $rootDevice = $this->findRootDevice();
        if ($rootDevice === null) {
            return $result;
        }

        $result['device'] = $rootDevice;

        // NVMe detection (device name) - always SSD
        if (str_starts_with($rootDevice, 'nvme')) {
            $result['type'] = 'nvme';
            $result['confidence'] = 'high';
            return $result;
        }

        // Check if we're on a known cloud provider that uses SSDs
        $cloudProvider = $this->detectCloudProvider();

        // Virtual disks (vd*, xvd*) - check cloud provider first
        if (str_starts_with($rootDevice, 'vd') || str_starts_with($rootDevice, 'xvd')) {
            // Most cloud providers use SSD storage for VPS
            $ssdProviders = [
                'Vultr', 'DigitalOcean', 'Linode', 'AWS EC2', 'Google Cloud',
                'Hetzner Cloud', 'OVH', 'Scaleway', 'UpCloud', 'IONOS',
            ];

            if ($cloudProvider !== null && in_array($cloudProvider, $ssdProviders, true)) {
                $result['type'] = 'ssd';
                $result['confidence'] = 'high';
                return $result;
            }

            // Unknown provider with virtual disk - assume SSD (most common now)
            // but with medium confidence
            $result['type'] = 'ssd';
            $result['confidence'] = 'medium';
            return $result;
        }

        // Physical disk - check rotational flag
        $baseDev = preg_replace('/\d+$/', '', $rootDevice);
        if ($baseDev === null) {
            $baseDev = $rootDevice;
        }

        $rotationalFile = "/sys/block/{$baseDev}/queue/rotational";
        if (file_exists($rotationalFile)) {
            $rotational = file_get_contents($rotationalFile);
            if ($rotational !== false) {
                $isRotational = trim($rotational) === '1';
                $result['type'] = $isRotational ? 'hdd' : 'ssd';
                $result['confidence'] = 'high';
                return $result;
            }
        }

        // Fallback for sd* devices
        if (str_starts_with($rootDevice, 'sd')) {
            // Could be SSD or HDD, can't determine without rotational flag
            $result['type'] = 'unknown';
            $result['confidence'] = 'low';
        }

        return $result;
    }

    /**
     * Detect cloud provider from DMI/virtualization info
     */
    private function detectCloudProvider(): ?string
    {
        $dmiFiles = [
            '/sys/class/dmi/id/product_name',
            '/sys/class/dmi/id/sys_vendor',
            '/sys/class/dmi/id/board_vendor',
            '/sys/class/dmi/id/bios_vendor',
        ];

        $providers = [
            'vultr' => 'Vultr',
            'digitalocean' => 'DigitalOcean',
            'linode' => 'Linode',
            'amazon' => 'AWS EC2',
            'amazon ec2' => 'AWS EC2',
            'google' => 'Google Cloud',
            'hetzner' => 'Hetzner Cloud',
            'ovh' => 'OVH',
            'scaleway' => 'Scaleway',
            'upcloud' => 'UpCloud',
            'ionos' => 'IONOS',
        ];

        foreach ($dmiFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $content = strtolower(trim($content));

            foreach ($providers as $indicator => $name) {
                if (str_contains($content, $indicator)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Find root filesystem device
     */
    private function findRootDevice(): ?string
    {
        // Method 1: Parse /proc/mounts
        if (file_exists('/proc/mounts')) {
            $mounts = file_get_contents('/proc/mounts');
            if ($mounts !== false) {
                foreach (explode("\n", $mounts) as $line) {
                    $parts = preg_split('/\s+/', $line);
                    if ($parts !== false && count($parts) >= 2 && $parts[1] === '/') {
                        $device = $parts[0];
                        // Remove /dev/ prefix
                        $device = preg_replace('#^/dev/#', '', $device);
                        // Handle device mapper
                        if ($device !== null && str_starts_with($device, 'mapper/')) {
                            // Try to resolve dm device
                            $dmDevice = $this->resolveDmDevice($device);
                            if ($dmDevice !== null) {
                                return $dmDevice;
                            }
                        }
                        return $device;
                    }
                }
            }
        }

        // Method 2: Use lsblk
        $output = @shell_exec('lsblk -no NAME,MOUNTPOINT 2>/dev/null');
        if (is_string($output)) {
            foreach (explode("\n", $output) as $line) {
                if (str_contains($line, ' /')) {
                    $parts = preg_split('/\s+/', trim($line));
                    if ($parts !== false && count($parts) >= 1) {
                        // Remove tree characters
                        return preg_replace('/^[├└─│\s]+/', '', $parts[0]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve device mapper to underlying device
     */
    private function resolveDmDevice(string $dmDevice): ?string
    {
        // Try to find underlying device through dm slaves
        $dmName = str_replace('mapper/', '', $dmDevice);
        $slavesPath = "/sys/block/dm-*/slaves";

        $dmBlocks = glob('/sys/block/dm-*');
        if ($dmBlocks === false) {
            return null;
        }

        foreach ($dmBlocks as $dmBlock) {
            $nameFile = $dmBlock . '/dm/name';
            if (file_exists($nameFile)) {
                $name = file_get_contents($nameFile);
                if ($name !== false && trim($name) === $dmName) {
                    $slaves = glob($dmBlock . '/slaves/*');
                    if ($slaves !== false && count($slaves) > 0) {
                        return basename($slaves[0]);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Generate a summary string for display
     */
    public function getSummary(): string
    {
        $cpu = $this->detectCpu();
        $arch = $this->detectArchitecture();
        $device = $this->detectDeviceType();
        $storage = $this->detectStorage();

        $parts = [];

        // CPU
        if ($cpu['vendor'] !== 'Unknown') {
            $cpuStr = $cpu['vendor'];
            $gen = $cpu['generation'];
            if ($gen !== 'Unknown' && $gen !== 'Unknown AMD' && $gen !== 'Unknown Intel') {
                $cpuStr .= ' ' . $gen;
            }
            $cpuStr .= " ({$cpu['cores']}c/{$cpu['threads']}t)";
            $parts[] = $cpuStr;
        }

        // Architecture
        $parts[] = $arch['name'];

        // Device type
        $deviceStr = ucfirst($device['type']);
        $tech = $device['technology'];
        if ($tech !== null) {
            $deviceStr .= " ({$tech})";
        }
        $parts[] = $deviceStr;

        // Storage
        if ($storage['type'] !== 'unknown') {
            $parts[] = strtoupper($storage['type']);
        }

        return implode(' | ', $parts);
    }
}
