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

/** Terminal Pods are history regardless of their controller or lack of one. */
function dm_k8s_pod_is_terminal(string $phase): bool
{
    return in_array($phase, ['Succeeded', 'Failed'], true);
}

/** Classify a warning event as current or recovered against live object state. */
function dm_k8s_warning_state(array $event, array $jobState, array $activePodsByKey): string
{
    $kind = (string)($event['involvedObject']['kind'] ?? '');
    $objectNamespace = (string)($event['involvedObject']['namespace'] ?? $event['metadata']['namespace'] ?? 'default');
    $key = $objectNamespace . '/' . (string)($event['involvedObject']['name'] ?? '');
    if ($kind === 'Job' && isset($jobState[$key])) {
        return $jobState[$key];
    }
    if ($kind === 'Pod') {
        if (!array_key_exists($key, $activePodsByKey)) {
            return 'recovered';
        }
        return $activePodsByKey[$key] ? 'recovered' : 'current';
    }
    return 'current';
}

/** Classify a host bind address as loopback, wildcard (all interfaces), or LAN/other. */
function dm_k8s_bind_class(string $hostIp): string
{
    if ($hostIp === '' || $hostIp === '0.0.0.0' || $hostIp === '::') {
        return 'wildcard';
    }
    if (str_starts_with($hostIp, '127.') || $hostIp === '::1') {
        return 'loopback';
    }
    return 'lan';
}

/** Parse only the tracked ports[] publications from a k3d config file. */
function dm_k8s_parse_k3d_ports(string $configFile): array
{
    $ports = [];
    if (!is_readable($configFile)) {
        return $ports;
    }
    $lines = file($configFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $ports;
    }
    $inPorts = false;
    foreach ($lines as $line) {
        if (preg_match('/^ports:\s*$/', $line)) {
            $inPorts = true;
            continue;
        }
        if ($inPorts && preg_match('/^[A-Za-z]/', $line)) {
            $inPorts = false;
        }
        if (!$inPorts) {
            continue;
        }
        if (preg_match('/^\s*-\s*port:\s*(\S+)/', $line, $matches)) {
            $parts = explode(':', $matches[1]);
            if (count($parts) === 2) {
                $ports[] = ['host_ip' => '', 'host_port' => $parts[0], 'node_port' => $parts[1], 'node' => ''];
            } elseif (count($parts) === 3) {
                $ports[] = ['host_ip' => $parts[0], 'host_port' => $parts[1], 'node_port' => $parts[2], 'node' => ''];
            }
        } elseif ($ports !== [] && preg_match('/^\s*-\s*(\S+)\s*$/', $line, $matches)) {
            $ports[count($ports) - 1]['node'] = $matches[1];
        }
    }
    return $ports;
}

/** Read live k3d host publications from the load-balancer and server containers. */
function dm_k8s_live_publications(string $cluster): array
{
    $result = ['available' => false, 'ports' => []];
    $live = [];
    foreach (["k3d-{$cluster}-serverlb", "k3d-{$cluster}-server-0"] as $container) {
        $code = 0;
        $rows = dm_k8s_run([
            '/usr/bin/docker', 'inspect', '--format', '{{json .HostConfig.PortBindings}}', $container,
        ], $code);
        $bindings = $code === 0 ? json_decode($rows, true) : null;
        if (!is_array($bindings)) {
            return $result;
        }
        foreach ($bindings as $containerPort => $hostBindings) {
            $nodePort = (string)(int)strtok((string)$containerPort, '/');
            foreach ((array)$hostBindings as $binding) {
                $live[] = [
                    'host_ip' => (string)($binding['HostIp'] ?? ''),
                    'host_port' => (string)($binding['HostPort'] ?? ''),
                    'node_port' => $nodePort,
                    'node' => $container,
                ];
            }
        }
    }
    $result['available'] = true;
    $result['ports'] = $live;
    return $result;
}

/** Reconcile tracked and live publications into drift and duplicate findings. */
function dm_k8s_publication_findings(array $tracked, array $live): array
{
    $hostKey = static function (array $port): string {
        $ip = (string)($port['host_ip'] ?? '');
        return ($ip === '' ? '*' : $ip) . ':' . (string)($port['host_port'] ?? '');
    };
    $fullKey = static function (array $port) use ($hostKey): string {
        return $hostKey($port) . ':' . (string)($port['node_port'] ?? '');
    };
    $trackedByFull = [];
    foreach ($tracked as $port) {
        $trackedByFull[$fullKey($port)] = $port;
    }
    $liveByFull = [];
    foreach ($live as $port) {
        $liveByFull[$fullKey($port)] = $port;
    }
    $drift = [];
    foreach ($trackedByFull as $key => $port) {
        if (!isset($liveByFull[$key])) {
            $drift[] = $port + ['state' => 'tracked-only'];
        }
    }
    foreach ($liveByFull as $key => $port) {
        if (!isset($trackedByFull[$key])) {
            $drift[] = $port + ['state' => 'live-only'];
        }
    }
    $duplicates = [];
    $byNodePort = [];
    foreach (array_merge($tracked, $live) as $port) {
        $byNodePort[(string)($port['node_port'] ?? '')][] = $hostKey($port);
    }
    foreach ($byNodePort as $nodePort => $bindings) {
        if (count(array_unique($bindings)) > 1) {
            $duplicates[] = ['node_port' => $nodePort, 'bindings' => array_values(array_unique($bindings))];
        }
    }
    return ['drift' => $drift, 'duplicates' => $duplicates];
}

/** Return whether every Service selector key/value matches the Pod labels. */
function dm_k8s_selector_matches(array $selector, array $labels): bool
{
    foreach ($selector as $key => $value) {
        if (($labels[$key] ?? null) !== $value) {
            return false;
        }
    }
    return true;
}

/** Collect ready and not-ready EndpointSlice addresses for one Service. */
function dm_k8s_endpoint_addresses(array $slices, string $namespace, string $service): array
{
    $ready = [];
    $notReady = [];
    foreach ($slices as $slice) {
        if (($slice['metadata']['namespace'] ?? '') !== $namespace) {
            continue;
        }
        if ((string)($slice['metadata']['labels']['kubernetes.io/service-name'] ?? '') !== $service) {
            continue;
        }
        foreach (($slice['endpoints'] ?? []) as $endpoint) {
            foreach (($endpoint['addresses'] ?? []) as $address) {
                $address = (string)$address;
                if (!empty($endpoint['conditions']['ready'])) {
                    $ready[$address] = true;
                } else {
                    $notReady[$address] = true;
                }
            }
        }
    }
    return ['ready' => array_keys($ready), 'not_ready' => array_keys($notReady)];
}

/** Collect Ingress host/path routes whose backend targets the named Service. */
function dm_k8s_ingress_routes(array $ingresses, string $namespace, string $service): array
{
    $routes = [];
    foreach ($ingresses as $ingress) {
        if (($ingress['metadata']['namespace'] ?? '') !== $namespace) {
            continue;
        }
        $class = (string)($ingress['spec']['ingressClassName'] ?? '');
        foreach (($ingress['spec']['rules'] ?? []) as $rule) {
            foreach (($rule['http']['paths'] ?? []) as $path) {
                if ((string)($path['backend']['service']['name'] ?? '') === $service) {
                    $routes[] = [
                        'host' => (string)($rule['host'] ?? ''),
                        'path' => (string)($path['path'] ?? '/'),
                        'class' => $class,
                    ];
                }
            }
        }
    }
    return $routes;
}

/** Return whether an IPv4 address falls inside a CIDR range. */
function dm_k8s_ip_in_cidr(string $ip, string $cidr): bool
{
    if (!str_contains($cidr, '/')) {
        return $ip === $cidr;
    }
    [$network, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 32) {
        return false;
    }
    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
    $ipLong = ip2long($ip);
    $netLong = ip2long($network);
    return $ipLong !== false && $netLong !== false
        && (((int)$ipLong & $mask) === ((int)$netLong & $mask));
}

/**
 * Assign exactly one exposure class per the network visibility contract:
 * public, lan-only, loopback-only, or cluster-internal. First match wins.
 *
 * @param list<array> $selectedServices Each with external_path, publications,
 *   ingress_routes, node_ports, and type keys.
 * @param list<array> $livePublications Cluster-wide live host publications.
 * @param list<array> $hostPorts Declared host ports for hostNetwork Pods.
 */
function dm_k8s_classify_exposure(array $selectedServices, array $livePublications, bool $hostNetwork, array $hostPorts): string
{
    $classes = [];
    if ($hostNetwork) {
        foreach ($hostPorts as $port) {
            $classes[] = dm_k8s_bind_class((string)($port['host_ip'] ?? '')) === 'loopback'
                ? 'loopback-only'
                : 'lan-only';
        }
    }
    $ingressTermination = [];
    foreach ($livePublications as $publication) {
        if (in_array((string)($publication['node_port'] ?? ''), ['80', '443'], true)) {
            $ingressTermination[] = $publication;
        }
    }
    foreach ($selectedServices as $service) {
        if (($service['type'] ?? '') === 'ExternalName') {
            continue;
        }
        if (!empty($service['external_path'])) {
            $classes[] = 'public';
            continue;
        }
        $binds = [];
        foreach (($service['publications'] ?? []) as $publication) {
            $binds[] = dm_k8s_bind_class((string)($publication['host_ip'] ?? ''));
        }
        if (($service['ingress_routes'] ?? []) !== []) {
            foreach ($ingressTermination as $publication) {
                $binds[] = dm_k8s_bind_class((string)($publication['host_ip'] ?? ''));
            }
        }
        if (in_array('lan', $binds, true) || in_array('wildcard', $binds, true)) {
            $classes[] = 'lan-only';
        } elseif (in_array('loopback', $binds, true)) {
            $classes[] = 'loopback-only';
        }
    }
    foreach (['public', 'lan-only', 'loopback-only'] as $class) {
        if (in_array($class, $classes, true)) {
            return $class;
        }
    }
    return 'cluster-internal';
}

/**
 * Build the Network visibility row for one Pod from read-only cluster data.
 *
 * @param array $pod Pod object.
 * @param list<array> $servicesItems Service objects.
 * @param list<array> $slices EndpointSlice objects.
 * @param list<array> $ingresses Ingress objects.
 * @param array<string, bool> $policyPresent NetworkPolicy presence by namespace.
 * @param list<array> $livePublications Live k3d host publications.
 * @param array<string, string> $podCidrs Node pod CIDR by node name.
 * @param list<array> $namespacePodLabels Labels of other Pods in the namespace.
 * @param array<string, list<string>> $findingRules Finding rule names by node port.
 */
function dm_k8s_pod_network(
    array $pod,
    array $servicesItems,
    array $slices,
    array $ingresses,
    array $policyPresent,
    array $livePublications,
    array $podCidrs,
    array $namespacePodLabels,
    bool $cloudflaredPresent,
    array $findingRules
): array {
    $namespace = (string)($pod['metadata']['namespace'] ?? 'default');
    $labels = (array)($pod['metadata']['labels'] ?? []);
    $podIp = (string)($pod['status']['podIP'] ?? '');
    $hostNetwork = !empty($pod['spec']['hostNetwork']);
    $hostIp = (string)($pod['status']['hostIP'] ?? '');
    $nodeName = (string)($pod['spec']['nodeName'] ?? '');
    $cidr = (string)($podCidrs[$nodeName] ?? '');
    $cniLabel = '';
    if ($podIp !== '') {
        $cniLabel = $cidr !== '' && dm_k8s_ip_in_cidr($podIp, $cidr)
            ? "cluster CNI {$cidr}"
            : 'cluster CNI';
    }
    $hostPorts = [];
    if ($hostNetwork) {
        foreach (($pod['spec']['containers'] ?? []) as $container) {
            foreach (($container['ports'] ?? []) as $port) {
                $hostPorts[] = [
                    'container_port' => (int)($port['containerPort'] ?? 0),
                    'host_port' => (int)($port['hostPort'] ?? ($port['containerPort'] ?? 0)),
                    'protocol' => (string)($port['protocol'] ?? 'TCP'),
                    'host_ip' => (string)($port['hostIP'] ?? ''),
                ];
            }
        }
    }

    $selected = [];
    foreach ($servicesItems as $service) {
        $selector = (array)($service['spec']['selector'] ?? []);
        if ($selector === [] || ($service['metadata']['namespace'] ?? '') !== $namespace) {
            continue;
        }
        if (!dm_k8s_selector_matches($selector, $labels)) {
            continue;
        }
        $serviceName = (string)($service['metadata']['name'] ?? '');
        $ports = [];
        $nodePorts = [];
        foreach (($service['spec']['ports'] ?? []) as $port) {
            $entry = [
                'port' => (int)($port['port'] ?? 0),
                'target_port' => (string)($port['targetPort'] ?? ''),
                'protocol' => (string)($port['protocol'] ?? 'TCP'),
                'node_port' => 0,
            ];
            if (!empty($port['nodePort'])) {
                $entry['node_port'] = (int)$port['nodePort'];
                $nodePorts[] = (string)$port['nodePort'];
            }
            $ports[] = $entry;
        }
        $servicePublications = [];
        $unpublishedNodePorts = [];
        foreach ($nodePorts as $nodePort) {
            $mapped = array_values(array_filter(
                $livePublications,
                static fn (array $publication): bool => (string)($publication['node_port'] ?? '') === $nodePort
            ));
            if ($mapped !== []) {
                $servicePublications = array_merge($servicePublications, $mapped);
            } else {
                $unpublishedNodePorts[] = $nodePort;
            }
        }
        $routes = dm_k8s_ingress_routes($ingresses, $namespace, $serviceName);
        $selected[] = [
            'name' => $serviceName,
            'source' => 'Service',
            'type' => (string)($service['spec']['type'] ?? 'ClusterIP'),
            'ports' => $ports,
            'selector' => $selector,
            'ready_endpoints' => [],
            'ready_count' => 0,
            'not_ready_count' => 0,
            'endpoints_source' => 'EndpointSlice',
            'node_ports' => $nodePorts,
            'publications' => $servicePublications,
            'publications_source' => 'k3d',
            'unpublished_node_ports' => $unpublishedNodePorts,
            'ingress_routes' => $routes,
            'ingress_source' => 'Ingress',
            'external_path' => $cloudflaredPresent && $routes !== [],
        ];
        $endpoints = dm_k8s_endpoint_addresses($slices, $namespace, $serviceName);
        $selected[count($selected) - 1]['ready_endpoints'] = $endpoints['ready'];
        $selected[count($selected) - 1]['ready_count'] = count($endpoints['ready']);
        $selected[count($selected) - 1]['not_ready_count'] = count($endpoints['not_ready']);
    }

    $warnings = [];
    $seenWarnings = [];
    $addWarning = static function (string $rule, string $message, string $source) use (&$warnings, &$seenWarnings): void {
        $key = $rule . ':' . $message;
        if (isset($seenWarnings[$key])) {
            return;
        }
        $seenWarnings[$key] = true;
        $warnings[] = ['rule' => $rule, 'message' => $message, 'source' => $source];
    };

    $unselectedOverlap = [];
    foreach ($servicesItems as $service) {
        $selector = (array)($service['spec']['selector'] ?? []);
        if ($selector === [] || ($service['metadata']['namespace'] ?? '') !== $namespace) {
            continue;
        }
        if (dm_k8s_selector_matches($selector, $labels)) {
            continue;
        }
        foreach ($selector as $key => $value) {
            if (array_key_exists($key, $labels)) {
                $unselectedOverlap[(string)($service['metadata']['name'] ?? '')] = true;
                break;
            }
        }
    }
    foreach (array_keys($unselectedOverlap) as $serviceName) {
        $sliceCount = 0;
        foreach ($slices as $slice) {
            if (($slice['metadata']['namespace'] ?? '') === $namespace
                && (string)($slice['metadata']['labels']['kubernetes.io/service-name'] ?? '') === $serviceName) {
                $sliceCount += count($slice['endpoints'] ?? []);
            }
        }
        if ($sliceCount === 0) {
            $addWarning(
                'selector-mismatch',
                "{$serviceName}: selector matches no Pod but overlaps existing Pod labels",
                'Service'
            );
        }
    }

    $class = dm_k8s_classify_exposure($selected, $livePublications, $hostNetwork, $hostPorts);

    foreach ($selected as $serviceEntry) {
        if ($serviceEntry['ready_count'] === 0 && $serviceEntry['not_ready_count'] > 0) {
            $addWarning(
                'no-ready-endpoints',
                "{$serviceEntry['name']}: EndpointSlices report no ready addresses",
                'EndpointSlice'
            );
        } elseif ($serviceEntry['ready_count'] === 0 && $serviceEntry['not_ready_count'] === 0) {
            $overlaps = false;
            foreach ($namespacePodLabels as $otherLabels) {
                foreach ($serviceEntry['selector'] as $key => $value) {
                    if (array_key_exists($key, $otherLabels)) {
                        $overlaps = true;
                        break 2;
                    }
                }
            }
            if ($overlaps) {
                $addWarning(
                    'selector-mismatch',
                    "{$serviceEntry['name']}: selector matches no Pod but overlaps existing Pod labels",
                    'Service'
                );
            } else {
                $addWarning(
                    'no-ready-endpoints',
                    "{$serviceEntry['name']}: EndpointSlice list is empty",
                    'EndpointSlice'
                );
            }
        }
        foreach ($serviceEntry['publications'] as $publication) {
            if (dm_k8s_bind_class((string)($publication['host_ip'] ?? '')) === 'wildcard') {
                $addWarning(
                    'wildcard-binding',
                    "{$serviceEntry['name']}: host publication binds all interfaces",
                    'k3d'
                );
                break;
            }
        }
        foreach ($serviceEntry['node_ports'] as $nodePort) {
            foreach ($findingRules[(string)$nodePort] ?? [] as $rule) {
                $addWarning(
                    $rule,
                    "{$serviceEntry['name']}: node port {$nodePort} has {$rule} findings",
                    'k3d'
                );
            }
        }
    }
    if ($hostNetwork) {
        foreach ($hostPorts as $port) {
            if ((string)($port['host_ip'] ?? '') === '') {
                $addWarning(
                    'wildcard-binding',
                    'hostNetwork Pod declares host ports without a bind address (all interfaces)',
                    'Pod'
                );
                break;
            }
        }
    }
    $policyMissing = empty($policyPresent[$namespace]);
    if ($policyMissing && in_array($class, ['lan-only', 'public'], true)) {
        $addWarning(
            'missing-networkpolicy',
            "{$namespace}: no NetworkPolicy objects exist for a {$class} row",
            'NetworkPolicy'
        );
    }
    if ($policyMissing) {
        foreach ($selected as $serviceEntry) {
            if (($serviceEntry['unpublished_node_ports'] ?? []) !== []) {
                $addWarning(
                    'missing-networkpolicy',
                    "{$serviceEntry['name']}: NodePort without host publication in a namespace with no NetworkPolicy",
                    'NetworkPolicy'
                );
                break;
            }
        }
    }

    return [
        'class' => $class,
        'pod_ip' => $podIp,
        'cni' => $cniLabel,
        'host_network' => $hostNetwork,
        'host_ip' => $hostIp,
        'host_ports' => $hostPorts,
        'services' => $selected,
        'warnings' => $warnings,
    ];
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
        'jobs' => [],
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

    $nodesCode = $podsCode = $eventsCode = $jobsCode = $nodeMetricsCode = $podMetricsCode = 0;
    $servicesCode = $slicesCode = $ingressCode = $policiesCode = 0;
    $nodes = json_decode(dm_k8s_kubectl($config, [
        'get', 'nodes', '-o', 'json',
    ], $nodesCode), true);
    $pods = json_decode(dm_k8s_kubectl($config, [
        'get', 'pods', '--all-namespaces', '-o', 'json',
    ], $podsCode), true);
    $services = json_decode(dm_k8s_kubectl($config, [
        'get', 'services', '--all-namespaces', '-o', 'json',
    ], $servicesCode), true);
    $slices = json_decode(dm_k8s_kubectl($config, [
        'get', 'endpointslices', '--all-namespaces', '-o', 'json',
    ], $slicesCode), true);
    $ingresses = json_decode(dm_k8s_kubectl($config, [
        'get', 'ingress', '--all-namespaces', '-o', 'json',
    ], $ingressCode), true);
    $policies = json_decode(dm_k8s_kubectl($config, [
        'get', 'networkpolicies', '--all-namespaces', '-o', 'json',
    ], $policiesCode), true);
    $jobs = json_decode(dm_k8s_kubectl($config, [
        'get', 'jobs', '--all-namespaces', '-o', 'json',
    ], $jobsCode), true);
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
    if ($jobsCode !== 0 || !is_array($jobs)) {
        $jobs = ['items' => []];
    }
    if ($servicesCode !== 0 || !is_array($services)) {
        $services = ['items' => []];
    }
    if ($slicesCode !== 0 || !is_array($slices)) {
        $slices = ['items' => []];
    }
    if ($ingressCode !== 0 || !is_array($ingresses)) {
        $ingresses = ['items' => []];
    }
    if ($policiesCode !== 0 || !is_array($policies)) {
        $policies = ['items' => []];
    }
    $policyPresent = [];
    foreach (($policies['items'] ?? []) as $policy) {
        $policyPresent[(string)($policy['metadata']['namespace'] ?? '')] = true;
    }
    $cloudflaredPresent = false;
    $podItems = (array)($pods['items'] ?? []);
    foreach ($podItems as $podItem) {
        foreach (($podItem['spec']['containers'] ?? []) as $container) {
            $image = (string)($container['image'] ?? '');
            if (str_contains($image, 'cloudflared')) {
                $cloudflaredPresent = true;
                break 2;
            }
        }
    }

    $trackedPublications = $provider === 'k3d'
        ? dm_k8s_parse_k3d_ports($config['K3D_CONFIG'])
        : [];
    $livePublicationState = $provider === 'k3d'
        ? dm_k8s_live_publications($cluster)
        : ['available' => false, 'ports' => []];
    $livePublications = (array)($livePublicationState['ports'] ?? []);
    $response['network'] = [
        'source_available' => (bool)$livePublicationState['available'],
        'source' => 'k3d',
        'state' => $livePublicationState['available'] ? 'ok' : 'provider-unavailable',
        'tracked_publications' => $trackedPublications,
        'live_publications' => $livePublications,
    ];
    $findings = $provider === 'k3d' && $livePublicationState['available']
        ? dm_k8s_publication_findings($trackedPublications, $livePublications)
        : ['drift' => [], 'duplicates' => []];
    $response['network']['drift'] = $findings['drift'];
    $response['network']['duplicates'] = $findings['duplicates'];
    $findingRules = [];
    foreach ($findings['duplicates'] as $duplicate) {
        $findingRules[(string)$duplicate['node_port']] = array_merge(
            $findingRules[(string)$duplicate['node_port']] ?? [],
            ['duplicate-host-ports']
        );
    }
    $response['network']['drift_rules'] = $findingRules;
    foreach (array_merge($findings['drift'], $findings['duplicates']) as $finding) {
        $rule = isset($finding['state']) ? 'tracked-vs-live-drift' : 'duplicate-host-ports';
        $response['network']['findings'][] = [
            'rule' => $rule,
            'host_ip' => (string)($finding['host_ip'] ?? ''),
            'host_port' => (string)($finding['host_port'] ?? ''),
            'node_port' => (string)($finding['node_port'] ?? ''),
            'state' => (string)($finding['state'] ?? 'live'),
            'source' => 'k3d',
        ];
    }
    $response['network']['findings'] = $response['network']['findings'] ?? [];
    $jobState = [];
    foreach (($jobs['items'] ?? []) as $job) {
        $jobKey = ($job['metadata']['namespace'] ?? 'default') . '/' . ($job['metadata']['name'] ?? '');
        $jobState[$jobKey] = !empty($job['status']['succeeded']) ? 'recovered' : 'current';
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
        $podCidrs[$name] = (string)($node['spec']['podCIDR'] ?? '');
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
    $activePodsByKey = [];
    $podCidrs = $podCidrs ?? [];
    $namespacePodLabels = [];
    $servicesItems = (array)($services['items'] ?? []);
    $slicesItems = (array)($slices['items'] ?? []);
    $ingressItems = (array)($ingresses['items'] ?? []);
    foreach ($podItems as $podItem) {
        if (dm_k8s_pod_is_terminal((string)($podItem['status']['phase'] ?? 'Unknown'))) {
            continue;
        }
        $namespacePodLabels[(string)($podItem['metadata']['namespace'] ?? 'default')][]
            = (array)($podItem['metadata']['labels'] ?? []);
    }
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
        if (dm_k8s_pod_is_terminal($phase)) {
            $finishedAt = '';
            foreach (($pod['status']['containerStatuses'] ?? []) as $container) {
                $ended = (string)($container['state']['terminated']['finishedAt'] ?? '');
                if ($ended !== '' && $ended > $finishedAt) {
                    $finishedAt = $ended;
                }
            }
            $response['jobs'][] = [
                'namespace' => $namespace,
                'name' => $pod['metadata']['name'] ?? 'unknown',
                'status' => $phase === 'Succeeded' ? 'Completed' : 'Failed',
                'restarts' => $restarts,
                'time' => $finishedAt !== '' ? $finishedAt : (string)($pod['metadata']['creationTimestamp'] ?? ''),
            ];
            continue;
        }
        $activePodsByKey[$namespace . '/' . ($pod['metadata']['name'] ?? '')] = $isReady;
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
            'network' => dm_k8s_pod_network(
                $pod,
                $servicesItems,
                $slicesItems,
                $ingressItems,
                $policyPresent,
                $livePublications,
                $podCidrs,
                $namespacePodLabels[$namespace] ?? [],
                $cloudflaredPresent,
                $findingRules
            ),
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
            'state' => dm_k8s_warning_state($event, $jobState, $activePodsByKey),
        ];
    }

    return $response;
}
