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
                'name' => $container['Names'] ?? 'unknown',
                'image' => $container['Image'] ?? 'unknown',
                'state' => $container['State'] ?? 'unknown',
                'status' => $container['Status'] ?? '',
            ];
        }
    }

    $nodesCode = $podsCode = $eventsCode = 0;
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

    if ($nodesCode !== 0 || $podsCode !== 0 || !is_array($nodes) || !is_array($pods)) {
        $response['cluster']['state'] = 'Degraded';
        $response['error'] = 'The Kubernetes API did not return node and pod status.';
        return $response;
    }
    if ($eventsCode !== 0 || !is_array($events)) {
        $events = ['items' => []];
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
        $response['nodes'][] = [
            'name' => $node['metadata']['name'] ?? 'unknown',
            'ready' => $ready,
            'roles' => $roles ?: ['worker'],
            'version' => $node['status']['nodeInfo']['kubeletVersion'] ?? 'unknown',
            'cpu' => $node['status']['capacity']['cpu'] ?? '-',
            'memory' => dm_k8s_memory((string)($node['status']['capacity']['memory'] ?? '-')),
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
        $namespaceCounts[$namespace] ??= ['pods' => 0, 'ready' => 0];
        $namespaceCounts[$namespace]['pods']++;
        $namespaceCounts[$namespace]['ready'] += $isReady ? 1 : 0;
        $response['pods'][] = [
            'namespace' => $namespace,
            'name' => $pod['metadata']['name'] ?? 'unknown',
            'ready' => "{$readyContainers}/{$totalContainers}",
            'phase' => $phase,
            'restarts' => $restarts,
            'node' => $pod['spec']['nodeName'] ?? '-',
        ];
    }
    ksort($namespaceCounts);
    foreach ($namespaceCounts as $name => $counts) {
        $response['namespaces'][] = ['name' => $name] + $counts;
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
