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
        echo json_encode([
            'provider' => $config['PROVIDER'],
            'cluster_name' => $config['CLUSTER_NAME'],
            'k3s_image' => $config['K3S_IMAGE'],
            'k3d_config' => $config['K3D_CONFIG'],
            'kubeconfig' => $config['KUBECONFIG'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $provider = (string)($_POST['provider'] ?? '');
    if (!in_array($provider, ['k3d', 'external'], true)) {
        throw new InvalidArgumentException('Invalid provider');
    }
    $updates = [
        'PROVIDER' => $provider,
        'CLUSTER_NAME' => dm_k8s_post_setting('cluster_name', '/^[a-z0-9][a-z0-9.-]{0,62}$/'),
        'K3S_IMAGE' => dm_k8s_post_setting('k3s_image', '/^[A-Za-z0-9._\/:@-]+$/'),
        'K3D_CONFIG' => dm_k8s_post_setting('k3d_config', '/^\/[A-Za-z0-9._ \/-]+$/'),
        'KUBECONFIG' => dm_k8s_post_setting('kubeconfig', '/^\/[A-Za-z0-9._ \/-]+$/'),
    ];
    $stored = is_readable($settingsPath)
        ? parse_ini_file($settingsPath, false, INI_SCANNER_RAW)
        : [];
    $stored = is_array($stored) ? $stored : [];
    $next = array_merge($stored, $updates);
    $order = [
        'PROVIDER', 'CLUSTER_NAME', 'DATA_ROOT', 'K3D_CONFIG', 'TOKEN_FILE',
        'DATASTORE_DIR', 'STORAGE_DIR', 'KUBECONFIG_DIR', 'K3S_IMAGE', 'KUBECONFIG',
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
