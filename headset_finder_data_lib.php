<?php
declare(strict_types=1);
/**
 * Shared helpers for headset finder JSON under ../lenovocalc/ (sibling of this budget app; products matrix + EPOS thumb URLs).
 */

function hf_lenovocalc_dir(): string
{
    $d = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lenovocalc';
    if (!is_dir($d)) {
        throw new RuntimeException('lenovocalc directory not found at ' . $d . ' (expected sibling of budget app directory)');
    }
    return $d;
}

function hf_products_json_path(): string
{
    return hf_lenovocalc_dir() . DIRECTORY_SEPARATOR . 'headset-finder-products.json';
}

function hf_epos_thumbs_json_path(): string
{
    return hf_lenovocalc_dir() . DIRECTORY_SEPARATOR . 'epos-finder-product-thumbs.json';
}

/** EPOS B2B variant + asset export (large; many rows per SKU). */
function hf_fullepos_csv_path(): string
{
    return hf_lenovocalc_dir() . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'fullepos.csv';
}

/** @return list<array<string,mixed>> */
function hf_load_products(): array
{
    $path = hf_products_json_path();
    if (!is_readable($path)) {
        throw new RuntimeException('Cannot read: ' . $path);
    }
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Products JSON must be a JSON array');
    }
    foreach ($data as $i => $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Products row ' . $i . ' is not an object');
        }
    }
    /** @var list<array<string,mixed>> $data */
    return $data;
}

/** @param list<array<string,mixed>> $rows */
function hf_save_products(array $rows): void
{
    $path = hf_products_json_path();
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed');
    }
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Temporary write failed');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace products file (check permissions)');
    }
}

/** @return array<string,string> */
function hf_load_epos_thumbs(): array
{
    $path = hf_epos_thumbs_json_path();
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('EPOS thumbs JSON must be an object');
    }
    $out = [];
    foreach ($data as $k => $v) {
        if (!is_string($k) || !is_string($v)) {
            continue;
        }
        $out[$k] = $v;
    }
    return $out;
}

/** @param array<string,string> $map */
function hf_save_epos_thumbs(array $map): void
{
    $path = hf_epos_thumbs_json_path();
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    ksort($map, SORT_NATURAL);
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed');
    }
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Temporary write failed');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace EPOS thumbs file (check permissions)');
    }
}

/** Preferred column order; extra keys from data are appended sorted. */
function hf_product_column_order(array $rows): array
{
    $preferred = [
        'Device Type',
        'Technology',
        'Wearing style/form factor',
        'Active Noise Cancellation',
        'Connectivity Input',
        'Connectivity Options',
        'Works With',
        'Product Choice',
        'Category',
        'Local LPN',
        'Vendor Competitor 1',
        'Product Competitor 1',
        'Vendor Competitor 2',
        'Product Competitor 2',
        'Yealink',
        'Recommended',
        'recommendedFlag',
    ];
    $seen = [];
    foreach ($rows as $row) {
        foreach (array_keys($row) as $k) {
            $seen[$k] = true;
        }
    }
    $out = [];
    foreach ($preferred as $k) {
        if (isset($seen[$k])) {
            $out[] = $k;
            unset($seen[$k]);
        }
    }
    $rest = array_keys($seen);
    sort($rest, SORT_STRING);
    return array_merge($out, $rest);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function hf_validate_and_cast_products(array $rows): array
{
    $clean = [];
    foreach ($rows as $i => $row) {
        $pc = trim((string) ($row['Product Choice'] ?? ''));
        if ($pc === '') {
            throw new RuntimeException('Row ' . ($i + 1) . ': Product Choice is required.');
        }
        $item = $row;
        if (array_key_exists('Local LPN', $item)) {
            $lpn = $item['Local LPN'];
            if ($lpn === '' || $lpn === null) {
                $item['Local LPN'] = null;
            } else {
                $item['Local LPN'] = (int) $lpn;
            }
        }
        if (array_key_exists('recommendedFlag', $item)) {
            $item['recommendedFlag'] = hf_parse_boolish($item['recommendedFlag']);
        }
        if (array_key_exists('Recommended', $item)) {
            $r = $item['Recommended'];
            if ($r === '' || $r === null) {
                $item['Recommended'] = null;
            }
        }
        $clean[] = $item;
    }
    return $clean;
}

/** @param mixed $v */
function hf_parse_boolish($v): bool
{
    if ($v === null || $v === '') {
        return false;
    }
    if (is_bool($v)) {
        return $v;
    }
    $s = strtolower(trim((string) $v));
    if (in_array($s, ['0', 'false', 'no', 'n', ''], true)) {
        return false;
    }
    return in_array($s, ['1', 'true', 'yes', 'y'], true);
}

/**
 * @param list<array<string,mixed>> $postRows from $_POST['rows']
 * @param list<string> $columns
 * @return list<array<string,mixed>>
 */
function hf_parse_products_from_post(array $postRows, array $columns): array
{
    $out = [];
    foreach ($postRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $empty = true;
        foreach ($columns as $c) {
            if (trim((string) ($row[$c] ?? '')) !== '') {
                $empty = false;
                break;
            }
        }
        if ($empty) {
            continue;
        }
        $item = [];
        foreach ($columns as $col) {
            $v = $row[$col] ?? '';
            if (is_array($v)) {
                $v = '';
            }
            $v = is_string($v) ? trim($v) : $v;
            if ($col === 'Local LPN') {
                $item[$col] = ($v === '' || $v === null) ? null : (int) $v;
            } elseif ($col === 'recommendedFlag') {
                $item[$col] = hf_parse_boolish($v);
            } elseif ($col === 'Recommended') {
                $item[$col] = ($v === '' || $v === null) ? null : (string) $v;
            } else {
                $item[$col] = $v === null ? '' : (string) $v;
            }
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * Import products from CSV file path (UTF-8, optional BOM).
 *
 * @return list<array<string,mixed>>
 */
function hf_import_products_csv(string $tmpPath): array
{
    $fh = fopen($tmpPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('Could not read uploaded file');
    }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException('CSV is empty');
    }
    $header = array_map(static fn ($h) => trim((string) $h), $header);
    $rows = [];
    while (($data = fgetcsv($fh)) !== false) {
        if (count($data) === 1 && trim((string) $data[0]) === '') {
            continue;
        }
        $row = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string) ($data[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($fh);
    if (count($rows) === 0) {
        throw new RuntimeException('No data rows in CSV');
    }
    $columns = hf_product_column_order($rows);
    $normalized = [];
    foreach ($rows as $r) {
        $line = [];
        foreach ($columns as $c) {
            $line[$c] = $r[$c] ?? '';
        }
        $parsed = hf_parse_products_from_post([$line], $columns);
        if (count($parsed) === 1) {
            $normalized[] = $parsed[0];
        }
    }
    return hf_validate_and_cast_products($normalized);
}

/** Try to load Composer autoload for PhpSpreadsheet (optional). */
function hf_phpspreadsheet_ready(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/salesout/vendor/autoload.php'] as $p) {
        if (is_readable($p)) {
            require_once $p;
            $ok = class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)
                && class_exists(\ZipStream\ZipStream::class);
            return $ok;
        }
    }
    $ok = false;
    return false;
}
