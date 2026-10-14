<?php

declare(strict_types=1);

/**
 * Resource envelope helpers: host, k3d container, and Kubernetes workload
 * allocation reads plus the guarded settings validation and preview contract.
 *
 * All host reads use fixed paths only (/proc and /sys). Cluster reads reuse
 * the allowlisted docker/kubectl wrappers from config.php. No function here
 * mutates cluster or container state; apply/rollback live in the rc script.
 */

/** Parse /proc/cpuinfo text into logical CPUs, sockets, and sibling pairs. */
function dm_k8s_parse_cpuinfo(string $cpuinfo): array
{
    $logical = 0;
    $cores = [];
    $sockets = [];
    foreach (explode("\n", $cpuinfo) as $line) {
        if (preg_match('/^processor\s*:\s*(\d+)/', $line, $matches)) {
            $logical++;
        } elseif (preg_match('/^core id\s*:\s*(\d+)/', $line, $matches)) {
            $cores[] = (int)$matches[1];
        } elseif (preg_match('/^physical id\s*:\s*(\d+)/', $line, $matches)) {
            $sockets[(int)$matches[1]] = true;
        }
    }
    $siblingPairs = 0;
    if ($cores !== [] && count($cores) === $logical && $logical > 0) {
        $pairCounts = array_count_values($cores);
        foreach ($pairCounts as $count) {
            if ($count >= 2) {
                $siblingPairs++;
            }
        }
    }
    return [
        'logical_cpus' => $logical,
        'sockets' => count($sockets),
        'sibling_pairs' => $siblingPairs,
    ];
}

/** Parse /proc/meminfo text into total host memory bytes. */
function dm_k8s_parse_meminfo(string $meminfo): int
{
    if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $meminfo, $matches)) {
        return (int)$matches[1] * 1024;
    }
    return 0;
}

/**
 * Read the host resource envelope through fixed paths.
 *
 * @return array<string, mixed>
 */
function dm_k8s_host_envelope(): array
{
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    $meminfo = @file_get_contents('/proc/meminfo');
    $cpu = is_string($cpuinfo) ? dm_k8s_parse_cpuinfo($cpuinfo) : ['logical_cpus' => 0, 'sockets' => 0, 'sibling_pairs' => 0];
    $numaNodes = [];
    foreach (glob('/sys/devices/system/node/node[0-9]*') ?: [] as $node) {
        $numaNodes[] = basename($node);
    }
    return [
        'logical_cpus' => (int)$cpu['logical_cpus'],
        'sockets' => (int)$cpu['sockets'],
        'sibling_pairs' => (int)$cpu['sibling_pairs'],
        'numa_nodes' => count($numaNodes),
        'numa_node_names' => $numaNodes,
        'memory_bytes' => is_string($meminfo) ? dm_k8s_parse_meminfo($meminfo) : 0,
        'source' => 'host (/proc/cpuinfo, /proc/meminfo, /sys/devices/system/node)',
    ];
}

/**
 * Read the applied k3d host-container envelope (Docker cpuset and limits).
 *
 * @return array<string, mixed>
 */
function dm_k8s_k3d_envelope(string $cluster): array
{
    $code = 0;
    $json = dm_k8s_run([
        '/usr/bin/docker', 'inspect', '--format', '{{json .HostConfig}}',
        "k3d-{$cluster}-server-0",
    ], $code);
    $hostConfig = $code === 0 ? json_decode($json, true) : null;
    if (!is_array($hostConfig)) {
        return [
            'available' => false,
            'cpuset_cpus' => '',
            'cpu_limit_cores' => 0,
            'cpu_shares' => 0,
            'memory_limit_bytes' => 0,
            'source' => 'docker',
        ];
    }
    return [
        'available' => true,
        'cpuset_cpus' => (string)($hostConfig['CpusetCpus'] ?? ''),
        'cpu_limit_cores' => (int)round(((int)($hostConfig['NanoCpus'] ?? 0)) / 1000000000),
        'cpu_shares' => (int)($hostConfig['CpuShares'] ?? 0),
        'memory_limit_bytes' => (int)($hostConfig['Memory'] ?? 0),
        'source' => 'docker inspect (k3d server container)',
    ];
}

/** Millicores of one quantity string, or 0 when unset. */
function dm_k8s_quantity_millicores(string $value): int
{
    if ($value === '') {
        return 0;
    }
    return dm_k8s_cpu_millicores($value);
}

/** Bytes of one quantity string, or 0 when unset. */
function dm_k8s_quantity_bytes(string $value): int
{
    if ($value === '') {
        return 0;
    }
    return dm_k8s_memory_bytes($value);
}

/**
 * Standard Kubernetes QoS class for one Pod.
 * Guaranteed: every container sets equal integer-or-lower cpu and memory
 * requests and limits. BestEffort: no requests or limits at all.
 */
function dm_k8s_pod_qos(array $pod): string
{
    $requests = 0;
    $limits = 0;
    foreach (($pod['spec']['containers'] ?? []) as $container) {
        $requests += empty($container['resources']['requests']) ? 0 : 1;
        $limits += empty($container['resources']['limits']) ? 0 : 1;
    }
    $containers = count(($pod['spec']['containers'] ?? []));
    if ($containers > 0 && $requests === 0 && $limits === 0) {
        return 'BestEffort';
    }
    $guaranteed = $containers > 0;
    foreach (($pod['spec']['containers'] ?? []) as $container) {
        $cpuRequest = dm_k8s_quantity_millicores((string)($container['resources']['requests']['cpu'] ?? ''));
        $cpuLimit = dm_k8s_quantity_millicores((string)($container['resources']['limits']['cpu'] ?? ''));
        $memoryRequest = dm_k8s_quantity_bytes((string)($container['resources']['requests']['memory'] ?? ''));
        $memoryLimit = dm_k8s_quantity_bytes((string)($container['resources']['limits']['memory'] ?? ''));
        if ($cpuRequest <= 0 || $cpuLimit <= 0 || $cpuRequest !== $cpuLimit
            || $memoryRequest <= 0 || $memoryLimit <= 0 || $memoryRequest !== $memoryLimit) {
            $guaranteed = false;
        }
    }
    return $guaranteed ? 'Guaranteed' : 'Burstable';
}

/** True when a Pod qualifies for static-policy exclusive CPU pinning. */
function dm_k8s_pod_exclusive_eligible(array $pod, string $cpuManagerPolicy): bool
{
    if ($cpuManagerPolicy !== 'static' || dm_k8s_pod_qos($pod) !== 'Guaranteed') {
        return false;
    }
    foreach (($pod['spec']['containers'] ?? []) as $container) {
        $cpuLimit = dm_k8s_quantity_millicores((string)($container['resources']['limits']['cpu'] ?? ''));
        if ($cpuLimit > 0 && $cpuLimit % 1000 === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Validation rules for a proposed k3d resource envelope. Every rule blocks
 * the apply; the list is empty when the proposal is safe.
 *
 * @param array<string, mixed> $host dm_k8s_host_envelope() result.
 * @param array<string, mixed> $workload Rollup with cpu_requests_millicores
 *   and memory_requests_bytes totals.
 * @return list<string>
 */
function dm_k8s_resource_violations(array $host, array $workload, int $proposedCpus, int $proposedMemoryBytes): array
{
    $violations = [];
    $logical = (int)($host['logical_cpus'] ?? 0);
    $hostMemory = (int)($host['memory_bytes'] ?? 0);
    $siblingPairs = (int)($host['sibling_pairs'] ?? 0);
    $numaNodes = max(1, (int)($host['numa_nodes'] ?? 1));
    $reserveCpus = (int)($workload['host_reserve_cpus'] ?? 1);
    $reserveMemory = (int)($workload['host_reserve_memory_bytes'] ?? 0);

    if ($proposedCpus < 0 || $proposedMemoryBytes < 0) {
        $violations[] = 'CPU and memory limits cannot be negative.';
        return $violations;
    }
    if ($logical > 0 && $proposedCpus > 0) {
        if ($proposedCpus < 1) {
            $violations[] = 'The k3d CPU limit must be at least one full core.';
        }
        if ($proposedCpus + $reserveCpus > $logical) {
            $violations[] = sprintf(
                'Proposed %d CPU(s) plus %d host-reserved CPU(s) exceed the %d logical CPUs on this host.',
                $proposedCpus,
                $reserveCpus,
                $logical
            );
        }
        if ($siblingPairs > 0 && $proposedCpus % 2 === 1) {
            $violations[] = 'An odd CPU count would split a hyperthread sibling pair on this host.';
        }
        if ($numaNodes > 1 && $proposedCpus % $numaNodes !== 0) {
            $violations[] = sprintf(
                'A %d CPU limit cannot be spread evenly across the %d NUMA nodes on this host.',
                $proposedCpus,
                $numaNodes
            );
        }
        $workloadCpu = (int)($workload['cpu_requests_millicores'] ?? 0);
        if ($workloadCpu > 0 && $proposedCpus * 1000 < $workloadCpu) {
            $violations[] = sprintf(
                'Proposed %d CPU(s) is below the %s of workload CPU requests already running.',
                $proposedCpus,
                dm_k8s_format_cpu($workloadCpu, 'cores')
            );
        }
    }
    if ($hostMemory > 0 && $proposedMemoryBytes > 0) {
        if ($proposedMemoryBytes < 512 * 1024 * 1024) {
            $violations[] = 'The k3d memory limit must be at least 512 MiB.';
        }
        if ($proposedMemoryBytes + $reserveMemory > $hostMemory) {
            $violations[] = sprintf(
                'Proposed %s plus %s host reserve exceed the %s of RAM on this host.',
                dm_k8s_format_memory($proposedMemoryBytes),
                dm_k8s_format_memory($reserveMemory),
                dm_k8s_format_memory($hostMemory)
            );
        }
        $workloadMemory = (int)($workload['memory_requests_bytes'] ?? 0);
        if ($workloadMemory > 0 && $proposedMemoryBytes < $workloadMemory) {
            $violations[] = sprintf(
                'Proposed %s is below the %s of workload memory requests already running.',
                dm_k8s_format_memory($proposedMemoryBytes),
                dm_k8s_format_memory($workloadMemory)
            );
        }
    }
    return $violations;
}

/**
 * Build the before/after preview shown before any apply. Never mutates state.
 *
 * @return array<string, mixed>
 */
function dm_k8s_resource_preview(array $host, array $k3d, array $workload, int $proposedCpus, int $proposedMemoryBytes): array
{
    $violations = dm_k8s_resource_violations($host, $workload, $proposedCpus, $proposedMemoryBytes);
    return [
        'current' => [
            'cpu_limit_cores' => (int)($k3d['cpu_limit_cores'] ?? 0),
            'cpuset_cpus' => (string)($k3d['cpuset_cpus'] ?? ''),
            'memory_limit_bytes' => (int)($k3d['memory_limit_bytes'] ?? 0),
        ],
        'proposed' => [
            'cpu_limit_cores' => $proposedCpus,
            'cpuset_cpus' => (string)($k3d['cpuset_cpus'] ?? ''),
            'memory_limit_bytes' => $proposedMemoryBytes,
        ],
        'host' => [
            'logical_cpus' => (int)($host['logical_cpus'] ?? 0),
            'memory_bytes' => (int)($host['memory_bytes'] ?? 0),
            'host_reserve_cpus' => (int)($workload['host_reserve_cpus'] ?? 1),
            'host_reserve_memory_bytes' => (int)($workload['host_reserve_memory_bytes'] ?? 0),
        ],
        'affected_workloads' => [
            'pods' => (int)($workload['pods'] ?? 0),
            'cpu_requests_millicores' => (int)($workload['cpu_requests_millicores'] ?? 0),
            'cpu_limits_millicores' => (int)($workload['cpu_limits_millicores'] ?? 0),
            'memory_requests_bytes' => (int)($workload['memory_requests_bytes'] ?? 0),
            'memory_limits_bytes' => (int)($workload['memory_limits_bytes'] ?? 0),
            'guaranteed_pods' => (int)($workload['guaranteed_pods'] ?? 0),
            'exclusive_eligible_pods' => (int)($workload['exclusive_eligible_pods'] ?? 0),
        ],
        'violations' => $violations,
        'safe' => $violations === [],
    ];
}

/**
 * Settings-level preview that does not depend on a reachable cluster: uses
 * live host reads plus the stored envelope values. Used by settings.php.
 *
 * @return array<string, mixed>
 */
function dm_k8s_settings_preview(int $proposedCores, int $proposedMemoryMib, int $reserveCpus, int $reserveMemoryMib): array
{
    $host = dm_k8s_host_envelope();
    $workload = [
        'host_reserve_cpus' => max(0, $reserveCpus),
        'host_reserve_memory_bytes' => max(0, $reserveMemoryMib) * 1048576,
        'pods' => 0,
        'cpu_requests_millicores' => 0,
        'cpu_limits_millicores' => 0,
        'memory_requests_bytes' => 0,
        'memory_limits_bytes' => 0,
        'guaranteed_pods' => 0,
        'exclusive_eligible_pods' => 0,
    ];
    return dm_k8s_resource_preview($host, ['available' => false], $workload, $proposedCores, $proposedMemoryMib * 1048576);
}
