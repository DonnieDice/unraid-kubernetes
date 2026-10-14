<?php

declare(strict_types=1);

require __DIR__ . '/../src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php';

$failures = [];
$check = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

// Host parsing from /proc text.
$cpuinfo = "processor\t: 0\nprocessor\t: 1\nprocessor\t: 2\nprocessor\t: 3\n"
    . "core id\t\t: 0\ncore id\t\t: 0\ncore id\t\t: 1\ncore id\t\t: 1\n"
    . "physical id\t: 0\nphysical id\t: 0\nphysical id\t: 0\nphysical id\t: 0\n";
$parsed = dm_k8s_parse_cpuinfo($cpuinfo);
$check('cpuinfo logical cpus', $parsed['logical_cpus'] === 4);
$check('cpuinfo sockets', $parsed['sockets'] === 1);
$check('cpuinfo sibling pairs', $parsed['sibling_pairs'] === 2);
$check('meminfo parse', dm_k8s_parse_meminfo("MemTotal:       16777216 kB\n") === 17179869184);

// QoS classification.
$mkPod = static function (array $resources): array {
    return [
        'metadata' => ['name' => 'test', 'namespace' => 'apps'],
        'spec' => ['containers' => [['name' => 'c', 'resources' => $resources]]],
        'status' => ['phase' => 'Running'],
    ];
};
$check(
    'guaranteed qos',
    dm_k8s_pod_qos($mkPod([
        'requests' => ['cpu' => '1', 'memory' => '128Mi'],
        'limits' => ['cpu' => '1', 'memory' => '128Mi'],
    ])) === 'Guaranteed'
);
$check(
    'burstable qos (limit only)',
    dm_k8s_pod_qos($mkPod(['limits' => ['cpu' => '1']])) === 'Burstable'
);
$check(
    'burstable qos (request below limit)',
    dm_k8s_pod_qos($mkPod([
        'requests' => ['cpu' => '500m', 'memory' => '64Mi'],
        'limits' => ['cpu' => '1', 'memory' => '128Mi'],
    ])) === 'Burstable'
);
$check(
    'besteffort qos',
    dm_k8s_pod_qos($mkPod([])) === 'BestEffort'
);

// Exclusive-CPU eligibility requires static policy, Guaranteed QoS, and
// whole-core integer CPU limits.
$guaranteedWholeCore = $mkPod([
    'requests' => ['cpu' => '2', 'memory' => '256Mi'],
    'limits' => ['cpu' => '2', 'memory' => '256Mi'],
]);
$guaranteedPartialCore = $mkPod([
    'requests' => ['cpu' => '1500m', 'memory' => '256Mi'],
    'limits' => ['cpu' => '1500m', 'memory' => '256Mi'],
]);
$check('exclusive eligible under static policy', dm_k8s_pod_exclusive_eligible($guaranteedWholeCore, 'static') === true);
$check('exclusive blocked without static policy', dm_k8s_pod_exclusive_eligible($guaranteedWholeCore, 'none') === false);
$check('exclusive blocked for partial core', dm_k8s_pod_exclusive_eligible($guaranteedPartialCore, 'static') === false);
$check('exclusive blocked for burstable', dm_k8s_pod_exclusive_eligible($mkPod(['limits' => ['cpu' => '1']]), 'static') === false);

// Validation rules block unsafe proposals.
$host = [
    'logical_cpus' => 8,
    'sockets' => 1,
    'sibling_pairs' => 4,
    'numa_nodes' => 1,
    'memory_bytes' => 16 * 1024 ** 3,
];
$workload = [
    'pods' => 5,
    'cpu_requests_millicores' => 2000,
    'cpu_limits_millicores' => 4000,
    'memory_requests_bytes' => 2 * 1024 ** 3,
    'memory_limits_bytes' => 4 * 1024 ** 3,
    'guaranteed_pods' => 1,
    'exclusive_eligible_pods' => 0,
    'host_reserve_cpus' => 2,
    'host_reserve_memory_bytes' => 2 * 1024 ** 3,
];

$violations = dm_k8s_resource_violations($host, $workload, 4, 4 * 1024 ** 3);
$check('safe proposal passes', $violations === []);

$violations = dm_k8s_resource_violations($host, $workload, 7, 4 * 1024 ** 3);
$check('cpu over host reserve blocked', $violations !== [] && str_contains(implode(' ', $violations), 'host-reserved'));

$violations = dm_k8s_resource_violations($host, $workload, 3, 4 * 1024 ** 3);
$check('odd cpu count blocked on sibling host', $violations !== [] && str_contains(implode(' ', $violations), 'sibling'));

$numaHost = $host;
$numaHost['numa_nodes'] = 2;
$violations = dm_k8s_resource_violations($numaHost, $workload, 3, 4 * 1024 ** 3);
$check('numa imbalance blocked', $violations !== [] && str_contains(implode(' ', $violations), 'NUMA'));

$violations = dm_k8s_resource_violations($host, $workload, 1, 4 * 1024 ** 3);
$check('cpu below workload requests blocked', $violations !== [] && str_contains(implode(' ', $violations), 'workload CPU requests'));

$violations = dm_k8s_resource_violations($host, $workload, 4, 15 * 1024 ** 3);
$check('memory over host reserve blocked', $violations !== [] && str_contains(implode(' ', $violations), 'host reserve'));

$violations = dm_k8s_resource_violations($host, $workload, 4, 1 * 1024 ** 3);
$check('memory below workload requests blocked', $violations !== [] && str_contains(implode(' ', $violations), 'workload memory requests'));

$violations = dm_k8s_resource_violations($host, $workload, 4, 256 * 1024 ** 2);
$check('memory minimum blocked', $violations !== [] && str_contains(implode(' ', $violations), '512 MiB'));

// Preview contract: current vs proposed plus affected workloads.
$preview = dm_k8s_resource_preview(
    $host,
    ['available' => true, 'cpu_limit_cores' => 4, 'cpuset_cpus' => '0-3', 'memory_limit_bytes' => 8 * 1024 ** 3],
    $workload,
    6,
    10 * 1024 ** 3
);
$check('preview current cpu', $preview['current']['cpu_limit_cores'] === 4);
$check('preview proposed cpu', $preview['proposed']['cpu_limit_cores'] === 6);
$check('preview proposed memory', $preview['proposed']['memory_limit_bytes'] === 10 * 1024 ** 3);
$check('preview affected pods', $preview['affected_workloads']['pods'] === 5);
$check('preview safe flag', $preview['safe'] === true);
$check('preview violations list', $preview['violations'] === []);

// Resource envelope rollup on a representative status payload.
$nodes = ['items' => [[
    'metadata' => ['name' => 'node-a'],
    'status' => [
        'capacity' => ['cpu' => '4', 'memory' => '8Gi'],
        'allocatable' => ['cpu' => '4', 'memory' => '7Gi'],
    ],
]]];
$pods = ['items' => [
    $mkPod([
        'requests' => ['cpu' => '500m', 'memory' => '128Mi'],
        'limits' => ['cpu' => '1', 'memory' => '256Mi'],
    ]),
    $mkPod([
        'requests' => ['cpu' => '2', 'memory' => '256Mi'],
        'limits' => ['cpu' => '2', 'memory' => '256Mi'],
    ]),
    array_merge($mkPod([]), ['status' => ['phase' => 'Succeeded']]),
]];
$config = [
    'PROVIDER' => 'k3d',
    'CLUSTER_NAME' => 'unraid-k3s',
    'HOST_RESERVE_CPUS' => '1',
    'HOST_RESERVE_MEMORY_MIB' => '512',
];
$envelope = dm_k8s_resource_envelope($config, $nodes, $pods);
$check('envelope capacity cpu', $envelope['capacity']['cpu_millicores'] === 4000);
$check('envelope capacity memory', $envelope['capacity']['memory_bytes'] === 8 * 1024 ** 3);
$check('envelope allocatable cpu', $envelope['allocatable']['cpu_millicores'] === 4000);
$check('envelope allocatable memory', $envelope['allocatable']['memory_bytes'] === 7 * 1024 ** 3);
$check('envelope skips terminal pods', $envelope['workloads']['pods'] === 2);
$check('envelope cpu requests', $envelope['workloads']['cpu_requests_millicores'] === 2500);
$check('envelope cpu limits', $envelope['workloads']['cpu_limits_millicores'] === 3000);
$check('envelope memory requests', $envelope['workloads']['memory_requests_bytes'] === 384 * 1024 ** 2);
$check('envelope memory limits', $envelope['workloads']['memory_limits_bytes'] === 512 * 1024 ** 2);
$check('envelope guaranteed pods', $envelope['workloads']['guaranteed_pods'] === 1);
$check('envelope exclusive default off', $envelope['exclusive_cpu_available'] === false);
$check('envelope pod count', count($envelope['pods']) === 2);
$check('envelope pod qos burstable', $envelope['pods'][0]['qos'] === 'Burstable');
$check('envelope pod qos guaranteed', $envelope['pods'][1]['qos'] === 'Guaranteed');
$check('envelope priority default', $envelope['pods'][0]['priority'] === 0);
$check('envelope managed flag', $envelope['managed'] === true);
$check('envelope readonly flag', $envelope['readonly'] === false);

// External provider stays read-only with no k3d reads.
$external = dm_k8s_resource_envelope(
    array_merge($config, ['PROVIDER' => 'external']),
    ['items' => []],
    ['items' => []]
);
$check('external readonly flag', $external['readonly'] === true);
$check('external managed flag', $external['managed'] === false);
$check('external k3d unavailable', $external['k3d']['available'] === false);

// Settings preview works without a reachable cluster.
$settingsPreview = dm_k8s_settings_preview(4, 4096, 2, 2048);
$check('settings preview present', isset($settingsPreview['proposed'], $settingsPreview['violations']));

// The verified CPU Manager policy drives exclusive eligibility end to end:
// a Guaranteed whole-core pod is eligible only under 'static'.
$policyEnvelope = dm_k8s_resource_envelope(
    $config,
    ['items' => []],
    ['items' => [$guaranteedWholeCore]],
    'static'
);
$check('static policy enables exclusive', $policyEnvelope['exclusive_cpu_available'] === true);
$check('static policy marks pod exclusive', $policyEnvelope['pods'][0]['exclusive_eligible'] === true);
$check(
    'non-static policy disables exclusive',
    dm_k8s_resource_envelope($config, ['items' => []], ['items' => [$guaranteedWholeCore]], 'none')['exclusive_cpu_available'] === false
);

// Terminal pods never reach the allocation rollup even with a policy set.
$terminalEnvelope = dm_k8s_resource_envelope(
    $config,
    ['items' => []],
    ['items' => [array_merge($guaranteedWholeCore, ['status' => ['phase' => 'Succeeded']])]],
    'static'
);
$check('terminal pods skipped under policy', $terminalEnvelope['workloads']['pods'] === 0);
$check('terminal pods produce no allocations', $terminalEnvelope['pods'] === []);

if ($failures !== []) {
    fwrite(STDERR, "Resource envelope failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo 'Resource envelope: 52 checks passed' . "\n";
