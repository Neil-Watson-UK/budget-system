<?php
// simple_mapping_test.php
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

// Just test the mapping
$csv_headers = ['PO Number', 'Amount Requested', 'Associated EPOS Staff', 'Activity Title'];
$db_columns = getBudgetTableColumns($pdo);

echo "<h3>Testing mapping of:</h3>";
echo "<pre>";
print_r($csv_headers);
echo "</pre>";

$mapping = mapCSVColumnsToDatabase($csv_headers, $db_columns);

echo "<h3>Mapping result:</h3>";
echo "<pre>";
print_r($mapping);
echo "</pre>";

// Check each one
foreach ($csv_headers as $header) {
    echo "$header -> " . ($mapping[$header] ?? 'NOT MAPPED') . "<br>";
}
?>