<?php

declare(strict_types=1);

require __DIR__ . '/../src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php';

function dm_k8s_test_pod(string $name, array $overrides = []): array
{
    $pod = [
        'metadata' => ['name' => $name, 'namespace' => 'apps', 'labels' => ['app' => $name]],
        'spec' => ['nodeName' => 'node-a', 'containers' => [['name' => 'c']]],
        'status' => ['phase' => 'Running', 'podIP' => '10.42.0.10', 'hostIP' => '172.23.0.5'],
    ];
    foreach ($overrides as $path => $value) {
        $node = &$pod;
        foreach (explode('.', $path) as $segment) {
            $node = &$node[$segment];
        }
        $node = $value;
        unset($node);
    }
    return $pod;
}

function dm_k8s_test_service(string $name, array $spec): array
{
    return [
        'metadata' => ['name' => $name, 'namespace' => 'apps'],
        'spec' => $spec,
    ];
}

function dm_k8s_test_slice(string $service, array $endpoints): array
{
    return [
        'metadata' => [
            'namespace' => 'apps',
            'labels' => ['kubernetes.io/service-name' => $service],
        ],
        'endpoints' => $endpoints,
    ];
}

$podCidrs = ['node-a' => '10.42.0.0/24'];
$policyPresent = [];
$findingRules = [];

function dm_k8s_network_row(array $pod, array $services, array $slices, array $ingresses, array $live): array
{
    global $podCidrs, $policyPresent, $findingRules;
    $podLabels = [];
    foreach ([$pod] as $each) {
        $podLabels[(string)$each['metadata']['namespace']][] = (array)($each['metadata']['labels'] ?? []);
    }
    return dm_k8s_pod_network(
        $pod,
        $services,
        $slices,
        $ingresses,
        $policyPresent,
        $live,
        $podCidrs,
        $podLabels['apps'],
        false,
        $findingRules
    );
}

function dm_k8s_test_rules(array $row): array
{
    return array_column($row['warnings'], 'rule');
}
$failures = [];
$check = function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

// Case 1: ClusterIP service with a ready endpoint -> cluster-internal, no warnings for endpoints.
$row = dm_k8s_network_row(
    dm_k8s_test_pod('web'),
    [dm_k8s_test_service('web', [
        'type' => 'ClusterIP',
        'selector' => ['app' => 'web'],
        'ports' => [['port' => 80, 'targetPort' => 8080, 'protocol' => 'TCP']],
    ])],
    [dm_k8s_test_slice('web', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => true],
    ]])],
    [],
    []
);
$check('clusterip: class is cluster-internal', $row['class'] === 'cluster-internal');
$check('clusterip: pod ip captured', $row['pod_ip'] === '10.42.0.10');
$check('clusterip: cni label from node cidr', $row['cni'] === 'cluster CNI 10.42.0.0/24');
$check('clusterip: one service selected', count($row['services']) === 1);
$check('clusterip: ready endpoint count', $row['services'][0]['ready_count'] === 1);
$check('clusterip: ready endpoint address', $row['services'][0]['ready_endpoints'] === ['10.42.0.10']);
$check('clusterip: endpoints source is EndpointSlice', $row['services'][0]['endpoints_source'] === 'EndpointSlice');
$check('clusterip: no missing-networkpolicy (not lan/public, no nodeport)', !in_array('missing-networkpolicy', dm_k8s_test_rules($row), true));

// Case 2: NodePort service with no host publication -> cluster-internal + missing-networkpolicy warning.
$row = dm_k8s_network_row(
    dm_k8s_test_pod('bank'),
    [dm_k8s_test_service('bank', [
        'type' => 'NodePort',
        'selector' => ['app' => 'bank'],
        'ports' => [['port' => 80, 'targetPort' => 80, 'protocol' => 'TCP', 'nodePort' => 32081]],
    ])],
    [dm_k8s_test_slice('bank', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => true],
    ]])],
    [],
    []
);
$check('nodeport-unmapped: class is cluster-internal', $row['class'] === 'cluster-internal');
$check('nodeport-unmapped: node port recorded', $row['services'][0]['node_ports'] === ['32081']);
$check('nodeport-unmapped: unpublished node port recorded', $row['services'][0]['unpublished_node_ports'] === ['32081']);
$check('nodeport-unmapped: missing-networkpolicy warning', in_array('missing-networkpolicy', dm_k8s_test_rules($row), true));

// Case 2b: NodePort mapped to a live LAN publication -> lan-only.
$row = dm_k8s_network_row(
    dm_k8s_test_pod('bank'),
    [dm_k8s_test_service('bank', [
        'type' => 'NodePort',
        'selector' => ['app' => 'bank'],
        'ports' => [['port' => 80, 'targetPort' => 80, 'protocol' => 'TCP', 'nodePort' => 32081]],
    ])],
    [dm_k8s_test_slice('bank', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => true],
    ]])],
    [],
    [['host_ip' => '192.168.1.31', 'host_port' => '38081', 'node_port' => '32081', 'node' => 'k3d-x-serverlb']]
);
$check('nodeport-lan: class is lan-only', $row['class'] === 'lan-only');
$check('nodeport-lan: publication attached', $row['services'][0]['publications'][0]['host_ip'] === '192.168.1.31');
$check('nodeport-lan: missing-networkpolicy warning for lan-only', in_array('missing-networkpolicy', dm_k8s_test_rules($row), true));

// Case 3: Ingress through loopback HTTP publication -> loopback-only; with cloudflared present -> public.
$ingresses = [[
    'metadata' => ['namespace' => 'apps'],
    'spec' => [
        'ingressClassName' => 'traefik',
        'rules' => [[
            'host' => 'app.example.com',
            'http' => ['paths' => [[
                'path' => '/',
                'backend' => ['service' => ['name' => 'web']],
            ]]],
        ]],
    ],
]];
$loopbackHttp = [['host_ip' => '127.0.0.1', 'host_port' => '30080', 'node_port' => '80', 'node' => 'k3d-x-serverlb']];
$row = dm_k8s_network_row(
    dm_k8s_test_pod('web'),
    [dm_k8s_test_service('web', [
        'type' => 'ClusterIP',
        'selector' => ['app' => 'web'],
        'ports' => [['port' => 80, 'targetPort' => 8080, 'protocol' => 'TCP']],
    ])],
    [dm_k8s_test_slice('web', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => true],
    ]])],
    $ingresses,
    $loopbackHttp
);
$check('ingress: class is loopback-only', $row['class'] === 'loopback-only');
$check('ingress: route host recorded', $row['services'][0]['ingress_routes'][0]['host'] === 'app.example.com');
$check('ingress: route class recorded', $row['services'][0]['ingress_routes'][0]['class'] === 'traefik');
$check('ingress: ingress source named', $row['services'][0]['ingress_source'] === 'Ingress');

$podLabelsForPublic = [];
$pod = dm_k8s_test_pod('web');
$row = dm_k8s_pod_network(
    $pod,
    [dm_k8s_test_service('web', [
        'type' => 'ClusterIP',
        'selector' => ['app' => 'web'],
        'ports' => [['port' => 80, 'targetPort' => 8080, 'protocol' => 'TCP']],
    ])],
    [dm_k8s_test_slice('web', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => true],
    ]])],
    $ingresses,
    $policyPresent,
    $loopbackHttp,
    $podCidrs,
    [$podLabelsForPublic],
    true,
    $findingRules
);
$check('ingress-public: class is public with cloudflared', $row['class'] === 'public');
$check('ingress-public: external path recorded', $row['services'][0]['external_path'] === true);

// Case 4: hostNetwork pod with declared ports.
$hostNetPod = dm_k8s_test_pod('daemon', [
    'spec.hostNetwork' => true,
    'spec.containers' => [[
        'name' => 'd',
        'ports' => [['containerPort' => 80, 'hostPort' => 80, 'protocol' => 'TCP', 'hostIP' => '']],
    ]],
    'status.hostIP' => '192.168.1.31',
]);
$row = dm_k8s_network_row($hostNetPod, [], [], [], []);
$check('hostnetwork: class is lan-only (wildcard host bind)', $row['class'] === 'lan-only');
$check('hostnetwork: host flag true', $row['host_network'] === true);
$check('hostnetwork: host ip captured', $row['host_ip'] === '192.168.1.31');
$check('hostnetwork: declared host port captured', $row['host_ports'][0]['host_port'] === 80);
$check('hostnetwork: wildcard-binding warning', in_array('wildcard-binding', dm_k8s_test_rules($row), true));
$check('hostnetwork: missing-networkpolicy for lan-only', in_array('missing-networkpolicy', dm_k8s_test_rules($row), true));

// Case 5: no ready endpoints -> warning, class kept.
$row = dm_k8s_network_row(
    dm_k8s_test_pod('web'),
    [dm_k8s_test_service('web', [
        'type' => 'ClusterIP',
        'selector' => ['app' => 'web'],
        'ports' => [['port' => 80, 'targetPort' => 8080, 'protocol' => 'TCP']],
    ])],
    [dm_k8s_test_slice('web', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => false],
    ]])],
    [],
    []
);
$check('no-endpoint: no-ready-endpoints warning', in_array('no-ready-endpoints', dm_k8s_test_rules($row), true));
$check('no-endpoint: not-ready count', $row['services'][0]['not_ready_count'] === 1);
$check('no-endpoint: class stays cluster-internal', $row['class'] === 'cluster-internal');

// Case 5b: selector mismatch - selector overlaps pod labels but matches nothing, empty slices.
$row = dm_k8s_network_row(
    dm_k8s_test_pod('web'),
    [dm_k8s_test_service('typo', [
        'type' => 'ClusterIP',
        'selector' => ['app' => 'webb'],
        'ports' => [['port' => 80, 'targetPort' => 8080, 'protocol' => 'TCP']],
    ])],
    [],
    [],
    []
);
$check('selector-mismatch: warning fired', in_array('selector-mismatch', dm_k8s_test_rules($row), true));

// Wildcard publication on a mapped NodePort -> wildcard-binding warning on lan-only row.
$row = dm_k8s_network_row(
    dm_k8s_test_pod('bank'),
    [dm_k8s_test_service('bank', [
        'type' => 'NodePort',
        'selector' => ['app' => 'bank'],
        'ports' => [['port' => 80, 'targetPort' => 80, 'protocol' => 'TCP', 'nodePort' => 33379]],
    ])],
    [dm_k8s_test_slice('bank', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => true],
    ]])],
    [],
    [['host_ip' => '0.0.0.0', 'host_port' => '33379', 'node_port' => '33379', 'node' => 'k3d-x-serverlb']]
);
$check('wildcard: class is lan-only', $row['class'] === 'lan-only');
$check('wildcard: wildcard-binding warning', in_array('wildcard-binding', dm_k8s_test_rules($row), true));

// Duplicate-host-ports findings attach to mapped node ports.
$findingRules = ['6443' => ['duplicate-host-ports']];
$row = dm_k8s_network_row(
    dm_k8s_test_pod('api'),
    [dm_k8s_test_service('api', [
        'type' => 'NodePort',
        'selector' => ['app' => 'api'],
        'ports' => [['port' => 6443, 'targetPort' => 6443, 'protocol' => 'TCP', 'nodePort' => 6443]],
    ])],
    [dm_k8s_test_slice('api', [[
        'addresses' => ['10.42.0.10'],
        'conditions' => ['ready' => true],
    ]])],
    [],
    [['host_ip' => '0.0.0.0', 'host_port' => '6443', 'node_port' => '6443', 'node' => 'k3d-x-server-0']]
);
$check('duplicate: duplicate-host-ports warning', in_array('duplicate-host-ports', dm_k8s_test_rules($row), true));

// Publication findings helpers.
$tracked = [
    ['host_ip' => '192.168.1.31', 'host_port' => '38081', 'node_port' => '32081', 'node' => 'loadbalancer'],
    ['host_ip' => '127.0.0.1', 'host_port' => '30080', 'node_port' => '80', 'node' => 'loadbalancer'],
];
$live = [
    ['host_ip' => '127.0.0.1', 'host_port' => '30080', 'node_port' => '80', 'node' => 'k3d-x-serverlb'],
];
$findings = dm_k8s_publication_findings($tracked, $live);
$check('findings: tracked-only drift detected', count($findings['drift']) === 1 && $findings['drift'][0]['state'] === 'tracked-only');
$check('findings: no duplicates for distinct ports', $findings['duplicates'] === []);

$findings = dm_k8s_publication_findings([], [
    ['host_ip' => '', 'host_port' => '6443', 'node_port' => '6443', 'node' => 'server'],
    ['host_ip' => '0.0.0.0', 'host_port' => '33379', 'node_port' => '6443', 'node' => 'server'],
]);
$check('findings: duplicate node-port publication detected', count($findings['duplicates']) === 1 && (string)$findings['duplicates'][0]['node_port'] === '6443');

// Bind classification.
$check('bind: empty is wildcard', dm_k8s_bind_class('') === 'wildcard');
$check('bind: 0.0.0.0 is wildcard', dm_k8s_bind_class('0.0.0.0') === 'wildcard');
$check('bind: 127.0.0.1 is loopback', dm_k8s_bind_class('127.0.0.1') === 'loopback');
$check('bind: 192.168.1.31 is lan', dm_k8s_bind_class('192.168.1.31') === 'lan');

// k3d config parser.
$tempConfig = tempnam(sys_get_temp_dir(), 'k3d-');
file_put_contents($tempConfig, "apiVersion: k3d.io/v1alpha5\nkind: Simple\nmetadata:\n  name: x\nports:\n  - port: 6443:6443\n    nodeFilters:\n      - server:0\n  - port: 127.0.0.1:30080:80\n    nodeFilters:\n      - loadbalancer\nvolumes:\n  - volume: a:b\n");
$parsed = dm_k8s_parse_k3d_ports($tempConfig);
unlink($tempConfig);
$check('parser: two entries', count($parsed) === 2);
$check('parser: kubeapi entry', $parsed[0]['host_ip'] === '' && $parsed[0]['host_port'] === '6443' && $parsed[0]['node_port'] === '6443');
$check('parser: loopback entry', $parsed[1]['host_ip'] === '127.0.0.1' && $parsed[1]['host_port'] === '30080' && $parsed[1]['node_port'] === '80');

// CIDR membership.
$check('cidr: pod ip in node cidr', dm_k8s_ip_in_cidr('10.42.0.10', '10.42.0.0/24') === true);
$check('cidr: outside ip', dm_k8s_ip_in_cidr('10.43.0.10', '10.42.0.0/24') === false);

if ($failures !== []) {
    fwrite(STDERR, "Network fixture failures:\n" . implode("\n", $failures) . "\n");
    exit(1);
}
echo "Network fixtures: ClusterIP, NodePort (mapped/unmapped), Ingress (loopback/public), hostNetwork, no-endpoint, selector-mismatch, wildcard, duplicate, findings, parser, CIDR checks passed\n";
