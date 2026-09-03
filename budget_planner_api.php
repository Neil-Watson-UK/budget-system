<?php
/**
 * JSON: budget planner snapshot for a region/year (allocation caps vs spend by item type).
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$region = $_GET['region'] ?? '';
$year = $_GET['year'] ?? date('Y');

if ($region === '' || !isset($GLOBALS['REGIONAL_SETTINGS'][$region])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid region']);
    exit;
}

try {
    $pdo = getDBConnection();
    $snap = getBudgetPlannerSnapshot($pdo, $region, $year);
    echo json_encode($snap);
} catch (Throwable $e) {
    error_log('budget_planner_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
