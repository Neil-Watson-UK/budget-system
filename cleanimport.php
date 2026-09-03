<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

echo "<h2>Clean Import of NeilsBudgetTester.csv</h2>";

// Read and clean the CSV
$csvFile = 'NeilsBudgetTester.csv';

if (!file_exists($csvFile)) {
    die("File not found: $csvFile. Make sure it's in the same directory.");
}

echo "<h3>Step 1: Reading CSV...</h3>";

// Manually read and clean the CSV
$rows = [];
if (($handle = fopen($csvFile, 'r')) !== false) {
    // Read first line as headers
    $headers = fgetcsv($handle);
    
    // Remove BOM if present
    if (!empty($headers[0]) && substr($headers[0], 0, 3) == "\xEF\xBB\xBF") {
        $headers[0] = substr($headers[0], 3);
    }
    
    echo "<h4>Headers found:</h4>";
    echo "<pre>";
    print_r($headers);
    echo "</pre>";
    
    $rowCount = 0;
    while (($data = fgetcsv($handle)) !== false) {
        $row = [];
        for ($i = 0; $i < count($headers); $i++) {
            $row[trim($headers[$i])] = isset($data[$i]) ? trim($data[$i]) : '';
        }
        $rows[] = $row;
        $rowCount++;
    }
    fclose($handle);
    
    echo "Found $rowCount rows.<br>";
}

if (empty($rows)) {
    die("No data found in CSV.");
}

echo "<h3>Step 2: Testing First 3 Rows...</h3>";

// Get database columns
$db_columns = getBudgetTableColumns($pdo);
$csv_headers = array_keys($rows[0]);
$column_mapping = mapCSVColumnsToDatabase($csv_headers, $db_columns);

echo "<h4>Column Mapping:</h4>";
echo "<pre>";
print_r($column_mapping);
echo "</pre>";

// Test each row
$successCount = 0;
$failCount = 0;

foreach (array_slice($rows, 0, 5) as $index => $row) {
    echo "<h4>Row $index (PO: " . ($row['PO Number'] ?? 'N/A') . "):</h4>";
    
    // Debug amount
    if (isset($row['Amount Requested'])) {
        $amount = $row['Amount Requested'];
        echo "Amount: '$amount'<br>";
        
        // Clean it
        $clean = preg_replace('/[^0-9\.]/', '', $amount);
        // Handle European decimal
        if (strpos($clean, ',') !== false && strpos($clean, '.') === false) {
            $clean = str_replace(',', '.', $clean);
        }
        echo "Cleaned: '$clean'<br>";
        echo "Is numeric? " . (is_numeric($clean) ? 'YES' : 'NO') . "<br>";
        echo "Float value: " . floatval($clean) . "<br>";
        echo "Greater than 0? " . (floatval($clean) > 0 ? 'YES' : 'NO') . "<br>";
    }
    
    // Test import
    $result = processImportRow($pdo, $row, $column_mapping, 'upsert', 'overwrite');
    
    echo "<pre>";
    print_r($result);
    echo "</pre>";
    
    if ($result['status'] === 'imported' || $result['status'] === 'updated') {
        $successCount++;
    } else {
        $failCount++;
    }
}

echo "<h3>Step 3: Summary</h3>";
echo "Successful: $successCount<br>";
echo "Failed: $failCount<br>";

// Now try the full import
echo "<hr><h2>Full Import Test</h2>";

// Use the processImportFile function
echo "<h3>Using processImportFile()...</h3>";

$results = processImportFile($pdo, $csvFile, 'csv', 'upsert', 'overwrite');

echo "<h4>Results:</h4>";
echo "<pre>";
print_r($results);
echo "</pre>";

// Show errors if any
if (!empty($results['errors'])) {
    echo "<h4 style='color: red;'>Errors:</h4>";
    foreach ($results['errors'] as $error) {
        echo "- $error<br>";
    }
}
?>