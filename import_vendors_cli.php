<?php
// import_vendors_cli.php - Command line import
error_reporting(E_ALL);

$db = new mysqli('localhost', 'budgetadmin', 'NotReevesP13453', 'cmmbudget');

// Path to your CSV file
$csvFile = 'vendors_export.csv';

if (!file_exists($csvFile)) {
    die("CSV file not found: $csvFile\n");
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Cannot open CSV file\n");
}

// Read and clean header
$headerLine = fgets($handle);
$headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine); // Remove BOM
$headers = str_getcsv($headerLine);

echo "Headers: " . implode(', ', $headers) . "\n\n";

$success = 0;
$errors = 0;
$line = 1;

while (($row = fgetcsv($handle)) !== false) {
    $line++;
    
    // Map data
    $data = [
        'vendor_name' => $row[1] ?? '', // Vendor Name
        'salesforce_id' => $row[0] ?? '', // Salesforce Id
        'account_type' => $row[2] ?? '', // Type
        'SalesMarket__c' => $row[24] ?? '', // SalesMarket__c
        // Add other fields as needed
    ];
    
    // Skip if no vendor name
    if (empty($data['vendor_name'])) {
        echo "Line $line: Skipped - no vendor name\n";
        $errors++;
        continue;
    }
    
    // Insert using prepared statement
    $stmt = $db->prepare("INSERT IGNORE INTO vendors (vendor_name, salesforce_id, account_type, region) 
                         VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", 
        $data['vendor_name'],
        $data['salesforce_id'],
        $data['account_type'],
        $data['SalesMarket__c']
    );
    
    if ($stmt->execute()) {
        if ($db->affected_rows > 0) {
            $success++;
            echo "Line $line: Added " . $data['vendor_name'] . "\n";
        } else {
            echo "Line $line: Skipped - duplicate: " . $data['vendor_name'] . "\n";
            $errors++;
        }
    } else {
        echo "Line $line: Error - " . $db->error . "\n";
        $errors++;
    }
    
    // Progress indicator
    if ($line % 1000 === 0) {
        echo "Processed $line lines...\n";
    }
}

fclose($handle);

echo "\n\n=== IMPORT COMPLETE ===\n";
echo "Successfully imported: $success\n";
echo "Errors/skipped: $errors\n";
echo "Total lines processed: " . ($line - 1) . "\n";

// Show final count
$result = $db->query("SELECT COUNT(*) as count FROM vendors");
$row = $result->fetch_assoc();
echo "Total vendors in database: " . $row['count'] . "\n";
?>