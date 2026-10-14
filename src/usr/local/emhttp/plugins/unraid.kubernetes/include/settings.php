<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function dm_k8s_post_setting(string $name, string $pattern): string
{
    $value = trim((string)($_POST[$name] ?? ''));
    if ($value === '' || !preg_match($pattern, $value)) {
        throw new InvalidArgumentException("Invalid {$name}");
    }
    return $value;
}

try {
    $settingsPath = dm_k8s_settings_path();
    $config = dm_k8s_config();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $payload = [
            'provider' => $config['PROVIDER'],
            'cluster_name' => $config['CLUSTER_NAME'],
            'k3s_image' => $config['K3S_IMAGE'],
            'k3d_config' => $config['K3D_CONFIG'],
            'kubeconfig' => $config['KUBECONFIG'],
            'show_metrics' => $config['SHOW_METRICS'],
            'show_dashboard_widget' => $config['SHOW_DASHBOARD_WIDGET'],
            'show_kubernetes_page' => $config['SHOW_KUBERNETES_PAGE'],
            'show_docker_header' => $config['SHOW_DOCKER_HEADER'],
            'cpu_display_unit' => $config['CPU_DISPLAY_UNIT'],
            'refresh_interval' => $config['REFRESH_INTERVAL'],
            'cpu_limit_cores' => $config['CPU_LIMIT_CORES'],
            'memory_limit_mib' => $config['MEMORY_LIMIT_MIB'],
            'host_reserve_cpus' => $config['HOST_RESERVE_CPUS'],
            'host_reserve_memory_mib' => $config['HOST_RESERVE_MEMORY_MIB'],
        ];
        if (($_GET['preview'] ?? '') === '1') {
            $proposedCores = preg_match('/^\d{1,9}$/', (string)($_GET['cpu_limit_cores'] ?? ''))
                ? (int)$_GET['cpu_limit_cores'] : (int)$config['CPU_LIMIT_CORES'];
            $proposedMemoryMib = preg_match('/^\d{1,9}$/', (string)($_GET['memory_limit_mib'] ?? ''))
                ? (int)$_GET['memory_limit_mib'] : (int)$config['MEMORY_LIMIT_MIB'];
            $payload['preview'] = dm_k8s_settings_preview(
                $proposedCores,
                $proposedMemoryMib,
                (int)$config['HOST_RESERVE_CPUS'],
                (int)$config['HOST_RESERVE_MEMORY_MIB']
            );
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    $provider = (string)($_POST['provider'] ?? '');
    if (!in_array($provider, ['k3d', 'external'], true)) {
        throw new InvalidArgumentException('Invalid provider');
    }
    $showMetrics = (string)($_POST['show_metrics'] ?? '');
    if (!in_array($showMetrics, ['yes', 'no'], true)) {
        throw new InvalidArgumentException('Invalid resource metrics setting');
    }
    $showDashboardWidget = (string)($_POST['show_dashboard_widget'] ?? '');
    $showKubernetesPage = (string)($_POST['show_kubernetes_page'] ?? '');
    $showDockerHeader = (string)($_POST['show_docker_header'] ?? '');
    foreach ([$showDashboardWidget, $showKubernetesPage, $showDockerHeader] as $visibility) {
        if (!in_array($visibility, ['yes', 'no'], true)) {
            throw new InvalidArgumentException('Invalid visibility setting');
        }
    }
    $cpuDisplayUnit = (string)($_POST['cpu_display_unit'] ?? '');
    if (!in_array($cpuDisplayUnit, ['auto', 'percent', 'cores'], true)) {
        throw new InvalidArgumentException('Invalid CPU display unit setting');
    }
    $refreshInterval = (string)($_POST['refresh_interval'] ?? '');
    if (!in_array($refreshInterval, ['5', '10', '15', '30', '60'], true)) {
        throw new InvalidArgumentException('Invalid refresh interval setting');
    }
    foreach ([
        'cpu_limit_cores' => 'CPU_LIMIT_CORES',
        'memory_limit_mib' => 'MEMORY_LIMIT_MIB',
        'host_reserve_cpus' => 'HOST_RESERVE_CPUS',
        'host_reserve_memory_mib' => 'HOST_RESERVE_MEMORY_MIB',
    ] as $field => $key) {
        $value = trim((string)($_POST[$field] ?? ''));
        if ($value !== '' && !preg_match('/^\d{1,9}$/', $value)) {
            throw new InvalidArgumentException("Invalid {$field} setting");
        }
    }
    $envelopeCores = (int)(trim((string)($_POST['cpu_limit_cores'] ?? '')) ?: '0');
    $envelopeMemoryMib = (int)(trim((string)($_POST['memory_limit_mib'] ?? '')) ?: '0');
    $reserveCpus = (int)(trim((string)($_POST['host_reserve_cpus'] ?? '')) ?: '1');
    $reserveMemoryMib = (int)(trim((string)($_POST['host_reserve_memory_mib'] ?? '')) ?: '512');
    // Server-side safety gate: the same validation the preview shows must
    // block the write itself, not only the browser's apply button.
    if ($provider === 'k3d') {
        $envelopePreview = dm_k8s_settings_preview($envelopeCores, $envelopeMemoryMib, $reserveCpus, $reserveMemoryMib);
        if (($envelopePreview['violations'] ?? []) !== []) {
            throw new InvalidArgumentException(
                'Resource envelope rejected: ' . implode(' ', $envelopePreview['violations'])
            );
        }
    }
    $updates = [
        'PROVIDER' => $provider,
        'CLUSTER_NAME' => dm_k8s_post_setting('cluster_name', '/^[a-z0-9][a-z0-9.-]{0,62}$/'),
        'K3S_IMAGE' => dm_k8s_post_setting('k3s_image', '/^[A-Za-z0-9._\/:@-]+$/'),
        'K3D_CONFIG' => dm_k8s_post_setting('k3d_config', '/^\/[A-Za-z0-9._ \/-]+$/'),
        'KUBECONFIG' => dm_k8s_post_setting('kubeconfig', '/^\/[A-Za-z0-9._ \/-]+$/'),
        'SHOW_METRICS' => $showMetrics,
        'SHOW_DASHBOARD_WIDGET' => $showDashboardWidget,
        'SHOW_KUBERNETES_PAGE' => $showKubernetesPage,
        'SHOW_DOCKER_HEADER' => $showDockerHeader,
        'CPU_DISPLAY_UNIT' => $cpuDisplayUnit,
        'REFRESH_INTERVAL' => $refreshInterval,
        'CPU_LIMIT_CORES' => trim((string)($_POST['cpu_limit_cores'] ?? '')) ?: '0',
        'MEMORY_LIMIT_MIB' => trim((string)($_POST['memory_limit_mib'] ?? '')) ?: '0',
        'HOST_RESERVE_CPUS' => trim((string)($_POST['host_reserve_cpus'] ?? '')) ?: '1',
        'HOST_RESERVE_MEMORY_MIB' => trim((string)($_POST['host_reserve_memory_mib'] ?? '')) ?: '512',
    ];
    $stored = is_readable($settingsPath)
        ? parse_ini_file($settingsPath, false, INI_SCANNER_RAW)
        : [];
    $stored = is_array($stored) ? $stored : [];
    $next = array_merge($stored, $updates);
    // Audit envelope writes so a bad apply can be traced and reverted:
    // old -> new values with a UTC timestamp, appended next to the settings.
    $envelopeKeys = ['CPU_LIMIT_CORES', 'MEMORY_LIMIT_MIB', 'HOST_RESERVE_CPUS', 'HOST_RESERVE_MEMORY_MIB'];
    $envelopeChanged = false;
    foreach ($envelopeKeys as $key) {
        if ((string)($stored[$key] ?? '') !== (string)$next[$key]) {
            $envelopeChanged = true;
        }
    }
    if ($envelopeChanged && $provider === 'k3d') {
        $auditPath = dirname($settingsPath) . '/unraid-kubernetes/resource-envelope.log';
        @mkdir(dirname($auditPath), 0755, true);
        $oldValues = [];
        $newValues = [];
        foreach ($envelopeKeys as $key) {
            $oldValues[] = strtolower($key) . '=' . (string)($stored[$key] ?? 'unset');
            $newValues[] = strtolower($key) . '=' . (string)$next[$key];
        }
        @file_put_contents(
            $auditPath,
            gmdate('Y-MM-dd\THH:i:ss\Z') . ' settings ' . implode(',', $oldValues) . ' -> ' . implode(',', $newValues) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
    $order = [
        'PROVIDER', 'CLUSTER_NAME', 'DATA_ROOT', 'K3D_CONFIG', 'TOKEN_FILE',
        'DATASTORE_DIR', 'STORAGE_DIR', 'KUBECONFIG_DIR', 'K3S_IMAGE', 'KUBECONFIG', 'SHOW_METRICS',
        'SHOW_DASHBOARD_WIDGET', 'SHOW_KUBERNETES_PAGE', 'SHOW_DOCKER_HEADER', 'CPU_DISPLAY_UNIT',
        'REFRESH_INTERVAL', 'CPU_LIMIT_CORES', 'MEMORY_LIMIT_MIB', 'HOST_RESERVE_CPUS',
        'HOST_RESERVE_MEMORY_MIB',
    ];
    $lines = [];
    foreach ($order as $key) {
        if (!array_key_exists($key, $next)) {
            continue;
        }
        $value = (string)$next[$key];
        if (str_contains($value, '"') || str_contains($value, '\\') || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException("Invalid {$key}");
        }
        $lines[] = "{$key}=\"{$value}\"";
    }
    $temporary = $settingsPath . '.new';
    if (file_put_contents($temporary, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write settings');
    }
    chmod($temporary, 0600);
    if (!rename($temporary, $settingsPath)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to activate settings');
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
