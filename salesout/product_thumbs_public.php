<?php
/**
 * product_thumbs_public.php — Icecat thumbnails from sales_out_products (SKU + normalized product_name).
 *
 * JSON: {"sku":{…},"byProductName":{…}}
 * Loaded by /lenovocalc/index.html via fetch (e.g. ../budgets/salesout/product_thumbs_public.php).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/thumb_manifest.php';

header('Content-Type: application/json; charset=utf-8');

if (!defined('SALESOUT_PRODUCT_THUMBS_PUBLIC_ENABLED') || !SALESOUT_PRODUCT_THUMBS_PUBLIC_ENABLED) {
    echo json_encode(['sku' => new stdClass(), 'byProductName' => new stdClass()]);
    exit;
}

try {
    $pdo = getDBConnection();
} catch (Throwable $e) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'database unavailable']);
    exit;
}

try {
    $m = sales_out_product_thumb_manifest($pdo);
} catch (Throwable $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'query failed']);
    exit;
}

header('Cache-Control: public, max-age=1800');

echo json_encode(
    [
        'sku'           => (object) ($m['sku'] ?? []),
        'byProductName' => (object) ($m['byProductName'] ?? []),
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
