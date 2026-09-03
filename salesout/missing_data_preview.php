<?php
// missing_data_preview.php - JSON preview of records for distributor+month (before delete)
session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$distributor = trim($_GET['distributor'] ?? '');
$year = (int) ($_GET['year'] ?? 0);
$month = (int) ($_GET['month'] ?? 0);

if (!$distributor || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT report_date, reseller_name, sku, product_name, quantity, total_value
        FROM sales_out_raw
        WHERE distributor_name = ? AND YEAR(report_date) = ? AND MONTH(report_date) = ?
        ORDER BY report_date DESC, total_value DESC
        LIMIT 20
    ");
    $stmt->execute([$distributor, $year, $month]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt, COALESCE(SUM(total_value), 0) as total
        FROM sales_out_raw
        WHERE distributor_name = ? AND YEAR(report_date) = ? AND MONTH(report_date) = ?
    ");
    $countStmt->execute([$distributor, $year, $month]);
    $totals = $countStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'distributor' => $distributor,
        'year' => $year,
        'month' => $month,
        'label' => date('M Y', strtotime("$year-$month-01")),
        'total_rows' => (int) ($totals['cnt'] ?? 0),
        'total_value' => (float) ($totals['total'] ?? 0),
        'preview' => $rows,
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
