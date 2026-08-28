<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $config = dm_k8s_config();
        if ($config['PROVIDER'] !== 'k3d') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'External clusters are monitored read-only']);
            exit;
        }
        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported lifecycle action']);
            exit;
        }
        if (!is_executable(DM_K8S_RC)) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => 'Kubernetes lifecycle service is unavailable']);
            exit;
        }
        $command = sprintf(
            'nohup %s %s >>/var/log/unraid-kubernetes.log 2>&1 &',
            escapeshellarg(DM_K8S_RC),
            escapeshellarg($action)
        );
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'error' => 'Lifecycle request could not be started']);
            exit;
        }
        echo json_encode(['ok' => true, 'action' => $action]);
        exit;
    }

    echo json_encode(dm_k8s_status(), JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
