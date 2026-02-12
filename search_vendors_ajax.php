<?php
// search_vendors_ajax.php - AJAX endpoint for vendor search
session_start();
require_once 'config.php';

$query = $_GET['q'] ?? '';

if (strlen($query) >= 2) {
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                vendor_name,
                salesforce_id,
                account_type,
                region,
                Owner_Full_Name__c as owner,
                Account_Status__c as status
            FROM vendors 
            WHERE vendor_name LIKE :query 
            OR salesforce_id LIKE :query
            ORDER BY 
                CASE 
                    WHEN vendor_name LIKE :exact_query THEN 1
                    WHEN vendor_name LIKE :start_query THEN 2
                    ELSE 3
                END,
                vendor_name
            LIMIT 15
        ");
        
        $exactQuery = $query;
        $startQuery = $query . '%';
        $anyQuery = '%' . $query . '%';
        
        $stmt->execute([
            ':query' => $anyQuery,
            ':exact_query' => $exactQuery,
            ':start_query' => $startQuery
        ]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode($results);
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode([]);
}
?>