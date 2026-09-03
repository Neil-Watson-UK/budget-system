<?php
/**
 * JSON: recent budget items for the same partner (vendor / external vendor names), same region.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'items' => []]);
    exit;
}

$region = $_GET['region'] ?? '';
$vendor = $_GET['vendor'] ?? '';
$external = $_GET['external_vendor'] ?? '';
$excludeId = (int) ($_GET['exclude_id'] ?? 0);

if ($region === '' || !isset($GLOBALS['REGIONAL_SETTINGS'][$region])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid region', 'items' => []]);
    exit;
}

try {
    $pdo = getDBConnection();
    $items = getPartnerSpendHistoryForRegion($pdo, $region, $vendor, $external, $excludeId, 50);
    echo json_encode(['items' => $items]);
} catch (Throwable $e) {
    error_log('partner_spend_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'items' => []]);
}
