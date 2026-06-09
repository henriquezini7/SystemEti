<?php
// Configuração padrão. A versão v3 não usa MySQL/MariaDB.
$config = [
    'app_name' => 'SystemETI',
    'app_env' => 'production',
    'app_url' => '',
    'upload_max_mb' => 500,
    'pdftotext_bin' => '/usr/bin/pdftotext',
    'pdfinfo_bin' => '/usr/bin/pdfinfo',
    'storage_driver' => 'json',
    'timezone' => 'America/Sao_Paulo',
];

$local = __DIR__ . '/config.local.php';
if (file_exists($local)) {
    $override = include $local;
    if (is_array($override)) {
        $config = array_merge($config, $override);
    }
}

return $config;
