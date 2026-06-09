<?php
class PdfLabelParser
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process($pdfPath, $textOutputPath)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '2048M');
        $text = $this->extractText($pdfPath, $textOutputPath);
        $rawText = $this->extractRawText($pdfPath);
        $pages = $this->countPages($pdfPath, $text);

        // v15: OCR automático. Se o PDF não tem texto selecionável (etiqueta em imagem),
        // renderiza as páginas e passa OCR (Tesseract) para conseguir ler pedidos/produtos.
        $usedOcr = false;
        if (!empty($this->config['ocr_enabled']) && $this->textIsSparse($text, $pages)) {
            $ocrText = $this->ocrPdf($pdfPath, $pages);
            if (trim($ocrText) !== '') {
                $text = $ocrText;
                $rawText = $ocrText;
                $usedOcr = true;
                @file_put_contents($textOutputPath, $ocrText);
            }
        }
        if (trim($text) === '') {
            throw new RuntimeException('O PDF não tem texto e o OCR não conseguiu ler. Confira se é um PDF válido.');
        }

        // v11: modo inteligente. Em vez de depender de uma única detecção inicial,
        // o painel roda os parsers conhecidos, pontua o resultado e fica com o mais completo.
        // Isso resolve PDFs mistos: Shopee com DACE, Mercado Livre com DANFE no final,
        // Jadlog/DANFE simplificada, etiquetas sem produto na primeira página etc.
        $smart = $this->parseSmart($text, $rawText);
        $platform = $smart['platform'];
        $result = $smart['result'];

        $result['platform'] = $platform;
        $result['pages'] = $pages;
        $result['raw_text'] = $text;
        $result['warnings'] = $result['warnings'] ?? [];
        if ($usedOcr) {
            $result['ocr'] = true;
            $result['warnings'][] = 'PDF de imagem: leitura feita por OCR. Confira os totais e os produtos.';
        }

        if (empty($result['labels']) && !empty($result['orders'])) {
            $codes = [];
            foreach ($result['orders'] as $order) {
                if (!empty($order['tracking_code'])) {
                    $codes[$order['tracking_code']] = true;
                } elseif (!empty($order['shipment_id'])) {
                    $codes[$order['shipment_id']] = true;
                } elseif (!empty($order['sale_id'])) {
                    $codes[$order['sale_id']] = true;
                }
            }
            $result['labels'] = array_keys($codes);
        }

        $result['total_labels'] = count(array_unique($result['labels'] ?? []));
        $result['total_orders'] = $this->countOrders($result['orders'] ?? []);
        $result['total_units'] = $this->sumUnits($result['orders'] ?? []);
        $result['items'] = $this->aggregateItems($result['orders'] ?? []);

        if ($result['total_units'] === 0 && !empty($result['items'])) {
            foreach ($result['items'] as $item) {
                $result['total_units'] += (int)$item['quantity'];
            }
        }

        return $result;
    }

    private function extractText($pdfPath, $textOutputPath)
    {
        $bin = $this->config['pdftotext_bin'] ?? 'pdftotext';
        if (!is_executable($bin) && $bin !== 'pdftotext') {
            throw new RuntimeException('pdftotext não encontrado. Instale poppler-utils na VPS.');
        }
        $cmd = escapeshellcmd($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($textOutputPath) . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        if ($code !== 0 || !file_exists($textOutputPath)) {
            throw new RuntimeException('Não foi possível extrair texto do PDF: ' . implode("\n", $out));
        }
        $text = file_get_contents($textOutputPath);
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        // Não lança erro quando vem vazio: pode ser PDF de imagem, e o process() aciona o OCR.
        return (string)$text;
    }

    private function extractRawText($pdfPath)
    {
        $bin = $this->config['pdftotext_bin'] ?? 'pdftotext';
        $tmp = tempnam(sys_get_temp_dir(), 'pdfraw_');
        if ($tmp === false) { return ''; }
        $cmd = escapeshellcmd($bin) . ' -raw -enc UTF-8 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tmp) . ' 2>/dev/null';
        @exec($cmd, $out, $code);
        $text = ($code === 0 && file_exists($tmp)) ? (string)file_get_contents($tmp) : '';
        @unlink($tmp);
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        return $text;
    }

    private function countPages($pdfPath, $text)
    {
        $bin = $this->config['pdfinfo_bin'] ?? 'pdfinfo';
        if (is_executable($bin) || $bin === 'pdfinfo') {
            $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($pdfPath) . ' 2>/dev/null';
            $out = shell_exec($cmd);
            if (preg_match('/Pages:\s*(\d+)/i', (string)$out, $m)) {
                return (int)$m[1];
            }
        }
        return max(1, substr_count($text, "\f") + 1);
    }

    private function parseSmart($text, $rawText = '')
    {
        $full = $text . "
" . $rawText;
        $detected = $this->detectPlatform($full);
        $candidates = [];

        $try = function ($platform, callable $fn) use (&$candidates, $full, $detected) {
            try {
                $result = $fn();
                if (!is_array($result)) { return; }
                $score = $this->scoreParserCandidate($platform, $result, $full, $detected);
                $candidates[] = [
                    'platform' => $platform,
                    'result' => $result,
                    'score' => $score,
                ];
            } catch (Throwable $e) {
                // Parser específico falhou; o modo inteligente continua tentando os outros.
            }
        };

        $try('shopee', function () use ($text, $rawText) { return $this->parseShopee($text, $rawText); });
        $try('mercado_livre', function () use ($text) { return $this->parseMercadoLivre($text); });
        $try('jadlog_danfe', function () use ($text, $rawText) { return $this->parseJadlogDanfe($text, $rawText); });
        $try('generico', function () use ($text, $rawText) { return $this->parseGeneric($text . "
" . $rawText); });

        usort($candidates, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return strcmp($a['platform'], $b['platform']);
            }
            return $b['score'] <=> $a['score'];
        });

        $best = $candidates[0] ?? [
            'platform' => 'generico',
            'result' => $this->parseGeneric($full),
            'score' => 0,
        ];

        $best['result']['warnings'] = $best['result']['warnings'] ?? [];
        if ($best['platform'] === 'generico') {
            $best['result']['warnings'][] = 'Modo inteligente não encontrou um padrão conhecido. Foram lidos apenas rastreios/códigos possíveis.';
        }
        return $best;
    }

    private function scoreParserCandidate($platform, $result, $text, $detected)
    {
        $orders = $result['orders'] ?? [];
        $labels = $result['labels'] ?? [];
        $realProducts = 0;
        $unknownProducts = 0;
        $completeFields = 0;
        foreach ($orders as $order) {
            $name = trim((string)($order['product_name'] ?? ''));
            if ($name !== '' && stripos($name, 'Produto não identificado') === false && $this->isValidProductName($name)) {
                $realProducts++;
            } else {
                $unknownProducts++;
            }
            foreach (['tracking_code','shipment_id','sale_id','pack_id','recipient','recipient_address','sender_name','sku'] as $field) {
                if (!empty($order[$field])) { $completeFields++; }
            }
        }

        $score = 0;
        $score += count(array_unique($labels)) * 3;
        $score += $this->countOrders($orders) * 8;
        $score += $this->sumUnits($orders) * 2;
        $score += $realProducts * 35;
        $score += min(80, $completeFields * 2);
        $score -= $unknownProducts * 25;

        $low = $this->lower($text);
        $flat = $this->lower($this->removeAccents($text));
        if ($platform === $detected) { $score += 30; }
        if ($platform === 'shopee' && (strpos($low, 'shopee') !== false || strpos($low, 'spxlm') !== false || strpos($low, 'skydrops') !== false || strpos($flat, 'identificacao dos bens') !== false || strpos($flat, 'checklist de carregamento') !== false || strpos($flat, 'produto variacao qnt sku') !== false || strpos($flat, 'item descricao quantidade valor') !== false || strpos($flat, 'danfe simplificado - etiqueta') !== false)) { $score += 65; }
        if ($platform === 'mercado_livre' && (strpos($low, 'mercado livre') !== false || strpos($low, 'shp:') !== false || strpos($flat, 'identificacao produtos') !== false || strpos($low, 'flex') !== false)) { $score += 45; }
        if ($platform === 'jadlog_danfe' && (strpos($low, 'jadlog') !== false || strpos($flat, 'package weight') !== false || (strpos($flat, 'danfe simplificada') !== false && strpos($flat, 'notas cliente') !== false && strpos($flat, 'jadlog') !== false))) { $score += 70; }
        if ($platform === 'generico') { $score -= 70; }
        if ($realProducts === 0) { $score -= 35; }
        return $score;
    }

    private function detectPlatform($text)
    {
        $low = $this->lower($text);
        $flat = $this->lower($this->removeAccents($text));

        // IMPORTANTE: muitos PDFs da Shopee/SkyDrops/glstore também trazem DANFE/DACE.
        // Por isso a Shopee precisa ser detectada antes de qualquer leitor DANFE genérico.
        if (
            strpos($low, 'shopee') !== false ||
            strpos($low, 'spxlm') !== false ||
            strpos($low, 'skydrops') !== false ||
            strpos($low, 'glstore') !== false ||
            strpos($low, 'shopee express') !== false ||
            strpos($low, 'shop name:') !== false ||
            strpos($flat, 'codigo de rastreamento') !== false && strpos($flat, 'identificacao dos bens') !== false ||
            strpos($flat, 'levar para agencia shopee') !== false ||
            strpos($flat, 'agencia shopee') !== false ||
            strpos($flat, 'checklist de carregamento') !== false ||
            strpos($flat, 'produto variacao qnt sku') !== false ||
            strpos($flat, 'item descricao quantidade valor') !== false ||
            (strpos($flat, 'danfe simplificado - etiqueta') !== false && strpos($flat, 'pedido:') !== false && strpos($flat, 'soc') !== false && strpos($flat, 'sp2') !== false)
        ) {
            return 'shopee';
        }

        if (strpos($low, 'jadlog') !== false || strpos($flat, 'package weight') !== false || (strpos($flat, 'danfe simplificada') !== false && strpos($flat, 'notas cliente') !== false && strpos($flat, 'jadlog') !== false)) {
            return 'jadlog_danfe';
        }

        if (strpos($low, 'mercado livre') !== false || strpos($low, 'shp:') !== false ||
            (strpos($low, 'dace resumida') !== false && strpos($flat, 'identificacao dos bens') === false) ||
            (strpos($low, 'flex') !== false && strpos($low, 'identifi') !== false && strpos($low, 'produtos') !== false) ||
            (strpos($low, 'envio:') !== false && strpos($low, 'venda:') !== false && strpos($low, 'destinatario:') !== false) ||
            (strpos($low, 'pack id:') !== false && strpos($low, 'identifi') !== false && strpos($low, 'produtos') !== false) ||
            (strpos($low, 'venda:') !== false && strpos($low, 'despachar:') !== false && strpos($low, 'nf:') !== false) ||
            (strpos($low, 'danfe simplificado') !== false && strpos($low, 'identifi') !== false && strpos($low, 'despachem as suas vendas') !== false)) {
            return 'mercado_livre';
        }
        return 'generico';
    }

    private function parseMercadoLivre($text)
    {
        $shpLabels = [];
        $trackingLabels = [];
        preg_match_all('/\bSHP:\s*(\d{6,})\b/i', $text, $shpMatches);
        foreach ($shpMatches[1] as $shp) {
            $shpLabels['SHP-' . $shp] = true;
        }

        preg_match_all('/Envio:\s*([0-9 ]{8,})/iu', $text, $envioMatches);
        foreach ($envioMatches[1] as $envio) {
            $id = preg_replace('/\D+/', '', $envio);
            if (strlen($id) >= 8) { $shpLabels['ENV-' . $id] = true; }
        }

        preg_match_all('/\b[A-Z]{2}\d{9}BR\b/', $text, $trackMatches);
        foreach ($trackMatches[0] as $code) {
            $trackingLabels[$code] = true;
        }
        $labels = !empty($shpLabels) ? $shpLabels : $trackingLabels;

        $orders = $this->parseMercadoLivreModernProductSection($text);
        if (empty($orders)) {
            $orders = $this->parseMercadoLivreProductSection($text);
        }
        if (empty($orders)) {
            $orders = $this->parseMercadoLivreFlexProductSection($text);
        }
        if (empty($orders)) {
            $orders = $this->parseMercadoLivreSingleProductSection($text);
        }
        if (empty($orders)) {
            $orders = $this->parseMercadoLivreOcrList($text);
        }
        if (empty($orders)) {
            $orders = $this->parseMercadoLivreReportFallbackSection($text);
        }
        if (empty($orders)) {
            $orders = $this->parseMercadoLivreLabelsOnly($text);
        }

        $orders = $this->enrichMercadoLivreOrders($orders, $text);

        return [
            'labels' => array_keys($labels),
            'orders' => $orders,
            'warnings' => empty($orders) ? ['PDF lido, mas não encontrei seção de produtos. O relatório terá somente etiquetas/rastreios.'] : [],
        ];
    }

    /* v15 - OCR -------------------------------------------------------------- */

    // Detecta PDF de imagem: pouquíssimo texto real por página.
    private function textIsSparse($text, $pages)
    {
        $stripped = preg_replace('/\s+/u', '', (string)$text);
        $len = function_exists('mb_strlen') ? mb_strlen($stripped, 'UTF-8') : strlen($stripped);
        $pages = max(1, (int)$pages);
        return ($len / $pages) < 15;
    }

    // Renderiza cada página (pdftoppm) e passa OCR (tesseract). Retorna o texto reconhecido.
    private function ocrPdf($pdfPath, $pages = 0)
    {
        $ppm = $this->config['pdftoppm_bin'] ?? '/usr/bin/pdftoppm';
        $tess = $this->config['tesseract_bin'] ?? '/usr/bin/tesseract';
        if ((strpos($ppm, '/') !== false && !is_executable($ppm)) || (strpos($tess, '/') !== false && !is_executable($tess))) {
            return '';
        }
        $dpi = (int)($this->config['ocr_dpi'] ?? 220);
        $lang = (string)($this->config['ocr_lang'] ?? 'por+eng');
        $maxPages = (int)($this->config['ocr_max_pages'] ?? 250);
        $pages = ((int)$pages > 0) ? (int)$pages : $this->countPages($pdfPath, '');
        $pages = max(1, min($pages, $maxPages));

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_' . bin2hex(random_bytes(5));
        @mkdir($tmp, 0775, true);
        $all = [];
        for ($p = 1; $p <= $pages; $p++) {
            $base = $tmp . DIRECTORY_SEPARATOR . 'p' . $p;
            $cmd = escapeshellcmd($ppm) . ' -png -singlefile -r ' . $dpi
                . ' -f ' . $p . ' -l ' . $p . ' '
                . escapeshellarg($pdfPath) . ' ' . escapeshellarg($base) . ' 2>/dev/null';
            @exec($cmd);
            $png = $base . '.png';
            if (!is_file($png)) { continue; }
            $outBase = $base . '_ocr';
            $cmd2 = escapeshellcmd($tess) . ' ' . escapeshellarg($png) . ' ' . escapeshellarg($outBase)
                . ' -l ' . escapeshellarg($lang) . ' 2>/dev/null';
            @exec($cmd2);
            $txtFile = $outBase . '.txt';
            if (is_file($txtFile)) {
                $all[] = (string)file_get_contents($txtFile);
                @unlink($txtFile);
            }
            @unlink($png);
        }
        foreach (glob($tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) { @unlink($f); }
        @rmdir($tmp);

        $text = implode("\f\n", $all);
        $text = str_replace("\r\n", "\n", $text);
        return str_replace("\r", "\n", $text);
    }

    // Leitor da lista "Identificação / Produtos" do Mercado Livre a partir de texto OCR.
    // No OCR, rastreio e produto ficam na MESMA linha; Venda/Quantidade/destinatário nas seguintes.
    private function parseMercadoLivreOcrList($text)
    {
        $flat = $this->lower($this->removeAccents((string)$text));
        $anchor = strpos($flat, 'despachem as suas vendas');
        if ($anchor === false) { $anchor = strpos($flat, 'identifica'); }
        if ($anchor === false) { $anchor = strpos($flat, 'produtos'); }
        if ($anchor === false) { return []; }

        $section = $this->substrUtf((string)$text, $anchor);
        $lines = preg_split('/\n/', $section);
        $orders = [];
        $current = null;

        $flush = function () use (&$orders, &$current) {
            if ($current) {
                $name = $this->cleanProduct($current['product_name'] ?? '');
                if ($name !== '' && $this->isValidProductName($name)) {
                    $current['product_name'] = $name;
                    $current['quantity'] = max(1, (int)($current['quantity'] ?? 1));
                    $orders[] = $current;
                }
            }
            $current = null;
        };

        foreach ($lines as $raw) {
            $line = trim(preg_replace('/\s+/u', ' ', (string)$raw));
            if ($line === '') { continue; }

            // Linha de pedido: rastreio Correios (AD/AP...BR) seguido do nome do produto.
            if (preg_match('/\b([A-Z]{2}\d{9}BR)\b\s*(.*)$/u', $line, $m)) {
                $flush();
                $rest = $m[2];
                // Remove o ruído do checkbox que o OCR coloca entre o código e o produto (ex.: "Oo", "(7", "O").
                $rest = preg_replace('/^[O0oQ\(\)\[\]\|\dCc\.\-_~»«]{1,3}\s+/u', '', $rest);
                $current = [
                    'tracking_code' => $m[1],
                    'shipment_id' => '',
                    'sale_id' => '',
                    'pack_id' => '',
                    'recipient' => '',
                    'product_name' => trim($rest),
                    'sku' => '',
                    'quantity' => 1,
                ];
                continue;
            }

            if (!$current) { continue; }

            if (preg_match('/Venda:\s*([0-9]{6,})/iu', $line, $m)) { $current['sale_id'] = $m[1]; }
            if (preg_match('/Pack\s*ID:\s*([0-9]{6,})/iu', $line, $m)) { $current['pack_id'] = $m[1]; }
            if (preg_match('/Quantidade:\s*(\d+)/iu', $line, $m)) { $current['quantity'] = max(1, (int)$m[1]); }

            if (!preg_match('/Venda:|Pack\s*ID:|Quantidade:/iu', $line)) {
                if ($this->looksLikeProduct($line) && mb_strlen($line, 'UTF-8') > 6) {
                    // Continuação do nome do produto (quebrou em 2 linhas).
                    $current['product_name'] = trim(($current['product_name'] ?? '') . ' ' . $line);
                } elseif (empty($current['recipient'])) {
                    $name = $this->cleanPersonName($line);
                    if ($name !== '') { $current['recipient'] = $name; }
                }
            }
        }
        $flush();
        return $orders;
    }

    private function parseMercadoLivreModernProductSection($text)
    {
        $text = $this->normalizePdfText($text);
        $pos = $this->striposUtf($text, 'Identifi');
        if ($pos === false) {
            $prodPos = $this->striposUtf($text, 'Produtos');
            if ($prodPos !== false) {
                $pos = max(0, $prodPos - 300);
            }
        }
        if ($pos === false) {
            $pos = $this->striposUtf($text, 'Despachem as suas vendas');
        }
        if ($pos === false || $this->striposUtf($text, 'Produtos') === false) {
            return [];
        }

        $section = $this->substrUtf($text, $pos);
        $lines = preg_split('/\n/', $section);
        $orders = [];
        $group = null;

        $newGroup = function () {
            return [
                'tracking_code' => '',
                'shipment_id' => '',
                'sale_id' => '',
                'pack_id' => '',
                'recipient' => '',
                'products' => [],
                'uuid' => '',
            ];
        };

        $flush = function () use (&$orders, &$group) {
            if (!$group || empty($group['products'])) {
                $group = null;
                return;
            }
            foreach ($group['products'] as $product) {
                $name = $this->cleanProduct($product['name'] ?? '');
                if ($name === '' || !$this->isValidProductName($name)) { continue; }
                $orders[] = $this->makeOrder(
                    $group['tracking_code'] ?? '',
                    $group['shipment_id'] ?? '',
                    $group['sale_id'] ?? '',
                    $group['pack_id'] ?? '',
                    $group['recipient'] ?? '',
                    $name,
                    $product['sku'] ?? '',
                    $product['quantity'] ?? 1
                );
            }
            $group = null;
        };

        $addProduct = function ($name) use (&$group) {
            $name = $this->cleanProduct($name);
            if (!$group || empty($group['uuid']) || $name === '' || !$this->isValidProductName($name)) { return; }
            $group['products'][] = ['name' => $name, 'sku' => '', 'quantity' => 1];
        };

        $setLastQty = function ($qty) use (&$group) {
            if (!$group || empty($group['products'])) { return; }
            $idx = count($group['products']) - 1;
            $group['products'][$idx]['quantity'] = max(1, (int)$qty);
        };

        $lineCount = 0;
        foreach ($lines as $rawLine) {
            $lineCount++;
            $line = rtrim((string)$rawLine);
            $trim = trim(preg_replace('/\s+/', ' ', $line));
            if ($trim === '' || stripos($trim, 'Identifi') !== false || strtolower($trim) === 'produtos') {
                continue;
            }
            if (stripos($trim, 'Despachem as suas vendas') !== false) {
                continue;
            }

            $parts = $this->splitTwoColumns($line);
            $left = trim($parts[0]);
            $right = trim($parts[1]);

            // Modelo ML com duas colunas: UUID/metadata à esquerda e produto à direita.
            if ($this->looksLikeMlItemId($left) && $right !== '') {
                $flush();
                $group = $newGroup();
                $group['uuid'] = $left;
                $addProduct($right);
                continue;
            }

            if ($left !== '') {
                if (preg_match('/Pack\s*ID:\s*([0-9 ]{8,})/iu', $left, $m)) {
                    if (!$group) { $group = $newGroup(); }
                    $group['pack_id'] = preg_replace('/\D+/', '', $m[1]);
                } elseif (preg_match('/Venda:\s*([0-9 ]{8,})/iu', $left, $m)) {
                    if (!$group) { $group = $newGroup(); }
                    $group['sale_id'] = preg_replace('/\D+/', '', $m[1]);
                } elseif (preg_match('/Quantidade:\s*(\d+)/iu', $left, $m)) {
                    $setLastQty((int)$m[1]);
                } elseif (preg_match('/\b(469\d{3})\s*(\d{5})\b/u', $left, $m)) {
                    if (!$group) { $group = $newGroup(); }
                    $group['shipment_id'] = $m[1] . $m[2];
                } elseif (!$this->looksLikeProduct($left) && !$this->looksLikeMlNoise($left) && !$this->looksLikeMlItemId($left)) {
                    if (!$group) { $group = $newGroup(); }
                    // Nome do comprador costuma vir depois de Venda/Quantidade.
                    $name = $this->cleanPersonName($left);
                    if ($name !== '' && !preg_match('/^(Pack ID|Venda|Quantidade)$/iu', $name)) {
                        $group['recipient'] = $name;
                    }
                }
            }

            if ($right !== '') {
                if (preg_match('/Quantidade:\s*(\d+)/iu', $right, $m)) {
                    $setLastQty((int)$m[1]);
                } elseif (preg_match('/SKU:\s*(.+)$/iu', $right, $m)) {
                    if ($group && !empty($group['products'])) {
                        $idx = count($group['products']) - 1;
                        $group['products'][$idx]['sku'] = trim($m[1]);
                    }
                } elseif (!$this->looksLikeMlNoise($right)) {
                    $addProduct($right);
                }
            }
        }
        $flush();

        // Esse parser só deve assumir o controle quando achou produto real na coluna direita.
        $filtered = [];
        foreach ($orders as $order) {
            if (!empty($order['product_name']) && $order['product_name'] !== 'Produto não identificado no PDF') {
                $filtered[] = $order;
            }
        }
        return $filtered;
    }

    private function splitTwoColumns($line)
    {
        $line = rtrim((string)$line);
        // Primeiro tenta por blocos grandes de espaço, preservando layout do pdftotext -layout.
        if (preg_match('/^(.{0,80}?)(?:\s{5,})(\S.*)$/u', $line, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        // Fallback fixo para layouts onde a segunda coluna começa por volta do caractere 56.
        $left = trim($this->substrUtf($line, 0, 56));
        $right = trim($this->substrUtf($line, 56));
        return [$left, $right];
    }

    private function looksLikeMlItemId($value)
    {
        $value = trim((string)$value);
        if ($value === '') { return false; }
        // UUID normal ou com erro comum de extração PDF onde "f" aparece como "fi".
        if (preg_match('/^[a-z0-9]{6,}(?:[-fi]{1,3}[a-z0-9]{3,}){2,}$/iu', $value)) { return true; }
        if (preg_match('/^[a-z0-9\-]{24,}$/iu', $value) && preg_match('/[a-z]/iu', $value)) { return true; }
        return false;
    }

    private function looksLikeMlNoise($line)
    {
        $line = trim((string)$line);
        if ($line === '') { return true; }
        return (bool)preg_match('/^(Identifi|Produtos|Despachem|Não demore|o seu comprador|NF:|DANFE|DADOS ADICIONAIS|Chave de acesso|Protocolo|Remetente:|CNPJ:|DESTINATARIO:|UF:|1\s*-\s*Sa[ií]da|Número|Emiss[ãa]o)\b/iu', $line);
    }

    private function parseMercadoLivreProductSection($text)
    {
        $pos = $this->striposUtf($text, 'Identifi');
        if ($pos === false) {
            return [];
        }
        $section = $this->substrUtf($text, $pos);
        $lines = preg_split('/\n/', $section);
        $orders = [];
        $current = null;
        $lastTracking = null;
        $lastSaleId = null;
        $lastPackId = null;
        $pendingCustomer = null;

        $flush = function () use (&$orders, &$current) {
            if ($current && !empty($current['product_name'])) {
                $current['quantity'] = max(1, (int)($current['quantity'] ?? 1));
                $orders[] = $current;
            }
            $current = null;
        };

        foreach ($lines as $line) {
            $line = rtrim($line);
            $trim = trim($line);
            if ($trim === '' || stripos($trim, 'Despachem as suas vendas') !== false || stripos($trim, 'Identifi') !== false || strtolower($trim) === 'produtos') {
                continue;
            }

            // Divide em duas colunas. Nos PDFs do ML, produto/quantidade ficam à direita.
            $left = trim($this->substrUtf($line, 0, 56));
            $right = trim($this->substrUtf($line, 56));
            if ($right === '' && preg_match('/^([A-Z]{2}\d{9}BR)\s{2,}(.+)$/u', $line, $m)) {
                $left = $m[1];
                $right = trim($m[2]);
            }

            if (preg_match('/\b([A-Z]{2}\d{9}BR)\b/', $left, $m)) {
                $flush();
                $lastTracking = $m[1];
                $current = [
                    'tracking_code' => $lastTracking,
                    'shipment_id' => '',
                    'sale_id' => $lastSaleId,
                    'pack_id' => $lastPackId,
                    'recipient' => '',
                    'product_name' => $this->cleanProduct($right),
                    'sku' => '',
                    'quantity' => 1,
                ];
                $this->applyMetadata($current, $left);
                $this->applyMetadata($current, $right);
                if (!empty($current['sale_id'])) { $lastSaleId = $current['sale_id']; }
                if (!empty($current['pack_id'])) { $lastPackId = $current['pack_id']; }
                continue;
            }

            if ($current) {
                if ($left !== '') {
                    if (preg_match('/Venda:\s*([0-9]+)/i', $left, $m)) {
                        $current['sale_id'] = $m[1];
                        $lastSaleId = $m[1];
                    } elseif (preg_match('/Pack\s*ID:\s*([0-9]+)/i', $left, $m)) {
                        $current['pack_id'] = $m[1];
                        $lastPackId = $m[1];
                    } elseif (preg_match('/SKU:\s*(.+)$/i', $left, $m)) {
                        $current['sku'] = trim($m[1]);
                    } elseif (preg_match('/Quantidade:\s*(\d+)/i', $left, $m)) {
                        $current['quantity'] = (int)$m[1];
                    } else {
                        // Normalmente aqui é o nome do comprador.
                        $pendingCustomer = $left;
                        if (empty($current['recipient']) && !$this->looksLikeProduct($left)) {
                            $current['recipient'] = $left;
                        }
                    }
                }

                if ($right !== '') {
                    if (preg_match('/Quantidade:\s*(\d+)/i', $right, $m)) {
                        $current['quantity'] = (int)$m[1];
                    } elseif (preg_match('/SKU:\s*(.+)$/i', $right, $m)) {
                        $current['sku'] = trim($m[1]);
                    } elseif (preg_match('/Venda:\s*([0-9]+)/i', $right, $m)) {
                        $current['sale_id'] = $m[1];
                        $lastSaleId = $m[1];
                    } elseif (preg_match('/Pack\s*ID:\s*([0-9]+)/i', $right, $m)) {
                        $current['pack_id'] = $m[1];
                        $lastPackId = $m[1];
                    } else {
                        $product = $this->cleanProduct($right);
                        if ($product !== '') {
                            // Novo produto dentro do mesmo pacote/etiqueta.
                            if (!empty($current['product_name']) && $current['quantity'] > 0) {
                                $prevTracking = $current['tracking_code'];
                                $prevSale = $current['sale_id'] ?: $lastSaleId;
                                $prevPack = $current['pack_id'] ?: $lastPackId;
                                $prevRecipient = $current['recipient'] ?: $pendingCustomer;
                                $flush();
                                $current = [
                                    'tracking_code' => $prevTracking ?: $lastTracking,
                                    'shipment_id' => '',
                                    'sale_id' => $prevSale,
                                    'pack_id' => $prevPack,
                                    'recipient' => $prevRecipient,
                                    'product_name' => $product,
                                    'sku' => '',
                                    'quantity' => 1,
                                ];
                            } else {
                                $current['product_name'] = trim(($current['product_name'] ?? '') . ' ' . $product);
                            }
                        }
                    }
                }
            }
        }
        $flush();

        // Remove falsos positivos sem nome de produto real.
        $filtered = [];
        foreach ($orders as $order) {
            $name = trim($order['product_name']);
            if ($name === '' || preg_match('/^(Quantidade|SKU|Venda|Pack ID)/i', $name)) {
                continue;
            }
            $filtered[] = $order;
        }
        return $filtered;
    }

    private function parseMercadoLivreFlexProductSection($text)
    {
        $pos = $this->striposUtf($text, 'Identifi');
        if ($pos === false || $this->striposUtf($text, 'Produtos') === false) {
            return [];
        }
        $section = $this->substrUtf($text, $pos);
        $lines = preg_split('/\n/', $section);
        $orders = [];
        $current = null;

        $flush = function () use (&$orders, &$current) {
            if ($current && !empty($current['shipment_id']) && !empty($current['product_name'])) {
                $current['quantity'] = max(1, (int)($current['quantity'] ?? 1));
                $orders[] = $current;
            }
            $current = null;
        };

        foreach ($lines as $rawLine) {
            $line = rtrim((string)$rawLine);
            $trim = trim(preg_replace('/\s+/', ' ', $line));
            if ($trim === '' || stripos($trim, 'Identifi') !== false || strtolower($trim) === 'produtos' || stripos($trim, 'Despachem as suas vendas') !== false) {
                continue;
            }

            if (preg_match('/^(\d{8,})\s{2,}(.+)$/u', $line, $m)) {
                $flush();
                $current = [
                    'tracking_code' => '',
                    'shipment_id' => preg_replace('/\D+/', '', $m[1]),
                    'sale_id' => '',
                    'pack_id' => '',
                    'recipient' => '',
                    'product_name' => $this->cleanProduct($m[2]),
                    'sku' => '',
                    'quantity' => 1,
                ];
                continue;
            }

            if (!$current) { continue; }

            if (preg_match('/Venda:\s*([0-9 ]{8,})/iu', $trim, $m)) {
                $current['sale_id'] = preg_replace('/\D+/', '', $m[1]);
            }
            if (preg_match('/Quantidade:\s*(\d+)/iu', $trim, $m)) {
                $current['quantity'] = (int)$m[1];
            }
            if (!preg_match('/Venda:|Quantidade:/iu', $trim) && !$this->looksLikeProduct($trim) && !preg_match('/^\d+$/', $trim)) {
                $current['recipient'] = $this->cleanPersonName($trim);
            }
        }
        $flush();
        return $orders;
    }

    private function parseMercadoLivreSingleProductSection($text)
    {
        $text = $this->normalizePdfText((string)$text);
        $flat = $this->lower($this->removeAccents($text));
        if (strpos($flat, 'identifi') === false || strpos($flat, 'produto') === false) {
            return [];
        }
        // Este fallback é para PDFs pequenos de FLEX/ML onde há apenas "Identificação Produto" no singular.
        $pos = $this->striposUtf($text, 'Identifi');
        if ($pos === false) { return []; }
        $section = $this->substrUtf($text, $pos);
        $lines = array_values(array_filter(array_map(function ($v) {
            return trim(preg_replace('/\s+/', ' ', (string)$v));
        }, preg_split('/\n/', $section)), function ($v) { return $v !== ''; }));

        $shipment = '';
        $sale = '';
        $pack = '';
        $recipient = '';
        $qty = 1;
        $afterPrompt = false;
        $productLines = [];
        foreach ($lines as $line) {
            if (stripos($line, 'Identifi') !== false || preg_match('/^Produto[s]?$/iu', $line)) { continue; }
            if (preg_match('/^([0-9]{10,14})\s+(.+)$/u', $line, $m)) { $shipment = $m[1]; $productLines[] = $m[2]; continue; }
            if (preg_match('/^([0-9]{10,14})$/', preg_replace('/\s+/', '', $line), $m)) { $shipment = $m[1]; continue; }
            if (preg_match('/Pack\s*ID:\s*([0-9 ]{8,})/iu', $line, $m)) { $pack = preg_replace('/\D+/', '', $m[1]); }
            if (preg_match('/Venda:\s*([0-9 ]{8,})/iu', $line, $m)) { $sale = preg_replace('/\D+/', '', $m[1]); }
            if (preg_match('/Quantidade:\s*(\d+)/iu', $line, $m)) { $qty = max(1, (int)$m[1]); }
            if (preg_match('/Pack\s*ID:|Venda:|Quantidade:/iu', $line)) { continue; }
            if (stripos($line, 'Despache') !== false || stripos($line, 'comprador') !== false) { $afterPrompt = true; continue; }
            if (!$afterPrompt) {
                if (!$this->looksLikeMlNoise($line) && !$this->looksLikeProduct($line) && !preg_match('/^[0-9a-f\-]{12,}$/iu', $line)) {
                    $recipient = $this->cleanPersonName($line);
                }
                continue;
            }
            if (!$this->looksLikeMlNoise($line)) { $productLines[] = $line; }
        }
        $name = $this->cleanProduct(implode(' ', $productLines));
        if ($name === '' || !$this->isValidProductName($name)) { return []; }
        return [$this->makeOrder('', $shipment, $sale, $pack, $recipient, $name, '', $qty)];
    }

    private function applyMetadata(&$current, $text)
    {
        if (preg_match('/Venda:\s*([0-9]+)/i', $text, $m)) {
            $current['sale_id'] = $m[1];
        }
        if (preg_match('/Pack\s*ID:\s*([0-9]+)/i', $text, $m)) {
            $current['pack_id'] = $m[1];
        }
        if (preg_match('/SKU:\s*(.+)$/i', $text, $m)) {
            $current['sku'] = trim($m[1]);
        }
        if (preg_match('/Quantidade:\s*(\d+)/i', $text, $m)) {
            $current['quantity'] = (int)$m[1];
        }
    }

    private function parseMercadoLivreReportFallbackSection($text)
    {
        $text = $this->normalizePdfText($text);
        $pos = $this->striposUtf($text, 'Produtos');
        if ($pos === false) {
            $pos = $this->striposUtf($text, 'Despachem as suas vendas');
        }
        if ($pos === false) { return []; }
        $section = $this->substrUtf($text, max(0, $pos - 300));
        $lines = preg_split('/\n/', $section);
        $orders = [];
        $current = null;

        $flush = function () use (&$orders, &$current) {
            if (!$current) { return; }
            $name = $this->cleanProduct($current['product_name'] ?? '');
            if ($name !== '' && $this->isValidProductName($name)) {
                $current['product_name'] = $name;
                $current['quantity'] = max(1, (int)($current['quantity'] ?? 1));
                $orders[] = $current;
            }
            $current = null;
        };

        foreach ($lines as $rawLine) {
            $line = rtrim((string)$rawLine);
            $trim = trim(preg_replace('/\s+/', ' ', $line));
            if ($trim === '' || $this->looksLikeMlNoise($trim)) { continue; }

            $parts = $this->splitTwoColumns($line);
            $left = trim($parts[0]);
            $right = trim($parts[1]);

            if (preg_match('/\b([A-Z]{2}\d{9}BR)\b/u', $left, $m) || preg_match('/\b([A-Z]{2}\d{9}BR)\b/u', $trim, $m)) {
                $flush();
                $product = $right;
                if ($product === '') {
                    $product = trim(preg_replace('/\b[A-Z]{2}\d{9}BR\b/u', '', $trim));
                }
                $current = [
                    'tracking_code' => $m[1],
                    'shipment_id' => '',
                    'sale_id' => '',
                    'pack_id' => '',
                    'recipient' => '',
                    'product_name' => $this->cleanProduct($product),
                    'sku' => '',
                    'quantity' => 1,
                ];
                continue;
            }

            if (preg_match('/^([0-9]{8,})\b/u', $left, $m) && $right !== '' && $this->looksLikeProduct($right)) {
                $flush();
                $current = [
                    'tracking_code' => '',
                    'shipment_id' => preg_replace('/\D+/', '', $m[1]),
                    'sale_id' => '',
                    'pack_id' => '',
                    'recipient' => '',
                    'product_name' => $this->cleanProduct($right),
                    'sku' => '',
                    'quantity' => 1,
                ];
                continue;
            }

            if (!$current) { continue; }
            $joined = trim($left . ' ' . $right);
            if (preg_match('/Pack\s*ID:\s*([0-9 ]{8,})/iu', $joined, $m)) {
                $current['pack_id'] = preg_replace('/\D+/', '', $m[1]);
            }
            if (preg_match('/Venda:\s*([0-9 ]{8,})/iu', $joined, $m)) {
                $current['sale_id'] = preg_replace('/\D+/', '', $m[1]);
            }
            if (preg_match('/Quantidade:\s*(\d+)/iu', $joined, $m)) {
                $current['quantity'] = (int)$m[1];
            }
            if (($right !== '' || $left !== '') && !preg_match('/Pack\s*ID:|Venda:|Quantidade:/iu', $joined)) {
                $candidate = $right !== '' ? $right : $left;
                if ($this->looksLikeProduct($candidate)) {
                    $current['product_name'] = $this->cleanProduct(trim(($current['product_name'] ?? '') . ' ' . $candidate));
                } else {
                    $name = $this->cleanPersonName($candidate);
                    if ($name !== '' && empty($current['recipient'])) {
                        $current['recipient'] = $name;
                    }
                }
            }
        }
        $flush();
        return $orders;
    }

    private function enrichMercadoLivreOrders($orders, $text)
    {
        if (empty($orders)) { return $orders; }
        $meta = $this->extractMercadoLivreMetadata($text);
        foreach ($orders as &$order) {
            $found = [];
            $tracking = trim((string)($order['tracking_code'] ?? ''));
            $shipment = trim((string)($order['shipment_id'] ?? ''));
            if ($tracking !== '' && isset($meta['tracking'][$tracking])) { $found = $meta['tracking'][$tracking]; }
            if (empty($found) && $shipment !== '' && isset($meta['shipment'][$shipment])) { $found = $meta['shipment'][$shipment]; }
            if (empty($found)) { continue; }
            if (empty($order['recipient']) && !empty($found['recipient'])) { $order['recipient'] = $found['recipient']; }
            if (empty($order['tracking_code']) && !empty($found['tracking_code'])) { $order['tracking_code'] = $found['tracking_code']; }
            if (empty($order['shipment_id']) && !empty($found['shipment_id'])) { $order['shipment_id'] = $found['shipment_id']; }
            foreach (['sender_name','sender_address','sender_document','sender_city','recipient_address','recipient_document','recipient_city','nf','service','weight'] as $field) {
                if (empty($order[$field]) && !empty($found[$field])) { $order[$field] = $found[$field]; }
            }
        }
        unset($order);
        return $orders;
    }

    private function extractMercadoLivreMetadata($text)
    {
        $text = $this->normalizePdfText($text);
        $pages = preg_split('/\f/', $text);
        $out = ['tracking' => [], 'shipment' => []];
        $lastTracking = '';
        $lastShipment = '';

        foreach ($pages as $page) {
            $page = (string)$page;
            $meta = [];
            if (preg_match('/\b([A-Z]{2}\d{9}BR)\b/u', $page, $m)) { $meta['tracking_code'] = $m[1]; }
            if (preg_match('/SHP:\s*(\d{6,})\b/iu', $page, $m)) { $meta['shipment_id'] = $m[1]; }
            if (preg_match('/Envio:\s*([0-9 ]{8,})/iu', $page, $m)) { $meta['shipment_id'] = preg_replace('/\D+/', '', $m[1]); }
            if (preg_match('/NF:\s*(\d+)/iu', $page, $m)) { $meta['nf'] = $m[1]; }
            if (preg_match('/\b(Sedex|PAC|FLEX)\b/iu', $page, $m)) { $meta['service'] = strtoupper($m[1]); }
            if (preg_match('/PESO\s*([0-9.,]+\s*[a-z]{1,3})/iu', $page, $m)) { $meta['weight'] = trim($m[1]); }

            if (preg_match('/NOME\s+REMETENTE:\s*([^\n]+)/iu', $page, $m)) { $meta['sender_name'] = $this->cleanPersonName($m[1]); }
            if (preg_match('/CNPJ\/CPF\s+REMETENTE:\s*([^\n]+)/iu', $page, $m)) { $meta['sender_document'] = trim($m[1]); }
            if (preg_match('/CIDADE-UF\s+REMETENTE:\s*([^\n]+)/iu', $page, $m)) { $meta['sender_city'] = trim(preg_replace('/\s+/', ' ', $m[1])); }
            if (preg_match('/ENDERE[ÇC]O\s+REMETENTE:\s*(.+?)(?:\n\s*CNPJ\/CPF\s+DESTINAT|\n\s*NOME\s+DESTINAT|\n\s*CIDADE-UF\s+DESTINAT|$)/isu', $page, $m)) { $meta['sender_address'] = $this->cleanAddress($m[1]); }

            if (preg_match('/NOME\s+DESTINAT[ÁA]RIO:\s*([^\n]+)/iu', $page, $m)) { $meta['recipient'] = $this->cleanPersonName($m[1]); }
            if (preg_match('/CNPJ\/CPF\s+DESTINAT[ÁA]RIO:\s*([^\n]+)/iu', $page, $m)) { $meta['recipient_document'] = trim($m[1]); }
            if (preg_match('/CIDADE-UF\s+DESTINAT[ÁA]RIO:\s*([^\n]+)/iu', $page, $m)) { $meta['recipient_city'] = trim(preg_replace('/\s+/', ' ', $m[1])); }
            if (preg_match('/ENDERE[ÇC]O\s+DESTINAT[ÁA]RIO:\s*(.+?)(?:\n\s*É contribuinte|\n\s*E contribuinte|\n\s*Chave de Acesso|$)/isu', $page, $m)) { $meta['recipient_address'] = $this->cleanAddress($m[1]); }

            $labelMeta = $this->extractMercadoLivreLabelMeta($page);
            foreach ($labelMeta as $k => $v) { if (!empty($v)) { $meta[$k] = $this->chooseBetterMetaValue($meta[$k] ?? '', $v); } }

            $tracking = $meta['tracking_code'] ?? '';
            $shipment = $meta['shipment_id'] ?? '';
            if ($tracking === '' && $shipment === '' && ($lastTracking !== '' || $lastShipment !== '')) {
                $tracking = $lastTracking;
                $shipment = $lastShipment;
                if ($tracking !== '') { $meta['tracking_code'] = $tracking; }
                if ($shipment !== '') { $meta['shipment_id'] = $shipment; }
            }
            if ($tracking !== '') { $lastTracking = $tracking; }
            if ($shipment !== '') { $lastShipment = $shipment; }

            if ($tracking !== '') {
                $out['tracking'][$tracking] = $this->mergeMeta($out['tracking'][$tracking] ?? [], $meta);
            }
            if ($shipment !== '') {
                $out['shipment'][$shipment] = $this->mergeMeta($out['shipment'][$shipment] ?? [], $meta);
            }
        }
        return $out;
    }

    private function extractMercadoLivreLabelMeta($page)
    {
        $meta = [];
        $lines = preg_split('/\n/', (string)$page);
        $cleanLines = array_values(array_map(function ($v) { return rtrim((string)$v); }, $lines));
        for ($i = 0; $i < count($cleanLines); $i++) {
            $line = trim($cleanLines[$i]);
            if (preg_match('/^DESTINATARIO$/iu', $line) || preg_match('/^DESTINAT[ÁA]RIO$/iu', $line)) {
                $name = '';
                $addr = [];
                for ($j = $i + 1; $j < min(count($cleanLines), $i + 10); $j++) {
                    $v = trim($cleanLines[$j]);
                    if ($v === '') { if ($name !== '') { break; } continue; }
                    if (preg_match('/^Remetente:?$/iu', $v)) { break; }
                    if ($name === '') { $name = $v; } else { $addr[] = $v; }
                }
                if ($name !== '') { $meta['recipient'] = $this->cleanPersonName($name); }
                if (!empty($addr)) { $meta['recipient_address'] = $this->cleanAddress(implode(' ', $addr)); }
            }
            if (preg_match('/^Remetente:?$/iu', $line)) {
                $name = '';
                $addr = [];
                for ($j = $i + 1; $j < min(count($cleanLines), $i + 8); $j++) {
                    $v = trim($cleanLines[$j]);
                    if ($v === '') { if ($name !== '') { break; } continue; }
                    if (preg_match('/^[0-9]{8,}$/', preg_replace('/\D+/', '', $v))) { break; }
                    if ($name === '') { $name = $v; } else { $addr[] = $v; }
                }
                if ($name !== '') { $meta['sender_name'] = $this->cleanPersonName($name); }
                if (!empty($addr)) { $meta['sender_address'] = $this->cleanAddress(implode(' ', $addr)); }
            }
        }
        return $meta;
    }

    private function mergeMeta($old, $new)
    {
        foreach ($new as $k => $v) {
            $v = trim((string)$v);
            if ($v === '') { continue; }
            $old[$k] = $this->chooseBetterMetaValue($old[$k] ?? '', $v);
        }
        return $old;
    }

    private function chooseBetterMetaValue($old, $new)
    {
        $old = trim((string)$old);
        $new = trim((string)$new);
        if ($old === '') { return $new; }
        if ($new === '') { return $old; }
        return $this->strlenUtf($new) > $this->strlenUtf($old) ? $new : $old;
    }

    private function cleanAddress($value)
    {
        $value = $this->normalizePdfText((string)$value);
        $value = preg_replace('/\s+/', ' ', trim($value));
        $value = preg_replace('/Código\s+de\s+Rastreamento:\s*[A-Z0-9]+/iu', '', $value);
        $value = preg_replace('/\b(REMETENTE|DESTINAT[ÁA]RIO|NOME:|CPF\/CNPJ).*$/iu', '', $value);
        $value = preg_replace('/\s*(Recebedor:|Assinatura:|Documento:).*$/iu', '', $value);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function parseMercadoLivreLabelsOnly($text)
    {
        $orders = [];
        $blocks = preg_split('/\f/', $text);
        foreach ($blocks as $block) {
            if (stripos($block, 'NF:') === false && stripos($block, 'SHP:') === false) {
                continue;
            }
            $tracking = '';
            $shipment = '';
            $recipient = '';
            if (preg_match('/\b([A-Z]{2}\d{9}BR)\b/', $block, $m)) {
                $tracking = $m[1];
            }
            if (preg_match('/SHP:\s*(\d+)/i', $block, $m)) {
                $shipment = $m[1];
            }
            if (preg_match('/DESTINATARIO\s*\n\s*([^\n]+)/iu', $block, $m)) {
                $recipient = trim($m[1]);
            }
            if ($tracking || $shipment) {
                $orders[] = [
                    'tracking_code' => $tracking,
                    'shipment_id' => $shipment,
                    'sale_id' => '',
                    'pack_id' => '',
                    'recipient' => $recipient,
                    'product_name' => 'Produto não identificado no PDF',
                    'sku' => '',
                    'quantity' => 1,
                ];
            }
        }
        return $orders;
    }

    private function parseShopee($text, $rawText = '')
    {
        $labels = [];
        $trackingPattern = '/(?:SPX[A-Z0-9]{8,}|BR[0-9]{12}[A-Z0-9]|[A-Z]{2}\d{9}[A-Z]{2}|LP\d{9,}|JT[A-Z0-9]{8,})/i';

        $pages = preg_split('/\f/', $text);
        $rawPages = $rawText !== '' ? preg_split('/\f/', $rawText) : [];
        $metaByTracking = [];
        $pageMeta = [];
        $orders = [];

        // 1º passe: lê a etiqueta/DACE e monta um mapa por rastreio.
        $lastMeta = [];
        foreach ($pages as $pageIndex => $page) {
            $page = $this->normalizePdfText(str_replace("\r", "\n", (string)$page));
            $rawPage = $this->normalizePdfText(str_replace("\r", "\n", (string)($rawPages[$pageIndex] ?? '')));
            $meta = $this->extractShopeePageMeta($page, $rawPage, $trackingPattern);

            // Algumas declarações vêm numa página separada. Se não trouxer nome/endereço, herda os dados bons da etiqueta anterior,
            // mas somente quando for o mesmo rastreio. Isso evita misturar dados de clientes diferentes.
            $metaTrackingBeforeInherit = $this->normalizeShopeeTracking($meta['tracking_code'] ?? '');
            $lastTrackingBeforeInherit = $this->normalizeShopeeTracking($lastMeta['tracking_code'] ?? '');
            if (!empty($lastMeta) && ($metaTrackingBeforeInherit === '' || $lastTrackingBeforeInherit === '' || $metaTrackingBeforeInherit === $lastTrackingBeforeInherit)) {
                foreach (['tracking_code','order_id','recipient','recipient_address','recipient_city','recipient_cep','sender_name','sender_address','sender_city','sender_cep','service','dace_number','dce_key'] as $field) {
                    if (empty($meta[$field]) && !empty($lastMeta[$field])) {
                        $meta[$field] = $lastMeta[$field];
                    }
                }
            }

            $tracking = $this->normalizeShopeeTracking($meta['tracking_code'] ?? '');
            if ($tracking !== '') {
                $labels[$tracking] = true;
                $meta['tracking_code'] = $tracking;
                $metaByTracking[$tracking] = $this->mergeMeta($metaByTracking[$tracking] ?? [], $meta);
            }
            $pageMeta[$pageIndex] = $meta;

            // Só deixa a etiqueta virar lastMeta quando ela tem dados reais de etiqueta, não só declaração mascarada.
            if (!empty($meta['recipient']) || !empty($meta['order_id']) || !empty($meta['sender_name'])) {
                $lastMeta = $meta;
            }
        }

        // 2º passe: lê a tabela IDENTIFICAÇÃO DOS BENS.
        foreach ($pages as $pageIndex => $page) {
            $page = $this->normalizePdfText(str_replace("\r", "\n", (string)$page));
            $rawPage = $this->normalizePdfText(str_replace("\r", "\n", (string)($rawPages[$pageIndex] ?? '')));
            $meta = $pageMeta[$pageIndex] ?? [];
            $tracking = $this->normalizeShopeeTracking($meta['tracking_code'] ?? $this->firstMatch($trackingPattern, $page . "\n" . $rawPage));
            if ($tracking !== '' && isset($metaByTracking[$tracking])) {
                $meta = $this->mergeMeta($metaByTracking[$tracking], $meta);
            }

            $items = $this->extractShopeeItemsFromPage($page, $rawPage);
            foreach ($items as $item) {
                $order = $this->makeOrder(
                    $tracking,
                    '',
                    $meta['order_id'] ?? '',
                    '',
                    $meta['recipient'] ?? '',
                    $item['product_name'],
                    $item['sku'],
                    $item['quantity']
                );
                foreach (['recipient_address','recipient_city','recipient_cep','sender_name','sender_address','sender_city','sender_cep','service','dace_number','dce_key'] as $field) {
                    if (!empty($meta[$field])) { $order[$field] = $meta[$field]; }
                }
                if (!empty($item['value'])) { $order['item_value'] = $item['value']; }
                $orders[] = $order;
                if ($tracking !== '') { $labels[$tracking] = true; }
            }
        }

        // Fallback para modelos Shopee que trazem produto fora da tabela padrão.
        if (empty($orders)) {
            $orders = $this->parseShopeeLooseText($text, $trackingPattern, $labels);
        }

        // Se uma etiqueta foi lida mas a tabela do item falhou, cria linha de alerta em vez de sumir com o pedido.
        $orderTrackings = [];
        foreach ($orders as $order) {
            $t = $this->normalizeShopeeTracking($order['tracking_code'] ?? '');
            if ($t !== '') { $orderTrackings[$t] = true; }
        }
        foreach (array_keys($labels) as $code) {
            $code = $this->normalizeShopeeTracking($code);
            if ($code === '' || isset($orderTrackings[$code])) { continue; }
            $meta = $metaByTracking[$code] ?? [];
            $order = $this->makeOrder($code, '', $meta['order_id'] ?? '', '', $meta['recipient'] ?? '', 'Produto não identificado no PDF Shopee', '', 1);
            foreach (['recipient_address','recipient_city','recipient_cep','sender_name','sender_address','sender_city','sender_cep','service','dace_number','dce_key'] as $field) {
                if (!empty($meta[$field])) { $order[$field] = $meta[$field]; }
            }
            $orders[] = $order;
        }

        // Labels devem ser os rastreios principais usados nos pedidos; isso evita contar SPX separado como outra etiqueta.
        $usedLabels = [];
        foreach ($orders as $order) {
            $t = $this->normalizeShopeeTracking($order['tracking_code'] ?? '');
            if ($t !== '') { $usedLabels[$t] = true; }
        }
        if (!empty($usedLabels)) { $labels = $usedLabels; }

        $warnings = [];
        $missingProducts = 0;
        foreach ($orders as $o) {
            if (($o['product_name'] ?? '') === 'Produto não identificado no PDF Shopee') { $missingProducts++; }
        }
        if ($missingProducts > 0) {
            $warnings[] = $missingProducts . ' etiqueta(s) foram encontradas, mas sem produto claro na tabela. Elas entram no total para a conta não ficar menor.';
        }

        return [
            'labels' => array_keys($labels),
            'orders' => $orders,
            'warnings' => $warnings,
        ];
    }

    private function normalizeShopeeTracking($code)
    {
        $code = strtoupper(trim((string)$code));
        $code = preg_replace('/\s+/', '', $code);
        if ($code === '') { return ''; }
        if (preg_match('/(BR\d{12}[A-Z0-9])/', $code, $m)) { return $m[1]; }
        if (preg_match('/([A-Z]{2}\d{9}[A-Z]{2})/', $code, $m)) { return $m[1]; }
        if (preg_match('/(SPX[A-Z0-9]{8,})/', $code, $m)) { return $m[1]; }
        return $code;
    }

    private function extractShopeePageMeta($page, $rawPage, $trackingPattern)
    {
        $page = $this->normalizePdfText((string)$page);
        $rawPage = $this->normalizePdfText((string)$rawPage);
        $both = $page . "\n" . $rawPage;
        $meta = [];
        $tracking = $this->firstMatch($trackingPattern, $both);
        $tracking = $this->normalizeShopeeTracking($tracking);
        if ($tracking !== '') { $meta['tracking_code'] = $tracking; }

        if (preg_match('/\b(26\d{10,}[A-Z0-9]{2,})\b/iu', $both, $m)) { $meta['order_id'] = strtoupper($m[1]); }
        if (preg_match('/N\.º\s*(\d{5,})/iu', $both, $m) || preg_match('/N[ºo]\s*(\d{5,})/iu', $both, $m)) { $meta['dace_number'] = $m[1]; }
        if (preg_match('/Chave\s+de\s+Acesso\s+DC-e\s*([0-9]{30,})/iu', $both, $m)) { $meta['dce_key'] = $m[1]; }
        if (preg_match('/\b(LEVAR\s+PARA\s+AG[ÊE]NCIA\s+SHOPEE|RETIRADA\s+PELO\s+COMPRADOR|AG[ÊE]NCIA)\b/iu', $page, $m)) { $meta['service'] = preg_replace('/\s+/', ' ', trim($m[1])); }

        $recipientData = $this->extractShopeeRecipientData($page);
        foreach ($recipientData as $k => $v) { if ($v !== '') { $meta[$k] = $v; } }

        $senderData = $this->extractShopeeSenderData($page, $rawPage);
        foreach ($senderData as $k => $v) { if ($v !== '') { $meta[$k] = $v; } }

        // CEP do destinatário também aparece na declaração, mesmo quando o nome está mascarado.
        if (empty($meta['recipient_cep']) && preg_match('/DESTINAT[ÁA]RIO.*?CEP:\s*([0-9]{8}|[0-9]{5}-[0-9]{3})/isu', $both, $m)) {
            $meta['recipient_cep'] = $this->formatCep($m[1]);
        }
        if (empty($meta['sender_cep']) && preg_match('/REMETENTE.*?CEP:\s*([0-9]{8}|[0-9]{5}-[0-9]{3})/isu', $both, $m)) {
            $meta['sender_cep'] = $this->formatCep($m[1]);
        }
        return $meta;
    }

    private function formatCep($cep)
    {
        $digits = preg_replace('/\D+/', '', (string)$cep);
        if (strlen($digits) === 8) { return substr($digits, 0, 5) . '-' . substr($digits, 5); }
        return trim((string)$cep);
    }

    private function extractShopeeRecipientData($page)
    {
        $lines = array_values(array_map('trim', preg_split('/\n/', $this->normalizePdfText((string)$page))));
        $data = ['recipient' => '', 'recipient_address' => '', 'recipient_city' => '', 'recipient_cep' => ''];

        // Caso comum do layout: DESTINATÁRIO na primeira coluna e o nome completo logo abaixo.
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if ($line === '') { continue; }
            if (preg_match('/^DESTINAT[ÁA]RIO\b/iu', $line)) {
                for ($j = $i + 1; $j < min(count($lines), $i + 8); $j++) {
                    $cand = trim($lines[$j]);
                    if ($cand === '') { continue; }
                    // Em alguns layouts o nome vem com textos da declaração na mesma linha.
                    $cand = preg_replace('/\s{2,}.*$/u', '', $cand);
                    if ($this->looksLikeShopeePersonName($cand)) {
                        $data['recipient'] = $this->cleanPersonName($cand);
                        $addr = [];
                        for ($k = $j + 1; $k < min(count($lines), $j + 7); $k++) {
                            $v = trim($lines[$k]);
                            if ($v === '') { continue; }
                            $v = trim(preg_replace('/\s{2,}.*$/u', '', $v));
                            $v = trim(preg_replace('/\b(REMETENTE|DESTINAT[ÁA]RIO|NOME:|CPF\/CNPJ).*$/iu', '', $v));
                            if ($v === '') { continue; }
                            if (preg_match('/^(Bairro:|CEP:|Pedido:|REMETENTE|DACE|DECLARA[ÇC][ÃA]O|CPF\/CNPJ|NOME:|SOC|SP2)/iu', $v)) { break; }
                            if (preg_match('/\b(\d{5}-?\d{3})\b/u', $v, $cm)) { $data['recipient_cep'] = $this->formatCep($cm[1]); }
                            $addr[] = $v;
                        }
                        if (!empty($addr)) { $data['recipient_address'] = $this->cleanAddress(implode(' ', $addr)); }
                        break 2;
                    }
                }
            }
        }

        // Fallback: nome completo geralmente aparece imediatamente antes do bloco DACE.
        if ($data['recipient'] === '') {
            for ($i = 0; $i < count($lines); $i++) {
                if (stripos($lines[$i], 'DACE RESUMIDA') === false) { continue; }
                for ($j = $i - 1; $j >= max(0, $i - 12); $j--) {
                    $cand = trim($lines[$j]);
                    if ($this->looksLikeShopeePersonName($cand)) {
                        $data['recipient'] = $this->cleanPersonName($cand);
                        break 2;
                    }
                }
            }
        }

        if (preg_match('/Bairro:\s*([^\n]+)/iu', $page, $m)) {
            $bairro = trim(preg_replace('/\s{2,}.*$/u', '', $m[1]));
            $bairro = trim(preg_replace('/\s+/', ' ', $bairro));
            if ($bairro !== '' && stripos($bairro, 'CEP') === false && empty($data['recipient_city'])) { $data['recipient_city'] = $bairro; }
        }
        if (preg_match('/CEP:\s*([0-9]{5}-?[0-9]{3})/iu', $page, $m)) {
            $data['recipient_cep'] = $this->formatCep($m[1]);
        }
        return $data;
    }

    private function extractShopeeSenderData($page, $rawPage)
    {
        $lines = array_values(array_map('trim', preg_split('/\n/', $this->normalizePdfText((string)$page))));
        $data = ['sender_name' => '', 'sender_address' => '', 'sender_city' => '', 'sender_cep' => ''];

        // Pega o bloco inferior da etiqueta: REMETENTE\nLoja\nEndereço\nCEP.
        for ($i = 0; $i < count($lines); $i++) {
            if (!preg_match('/^REMETENTE\b/iu', $lines[$i])) { continue; }
            $name = '';
            $addr = [];
            for ($j = $i + 1; $j < min(count($lines), $i + 10); $j++) {
                $v = trim($lines[$j]);
                if ($v === '') { continue; }
                if (preg_match('/^(SOC|SP2|DACE|DECLARA[ÇC][ÃA]O|Código de Rastreamento|NOME:|CPF\/CNPJ|DESTINAT[ÁA]RIO)/iu', $v)) { break; }
                if (preg_match('/^CEP:\s*([0-9]{5}-?[0-9]{3})/iu', $v, $m)) { $data['sender_cep'] = $this->formatCep($m[1]); break; }
                if ($name === '' && $this->looksLikeSenderName($v)) { $name = $v; }
                elseif ($name !== '') { $addr[] = $v; }
            }
            if ($name !== '') {
                $name = preg_replace('/\s+(SOC|SP2)$/iu', '', $name);
                $data['sender_name'] = $this->cleanPersonName($name);
                if (!empty($addr)) { $data['sender_address'] = $this->cleanAddress(implode(' ', $addr)); }
                break;
            }
        }

        // Fallback da declaração: NOME: loja Mãe / NOME: loja alan.
        if ($data['sender_name'] === '' && preg_match('/REMETENTE\s+NOME:\s*([^\n]+)/iu', $rawPage, $m)) {
            $data['sender_name'] = $this->cleanPersonName($m[1]);
        }
        if ($data['sender_name'] === '' && preg_match('/NOME:\s*(loja[^\n]+)/iu', $rawPage, $m)) {
            $data['sender_name'] = $this->cleanPersonName($m[1]);
        }
        return $data;
    }

    private function looksLikeSenderName($value)
    {
        $v = trim((string)$value);
        if ($v === '' || preg_match('/^(Alameda|Rua|Avenida|Av\b|CEP|Envio previsto|Paulo$|Barueri$)/iu', $v)) { return false; }
        if (preg_match('/\d{5}-?\d{3}/', $v)) { return false; }
        return (bool)preg_match('/[A-Za-zÁÉÍÓÚÂÊÎÔÛÃÕÇáéíóúâêîôûãõç]/u', $v);
    }

    private function looksLikeShopeePersonName($value)
    {
        $v = trim(preg_replace('/\s+/', ' ', (string)$value));
        if ($v === '') { return false; }
        if (preg_match('/\*{2,}/', $v)) { return false; }
        if (preg_match('/^(REMETENTE|DESTINAT[ÁA]RIO|RESIDENCIAL|COMERCIAL|AG[ÊE]NCIA|SOC|SP2|CEP:?|Bairro:?|Pedido:?|Envio|RETIRADA|PELO|COMPRADOR|LEVAR|PARA|DACE|DECLARA[ÇC][ÃA]O|NOME:|CPF\/CNPJ|Loja|ALLDROP|Alameda|Rua|Avenida|Av\b)/iu', $v)) { return false; }
        if (preg_match('/\d{4,}/', $v)) { return false; }
        return (bool)preg_match('/[A-Za-zÁÉÍÓÚÂÊÎÔÛÃÕÇáéíóúâêîôûãõç]/u', $v);
    }

    private function extractShopeeRecipient($page)
    {
        $page = str_replace("\r", "\n", (string)$page);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n/', $page)), function($v) { return $v !== ''; }));

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^DESTINAT[ÁA]RIO$/iu', $line) && !empty($lines[$i + 1])) {
                return $this->cleanPersonName($lines[$i + 1]);
            }
            // Declaração Shopee: "NOME: Remetente     NOME: Destinatário"
            if (preg_match_all('/NOME:\s*([^\n]+?)(?=\s{2,}NOME:|$)/iu', $line, $m) && count($m[1]) >= 2) {
                return $this->cleanPersonName(end($m[1]));
            }
        }
        if (preg_match('/DESTINAT[ÁA]RIO\s*\n\s*([^\n]+)/iu', $page, $m)) {
            return $this->cleanPersonName($m[1]);
        }
        return '';
    }

    private function extractShopeeItemsFromPage($page, $rawPage = '')
    {
        $items = [];

        // Modelos SkyDrops/Shopee com checklist: "Produto / Variação / Qnt / SKU".
        // Esses PDFs não usam a tabela clássica "Identificação dos bens".
        $items = $this->extractShopeeChecklistItems($page);
        if (empty($items) && trim((string)$rawPage) !== '') { $items = $this->extractShopeeChecklistItems($rawPage); }
        if (!empty($items)) { return $items; }

        // Modelos DACE novo: "ITEM DESCRIÇÃO QUANTIDADE VALOR".
        $items = $this->extractShopeeDaceItemTable($page);
        if (empty($items) && trim((string)$rawPage) !== '') { $items = $this->extractShopeeDaceItemTable($rawPage); }
        if (!empty($items)) { return $items; }

        if (trim((string)$rawPage) !== '') {
            $items = $this->extractShopeeItemsFromRawPage($rawPage);
            if (!empty($items)) {
                return $items;
            }
        }

        if ($this->striposUtf($page, 'IDENTIFICA') === false || $this->striposUtf($page, 'BENS') === false) {
            return [];
        }
        $section = $page;
        if (preg_match('/IDENTIFICA[ÇC][ÃA]O\s+DOS\s+BENS(.+?)(?:\n\s*Peso\s+Total|\n\s*DECLARA[ÇC][ÃA]O|\n\s*OBSERVA[ÇC][ÃA]O|\f|$)/isu', $page, $m)) {
            $section = $m[1];
        } else {
            $pos = $this->striposUtf($page, 'IDENTIFICA');
            if ($pos !== false) {
                $section = $this->substrUtf($page, $pos);
            }
        }

        $lines = preg_split('/\n/', $section);
        $items = [];
        $currentIndex = null;
        foreach ($lines as $rawLine) {
            $line = trim(preg_replace('/\s+/', ' ', (string)$rawLine));
            if ($line === '') { continue; }
            if (preg_match('/^(N[ºo]|C[ÓO]DIGO|SKU|DESCRI|VARIA|QTD|VALOR)\b/iu', $line)) { continue; }
            if (preg_match('/^Totais\b/iu', $line) || preg_match('/^Peso\s+Total\b/iu', $line) || preg_match('/^DECLARA[ÇC][ÃA]O\b/iu', $line)) { break; }

            if (preg_match('/^\s*(\d+)\s+([A-Z0-9._\-\/]{2,})\s+(.+?)\s+(\d+)\s+([\d.,]+)\s*$/iu', $line, $m)) {
                $name = $this->cleanProduct($m[3]);
                if ($this->isValidProductName($name)) {
                    $items[] = ['sku' => trim($m[2]), 'product_name' => $name, 'quantity' => max(1, (int)$m[4])];
                    $currentIndex = count($items) - 1;
                }
                continue;
            }

            if (preg_match('/^\s*(\d+)\s+(.+?)\s+(\d+)\s+([\d.,]+)\s*$/iu', $line, $m)) {
                $name = $this->cleanProduct($m[2]);
                if ($this->isValidProductName($name)) {
                    $items[] = ['sku' => '', 'product_name' => $name, 'quantity' => max(1, (int)$m[3])];
                    $currentIndex = count($items) - 1;
                }
                continue;
            }

            if ($currentIndex !== null && !$this->looksLikeShopeeNoise($line)) {
                $items[$currentIndex]['product_name'] = $this->cleanProduct($items[$currentIndex]['product_name'] . ' ' . $line);
            }
        }
        return $items;
    }

    private function extractShopeeChecklistItems($text)
    {
        $text = $this->normalizePdfText((string)$text);
        $flat = $this->lower($this->removeAccents($text));
        if (strpos($flat, 'produto') === false || strpos($flat, 'qnt') === false || strpos($flat, 'sku') === false) {
            return [];
        }

        $rawLines = preg_split('/\n/', $text);
        $start = -1;
        for ($i = 0; $i < count($rawLines); $i++) {
            $l = $this->lower($this->removeAccents($rawLines[$i]));
            if (strpos($l, 'produto') !== false && strpos($l, 'qnt') !== false && strpos($l, 'sku') !== false) {
                $start = $i + 1;
                break;
            }
        }
        if ($start < 0) { return []; }

        $product = '';
        $sku = '';
        $qty = 1;
        for ($i = $start; $i < count($rawLines); $i++) {
            $raw = rtrim((string)$rawLines[$i]);
            $line = trim(preg_replace('/\s+/', ' ', $raw));
            if ($line === '') { continue; }
            $l = $this->lower($this->removeAccents($line));
            if (strpos($l, 'checklist de carregamento') !== false || strpos($l, 'id pedido') !== false || strpos($l, 'atencao vendedor') !== false || strpos($l, 'corte aqui') !== false || preg_match('/\bpackage\s+\d+\b/i', $line)) {
                break;
            }

            // Linha principal com colunas preservadas pelo pdftotext -layout.
            // Ex.: "1   Perfume ... Original        1      VZN-ORIGINAL-"
            if (preg_match('/^\s*\d+\s+(.+?)\s{2,}(\d+)\s+([A-Z0-9._\-]+)\s*$/iu', $raw, $m)) {
                $product = trim(($product ? $product . ' ' : '') . $m[1]);
                $qty = max(1, (int)$m[2]);
                $sku = trim($m[3]);
                continue;
            }

            // Linha de continuação com variação na esquerda e resto do SKU na direita.
            // Ex.: "    Luxo                                 27"
            if ($product !== '' && preg_match('/^\s+(.+?)\s{2,}([A-Z0-9._\-]+)\s*$/iu', $raw, $m)) {
                $left = trim($m[1]);
                $right = trim($m[2]);
                if ($left !== '' && !$this->looksLikeShopeeNoise($left)) { $product .= ' ' . $left; }
                if ($right !== '' && $sku !== '') { $sku .= $right; }
                continue;
            }

            // Fallback para texto sem layout.
            if (preg_match('/^\d+\s+(.+?)\s+(\d+)\s+([A-Z0-9._\-]+)$/iu', $line, $m)) {
                $product = trim(($product ? $product . ' ' : '') . $m[1]);
                $qty = max(1, (int)$m[2]);
                $sku = trim($m[3]);
                continue;
            }
            if ($product !== '' && !$this->looksLikeShopeeNoise($line)) {
                $product .= ' ' . $line;
            } elseif ($product === '' && !$this->looksLikeShopeeNoise($line)) {
                $product = preg_replace('/^\d+\s+/u', '', $line);
            }
        }

        $name = $this->cleanProduct($product);
        if ($name === '' || !$this->isValidProductName($name)) { return []; }
        return [[
            'sku' => $sku,
            'product_name' => $name,
            'quantity' => $qty,
        ]];
    }

    private function extractShopeeDaceItemTable($text)
    {
        $text = $this->normalizePdfText((string)$text);
        $flat = $this->lower($this->removeAccents($text));
        if (strpos($flat, 'item descricao quantidade valor') === false && strpos($flat, 'valor total') === false) {
            return [];
        }

        $lines = array_values(array_filter(array_map(function ($v) {
            return trim(preg_replace('/\s+/', ' ', (string)$v));
        }, preg_split('/\n/', $text)), function ($v) { return $v !== ''; }));

        $items = [];
        $start = -1;
        for ($i = 0; $i < count($lines); $i++) {
            $l = $this->lower($this->removeAccents($lines[$i]));
            if (strpos($l, 'item descricao quantidade valor') !== false) {
                $start = $i + 1;
                break;
            }
        }
        if ($start < 0) { return []; }

        $buffer = [];
        for ($i = $start; $i < count($lines); $i++) {
            $line = $lines[$i];
            $l = $this->lower($this->removeAccents($line));
            if (strpos($l, 'dace') === 0 || strpos($l, 'serie') === 0 || strpos($l, 'valor total') !== false || strpos($l, 'declaracao auxiliar') !== false || strpos($l, 'dados adicionais') !== false || strpos($l, 'identificacao do') !== false) {
                break;
            }
            $buffer[] = $line;
            $joined = trim(implode(' ', $buffer));
            // Ex.: "1 Perfume Armaf Club De Nuit ... 1 175.00"
            if (preg_match('/^\s*(\d+)\s+(.+?)\s+(\d+)\s+([\d.,]+)\s*$/u', $joined, $m)) {
                $name = $this->cleanProduct($m[2]);
                if ($this->isValidProductName($name)) {
                    $items[] = [
                        'sku' => '',
                        'product_name' => $name,
                        'quantity' => max(1, (int)$m[3]),
                        'value' => $m[4],
                    ];
                }
                $buffer = [];
            }
        }
        return $items;
    }

    private function extractShopeeItemsFromRawPage($rawPage)
    {
        $rawPage = $this->normalizePdfText((string)$rawPage);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n/', $rawPage)), function ($v) {
            return $v !== '';
        }));
        if (empty($lines)) { return []; }

        $lower = array_map(function ($v) { return $this->lower($this->removeAccents($v)); }, $lines);
        $start = -1;
        for ($i = 0; $i < count($lower); $i++) {
            if (strpos($lower[$i], 'identificacao') !== false) {
                for ($j = $i; $j < min(count($lower), $i + 14); $j++) {
                    if (strpos($lower[$j], 'qtd') !== false) {
                        $start = $j + 1;
                        break 2;
                    }
                }
            }
        }
        if ($start < 0) { return []; }

        $end = count($lines);
        for ($i = $start; $i < count($lower); $i++) {
            if ($lower[$i] === 'declaracao' || $lower[$i] === 'observacao' || $lower[$i] === 'assinatura') {
                $end = $i;
                break;
            }
        }
        $tokens = array_slice($lines, $start, max(0, $end - $start));
        if (empty($tokens)) { return []; }
        // Alguns PDFs da Shopee trazem o índice e o SKU na mesma linha: "1 FAKHAR-".
        // Separar isso evita perder o item inteiro.
        $expandedTokens = [];
        foreach ($tokens as $tok) {
            $tok = trim((string)$tok);
            if (preg_match('/^(\d{1,2})\s+(.+)$/u', $tok, $mm) && !preg_match('/\d{2}-\d{2}-\d{4}/', $tok) && !preg_match('/^[\d.,]+$/', trim($mm[2]))) {
                $expandedTokens[] = $mm[1];
                $expandedTokens[] = trim($mm[2]);
            } else {
                $expandedTokens[] = $tok;
            }
        }
        $tokens = $expandedTokens;

        $total = 0;
        for ($i = 0; $i < count($tokens); $i++) {
            $t = $this->removeAccents($tokens[$i]);
            if (preg_match('/^Total$/i', $t) && isset($tokens[$i + 1]) && preg_match('/^\d+$/', $tokens[$i + 1])) {
                $total = (int)$tokens[$i + 1];
                break;
            }
            if (preg_match('/^Total\s+(\d+)$/i', $t, $m)) {
                $total = (int)$m[1];
                break;
            }
        }
        if ($total <= 0) {
            $total = 1;
            foreach ($tokens as $t) {
                if (preg_match('/^\d+$/', $t)) { $total = max($total, (int)$t); }
            }
            $total = min($total, 80);
        }

        $items = [];
        $searchFrom = 0;
        for ($idx = 1; $idx <= $total; $idx++) {
            $startPos = $this->findItemStartToken($tokens, $idx, $searchFrom);
            if ($startPos === -1) { continue; }
            $nextPos = ($idx < $total) ? $this->findItemStartToken($tokens, $idx + 1, $startPos + 1) : -1;
            if ($nextPos === -1) {
                $nextPos = $this->findItemEndToken($tokens, $startPos + 1);
            }
            if ($nextPos === -1 || $nextPos <= $startPos) { $nextPos = count($tokens); }

            $itemTokens = array_slice($tokens, $startPos + 1, $nextPos - $startPos - 1);
            $parsed = $this->parseShopeeRawItemTokens($itemTokens);
            if ($parsed && $this->isValidProductName($parsed['product_name'])) {
                $items[] = $parsed;
            }
            $searchFrom = $nextPos;
        }

        return $items;
    }

    private function findItemStartToken($tokens, $idx, $from)
    {
        $idxStr = (string)$idx;
        for ($i = max(0, (int)$from); $i < count($tokens); $i++) {
            $t = trim((string)$tokens[$i]);
            if ($t !== $idxStr) { continue; }
            if (isset($tokens[$i + 1]) && preg_match('/^\d{2}-\d{2}-\d{4}$/', $tokens[$i + 1])) { continue; }
            if (isset($tokens[$i - 1]) && preg_match('/^Total$/i', $this->removeAccents($tokens[$i - 1]))) { continue; }
            if ((int)$idx > 1) {
                $prev = isset($tokens[$i - 1]) ? trim((string)$tokens[$i - 1]) : '';
                // Evita confundir número de variação/SKU com início do próximo item.
                if (!preg_match('/^[（(]?\d{1,2}[）)]?$/u', $prev) && !preg_match('/^[（(]?\d{1,2}[）)]?\s+[\d.,]+$/u', $prev)) { continue; }
            }
            return $i;
        }
        return -1;
    }

    private function findItemEndToken($tokens, $from)
    {
        for ($i = max(0, (int)$from); $i < count($tokens); $i++) {
            $t = trim((string)$tokens[$i]);
            if (preg_match('/\d{2}-\d{2}-\d{4}/', $t) || preg_match('/\bTotal\b/i', $this->removeAccents($t)) || preg_match('/^UP[A-Z0-9]+\b/i', $t)) {
                return $i;
            }
        }
        return count($tokens);
    }

    private function parseShopeeRawItemTokens($tokens)
    {
        $tokens = array_values(array_filter(array_map(function ($v) {
            $v = $this->normalizePdfText((string)$v);
            return trim($v);
        }, $tokens), function ($v) { return $v !== ''; }));
        if (empty($tokens)) { return null; }

        $qty = 1;
        $value = '';
        while (!empty($tokens)) {
            $last = trim((string)end($tokens));
            $plainLast = $this->removeAccents($last);

            if (preg_match('/^\d+$/', $last)) {
                $qty = max(1, (int)$last);
                array_pop($tokens);
                break;
            }
            // Ex.: "1 275", "（4） 140.65", "25 ml 1 59.93".
            if (preg_match('/^(.*?)\s*[（(]?\s*(\d+)\s*[）)]?\s+([\d.,]+)\s*$/u', $last, $m)) {
                $prefix = trim($m[1]);
                $qty = max(1, (int)$m[2]);
                $value = trim($m[3]);
                array_pop($tokens);
                if ($prefix !== '' && !preg_match('/^[\d\s.,()（）]+$/u', $prefix)) {
                    $tokens[] = $prefix;
                }
                break;
            }
            if (preg_match('/\bTotal\b/i', $plainLast) || preg_match('/\d{2}-\d{2}-\d{4}/', $last) || preg_match('/^UP[A-Z0-9]+\b/i', $last)) {
                array_pop($tokens);
                continue;
            }
            break;
        }

        $productStart = 0;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($this->tokenLooksLikeProductStart($tokens[$i])) {
                $productStart = $i;
                break;
            }
        }
        if ($productStart === 0 && count($tokens) > 2 && $this->tokenLooksLikeSku($tokens[0]) && $this->tokenLooksLikeSku($tokens[1])) {
            $productStart = 2;
        } elseif ($productStart === 0 && count($tokens) > 1 && $this->tokenLooksLikeSku($tokens[0])) {
            $productStart = 1;
        }

        $skuTokens = array_slice($tokens, 0, $productStart);
        $productTokens = array_slice($tokens, $productStart);
        $productTokens = array_values(array_filter($productTokens, function ($t) {
            return !$this->looksLikeShopeeNoise($t)
                && !preg_match('/\d{2}-\d{2}-\d{4}/', $t)
                && !preg_match('/\bTotal\b/i', $this->removeAccents($t))
                && !preg_match('/^UP[A-Z0-9]+\b/i', $t);
        }));

        $sku = trim(implode(' ', $skuTokens));
        $name = $this->cleanProduct(implode(' ', $productTokens));
        $name = preg_replace('/\s+-\s+/', ' - ', $name);
        if ($name === '' || !$this->isValidProductName($name)) { return null; }
        $out = ['sku' => $sku, 'product_name' => $name, 'quantity' => $qty];
        if ($value !== '') { $out['value'] = $value; }
        return $out;
    }

    private function tokenLooksLikeSku($token)
    {
        $token = trim((string)$token);
        if ($token === '') { return false; }
        if (preg_match('/^\d{1,14}$/', $token)) { return true; }
        if (preg_match('/^[A-Z0-9._\-]{2,24}$/', $token) && !preg_match('/[a-záéíóúâêîôûãõç]/u', $token)) { return true; }
        return false;
    }

    private function tokenLooksLikeProductStart($token)
    {
        $token = trim((string)$token);
        if ($token === '') { return false; }
        if (preg_match('/[a-záéíóúâêîôûãõç]/u', $token)) { return true; }
        $common = '/^(Perfume|Body|Kit|Camiseta|Camisa|Vestido|Cal[çc]a|Tênis|Tenis|Bolsa|Rel[óo]gio|Creme|Shampoo|Condicionador|Sabonete|Garrafa|Copo|Caneca|Livro|Brinquedo|Royal|Armaf|Lattafa|Maison|Orientica|Khamrah|Yara)$/iu';
        return (bool)preg_match($common, $token);
    }

    private function normalizePdfText($text)
    {
        $text = (string)$text;
        // Alguns PDFs do Mercado Livre extraem a letra f como caractere privado U+E016.
        // Isso quebrava palavras como Perfume/Lattafa e até o cabeçalho Identificação.
        $text = str_replace(["\u{E016}", "\u{FB01}"], 'f', $text);
        $text = str_replace(["\xEF\xBF\xBE", "\u{FFFE}", "\u{FFFD}"], '-', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return $text;
    }

    private function removeAccents($text)
    {
        $text = (string)$text;
        $map = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
            'ç'=>'c','Ç'=>'C','º'=>'o','ª'=>'a'
        ];
        return strtr($text, $map);
    }

    private function parseShopeeLooseText($text, $trackingPattern, &$labels)
    {
        $orders = [];
        $lines = preg_split('/\n/', $text);
        $currentTracking = '';
        $currentOrder = '';
        $currentRecipient = '';
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') { continue; }

            if (preg_match($trackingPattern, $trim, $m)) {
                $currentTracking = strtoupper($m[0]);
                $labels[$currentTracking] = true;
            }
            if (preg_match('/(?:Pedido|Order\s*ID|N[ºo]\.??\s*Pedido)\s*[:#]?\s*([A-Z0-9\-]{6,})/iu', $trim, $m)) {
                $currentOrder = $m[1];
            }
            if (preg_match('/^DESTINAT[ÁA]RIO$/iu', $trim)) {
                continue;
            }

            if (preg_match('/^(.{6,}?)\s+(?:Quantidade|Qtd|Qty)\s*[:\-]?\s*(\d+)\b/iu', $trim, $m)) {
                $name = $this->cleanProduct($m[1]);
                if ($this->isValidProductName($name)) {
                    $orders[] = $this->makeOrder($currentTracking, '', $currentOrder, '', $currentRecipient, $name, '', (int)$m[2]);
                }
                continue;
            }
            if (preg_match('/^(.{6,}?)\s+x\s*(\d+)\b/iu', $trim, $m)) {
                $name = $this->cleanProduct($m[1]);
                if ($this->isValidProductName($name)) {
                    $orders[] = $this->makeOrder($currentTracking, '', $currentOrder, '', $currentRecipient, $name, '', (int)$m[2]);
                }
                continue;
            }
            if (preg_match('/(?:Produto|Item|Descri[çc][ãa]o)\s*[:\-]\s*(.{4,})/iu', $trim, $m)) {
                $name = $this->cleanProduct($m[1]);
                $qty = 1;
                if (preg_match('/(?:Quantidade|Qtd|Qty|x)\s*[:\-]?\s*(\d+)/iu', $trim, $qm)) {
                    $qty = (int)$qm[1];
                }
                if ($this->isValidProductName($name)) {
                    $orders[] = $this->makeOrder($currentTracking, '', $currentOrder, '', $currentRecipient, $name, '', $qty);
                }
                continue;
            }
        }
        return $orders;
    }

    private function firstMatch($pattern, $text)
    {
        if (preg_match($pattern, (string)$text, $m)) {
            return $m[0];
        }
        return '';
    }

    private function cleanPersonName($name)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string)$name));
        $name = preg_replace('/\s+(DECLARA[ÇC][ÃA]O\s+DE\s+CONTE[ÚU]DO|ENDERE[ÇC]O|CEP|CPF|CNPJ|MUNIC[ÍI]PIO).*$/iu', '', $name);
        $name = preg_replace('/\s+\([^)]*\)$/', '', $name);
        return trim($name);
    }

    private function looksLikeShopeeNoise($line)
    {
        return (bool)preg_match('/^(Totais|Peso Total|DECLARA[ÇC][ÃA]O|OBSERVA[ÇC][ÃA]O|Assinatura|Constitui crime|https?:\/\/|Declaro\b|responsabilidade\b|termos\b|iniciem\b|não\s+realizo\b)/iu', trim((string)$line));
    }

    private function parseJadlogDanfe($text, $rawText = '')
    {
        $text = $this->normalizePdfText((string)$text);
        $rawText = $this->normalizePdfText((string)$rawText);
        $both = $text . "\n" . $rawText;
        $flat = $this->lower($this->removeAccents($both));
        if (strpos($flat, 'jadlog') === false && strpos($flat, 'danfe simplificada') === false && strpos($flat, 'notas cliente') === false) {
            return ['labels' => [], 'orders' => [], 'warnings' => []];
        }

        $labels = [];
        $squash = preg_replace('/\s+/', '', $both);
        preg_match_all('/\b(\d{12,14}\$\d{6,}\d*)\b/u', $squash, $fullMatches);
        foreach ($fullMatches[1] as $code) { $labels[$code] = true; }
        preg_match_all('/\b(\d{12,14})\b/u', $both, $numMatches);
        foreach ($numMatches[1] as $code) {
            // Evita chave de acesso/NF e pega só códigos de transporte plausíveis.
            if (strlen($code) >= 12 && strlen($code) <= 14) { $labels[$code] = true; }
        }
        $tracking = '';
        if (!empty($fullMatches[1])) { $tracking = $fullMatches[1][0]; }
        elseif (!empty($numMatches[1])) { $tracking = $numMatches[1][0]; }

        $meta = [
            'tracking_code' => $tracking,
            'shipment_id' => '',
            'sale_id' => '',
            'pack_id' => '',
            'recipient' => '',
            'recipient_address' => '',
            'recipient_city' => '',
            'recipient_cep' => '',
            'sender_name' => '',
            'sender_address' => '',
            'sender_city' => '',
            'sender_cep' => '',
            'service' => 'JADLOG',
            'nf' => '',
            'weight' => '',
            'dce_key' => '',
        ];

        if (preg_match('/Package\s+Weight\s*([0-9.,]+\s*KG)/iu', $both, $m)) { $meta['weight'] = trim($m[1]); }
        if (preg_match('/N[úu]mero:\s*(\d+)/iu', $both, $m)) { $meta['nf'] = $m[1]; }
        if (preg_match('/\b(\d{44})\b/u', preg_replace('/\s+/', '', $both), $m)) { $meta['dce_key'] = $m[1]; }

        $labelMeta = $this->extractJadlogLabelMeta($text, $rawText);
        $meta = $this->mergeMeta($meta, $labelMeta);

        if (preg_match('/DESTINAT[ÁA]RIO:\s*([^,\n]+)(?:,\s*([^\n]+))?/iu', $both, $m)) {
            $meta['recipient'] = $this->chooseBetterMetaValue($meta['recipient'], $this->cleanPersonName($m[1]));
            if (!empty($m[2])) { $meta['recipient_city'] = $this->chooseBetterMetaValue($meta['recipient_city'], trim($m[2])); }
        } elseif (preg_match('/RECEBEDOR:\s*([^\n]+)/iu', $both, $m)) {
            $meta['recipient'] = $this->chooseBetterMetaValue($meta['recipient'], $this->cleanPersonName($m[1]));
        }

        $items = $this->extractJadlogDanfeItems($text, $rawText);
        $orders = [];
        foreach ($items as $item) {
            $order = $this->makeOrder($meta['tracking_code'], $meta['shipment_id'], $meta['sale_id'], $meta['pack_id'], $meta['recipient'], $item['product_name'], $item['sku'], $item['quantity']);
            foreach (['recipient_address','recipient_city','recipient_cep','sender_name','sender_address','sender_city','sender_cep','service','nf','weight','dce_key'] as $field) {
                if (!empty($meta[$field])) { $order[$field] = $meta[$field]; }
            }
            if (!empty($item['variation'])) { $order['variation'] = $item['variation']; }
            if (!empty($item['value'])) { $order['item_value'] = $item['value']; }
            $orders[] = $order;
        }

        if (empty($orders) && !empty($labels)) {
            $order = $this->makeOrder($tracking, '', '', '', $meta['recipient'], 'Produto não identificado no DANFE/Jadlog', '', 1);
            foreach (['recipient_address','recipient_city','recipient_cep','sender_name','sender_address','sender_city','sender_cep','service','nf','weight','dce_key'] as $field) {
                if (!empty($meta[$field])) { $order[$field] = $meta[$field]; }
            }
            $orders[] = $order;
        }

        $usedLabels = [];
        foreach ($orders as $order) {
            $code = trim((string)($order['tracking_code'] ?? ''));
            if ($code !== '') { $usedLabels[$code] = true; }
        }
        if (!empty($usedLabels)) { $labels = $usedLabels; }

        return [
            'labels' => array_keys($labels),
            'orders' => $orders,
            'warnings' => empty($items) ? ['DANFE/Jadlog lido, mas o produto não ficou claro na tabela.'] : [],
        ];
    }

    private function extractJadlogLabelMeta($text, $rawText = '')
    {
        $source = trim((string)$rawText) !== '' ? $rawText : $text;
        $pages = preg_split('/\f/', $this->normalizePdfText((string)$source));
        $page = '';
        foreach ($pages as $p) {
            if (stripos($p, 'JADLOG') !== false || stripos($p, 'Package Weight') !== false || stripos($p, 'Track') !== false) {
                $page = $p;
                break;
            }
        }
        $data = [];
        if ($page === '') { return $data; }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\n/', $page)), function ($v) { return $v !== ''; }));
        if (empty($lines)) { return $data; }

        for ($i = 0; $i < count($lines); $i++) {
            if (stripos($lines[$i], 'JADLOG') !== false) {
                $name = trim($lines[$i + 1] ?? '');
                if ($name !== '' && !preg_match('/Package|Weight|RECEBEDOR/i', $name)) {
                    $data['recipient'] = $this->cleanPersonName($name);
                    $addr = [];
                    for ($j = $i + 2; $j < count($lines); $j++) {
                        $v = trim($lines[$j]);
                        if (preg_match('/^RECEBEDOR:?$/iu', $v) || preg_match('/^Package$/iu', $v)) { break; }
                        $addr[] = $v;
                    }
                    if (!empty($addr)) {
                        $address = $this->cleanAddress(implode(' ', $addr));
                        $data['recipient_address'] = $address;
                        if (preg_match('/\b(\d{8}|\d{5}-\d{3})\b/u', $address, $cm)) { $data['recipient_cep'] = $this->formatCep($cm[1]); }
                    }
                }
                break;
            }
        }

        for ($i = 0; $i < count($lines); $i++) {
            if (!preg_match('/^LOJA$/iu', $lines[$i])) { continue; }
            $nameParts = [];
            $addrParts = [];
            for ($j = $i + 1; $j < min(count($lines), $i + 16); $j++) {
                $v = trim($lines[$j]);
                if ($v === '' || preg_match('/^\d{10,}$/', $v) || preg_match('/^Track$/iu', $v)) { break; }
                if (count($nameParts) < 2 && !preg_match('/^(Rua|Av|Avenida|Alameda|Travessa|Estrada|Rodovia|\d+|-)\b/iu', $v)) {
                    $nameParts[] = $v;
                    continue;
                }
                $addrParts[] = $v;
            }
            if (!empty($nameParts)) { $data['sender_name'] = $this->cleanPersonName('LOJA ' . implode(' ', $nameParts)); }
            if (!empty($addrParts)) {
                $address = $this->cleanAddress(implode(' ', $addrParts));
                $address = str_replace(['S ão Paulo', 'S ão'], ['São Paulo', 'São'], $address);
                $address = preg_replace('/Scha\s+hin/iu', 'Schahin', $address);
                $data['sender_address'] = $address;
                if (preg_match('/\b(\d{8}|\d{5}-\d{3})\b/u', $address, $cm)) { $data['sender_cep'] = $this->formatCep($cm[1]); }
            }
            break;
        }
        return $data;
    }

    private function extractJadlogDanfeItems($text, $rawText = '')
    {
        $source = trim((string)$rawText) !== '' ? $rawText : $text;
        $source = $this->normalizePdfText($source);
        $pages = preg_split('/\f/', $source);
        $items = [];
        foreach ($pages as $page) {
            $flat = $this->lower($this->removeAccents($page));
            if (strpos($flat, 'danfe simplificada') === false && strpos($flat, 'notas cliente') === false && strpos($flat, 'sku') === false) { continue; }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\n/', $page)), function ($v) { return $v !== ''; }));
            if (empty($lines)) { continue; }

            $start = -1;
            for ($i = 0; $i < count($lines); $i++) {
                $l = $this->lower($this->removeAccents($lines[$i]));
                if (strpos($l, 'qtd') !== false && strpos($l, 'total') !== false) {
                    $start = $i + 1;
                    break;
                }
                if (strpos($l, 'sku') !== false && strpos($l, 'descricao') !== false) {
                    $start = $i + 1;
                }
            }
            if ($start < 0) { continue; }

            $tokens = [];
            for ($i = $start; $i < count($lines); $i++) {
                $l = trim($lines[$i]);
                $plain = $this->lower($this->removeAccents($l));
                if (preg_match('/^(total|conf\.|lei\s+12|tributa)/iu', $plain)) { break; }
                if (preg_match('/^(sku|descri|variac|qtd|total)$/iu', $plain)) { continue; }
                $tokens[] = $l;
            }
            if (empty($tokens)) { continue; }

            $starts = [];
            for ($i = 0; $i < count($tokens); $i++) {
                if ($this->looksLikeDanfeSkuStart($tokens[$i])) { $starts[] = $i; }
            }
            if (empty($starts)) { $starts = [0]; }
            $starts[] = count($tokens);

            for ($s = 0; $s < count($starts) - 1; $s++) {
                $chunk = array_slice($tokens, $starts[$s], $starts[$s + 1] - $starts[$s]);
                $item = $this->parseJadlogDanfeItemTokens($chunk);
                if ($item && $this->isValidProductName($item['product_name'])) { $items[] = $item; }
            }
        }
        return $items;
    }

    private function looksLikeDanfeSkuStart($token)
    {
        $t = trim((string)$token);
        if ($t === '') { return false; }
        if (preg_match('/^(MLB|SKU|COD|C[ÓO]D)[A-Z0-9._\-]*\d*/iu', $t)) { return true; }
        if (preg_match('/^[A-Z]{2,}[0-9]{3,}[A-Z0-9._\-]*$/u', $t)) { return true; }
        return false;
    }

    private function parseJadlogDanfeItemTokens($tokens)
    {
        $tokens = array_values(array_filter(array_map(function ($v) {
            return trim($this->normalizePdfText((string)$v));
        }, $tokens), function ($v) { return $v !== ''; }));
        if (empty($tokens)) { return null; }

        $qty = 1;
        $value = '';
        $variation = '';
        while (!empty($tokens)) {
            $last = trim((string)end($tokens));
            if (preg_match('/^(.*?)\s+(\d+)\s+([\d.,]+)$/u', $last, $m)) {
                $variation = trim($m[1]);
                $qty = max(1, (int)$m[2]);
                $value = trim($m[3]);
                array_pop($tokens);
                break;
            }
            if (preg_match('/^[\d.,]+$/', $last) && count($tokens) >= 2 && preg_match('/^\d+$/', $tokens[count($tokens) - 2])) {
                $value = $last;
                array_pop($tokens);
                $qty = max(1, (int)array_pop($tokens));
                break;
            }
            break;
        }

        $productStart = 0;
        for ($i = 0; $i < count($tokens); $i++) {
            if ($this->tokenLooksLikeProductStart($tokens[$i])) {
                $productStart = $i;
                break;
            }
        }
        if ($productStart === 0 && count($tokens) > 1 && $this->tokenLooksLikeSku($tokens[0])) {
            $productStart = 1;
            if (isset($tokens[1]) && preg_match('/^\d{1,4}$/', $tokens[1]) && isset($tokens[2]) && $this->tokenLooksLikeProductStart($tokens[2])) {
                $productStart = 2;
            }
        }

        $skuTokens = array_slice($tokens, 0, $productStart);
        $productTokens = array_slice($tokens, $productStart);
        $sku = trim(preg_replace('/\s+/', '', implode('', $skuTokens)));
        $name = $this->cleanProduct(implode(' ', $productTokens));
        if ($name === '' || !$this->isValidProductName($name)) { return null; }
        $out = ['sku' => $sku, 'product_name' => $name, 'quantity' => $qty];
        if ($value !== '') { $out['value'] = $value; }
        if ($variation !== '') { $out['variation'] = $variation; }
        return $out;
    }

    private function parseGeneric($text)
    {
        $labels = [];
        preg_match_all('/\b[A-Z]{2}\d{9}[A-Z]{2}\b|\bSPX[A-Z0-9]{8,}\b/i', $text, $matches);
        foreach ($matches[0] as $code) {
            $labels[strtoupper($code)] = true;
        }
        $orders = [];
        foreach (array_keys($labels) as $code) {
            $orders[] = $this->makeOrder($code, '', '', '', '', 'Produto não identificado no PDF', '', 1);
        }
        return [
            'labels' => array_keys($labels),
            'orders' => $orders,
            'warnings' => ['Plataforma não identificada. Fiz leitura genérica de rastreios e etiquetas.'],
        ];
    }

    private function makeOrder($tracking, $shipment, $sale, $pack, $recipient, $product, $sku, $qty)
    {
        return [
            'tracking_code' => $tracking,
            'shipment_id' => $shipment,
            'sale_id' => $sale,
            'pack_id' => $pack,
            'recipient' => $recipient,
            'product_name' => $this->cleanProduct($product),
            'sku' => trim($sku),
            'quantity' => max(1, (int)$qty),
        ];
    }

    private function aggregateItems($orders)
    {
        $map = [];
        foreach ($orders as $order) {
            $name = $this->cleanProduct($order['product_name'] ?? 'Produto não identificado');
            if ($name === '') { $name = 'Produto não identificado'; }
            $sku = trim($order['sku'] ?? '');
            $key = $this->lower($sku !== '' ? 'sku:' . $sku : 'name:' . $name);
            if (!isset($map[$key])) {
                $map[$key] = [
                    'product_name' => $name,
                    'sku' => $sku,
                    'quantity' => 0,
                    'orders_count' => 0,
                ];
            }
            $map[$key]['quantity'] += max(1, (int)($order['quantity'] ?? 1));
            $map[$key]['orders_count']++;
        }
        usort($map, function ($a, $b) {
            if ($a['quantity'] === $b['quantity']) {
                return strcasecmp($a['product_name'], $b['product_name']);
            }
            return $b['quantity'] <=> $a['quantity'];
        });
        return array_values($map);
    }

    private function countOrders($orders)
    {
        $keys = [];
        $i = 0;
        foreach ($orders as $order) {
            $key = $order['sale_id'] ?: ($order['pack_id'] ?: ($order['tracking_code'] ?: ($order['shipment_id'] ?: 'row-' . $i)));
            $keys[$key] = true;
            $i++;
        }
        return count($keys);
    }

    private function sumUnits($orders)
    {
        $sum = 0;
        foreach ($orders as $order) {
            $sum += max(1, (int)($order['quantity'] ?? 1));
        }
        return $sum;
    }

    private function cleanProduct($name)
    {
        $name = $this->normalizePdfText((string)$name);
        $name = preg_replace('/\s+/', ' ', trim((string)$name));
        $name = preg_replace('/\b(?:Quantidade|Qtd|Qty)\s*[:\-]?\s*\d+\b/iu', '', $name);
        $name = preg_replace('/\bSKU\s*:\s*\S+\b/iu', '', $name);
        $name = preg_replace('/\bVenda\s*:\s*\d+\b/iu', '', $name);
        $name = preg_replace('/\bPack\s*ID\s*:\s*\d+\b/iu', '', $name);
        $name = preg_replace('/\b\d{2}-\d{2}-\d{4}\b.*$/u', '', $name);
        $name = preg_replace('/\bTotal\s+\d+.*$/iu', '', $name);
        $name = preg_replace('/\bUP[A-Z0-9]+\b.*$/iu', '', $name);
        $name = preg_replace('/[（(]\s*\d+\s*[）)]\s*[\d.,]+\s*$/u', '', $name);
        $name = preg_replace('/\s+\d+\s+[\d.,]+\s*$/u', '', $name);
        $replacements = [
            '/\bPerfiume\b/iu' => 'Perfume',
            '/\bParfiume\b/iu' => 'Parfum',
            '/\bLattafia\b/iu' => 'Lattafa',
            '/\bArmafi\b/iu' => 'Armaf',
            '/\bAfinan\b/iu' => 'Afnan',
            '/\bAfiinan\b/iu' => 'Afnan',
            '/\bLollection\b/iu' => 'Collection',
            '/\bEau De Perfiume\b/iu' => 'Eau De Parfum',
            '/\bEdp\b/iu' => 'EDP',
            '/\bEdt\b/iu' => 'EDT',
            '/\bMl\b/u' => 'ml',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $name = preg_replace($pattern, $replacement, $name);
        }
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function looksLikeProduct($text)
    {
        return (bool)preg_match('/(ml|250|100|perfume|body|splash|mist|camisa|kit|unidade|feminino|masculino|cabo|case|capinha|garrafa|creme|shampoo|bolsa|tenis|tênis)/iu', $text);
    }

    private function isValidProductName($name)
    {
        if ($this->strlenUtf($name) < 4) { return false; }
        if (preg_match('/^(Pedido|Order|Rastreio|Destinat|Remetente|Endereco|Endereço|Telefone|CPF|CNPJ|CEP)\b/iu', $name)) { return false; }
        return true;
    }

    private function lower($value)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function striposUtf($haystack, $needle)
    {
        return function_exists('mb_stripos') ? mb_stripos($haystack, $needle, 0, 'UTF-8') : stripos($haystack, $needle);
    }

    private function substrUtf($value, $start, $length = null)
    {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($value, $start, null, 'UTF-8') : mb_substr($value, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }

    private function strlenUtf($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

}
