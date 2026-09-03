<?php
// product_search.php - JSON API for product autocomplete (by name or SKU)
session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$pdo = getDBConnection();
$like = '%' . $q . '%';

try {
    // Simple approach: search both tables separately and merge
    $out = [];
    $seen = [];
    
    // Search product master first (preferred)
    $stmt1 = $pdo->prepare("
        SELECT sku, product_name
        FROM sales_out_products
        WHERE (product_name LIKE ? OR sku LIKE ?)
        AND sku IS NOT NULL
        AND sku != ''
        ORDER BY product_name
        LIMIT 25
    ");
    $stmt1->execute([$like, $like]);
    while ($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
        $sku = trim($row['sku'] ?? '');
        if ($sku && !isset($seen[$sku])) {
            $seen[$sku] = true;
            $out[] = [
                'sku' => $sku,
                'label' => trim($row['product_name'] ?? '') ?: $sku,
            ];
        }
    }
    
    // Then search sales data for products not in master
    $stmt2 = $pdo->prepare("
        SELECT DISTINCT s.sku, s.product_name
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE p.sku IS NULL
        AND (s.product_name LIKE ? OR s.sku LIKE ?)
        AND s.sku IS NOT NULL
        AND s.sku != ''
        ORDER BY s.product_name
        LIMIT 25
    ");
    $stmt2->execute([$like, $like]);
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $sku = trim($row['sku'] ?? '');
        if ($sku && !isset($seen[$sku]) && count($out) < 25) {
            $seen[$sku] = true;
            $out[] = [
                'sku' => $sku,
                'label' => trim($row['product_name'] ?? '') ?: $sku,
            ];
        }
    }
    
    echo json_encode($out);
} catch (PDOException $e) {
    // Log error but don't expose to client
    error_log('Product search error: ' . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Product search error: ' . $e->getMessage());
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
