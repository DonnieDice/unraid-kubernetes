<?php

declare(strict_types=1);

require __DIR__ . '/../src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php';

$jobs = ['work/succeeded' => 'recovered', 'work/failed' => 'current'];
foreach (['Succeeded' => true, 'Failed' => true, 'Running' => false, 'Pending' => false, 'Unknown' => false, '' => false] as $phase => $expected) {
    if (dm_k8s_pod_is_terminal($phase) !== $expected) {
        throw new RuntimeException("Unexpected terminal classification for {$phase}");
    }
}
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
echo "Terminal workload classification: 6 checks passed\n";
