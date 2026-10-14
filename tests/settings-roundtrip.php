<?php

declare(strict_types=1);

$fixturePath = sys_get_temp_dir() . '/dm-k8s-settings-' . getmypid() . '.cfg';
copy(__DIR__ . '/fixtures/tower.settings.cfg', $fixturePath);
chmod($fixturePath, 0600);
putenv("UNRAID_K8S_SETTINGS={$fixturePath}");

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'provider' => 'k3d',
    'cluster_name' => 'test-unraid',
    'k3s_image' => 'rancher/k3s:v1.36.1-k3s1',
    'k3d_config' => '/mnt/user/appdata/test/config.yaml',
    'kubeconfig' => '/mnt/user/appdata/test/kubeconfig.yaml',
    'show_metrics' => 'no',
    'show_dashboard_widget' => 'no',
    'show_kubernetes_page' => 'yes',
    'show_docker_header' => 'no',
    'cpu_display_unit' => 'cores',
    'refresh_interval' => '5',
    'cpu_limit_cores' => '4',
    'memory_limit_mib' => '4096',
    'host_reserve_cpus' => '2',
    'host_reserve_memory_mib' => '2048',
];

ob_start();
require __DIR__ . '/../src/usr/local/emhttp/plugins/unraid.kubernetes/include/settings.php';
$response = json_decode((string)ob_get_clean(), true);
$saved = parse_ini_file($fixturePath, false, INI_SCANNER_RAW);
@unlink($fixturePath);

if (($response['ok'] ?? false) !== true
    || ($saved['CLUSTER_NAME'] ?? '') !== 'test-unraid'
    || ($saved['SHOW_METRICS'] ?? '') !== 'no'
    || ($saved['SHOW_DASHBOARD_WIDGET'] ?? '') !== 'no'
    || ($saved['SHOW_KUBERNETES_PAGE'] ?? '') !== 'yes'
    || ($saved['SHOW_DOCKER_HEADER'] ?? '') !== 'no'
    || ($saved['CPU_DISPLAY_UNIT'] ?? '') !== 'cores'
    || ($saved['REFRESH_INTERVAL'] ?? '') !== '5'
    || ($saved['CPU_LIMIT_CORES'] ?? '') !== '4'
    || ($saved['MEMORY_LIMIT_MIB'] ?? '') !== '4096'
    || ($saved['HOST_RESERVE_CPUS'] ?? '') !== '2'
    || ($saved['HOST_RESERVE_MEMORY_MIB'] ?? '') !== '2048'
    || ($saved['TOKEN_FILE'] ?? '') === '') {
    fwrite(STDERR, 'Settings round-trip failed: response=' . json_encode($response)
        . ', cluster=' . ($saved['CLUSTER_NAME'] ?? 'missing')
        . ', preserved_token_path=' . (isset($saved['TOKEN_FILE']) ? 'yes' : 'no') . "\n");
    exit(1);
}
