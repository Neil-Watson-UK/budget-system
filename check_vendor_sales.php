<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: text/html; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$vendorId = $_GET['vendor_id'] ?? '';
$vendorName = $_GET['vendor_name'] ?? '';

if (empty($vendorId) && empty($vendorName)) {
    die('Please provide vendor_id or vendor_name');
}

$pdo = getDBConnection();

echo "<!DOCTYPE html><html><head><title>Sales Data Check</title>";
echo "<style>body { font-family: Arial, sans-serif; margin: 20px; } table { margin: 10px 0; } th { background: #f0f0f0; padding: 8px; } td { padding: 5px; }</style></head><body>";
echo "<h2>Sales Data Check for Vendor</h2>";
echo "<p><strong>Vendor ID:</strong> " . htmlspecialchars($vendorId ?: 'N/A') . "</p>";
echo "<p><strong>Vendor Name:</strong> " . htmlspecialchars($vendorName ?: 'N/A') . "</p>";
echo "<hr>";

// Check vendor details
if ($vendorId) {
    $stmt = $pdo->prepare("SELECT id, vendor_name, salesforce_id FROM vendors WHERE id = ?");
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($vendor) {
        echo "<h3>Vendor Details:</h3>";
        echo "<pre>" . print_r($vendor, true) . "</pre>";
        $vendorName = $vendor['vendor_name'];
    }
}

// Check sales by matched_vendor_id
if ($vendorId) {
    echo "<h3>Sales Records by matched_vendor_id = $vendorId:</h3>";
    try {
        $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               COALESCE(SUM(total_value), 0) as total,
               MIN(report_date) as earliest_date,
               MAX(report_date) as latest_date
        FROM sales_out_raw
        WHERE matched_vendor_id = ?
        ");
        $stmt->execute([$vendorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && isset($result['count']) && $result['count'] > 0) {
            echo "<div style='color: green;'><strong>✓ Found {$result['count']} records with total: £" . number_format($result['total'], 2) . "</strong></div>";
            echo "<pre>" . print_r($result, true) . "</pre>";
            
            // Get sample records
            $stmt = $pdo->prepare("
            SELECT report_date, reseller_name, total_value, matched_vendor_id
            FROM sales_out_raw
            WHERE matched_vendor_id = ?
            ORDER BY report_date DESC
                LIMIT 10
            ");
            $stmt->execute([$vendorId]);
            $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($samples) {
                echo "<h4>Sample Records:</h4>";
                echo "<pre>" . print_r($samples, true) . "</pre>";
            }
        } else {
            echo "<div style='color: red;'><strong>✗ No sales records found with matched_vendor_id = $vendorId</strong></div>";
            echo "<p>This means sales data exists but isn't matched to this vendor yet.</p>";
            if ($result) {
                echo "<pre>Query result: " . print_r($result, true) . "</pre>";
            }
        }
    } catch (Exception $e) {
        echo "<div style='color: red;'><strong>Error: " . htmlspecialchars($e->getMessage()) . "</strong></div>";
    }
    echo "<br>";
}

// Check sales by reseller_name
if ($vendorName) {
    echo "<h3>Sales Records by reseller_name (exact match: '$vendorName'):</h3>";
    try {
        $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               COALESCE(SUM(total_value), 0) as total,
               MIN(report_date) as earliest_date,
               MAX(report_date) as latest_date
        FROM sales_out_raw
        WHERE LOWER(TRIM(reseller_name)) = LOWER(TRIM(?))
        ");
        $stmt->execute([$vendorName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && isset($result['count']) && $result['count'] > 0) {
            echo "<div style='color: green;'><strong>✓ Found {$result['count']} records with total: £" . number_format($result['total'], 2) . "</strong></div>";
            echo "<pre>" . print_r($result, true) . "</pre>";
            
            // Get sample records
            $stmt = $pdo->prepare("
            SELECT report_date, reseller_name, total_value, matched_vendor_id
            FROM sales_out_raw
            WHERE LOWER(TRIM(reseller_name)) = LOWER(TRIM(?))
            ORDER BY report_date DESC
                LIMIT 10
            ");
            $stmt->execute([$vendorName]);
            $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($samples) {
                echo "<h4>Sample Records:</h4>";
                echo "<pre>" . print_r($samples, true) . "</pre>";
                if (isset($samples[0]['matched_vendor_id']) && $samples[0]['matched_vendor_id'] != $vendorId) {
                    echo "<div style='color: orange;'><strong>⚠ These records have matched_vendor_id = {$samples[0]['matched_vendor_id']}, not $vendorId</strong></div>";
                }
            }
        } else {
            echo "<div style='color: red;'><strong>✗ No sales records found with exact reseller_name match</strong></div>";
        }
    } catch (Exception $e) {
        echo "<div style='color: red;'><strong>Error: " . htmlspecialchars($e->getMessage()) . "</strong></div>";
    }
    echo "<br>";
}

// Check similar reseller names
if ($vendorName) {
    echo "<h3>Similar Reseller Names in sales_out_raw:</h3>";
    try {
        // Build search patterns from the actual vendor name (not hardcoded)
        $clean = trim(preg_replace('/\s+/', ' ', $vendorName));
        $words = preg_split('/[\s\-\.,&]+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
        $searchTerms = [
            '%' . $clean . '%',
            '%' . str_replace(['&', 'Ltd', 'Limited', ' '], '%', $vendorName) . '%'
        ];
        foreach ($words as $w) {
            if (strlen($w) >= 2 && !in_array(strtolower($w), ['ltd', 'limited', 'the', 'and', 'uk'], true)) {
                $searchTerms[] = '%' . $w . '%';
            }
        }
        $searchTerms = array_unique($searchTerms);
        echo "<p><small class='text-muted'>Search terms used for &quot;" . htmlspecialchars($vendorName) . "&quot;: " . htmlspecialchars(implode(', ', $searchTerms)) . "</small></p>";
        
        $allSimilar = [];
        foreach ($searchTerms as $searchTerm) {
            try {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT reseller_name, 
                           COUNT(*) as record_count,
                           COALESCE(SUM(total_value), 0) as total_value,
                           matched_vendor_id
                    FROM sales_out_raw
                    WHERE reseller_name LIKE ?
                    GROUP BY reseller_name, matched_vendor_id
                    ORDER BY total_value DESC
                    LIMIT 20
                ");
                $stmt->execute([$searchTerm]);
                $similar = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $allSimilar = array_merge($allSimilar, $similar);
            } catch (Exception $e) {
                echo "<p style='color: orange;'>Warning searching for '$searchTerm': " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    
    // Remove duplicates
    $unique = [];
    foreach ($allSimilar as $item) {
        $key = $item['reseller_name'] . '|' . $item['matched_vendor_id'];
        if (!isset($unique[$key])) {
            $unique[$key] = $item;
        }
    }
    $allSimilar = array_values($unique);
    
    if ($allSimilar) {
        echo "<p><strong>Found " . count($allSimilar) . " similar reseller names:</strong></p>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Reseller Name</th><th>Records</th><th>Total Value</th><th>Matched Vendor ID</th><th>Action</th></tr>";
        foreach ($allSimilar as $item) {
            $highlight = ($item['matched_vendor_id'] == $vendorId) ? "style='background-color: #90EE90;'" : "";
            echo "<tr $highlight>";
            echo "<td>" . htmlspecialchars($item['reseller_name']) . "</td>";
            echo "<td>" . $item['record_count'] . "</td>";
            echo "<td>£" . number_format($item['total_value'], 2) . "</td>";
            echo "<td>" . ($item['matched_vendor_id'] ?: 'NULL') . "</td>";
            if ($item['matched_vendor_id'] != $vendorId && $item['total_value'] > 0) {
                echo "<td><a href='?vendor_id=$vendorId&vendor_name=" . urlencode($vendorName) . "&rematch=" . urlencode($item['reseller_name']) . "'>Match to this vendor</a></td>";
            } else {
                echo "<td>-</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div style='color: red;'><strong>✗ No similar reseller names found</strong></div>";
    }
    } catch (Exception $e) {
        echo "<div style='color: red;'><strong>Error: " . htmlspecialchars($e->getMessage()) . "</strong></div>";
    }
    echo "<br>";
}

// Check reseller mapping
if ($vendorName) {
    echo "<h3>Reseller Mappings:</h3>";
    $stmt = $pdo->prepare("
        SELECT m.*, v.vendor_name
        FROM sales_out_reseller_mapping m
        JOIN vendors v ON m.vendor_id = v.id
        WHERE LOWER(TRIM(m.reseller_name_raw)) LIKE ?
        OR LOWER(TRIM(v.vendor_name)) LIKE ?
    ");
    $searchTerm = '%' . strtolower($vendorName) . '%';
    $stmt->execute([$searchTerm, $searchTerm]);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($mappings) {
        echo "<pre>" . print_r($mappings, true) . "</pre>";
    } else {
        echo "<p>No mappings found.</p>";
    }
}

// Check current year sales specifically
if ($vendorId) {
    $currentYear = date('Y');
    echo "<h3>Current Year ($currentYear) Sales:</h3>";
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               COALESCE(SUM(total_value), 0) as total
        FROM sales_out_raw
        WHERE matched_vendor_id = ?
        AND YEAR(report_date) = ?
    ");
    $stmt->execute([$vendorId, $currentYear]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($result, true) . "</pre>";
}

echo "<hr>";
echo "<h3>Summary:</h3>";
if ($vendorId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM sales_out_raw WHERE matched_vendor_id = ?");
    $stmt->execute([$vendorId]);
    $total = $stmt->fetchColumn();
    if ($total > 0) {
        echo "<p style='color: green;'><strong>✓ This vendor has $total sales records matched.</strong></p>";
    } else {
        echo "<p style='color: red;'><strong>✗ This vendor has NO sales records matched. Sales may exist under a different reseller name.</strong></p>";
        echo "<p><strong>Next Steps:</strong></p>";
        echo "<ul>";
        echo "<li>Check the 'Similar Reseller Names' section above to find potential matches</li>";
        echo "<li>If you find sales under a different name, use the mapping system in salesout/mapping.php to link them</li>";
        echo "<li>Or manually update the matched_vendor_id in sales_out_raw table</li>";
        echo "</ul>";
    }
}
echo "<hr>";
echo "<p><a href='javascript:window.close()'>Close Window</a> | <a href='regional_view.php'>Back to Regional View</a></p>";
echo "</body></html>";
?>
