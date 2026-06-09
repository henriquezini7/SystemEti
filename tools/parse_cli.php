<?php
require_once __DIR__ . '/../app/parser.php';
$config = include __DIR__ . '/../app/config.php';
$pdf = $argv[1] ?? null;
if (!$pdf || !file_exists($pdf)) {
    fwrite(STDERR, "Uso: php tools/parse_cli.php arquivo.pdf\n");
    exit(1);
}
$tmp = sys_get_temp_dir() . '/labels_' . uniqid() . '.txt';
$parser = new PdfLabelParser($config);
$result = $parser->process($pdf, $tmp);
unset($result['raw_text']);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
