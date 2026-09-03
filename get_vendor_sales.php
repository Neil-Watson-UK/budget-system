<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions.php';
ob_end_clean();

header('Content-Type: application/json; charset=UTF-8');

$vendorName = $_GET['vendor'] ?? '';
$salesforceId = $_GET['salesforce_id'] ?? '';
$vendorId = $_GET['vendor_id'] ?? '';

if (empty($vendorName)) {
    echo json_encode(['error' => 'Vendor name required']);
    exit;
}

$vendorInfo = null;
try {
$pdo = getDBConnection();

// sales_out_raw date column is report_date (not sale_date)
// Get vendor info from vendors table
$vendorInfo = [
    'salesforce_id' => $salesforceId,
    'account_type' => null,
    'amplify_level' => null,
    'account_status' => null,
    'target_percentage' => null,
    'current_year_sales' => 0,
    'three_year_total' => 0,
    'debug' => ['date_column' => 'report_date']
];

// Try to get vendor details from vendors table
if (!empty($vendorId)) {
    $stmt = $pdo->prepare("SELECT salesforce_id, account_type, AMPLIFY_Level__c, Account_Status__c FROM vendors WHERE id = ?");
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($vendor) {
        $vendorInfo['salesforce_id'] = $vendor['salesforce_id'] ?: $salesforceId;
        $vendorInfo['account_type'] = $vendor['account_type'];
        $vendorInfo['amplify_level'] = $vendor['AMPLIFY_Level__c'];
        $vendorInfo['account_status'] = $vendor['Account_Status__c'];
        $vendorInfo['debug']['found_by'] = 'vendor_id';
    }
} else if (!empty($vendorName)) {
    // First try to find vendor through reseller mapping table (most reliable)
    $stmt = $pdo->prepare("
        SELECT v.id, v.salesforce_id, v.account_type, v.AMPLIFY_Level__c, v.Account_Status__c
        FROM sales_out_reseller_mapping m
        JOIN vendors v ON m.vendor_id = v.id
        WHERE LOWER(TRIM(m.reseller_name_raw)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$vendorName]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($vendor) {
        $vendorId = $vendor['id'];
        $vendorInfo['salesforce_id'] = $vendor['salesforce_id'] ?: $salesforceId;
        $vendorInfo['account_type'] = $vendor['account_type'];
        $vendorInfo['amplify_level'] = $vendor['AMPLIFY_Level__c'];
        $vendorInfo['account_status'] = $vendor['Account_Status__c'];
        $vendorInfo['debug']['found_by'] = 'reseller_mapping';
    } else {
        // Try to find vendor by name directly
        $stmt = $pdo->prepare("SELECT salesforce_id, account_type, AMPLIFY_Level__c, Account_Status__c, id FROM vendors WHERE TRIM(LOWER(vendor_name)) = TRIM(LOWER(?))");
        $stmt->execute([$vendorName]);
        $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($vendor) {
            $vendorInfo['salesforce_id'] = $vendor['salesforce_id'] ?: $salesforceId;
            $vendorInfo['account_type'] = $vendor['account_type'];
            $vendorInfo['amplify_level'] = $vendor['AMPLIFY_Level__c'];
            $vendorInfo['account_status'] = $vendor['Account_Status__c'];
            $vendorId = $vendor['id'];
            $vendorInfo['debug']['found_by'] = 'vendor_name_direct';
        }
    }
}

// If still no vendorId, try to find through sales_out_raw by reseller name
if (empty($vendorId) && !empty($vendorName)) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT matched_vendor_id
            FROM sales_out_raw
            WHERE LOWER(TRIM(reseller_name)) = LOWER(TRIM(?))
            AND matched_vendor_id IS NOT NULL
            LIMIT 1
        ");
        $stmt->execute([$vendorName]);
        $matchedVendorId = $stmt->fetchColumn();
        
        if ($matchedVendorId) {
            $vendorId = $matchedVendorId;
            $vendorInfo['debug']['found_by'] = 'sales_out_raw_reseller_name';
            
            // Get vendor details
            $stmt = $pdo->prepare("SELECT salesforce_id, account_type, AMPLIFY_Level__c, Account_Status__c FROM vendors WHERE id = ?");
            $stmt->execute([$vendorId]);
            $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($vendor) {
                $vendorInfo['salesforce_id'] = $vendor['salesforce_id'] ?: $salesforceId;
                $vendorInfo['account_type'] = $vendor['account_type'];
                $vendorInfo['amplify_level'] = $vendor['AMPLIFY_Level__c'];
                $vendorInfo['account_status'] = $vendor['Account_Status__c'];
            }
        }
    } catch (Exception $e) {
        error_log("Error finding vendor through sales_out_raw: " . $e->getMessage());
        $vendorInfo['debug']['error'] = $e->getMessage();
    }
}

// Also try to find vendor by Salesforce ID if we have one but no vendorId yet
if (empty($vendorId) && !empty($salesforceId)) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM vendors WHERE salesforce_id = ? LIMIT 1");
        $stmt->execute([$salesforceId]);
        $sfVendorId = $stmt->fetchColumn();
        if ($sfVendorId) {
            $vendorId = $sfVendorId;
            $vendorInfo['debug']['found_by'] = 'salesforce_id';
            
            // Get vendor details
            $stmt = $pdo->prepare("SELECT salesforce_id, account_type, AMPLIFY_Level__c, Account_Status__c FROM vendors WHERE id = ?");
            $stmt->execute([$vendorId]);
            $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($vendor) {
                $vendorInfo['salesforce_id'] = $vendor['salesforce_id'] ?: $salesforceId;
                $vendorInfo['account_type'] = $vendor['account_type'];
                $vendorInfo['amplify_level'] = $vendor['AMPLIFY_Level__c'];
                $vendorInfo['account_status'] = $vendor['Account_Status__c'];
            }
        }
    } catch (Exception $e) {
        error_log("Error finding vendor by Salesforce ID: " . $e->getMessage());
    }
}

$vendorInfo['debug']['vendor_id'] = $vendorId;
$vendorInfo['debug']['vendor_name'] = $vendorName;

// Detect date column (sales_out_raw uses report_date; legacy may use sale_date)
$dateCol = 'report_date';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM sales_out_raw")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('sale_date', $cols) && !in_array('report_date', $cols)) {
        $dateCol = 'sale_date';
    }
} catch (Exception $e) {
    // keep report_date as default
}
$vendorInfo['debug']['date_column'] = $dateCol;

// Get sales data from salesout system
if (!empty($vendorId)) {
    try {
        // Get current year sales - try by matched_vendor_id first, then by reseller_name if no match
        $currentYear = date('Y');
        $sql = "SELECT COALESCE(SUM(total_value), 0) as total
            FROM sales_out_raw
            WHERE matched_vendor_id = ? 
            AND YEAR(`" . preg_replace('/[^a-z0-9_]/i', '', $dateCol) . "`) = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$vendorId, $currentYear]);
        $currentYearData = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentYearSales = 0;
        if ($currentYearData !== false && isset($currentYearData['total']) && $currentYearData['total'] !== null) {
            $currentYearSales = floatval($currentYearData['total']);
        }
        
        // If no sales found by vendor_id, try by exact reseller_name
        if ($currentYearSales == 0 && !empty($vendorName)) {
            $sql = "SELECT COALESCE(SUM(total_value), 0) as total
            FROM sales_out_raw
            WHERE LOWER(TRIM(reseller_name)) = LOWER(TRIM(?))
            AND YEAR(`" . preg_replace('/[^a-z0-9_]/i', '', $dateCol) . "`) = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$vendorName, $currentYear]);
            $currentYearDataByName = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($currentYearDataByName && isset($currentYearDataByName['total']) && floatval($currentYearDataByName['total']) > 0) {
                $currentYearSales = floatval($currentYearDataByName['total']);
                $vendorInfo['debug']['current_year_found_by'] = 'reseller_name';
            }
        }
        
        // If still 0, sum sales for any reseller name that maps to this vendor (handles "Misco" vs "MISCO LTD" etc.)
        if ($currentYearSales == 0 && !empty($vendorId)) {
            $sql = "SELECT COALESCE(SUM(s.total_value), 0) as total
            FROM sales_out_raw s
            INNER JOIN sales_out_reseller_mapping m ON LOWER(TRIM(m.reseller_name_raw)) = LOWER(TRIM(s.reseller_name))
            WHERE m.vendor_id = ? AND YEAR(s.`" . preg_replace('/[^a-z0-9_]/i', '', $dateCol) . "`) = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$vendorId, $currentYear]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['total']) && floatval($row['total']) > 0) {
                $currentYearSales = floatval($row['total']);
                $vendorInfo['debug']['current_year_found_by'] = 'reseller_mapping';
            }
        }
        
        $vendorInfo['current_year_sales'] = $currentYearSales;
        $vendorInfo['debug']['current_year_query'] = ['vendor_id' => $vendorId, 'year' => $currentYear, 'result' => $currentYearData, 'total' => $currentYearSales, 'row_count' => $stmt->rowCount()];
        
        // Get 3-year total (current year + 2 previous years)
        $threeYearsAgo = $currentYear - 2;
        $sql = "SELECT COALESCE(SUM(total_value), 0) as total
            FROM sales_out_raw
            WHERE matched_vendor_id = ? 
            AND YEAR(`" . preg_replace('/[^a-z0-9_]/i', '', $dateCol) . "`) >= ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$vendorId, $threeYearsAgo]);
        $threeYearData = $stmt->fetch(PDO::FETCH_ASSOC);
        $threeYearTotal = 0;
        if ($threeYearData !== false && isset($threeYearData['total']) && $threeYearData['total'] !== null) {
            $threeYearTotal = floatval($threeYearData['total']);
        }
        
        // If no sales found by vendor_id, try by exact reseller_name
        if ($threeYearTotal == 0 && !empty($vendorName)) {
            $sql = "SELECT COALESCE(SUM(total_value), 0) as total
            FROM sales_out_raw
            WHERE LOWER(TRIM(reseller_name)) = LOWER(TRIM(?))
            AND YEAR(`" . preg_replace('/[^a-z0-9_]/i', '', $dateCol) . "`) >= ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$vendorName, $threeYearsAgo]);
            $threeYearDataByName = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($threeYearDataByName && isset($threeYearDataByName['total']) && floatval($threeYearDataByName['total']) > 0) {
                $threeYearTotal = floatval($threeYearDataByName['total']);
                $vendorInfo['debug']['three_year_found_by'] = 'reseller_name';
            }
        }
        
        // If still 0, sum sales for any reseller name that maps to this vendor
        if ($threeYearTotal == 0 && !empty($vendorId)) {
            $sql = "SELECT COALESCE(SUM(s.total_value), 0) as total
            FROM sales_out_raw s
            INNER JOIN sales_out_reseller_mapping m ON LOWER(TRIM(m.reseller_name_raw)) = LOWER(TRIM(s.reseller_name))
            WHERE m.vendor_id = ? AND YEAR(s.`" . preg_replace('/[^a-z0-9_]/i', '', $dateCol) . "`) >= ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$vendorId, $threeYearsAgo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['total']) && floatval($row['total']) > 0) {
                $threeYearTotal = floatval($row['total']);
                $vendorInfo['debug']['three_year_found_by'] = 'reseller_mapping';
            }
        }
        
        $vendorInfo['three_year_total'] = $threeYearTotal;
        $vendorInfo['debug']['three_year_query'] = ['vendor_id' => $vendorId, 'from_year' => $threeYearsAgo, 'result' => $threeYearData, 'total' => $threeYearTotal];
        
        // Get target and calculate percentage (sales_out_targets: annual_target, year)
        $stmt = $pdo->prepare("
            SELECT annual_target
            FROM sales_out_targets
            WHERE target_type = 'reseller' 
            AND entity_key = ?
            AND year = ?
        ");
        $stmt->execute([$vendorId, $currentYear]);
        $targetData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($targetData && isset($targetData['annual_target']) && (float)$targetData['annual_target'] > 0) {
            $target = floatval($targetData['annual_target']);
            $sales = $vendorInfo['current_year_sales'] ?? 0;
            if ($sales > 0) {
                $vendorInfo['target_percentage'] = ($sales / $target) * 100;
            }
        }
        
        $vendorInfo['debug']['sales_query_success'] = true;
        // Ensure values are set (not null)
        if (!isset($vendorInfo['current_year_sales']) || $vendorInfo['current_year_sales'] === null) {
            $vendorInfo['current_year_sales'] = 0;
        }
        if (!isset($vendorInfo['three_year_total']) || $vendorInfo['three_year_total'] === null) {
            $vendorInfo['three_year_total'] = 0;
        }
    } catch (Exception $e) {
        error_log("Error fetching sales data for vendor ID $vendorId: " . $e->getMessage());
        $vendorInfo['error'] = $e->getMessage();
        $vendorInfo['debug']['sales_query_error'] = $e->getMessage();
        // Ensure values are set even on error
        $vendorInfo['current_year_sales'] = 0;
        $vendorInfo['three_year_total'] = 0;
    }
} else {
    $vendorInfo['debug']['no_vendor_id'] = 'Could not find vendor ID';
    // Ensure values are set even if no vendor_id
    $vendorInfo['current_year_sales'] = 0;
    $vendorInfo['three_year_total'] = 0;
}

// Final check - ensure no null values
$vendorInfo['current_year_sales'] = $vendorInfo['current_year_sales'] ?? 0;
$vendorInfo['three_year_total'] = $vendorInfo['three_year_total'] ?? 0;

// Budget spend items for this vendor (from budget_items, matched by vendor name)
$vendorInfo['budget_items'] = [];
if (!empty($vendorName)) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, po_number, activity_title, amount_requested, status, start_date, end_date, region, currency
            FROM budget_items
            WHERE TRIM(LOWER(vendor)) = TRIM(LOWER(?))
            ORDER BY start_date DESC, id DESC
            LIMIT 100
        ");
        $stmt->execute([$vendorName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Sanitise strings so json_encode never fails (invalid UTF-8)
        foreach ($rows as $row) {
            $clean = [];
            foreach ($row as $k => $v) {
                if ($v === null || is_int($v) || is_float($v)) {
                    $clean[$k] = $v;
                } else {
                    $clean[$k] = mb_convert_encoding(trim((string)$v), 'UTF-8', 'UTF-8') ?: (string)$v;
                }
            }
            $vendorInfo['budget_items'][] = $clean;
        }
    } catch (Exception $e) {
        error_log("Error fetching budget items for vendor: " . $e->getMessage());
    }
}

$json = @json_encode($vendorInfo, JSON_UNESCAPED_UNICODE);
if ($json === false) {
    error_log("get_vendor_sales json_encode failed: " . (function_exists('json_last_error_msg') ? json_last_error_msg() : 'unknown'));
    $vendorInfo['budget_items'] = [];
    $vendorInfo['error'] = 'Could not encode response';
    $json = json_encode($vendorInfo);
}
} catch (Throwable $e) {
    error_log("get_vendor_sales exception: " . $e->getMessage());
    $json = json_encode([
        'error' => 'Error loading vendor information. Please try again.',
        'salesforce_id' => $salesforceId,
        'current_year_sales' => 0,
        'three_year_total' => 0,
        'target_percentage' => null,
        'budget_items' => [],
        'debug' => ['error' => $e->getMessage()],
    ]);
}
echo $json;
