<?php
// test_import.php - Debug version
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

echo "<h2>Debug: Import Test</h2>";

// Test with a single row
$test_row = [
    'PO Number' => 'TEST-IMPORT-001',
    'Region' => 'AMER',
    'Country' => 'America',
    'Amount Requested' => '1234.56',
    'Currency' => 'USD',
    'Activity Title' => 'Test Import Debug',
    'Status' => 'Planned',
    'Vendor' => 'Test Vendor',
    'External Vendor' => '',
    'Vendor Contact' => 'Test Contact',
    'Account' => '1234',
    'Sub Account' => '5678',
    'Budget Category' => 'advertising',
    'Start Date' => '2024-01-15',
    'End Date' => '',
    'Invoiced Date' => '',
    'Associated EPOS Staff' => 'test@epos.com',
    'Item Type' => 'Reseller',
    'Path' => 'direct',
    'Frequency of Spend' => 'One-off',
    'Activity Description' => 'Test description',
    'Comments' => 'Test comment',
    'PO Prefix' => 'PO'
];

echo "<h3>Test Row Data:</h3>";
echo "<pre>";
print_r($test_row);
echo "</pre>";

// Get database columns
$db_columns = getBudgetTableColumns($pdo);

echo "<h3>Database Columns:</h3>";
echo "<pre>";
print_r($db_columns);
echo "</pre>";

// Create column mapping
$csv_headers = array_keys($test_row);
$column_mapping = mapCSVColumnsToDatabase($csv_headers, $db_columns);

echo "<h3>Column Mapping:</h3>";
echo "<pre>";
print_r($column_mapping);
echo "</pre>";

// Test processImportRow
echo "<h3>Testing processImportRow...</h3>";

$result = processImportRow($pdo, $test_row, $column_mapping, 'upsert', 'overwrite');

echo "<h3>Result:</h3>";
echo "<pre>";
print_r($result);
echo "</pre>";

// Check if it was inserted
if (isset($result['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM budget_items WHERE id = ?");
    $stmt->execute([$result['id']]);
    $inserted = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h3>Inserted Record:</h3>";
    echo "<pre>";
    print_r($inserted);
    echo "</pre>";
}

// Test with problematic row from your CSV
echo "<hr><h2>Testing Problematic Row</h2>";

$problem_row = [
    'PO Number' => 'FRANCE-1EIZ4C',
    'Region' => 'FRANCE',
    'Country' => 'France',
    'Amount Requested' => '1276.98',
    'Currency' => '',  // Empty currency!
    'Activity Title' => 'Neil\'s Test ActivityFRANCE',
    'Status' => 'Planned',
    'Vendor' => 'Simply Headsets Pty Ltd',
    'External Vendor' => '',
    'Vendor Contact' => '',
    'Account' => '615000_Prepaid Expenses',
    'Sub Account' => '192001_Direct Mailing',
    'Budget Category' => 'Marketing',
    'Start Date' => '28/11/2025',  // Problematic date format
    'End Date' => '',
    'Invoiced Date' => '',
    'Associated EPOS Staff' => 'pumv@eposaudio.com',
    'Item Type' => 'budget',
    'Path' => 'direct',
    'Frequency of Spend' => 'One-off',
    'Activity Description' => 'just testing',
    'Comments' => '',
    'PO Prefix' => 'PO'
];

echo "<h3>Problem Row:</h3>";
echo "<pre>";
print_r($problem_row);
echo "</pre>";

// Test date conversion
echo "<h3>Testing Date Conversion:</h3>";
echo "Start Date conversion: " . formatDateForDB('28/11/2025') . "<br>";

// Test amount cleaning
echo "<h3>Testing Amount Cleaning:</h3>";
$test_amounts = [
    '1276.98',
    '1,276.98',
    '$1,276.98',
    '€1.276,98',  // European format
    '1 276,98'
];

foreach ($test_amounts as $amount) {
    $clean = str_replace(['$', '€', '£', '¥', '₹', ',', ' '], '', $amount);
    // Handle European decimal
    if (strpos($clean, ',') !== false && strpos($clean, '.') === false) {
        $clean = str_replace(',', '.', $clean);
    }
    echo "'$amount' -> '$clean' -> " . (is_numeric($clean) ? "VALID: " . floatval($clean) : "INVALID") . "<br>";
}
?>