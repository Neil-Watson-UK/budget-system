<?php

/**

 * export_product_thumbs_json.php — Same payload as product_thumbs_public.php (SKU + byProductName), auth required.

 */

session_start();

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/functions.php';

require_once __DIR__ . '/thumb_manifest.php';



if (!isset($_SESSION['user_id'])) {

    header('HTTP/1.1 401 Unauthorized');

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(['error' => 'login required', 'login' => '../login.php']);

    exit;

}



header('Content-Type: application/json; charset=utf-8');

header('Cache-Control: no-store');



try {

    $pdo = getDBConnection();

    $m = sales_out_product_thumb_manifest($pdo);

} catch (Throwable $e) {

    header('HTTP/1.1 500 Internal Server Error');

    echo json_encode(['error' => $e->getMessage()]);

    exit;

}



echo json_encode(

    [

        'sku'             => (object) ($m['sku'] ?? []),

        'byProductName'   => (object) ($m['byProductName'] ?? []),

    ],

    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT

);

