<?php
// salesout/functions.php - Helper functions

/**
 * Match reseller name to known vendor (from budget vendors table)
 */
function matchResellerToVendor($pdo, string $resellerName): ?int {
    $resellerName = trim($resellerName);
    if (empty($resellerName)) return null;
    
    // 1. Check mapping table first
    $stmt = $pdo->prepare("
        SELECT vendor_id FROM sales_out_reseller_mapping 
        WHERE LOWER(TRIM(reseller_name_raw)) = LOWER(?)
    ");
    $stmt->execute([$resellerName]);
    $mapped = $stmt->fetchColumn();
    if ($mapped) return (int) $mapped;
    
    // 2. Fuzzy match against vendors
    $stmt = $pdo->prepare("
        SELECT id FROM vendors 
        WHERE LOWER(vendor_name) = LOWER(?) 
        OR vendor_name LIKE ?
        LIMIT 1
    ");
    $stmt->execute([$resellerName, '%' . $resellerName . '%']);
    $vendorId = $stmt->fetchColumn();
    
    return $vendorId ? (int) $vendorId : null;
}

/**
 * Get product info by SKU
 */
function getProductBySku($pdo, string $sku): ?array {
    $sku = trim($sku);
    if (empty($sku)) return null;
    
    $stmt = $pdo->prepare("
        SELECT * FROM sales_out_products 
        WHERE sku = ? OR sku = ? OR REPLACE(sku, ' ', '') = REPLACE(?, ' ', '')
        LIMIT 1
    ");
    $stmt->execute([$sku, trim($sku), $sku]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $row ?: null;
}

/**
 * Normalise column header for flexible matching
 */
function normaliseHeader(string $header): string {
    return strtolower(preg_replace('/[^a-z0-9]/', '', trim($header)));
}

/**
 * Parse a decimal/currency value from various formats.
 * Handles: European (3,25 or 1.234,56), US (3.25 or 1,234.56), space thousands (1 841.40)
 */
function parseDecimalValue(string $val): float {
    $val = trim((string) $val);
    if ($val === '') return 0.0;
    // Remove currency symbols and nbsp
    $val = str_replace(["\xc2\xa0", "\xc2\xa3", '€', '$', '£', ' '], '', $val);
    $val = trim($val);
    $lastComma = strrpos($val, ',');
    $lastDot = strrpos($val, '.');
    if ($lastComma !== false && $lastDot !== false) {
        // Both present: last occurrence is decimal separator
        if ($lastComma > $lastDot) {
            $val = str_replace('.', '', $val);
            $val = str_replace(',', '.', $val);
        } else {
            $val = str_replace(',', '', $val);
        }
    } elseif ($lastComma !== false) {
        $after = substr($val, $lastComma + 1);
        // 2 digits after comma = decimal (3,25). 3+ digits = thousands (1,234)
        if (preg_match('/^\d{2}$/', $after)) {
            $val = str_replace(',', '.', $val);
        } else {
            $val = str_replace(',', '', $val);
        }
    }
    $val = preg_replace('/[^\d.\-]/', '', $val);
    return $val === '' ? 0.0 : (float) $val;
}
