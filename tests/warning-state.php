<?php

declare(strict_types=1);

require __DIR__ . '/../src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php';

$jobs = ['work/succeeded' => 'recovered', 'work/failed' => 'current'];
$pods = ['work/ready' => true, 'work/unready' => false, 'other/ready' => false];
$cases = [
    ['Job', 'succeeded', 'work', 'recovered'],
    ['Job', 'failed', 'work', 'current'],
    ['Job', 'unknown', 'work', 'current'],
    ['Pod', 'ready', 'work', 'recovered'],
    ['Pod', 'unready', 'work', 'current'],
    ['Pod', 'ready', 'other', 'current'],
    ['Pod', 'retired', 'work', 'recovered'],
    ['Node', 'ready', 'work', 'current'],
];
foreach ($cases as [$kind, $name, $namespace, $expected]) {
    $event = ['involvedObject' => ['kind' => $kind, 'name' => $name, 'namespace' => $namespace]];
    if (dm_k8s_warning_state($event, $jobs, $pods) !== $expected) {
        throw new RuntimeException("Unexpected warning state for {$kind} {$namespace}/{$name}");
    }
    unset($event['involvedObject']['namespace']);
    $event['metadata']['namespace'] = $namespace;
    if (dm_k8s_warning_state($event, $jobs, $pods) !== $expected) {
        throw new RuntimeException('Event namespace fallback changed warning classification');
    }
}
echo "Warning classification: 16 checks passed\n";
