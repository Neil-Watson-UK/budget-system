<?php
// mapping_api.php - JSON API for reseller/vendor autocomplete
session_start();
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getDBConnection();
$q = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? '';

if (strlen($q) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

$qLike = '%' . $q . '%';

if ($type === 'vendor') {
    $stmt = $pdo->prepare("
        SELECT id, vendor_name 
        FROM vendors 
        WHERE vendor_name LIKE ? 
        ORDER BY vendor_name 
        LIMIT 20
    ");
    $stmt->execute([$qLike]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['results' => $rows]);
    exit;
}

if ($type === 'suggest_vendors') {
    // Suggest vendors for a reseller name (extract key words, search vendors)
    $reseller = trim($_GET['reseller'] ?? '');
    $words = preg_split('/[\s\-,\.&]+/', $reseller, -1, PREG_SPLIT_NO_EMPTY);
    $skip = ['ltd', 'limited', 'plc', 'uk', 'uklimited', 'the', 'and', 'or'];
    $terms = array_filter(array_map('strtolower', $words), fn($w) => strlen($w) >= 2 && !in_array($w, $skip));
    if (empty($terms)) {
        $stmt = $pdo->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name LIMIT 15");
    } else {
        $conds = array_fill(0, count($terms), 'LOWER(vendor_name) LIKE ?');
        $params = array_map(fn($t) => '%' . $t . '%', $terms);
        $sql = "SELECT id, vendor_name FROM vendors WHERE " . implode(' OR ', $conds) . " ORDER BY vendor_name LIMIT 15";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['results' => $rows]);
    exit;
}

if ($type === 'reseller') {
    $stmt = $pdo->prepare("
        SELECT s.reseller_name,
            MAX(m.vendor_id) as mapped_vendor_id,
            MAX(v.vendor_name) as mapped_vendor_name
        FROM sales_out_raw s
        LEFT JOIN sales_out_reseller_mapping m ON LOWER(TRIM(m.reseller_name_raw)) = LOWER(TRIM(s.reseller_name))
        LEFT JOIN vendors v ON m.vendor_id = v.id
        WHERE s.reseller_name != '' AND (
            s.reseller_name LIKE ? OR LOWER(s.reseller_name) LIKE ?
        )
        GROUP BY s.reseller_name
        ORDER BY s.reseller_name
        LIMIT 25
    ");
    $stmt->execute([$qLike, strtolower($qLike)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['results' => $rows]);
    exit;
}

if ($type === 'unmapped_reseller') {
    $stmt = $pdo->prepare("
        SELECT reseller_name, COUNT(*) as cnt, COALESCE(SUM(total_value), 0) as total
        FROM sales_out_raw
        WHERE reseller_name != '' AND matched_vendor_id IS NULL
        AND (reseller_name LIKE ? OR LOWER(reseller_name) LIKE ?)
        GROUP BY reseller_name
        ORDER BY total DESC
        LIMIT 25
    ");
    $stmt->execute([$qLike, strtolower($qLike)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['results' => $rows]);
    exit;
}

echo json_encode(['results' => []]);
