<?php

declare(strict_types=1);

const DM_K8S_RC = '/etc/rc.d/rc.unraid-kubernetes';

function dm_k8s_settings_path(): string
{
    return getenv('UNRAID_K8S_SETTINGS') ?: '/boot/config/plugins/unraid.kubernetes/settings.cfg';
}

/** @return array<string, string> */
function dm_k8s_config(): array
{
    $defaults = [
        'PROVIDER' => 'k3d',
        'CLUSTER_NAME' => 'unraid-k3s',
        'DATA_ROOT' => '/mnt/user/appdata/unraid-kubernetes',
        'K3D_CONFIG' => '/mnt/user/appdata/unraid-kubernetes/config/k3d-unraid-k3s.yaml',
        'K3S_IMAGE' => 'rancher/k3s:v1.36.1-k3s1',
        'KUBECONFIG' => '/boot/config/plugins/unraid.kubernetes/external-kubeconfig.yaml',
        'SHOW_METRICS' => 'yes',
        'SHOW_DASHBOARD_WIDGET' => 'yes',
        'SHOW_KUBERNETES_PAGE' => 'yes',
        'SHOW_DOCKER_HEADER' => 'yes',
        'CPU_DISPLAY_UNIT' => 'auto',
        'REFRESH_INTERVAL' => '15',
    ];
    $settings = dm_k8s_settings_path();
    $stored = is_readable($settings)
        ? parse_ini_file($settings, false, INI_SCANNER_RAW)
        : [];
    $config = array_merge($defaults, is_array($stored) ? $stored : []);

    if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', (string)$config['CLUSTER_NAME'])) {
        throw new RuntimeException('Invalid cluster name in plugin settings');
    }
    if (!in_array($config['PROVIDER'], ['k3d', 'external'], true)) {
        throw new RuntimeException('Invalid Kubernetes provider in plugin settings');
    }
    foreach (['SHOW_METRICS', 'SHOW_DASHBOARD_WIDGET', 'SHOW_KUBERNETES_PAGE', 'SHOW_DOCKER_HEADER'] as $key) {
        if (!in_array($config[$key], ['yes', 'no'], true)) {
            throw new RuntimeException("Invalid {$key} setting");
        }
    }
    if (!in_array($config['CPU_DISPLAY_UNIT'], ['auto', 'percent', 'cores'], true)) {
        throw new RuntimeException('Invalid CPU display unit setting');
    }
    if (!in_array($config['REFRESH_INTERVAL'], ['5', '10', '15', '30', '60'], true)) {
        throw new RuntimeException('Invalid refresh interval setting');
    }
    foreach (['DATA_ROOT', 'K3D_CONFIG', 'KUBECONFIG'] as $key) {
        if (!str_starts_with((string)$config[$key], '/')) {
            throw new RuntimeException("Invalid {$key} in plugin settings");
        }
    }
    return array_map('strval', $config);
}

/** @param list<string> $arguments */
function dm_k8s_kubectl(array $config, array $arguments, ?int &$exitCode = null): string
{
    if ($config['PROVIDER'] === 'external') {
        return dm_k8s_run(array_merge([
            '/usr/local/bin/kubectl', '--kubeconfig', $config['KUBECONFIG'],
        ], $arguments), $exitCode);
    }

    $server = "k3d-{$config['CLUSTER_NAME']}-server-0";
    $kubeconfig = "/etc/rancher/k3s/kubeconfig/{$config['CLUSTER_NAME']}.yaml";
    return dm_k8s_run(array_merge([
        '/usr/bin/docker', 'exec', $server, 'kubectl', '--kubeconfig', $kubeconfig,
    ], $arguments), $exitCode);
}

/** @param list<string> $arguments */
function dm_k8s_run(array $arguments, ?int &$exitCode = null): string
{
    $command = implode(' ', array_map('escapeshellarg', array_merge(['/usr/bin/timeout', '10s'], $arguments)));
    $output = [];
    $code = 0;
    exec($command . ' 2>/dev/null', $output, $code);
    $exitCode = $code;
    return implode("\n", $output);
}

function dm_k8s_memory(string $value): string
{
    if (preg_match('/^(\d+)Ki$/', $value, $matches)) {
        return number_format(((int)$matches[1]) / 1024 / 1024, 1) . ' GiB';
    }
    return $value;
}

function dm_k8s_cpu_millicores(string $value): int
{
    if (preg_match('/^(\d+)n$/', $value, $matches)) {
        return (int)$matches[1] > 0 ? max(1, (int)round(((int)$matches[1]) / 1000000)) : 0;
    }
    if (preg_match('/^(\d+)u$/', $value, $matches)) {
        return (int)$matches[1] > 0 ? max(1, (int)round(((int)$matches[1]) / 1000)) : 0;
    }
    if (preg_match('/^(\d+)m$/', $value, $matches)) {
        return (int)$matches[1];
    }
    return (int)round(((float)$value) * 1000);
}

function dm_k8s_memory_bytes(string $value): int
{
    if (!preg_match('/^([0-9.]+)(Ki|Mi|Gi|Ti)?$/', $value, $matches)) {
        return 0;
    }
    $multipliers = ['' => 1, 'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4];
    return (int)round(((float)$matches[1]) * $multipliers[$matches[2] ?? '']);
}

function dm_k8s_format_cpu(int $millicores, string $unit = 'auto'): string
{
    if ($unit === 'cores' || ($unit === 'auto' && $millicores >= 1000)) {
        return number_format($millicores / 1000, 2) . ' cores';
    }
    return number_format($millicores / 10, 1) . '% core';
}

function dm_k8s_format_memory(int $bytes): string
{
    if ($bytes >= 1024 ** 3) {
        return number_format($bytes / (1024 ** 3), 1) . ' GiB';
    }
    return number_format($bytes / (1024 ** 2), 0) . ' MiB';
}

function dm_k8s_format_usage(string $value, int $used, int $capacity): string
{
    if ($capacity <= 0) {
        return $value;
    }
    return sprintf('%s (%d%%)', $value, (int)round(($used / $capacity) * 100));
}

/** @return array<string, mixed> */
function dm_k8s_status(): array
{
    $config = dm_k8s_config();
    $cluster = $config['CLUSTER_NAME'];
    $server = "k3d-{$cluster}-server-0";
    $provider = $config['PROVIDER'];
    $inspectCode = 0;
    if ($provider === 'external') {
        $running = trim(dm_k8s_kubectl($config, ['get', '--raw=/readyz'], $inspectCode)) === 'ok' ? 'true' : 'false';
    } else {
        $running = trim(dm_k8s_run([
            '/usr/bin/docker', 'inspect', '--format', '{{.State.Running}}', $server,
        ], $inspectCode));
    }

    $response = [
        'cluster' => [
            'name' => $cluster,
            'state' => $inspectCode === 0
                ? ($running === 'true' ? 'Running' : ($provider === 'k3d' ? 'Stopped' : 'Unavailable'))
                : ($provider === 'k3d' ? 'Not installed' : 'Unavailable'),
            'image' => $config['K3S_IMAGE'],
            'provider' => $provider,
            'managed' => $provider === 'k3d',
        ],
        'nodes' => [],
        'pods' => [],
        'namespaces' => [],
        'warnings' => [],
        'runtime' => [],
        'metrics_enabled' => $config['SHOW_METRICS'] === 'yes',
        'refresh_interval' => (int)$config['REFRESH_INTERVAL'],
        'error' => null,
        'updated_at' => gmdate(DATE_ATOM),
    ];

    if ($running !== 'true') {
        return $response;
    }

    if ($provider === 'k3d') {
        $runtimeRows = dm_k8s_run([
            '/usr/bin/docker', 'ps', '--all', '--filter', "label=k3d.cluster={$cluster}", '--format', '{{json .}}',
        ]);
        foreach (array_filter(explode("\n", $runtimeRows)) as $runtimeRow) {
            $container = json_decode($runtimeRow, true);
            if (!is_array($container)) {
                continue;
            }
            $response['runtime'][] = [
                'id' => $container['ID'] ?? '',
                'name' => $container['Names'] ?? 'unknown',
                'image' => $container['Image'] ?? 'unknown',
                'state' => $container['State'] ?? 'unknown',
                'status' => $container['Status'] ?? '',
                'cpu' => '-',
                'memory' => '-',
                'memory_usage' => '',
            ];
        }

        $runtimeIds = array_values(array_filter(array_column($response['runtime'], 'id')));
        if ($runtimeIds !== []) {
            $statsRows = dm_k8s_run(array_merge([
                '/usr/bin/docker', 'stats', '--no-stream', '--format', '{{json .}}',
            ], $runtimeIds));
            $statsByContainer = [];
            foreach (array_filter(explode("\n", $statsRows)) as $statsRow) {
                $stats = json_decode($statsRow, true);
                if (!is_array($stats)) {
                    continue;
                }
                foreach (['Container', 'ID', 'Name'] as $key) {
                    if (!empty($stats[$key])) {
                        $statsByContainer[(string)$stats[$key]] = $stats;
                    }
                }
            }
            foreach ($response['runtime'] as &$container) {
                $stats = $statsByContainer[$container['id']] ?? $statsByContainer[$container['name']] ?? null;
                if ($stats !== null) {
                    $container['cpu'] = $stats['CPUPerc'] ?? '-';
                    $container['memory'] = $stats['MemPerc'] ?? '-';
                    $container['memory_usage'] = $stats['MemUsage'] ?? '';
                }
            }
            unset($container);
        }
    }

    $nodesCode = $podsCode = $eventsCode = $nodeMetricsCode = $podMetricsCode = 0;
    $nodes = json_decode(dm_k8s_kubectl($config, [
        'get', 'nodes', '-o', 'json',
    ], $nodesCode), true);
    $pods = json_decode(dm_k8s_kubectl($config, [
        'get', 'pods', '--all-namespaces', '-o', 'json',
    ], $podsCode), true);
    $events = json_decode(dm_k8s_kubectl($config, [
        'get', 'events', '--all-namespaces',
        '--field-selector', 'type=Warning', '--sort-by=.lastTimestamp', '-o', 'json',
    ], $eventsCode), true);
    $nodeMetrics = $podMetrics = ['items' => []];
    if ($config['SHOW_METRICS'] === 'yes') {
        $nodeMetrics = json_decode(dm_k8s_kubectl($config, [
            'get', '--raw=/apis/metrics.k8s.io/v1beta1/nodes',
        ], $nodeMetricsCode), true);
        $podMetrics = json_decode(dm_k8s_kubectl($config, [
            'get', '--raw=/apis/metrics.k8s.io/v1beta1/pods',
        ], $podMetricsCode), true);
    }

    if ($nodesCode !== 0 || $podsCode !== 0 || !is_array($nodes) || !is_array($pods)) {
        $response['cluster']['state'] = 'Degraded';
        $response['error'] = 'The Kubernetes API did not return node and pod status.';
        return $response;
    }
    if ($eventsCode !== 0 || !is_array($events)) {
        $events = ['items' => []];
    }
    if ($nodeMetricsCode !== 0 || !is_array($nodeMetrics)) {
        $nodeMetrics = ['items' => []];
    }
    if ($podMetricsCode !== 0 || !is_array($podMetrics)) {
        $podMetrics = ['items' => []];
    }

    $nodeUsage = [];
    foreach (($nodeMetrics['items'] ?? []) as $metric) {
        $nodeUsage[$metric['metadata']['name'] ?? ''] = [
            'cpu' => dm_k8s_cpu_millicores((string)($metric['usage']['cpu'] ?? '0')),
            'memory' => dm_k8s_memory_bytes((string)($metric['usage']['memory'] ?? '0')),
        ];
    }
    $podUsage = [];
    foreach (($podMetrics['items'] ?? []) as $metric) {
        $cpu = $memory = 0;
        foreach (($metric['containers'] ?? []) as $container) {
            $cpu += dm_k8s_cpu_millicores((string)($container['usage']['cpu'] ?? '0'));
            $memory += dm_k8s_memory_bytes((string)($container['usage']['memory'] ?? '0'));
        }
        $key = ($metric['metadata']['namespace'] ?? 'default') . '/' . ($metric['metadata']['name'] ?? '');
        $podUsage[$key] = ['cpu' => $cpu, 'memory' => $memory];
    }

    foreach (($nodes['items'] ?? []) as $node) {
        $ready = false;
        foreach (($node['status']['conditions'] ?? []) as $condition) {
            if (($condition['type'] ?? '') === 'Ready') {
                $ready = ($condition['status'] ?? '') === 'True';
            }
        }
        $roles = [];
        foreach (array_keys($node['metadata']['labels'] ?? []) as $label) {
            if (str_starts_with($label, 'node-role.kubernetes.io/')) {
                $roles[] = substr($label, strlen('node-role.kubernetes.io/')) ?: 'worker';
            }
        }
        $name = $node['metadata']['name'] ?? 'unknown';
        $usage = $nodeUsage[$name] ?? null;
        $cpuCapacity = dm_k8s_cpu_millicores((string)($node['status']['capacity']['cpu'] ?? '0'));
        $memoryCapacity = dm_k8s_memory_bytes((string)($node['status']['capacity']['memory'] ?? '0'));
        $response['nodes'][] = [
            'name' => $name,
            'ready' => $ready,
            'roles' => $roles ?: ['worker'],
            'version' => $node['status']['nodeInfo']['kubeletVersion'] ?? 'unknown',
            'cpu' => $usage ? dm_k8s_format_usage(dm_k8s_format_cpu($usage['cpu'], $config['CPU_DISPLAY_UNIT']), $usage['cpu'], $cpuCapacity) : '-',
            'memory' => $usage ? dm_k8s_format_usage(dm_k8s_format_memory($usage['memory']), $usage['memory'], $memoryCapacity) : '-',
        ];
    }

    $namespaceCounts = [];
    foreach (($pods['items'] ?? []) as $pod) {
        $namespace = $pod['metadata']['namespace'] ?? 'default';
        $phase = $pod['status']['phase'] ?? 'Unknown';
        $readyContainers = 0;
        $restarts = 0;
        foreach (($pod['status']['containerStatuses'] ?? []) as $container) {
            $readyContainers += !empty($container['ready']) ? 1 : 0;
            $restarts += (int)($container['restartCount'] ?? 0);
        }
        $totalContainers = count($pod['spec']['containers'] ?? []);
        $isReady = $totalContainers > 0 && $readyContainers === $totalContainers && $phase === 'Running';
        $usage = $podUsage[$namespace . '/' . ($pod['metadata']['name'] ?? '')] ?? null;
        $namespaceCounts[$namespace] ??= ['pods' => 0, 'ready' => 0, 'cpu' => 0, 'memory' => 0];
        $namespaceCounts[$namespace]['pods']++;
        $namespaceCounts[$namespace]['ready'] += $isReady ? 1 : 0;
        $namespaceCounts[$namespace]['cpu'] += $usage['cpu'] ?? 0;
        $namespaceCounts[$namespace]['memory'] += $usage['memory'] ?? 0;
        $response['pods'][] = [
            'namespace' => $namespace,
            'name' => $pod['metadata']['name'] ?? 'unknown',
            'ready' => "{$readyContainers}/{$totalContainers}",
            'phase' => $phase,
            'restarts' => $restarts,
            'cpu' => $usage ? dm_k8s_format_cpu($usage['cpu'], $config['CPU_DISPLAY_UNIT']) : '-',
            'memory' => $usage ? dm_k8s_format_memory($usage['memory']) : '-',
            'node' => $pod['spec']['nodeName'] ?? '-',
        ];
    }
    ksort($namespaceCounts);
    foreach ($namespaceCounts as $name => $counts) {
        $response['namespaces'][] = [
            'name' => $name,
            'pods' => $counts['pods'],
            'ready' => $counts['ready'],
            'cpu' => dm_k8s_format_cpu($counts['cpu'], $config['CPU_DISPLAY_UNIT']),
            'memory' => dm_k8s_format_memory($counts['memory']),
        ];
    }

    $warningCutoff = time() - 900;
    foreach (array_slice($events['items'] ?? [], -20) as $event) {
        $eventTime = (string)($event['lastTimestamp'] ?? $event['eventTime'] ?? '');
        $eventTimestamp = $eventTime !== '' ? strtotime($eventTime) : false;
        if ($eventTimestamp === false || $eventTimestamp < $warningCutoff) {
            continue;
        }
        $response['warnings'][] = [
            'namespace' => $event['metadata']['namespace'] ?? 'default',
            'reason' => $event['reason'] ?? 'Warning',
            'message' => $event['message'] ?? '',
            'object' => $event['involvedObject']['name'] ?? '',
            'time' => $eventTime,
        ];
    }

    return $response;
}
