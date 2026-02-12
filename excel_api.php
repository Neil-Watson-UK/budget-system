<?php
// excel_api.php - FINAL PRODUCTION VERSION
ini_set('display_errors', 0); // Turn off for production
error_reporting(0);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
// Add these headers for better Excel compatibility
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Add date range filtering
$start_date = $_GET['start_date'] ?? date('Y-01-01'); // Current year start
$end_date = $_GET['end_date'] ?? date('Y-12-31'); // Current year end

// Database credentials
$host = '92.205.6.240';
$dbname = 'cmmbudget';
$username = 'budgetadmin';
$password = 'NotReevesP13453'; // CORRECT PASSWORD

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4;port=3306",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    
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