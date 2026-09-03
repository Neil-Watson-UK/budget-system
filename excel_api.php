<?php
// excel_api.php - FINAL PRODUCTION VERSION
ini_set('display_errors', 0); // Turn off for production
error_reporting(0);

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config.php';

if (!defined('EXCEL_API_KEY') || EXCEL_API_KEY === '') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'excel_api.php is disabled until EXCEL_API_KEY is set in config.php']);
    exit;
}
if (($_GET['key'] ?? '') !== EXCEL_API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid API key']);
    exit;
}

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-12-31');

try {
    $pdo = getDBConnection();
    
    // Get filtered budget_items data
    $query = "SELECT * FROM budget_items 
              WHERE entry_creation_date BETWEEN :start_date AND :end_date 
              ORDER BY entry_creation_date DESC";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date);
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return JSON for Power Query
    echo json_encode([
        'success' => true,
        'data' => $data,
        'count' => count($data),
        'timestamp' => date('Y-m-d H:i:s'),
        'filters' => [
            'start_date' => $start_date,
            'end_date' => $end_date
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
}
?>