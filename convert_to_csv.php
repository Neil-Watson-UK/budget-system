<?php
// convert_to_csv.php - Debug version
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

echo "<h2>Debug: Convert CSV Test</h2>";

// Test with your CSV data directly
$csvData = <<<CSV
PO Number,Region,Country,Amount Requested,Currency,Activity Title,Status,Vendor,External Vendor,Vendor Contact,Account,Sub Account,Budget Category,Start Date,End Date,Invoiced Date,Associated EPOS Staff,Item Type,Path,Frequency of Spend,Activity Description,Comments,PO Prefix
AMER-0GVS7X,AMER,America,4356.65,EUR,Neil's Test Activity US 3,Planned,Bechtle Austria GmbH,,Mr Vendor,199800_OTHER ADVERTISING AND PROMOTIONS,192001_Direct Mailing,Marketing,15/01/2026,30/01/2026,,notspecified@epos.com,budget,direct,One-off,just a test activity,really hope this works,PO
AMER-16BIKM,AMER,America,8356.65,EUR,Neil's Test Activity US 5,Executed,AVAD GmbH,,Mr Vendor,615000_Prepaid Expenses,191006_Marketing - TV,Marketing,06/02/2026,30/01/2026,,notspecified@epos.com,budget,direct,One-off,testing,,PO
CSV;

echo "<h3>Original CSV:</h3>";
echo "<pre>" . htmlspecialchars($csvData) . "</pre>";

// Save to temp file
$tempFile = tempnam(sys_get_temp_dir(), 'test_csv_');
file_put_contents($tempFile, $csvData);

echo "<h3>Processing CSV...</h3>";

// Test readCSVFileSimple
$data = readCSVFileSimple($tempFile);

echo "<h3>Parsed Data:</h3>";
echo "<pre>";
print_r($data);
echo "</pre>";

// Test processImportFile
echo "<h3>Testing Import...</h3>";

$results = processImportFile($pdo, $tempFile, 'csv', 'upsert', 'overwrite');

echo "<h3>Import Results:</h3>";
echo "<pre>";
print_r($results);
echo "</pre>";

// Clean up
unlink($tempFile);

echo "<hr>";
echo "<h3>Database Columns:</h3>";
$columns = getBudgetTableColumns($pdo);
echo "<pre>";
print_r($columns);
echo "</pre>";

// Test column mapping
echo "<h3>Column Mapping Test:</h3>";
$csv_headers = array_keys($data[0] ?? []);
$mapping = mapCSVColumnsToDatabase($csv_headers, $columns);
echo "<pre>";
print_r($mapping);
echo "</pre>";
?>