<?php
// Configuração padrão. A versão v3 não usa MySQL/MariaDB.
$config = [
    'app_name' => 'SystemETI',
    'app_env' => 'production',
    'app_url' => '',
    'upload_max_mb' => 500,
    'pdftotext_bin' => '/usr/bin/pdftotext',
    'pdfinfo_bin' => '/usr/bin/pdfinfo',
    'pdftoppm_bin' => '/usr/bin/pdftoppm',
    'tesseract_bin' => '/usr/bin/tesseract',
    'ocr_enabled' => true,
    'ocr_dpi' => 220,
    'ocr_max_pages' => 250,
    'ocr_lang' => 'por+eng',
    'ocr_psm' => 6,
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
