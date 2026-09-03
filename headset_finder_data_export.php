<?php
declare(strict_types=1);
/**
 * Download headset finder data as CSV (Excel-friendly UTF-8 BOM), XLSX, or ZIP of two CSVs (dataset=all).
 * dataset=fullepos streams ../lenovocalc/products/fullepos.csv (full variant/asset export for review).
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/headset_finder_data_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('headset_finder_data_export.php'));
    exit;
}

$dataset = $_GET['dataset'] ?? 'products';
$formatIn = strtolower((string) ($_GET['format'] ?? 'csv'));
$format = in_array($formatIn, ['csv', 'xlsx', 'zip'], true) ? $formatIn : 'csv';

// Large EPOS catalog: stream file only (do not load JSON; CSV / XLSX workbook not supported).
if ($dataset === 'fullepos') {
    if ($format !== 'csv') {
        header('Content-Type: text/plain; charset=UTF-8', true, 400);
        echo 'The full fullepos catalog is only available as CSV (too large for a single Excel workbook). Use format=csv.';
        exit;
    }
    $path = hf_fullepos_csv_path();
    if (!is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'fullepos.csv was not found (expected at lenovocalc/products/fullepos.csv next to the budget app).';
        exit;
    }
    $stamp = date('Ymd_His');
    $size = @filesize($path);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="epos_fullepos_catalog_' . $stamp . '.csv"');
    header('Cache-Control: max-age=0');
    if ($size !== false) {
        header('Content-Length: ' . (string) $size);
    }
    $in = fopen($path, 'rb');
    if ($in === false) {
        http_response_code(500);
        echo 'Could not open catalog file.';
        exit;
    }
    $peek = fread($in, 3);
    $hasBom = ($peek === "\xEF\xBB\xBF");
    if ($size !== false) {
        header('Content-Length: ' . (string) ($hasBom ? $size : $size + 3));
    }
    if ($hasBom) {
        rewind($in);
        fpassthru($in);
    } else {
        rewind($in);
        echo "\xEF\xBB\xBF";
        fpassthru($in);
    }
    fclose($in);
    exit;
}

if (!in_array($dataset, ['products', 'thumbs', 'all'], true)) {
    $dataset = 'products';
}

try {
    $products = hf_load_products();
    $thumbs = hf_load_epos_thumbs();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error loading data: ' . htmlspecialchars($e->getMessage());
    exit;
}

$stamp = date('Ymd_His');
$xlsxOk = hf_phpspreadsheet_ready();

/** @param resource $out */
function hf_stream_products_csv($out, array $products): void
{
    $cols = hf_product_column_order($products);
    fputcsv($out, $cols);
    foreach ($products as $row) {
        $line = [];
        foreach ($cols as $c) {
            $v = $row[$c] ?? '';
            if ($v === null) {
                $line[] = '';
            } elseif (is_bool($v)) {
                $line[] = $v ? '1' : '0';
            } else {
                $line[] = (string) $v;
            }
        }
        fputcsv($out, $line);
    }
}

/** @param resource $out */
function hf_stream_thumbs_csv($out, array $thumbs): void
{
    fputcsv($out, ['Product Choice', 'EPOS image URL']);
    foreach ($thumbs as $k => $v) {
        fputcsv($out, [$k, $v]);
    }
}

// ZIP: both CSVs (no PhpSpreadsheet needed)
if ($dataset === 'all' && $format === 'zip' && class_exists(\ZipArchive::class)) {
    $zipPath = sys_get_temp_dir() . '/hf_' . $stamp . '.zip';
    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo 'Could not create ZIP';
        exit;
    }
    $ob1 = fopen('php://memory', 'r+');
    fprintf($ob1, "\xEF\xBB\xBF");
    hf_stream_products_csv($ob1, $products);
    rewind($ob1);
    $zip->addFromString('headset_finder_products.csv', stream_get_contents($ob1));
    fclose($ob1);

    $ob2 = fopen('php://memory', 'r+');
    fprintf($ob2, "\xEF\xBB\xBF");
    hf_stream_thumbs_csv($ob2, $thumbs);
    rewind($ob2);
    $zip->addFromString('headset_finder_epos_images.csv', stream_get_contents($ob2));
    fclose($ob2);

    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="headset_finder_all_' . $stamp . '.zip"');
    header('Cache-Control: max-age=0');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

if (!$xlsxOk || $format === 'csv') {
    if ($dataset === 'all') {
        header('Content-Type: text/plain; charset=UTF-8', true, 400);
        echo 'Combined export: use format=zip (two CSV files) or install PhpSpreadsheet and use format=xlsx.';
        exit;
    }
    $outName = 'headset_finder_' . $dataset . '_' . $stamp . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $outName . '"');
    header('Cache-Control: max-age=0');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    if ($dataset === 'thumbs') {
        hf_stream_thumbs_csv($out, $thumbs);
    } else {
        hf_stream_products_csv($out, $products);
    }
    fclose($out);
    exit;
}

// ---- XLSX ----
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Calibri');

if ($dataset === 'products') {
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Products');
    $cols = hf_product_column_order($products);
    foreach ($cols as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));
    $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
    $rnum = 2;
    foreach ($products as $row) {
        $ci = 1;
        foreach ($cols as $col) {
            $v = $row[$col] ?? '';
            if ($v === null) {
                $cell = '';
            } elseif (is_bool($v)) {
                $cell = $v ? true : false;
            } else {
                $cell = $v;
            }
            $sheet->setCellValueByColumnAndRow($ci, $rnum, $cell);
            $ci++;
        }
        $rnum++;
    }
} elseif ($dataset === 'thumbs') {
    $ts = $spreadsheet->getActiveSheet();
    $ts->setTitle('EPOS_images');
    $ts->setCellValue('A1', 'Product Choice');
    $ts->setCellValue('B1', 'EPOS image URL');
    $ts->getStyle('A1:B1')->getFont()->setBold(true);
    $r = 2;
    foreach ($thumbs as $k => $v) {
        $ts->setCellValue('A' . $r, $k);
        $ts->setCellValue('B' . $r, $v);
        $r++;
    }
} else {
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Products');
    $cols = hf_product_column_order($products);
    foreach ($cols as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($cols));
    $sheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
    $rnum = 2;
    foreach ($products as $row) {
        $ci = 1;
        foreach ($cols as $col) {
            $v = $row[$col] ?? '';
            $cell = $v === null ? '' : (is_bool($v) ? ($v ? true : false) : $v);
            $sheet->setCellValueByColumnAndRow($ci, $rnum, $cell);
            $ci++;
        }
        $rnum++;
    }
    $ts = $spreadsheet->createSheet();
    $ts->setTitle('EPOS_images');
    $ts->setCellValue('A1', 'Product Choice');
    $ts->setCellValue('B1', 'EPOS image URL');
    $ts->getStyle('A1:B1')->getFont()->setBold(true);
    $r = 2;
    foreach ($thumbs as $k => $v) {
        $ts->setCellValue('A' . $r, $k);
        $ts->setCellValue('B' . $r, $v);
        $r++;
    }
    $spreadsheet->setActiveSheetIndex(0);
}

$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
$fn = 'headset_finder_' . $dataset . '_' . $stamp . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fn . '"');
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
