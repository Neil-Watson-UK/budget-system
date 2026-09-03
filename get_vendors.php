<?php
// get_vendors.php
session_start();
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

try {
    $pdo = getDBConnection();

    $query = $_GET['q'] ?? '';
    $exact = isset($_GET['exact']) && $_GET['exact'] == 'true';

    if (empty($query)) {
        echo json_encode([]);
        exit;
    }

    if ($exact) {
        // For exact matching (when loading existing item)
        $stmt = $pdo->prepare("
            SELECT vendor_name, salesforce_id, account_type, region 
            FROM vendors 
            WHERE vendor_name = ?
            LIMIT 1
        ");
        $stmt->execute([$query]);
    } else {
        // For autocomplete search
        $stmt = $pdo->prepare("
            SELECT vendor_name, salesforce_id, account_type, region 
            FROM vendors 
            WHERE vendor_name LIKE ? 
            ORDER BY vendor_name
            LIMIT 10
        ");
        $stmt->execute(["%$query%"]);
    }

    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($vendors);

} catch (PDOException $e) {
    error_log("get_vendors.php error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error. Please try again.', 'vendors' => []]);
} catch (Exception $e) {
    error_log("get_vendors.php error: " . $e->getMessage());
    echo json_encode(['error' => 'An error occurred. Please try again.', 'vendors' => []]);
}
?>