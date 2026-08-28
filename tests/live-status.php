<?php

declare(strict_types=1);

require __DIR__ . '/../src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php';

$status = dm_k8s_status();
echo json_encode([
    'cluster' => $status['cluster'],
    'nodes' => count($status['nodes']),
    'pods' => count($status['pods']),
    'namespaces' => count($status['namespaces']),
    'warnings' => count($status['warnings']),
    'runtime' => count($status['runtime']),
    'error' => $status['error'],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
