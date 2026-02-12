<?php
// csv_import_fixed.php
ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering and set headers immediately
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

function json_response($data) {
    // Clear any output
    while (ob_get_level()) ob_end_clean();
    
    // Set JSON header
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// Handle non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Please use POST method with file upload'
    ]);
}

if (!isset($_FILES['csv_file'])) {
    json_response([
        'success' => false, 
        'message' => 'No CSV file uploaded'
    ]);
}

try {
    // Database connection
    $host = '92.205.6.240';
    $dbname = 'cmmbudget';
    $username = 'budgetadmin';
    $password = 'NotReevesP13453';
    
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4;port=3306",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
    
    $file = $_FILES['csv_file'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }
    
    // Read and clean CSV
    $csvContent = file_get_contents($file['tmp_name']);
    
    // Remove UTF-8 BOM
    $bom = pack('H*', 'EFBBBF');
    $csvContent = preg_replace("/^$bom/", '', $csvContent);
    
    // Convert to UTF-8 if needed
    if (!mb_check_encoding($csvContent, 'UTF-8')) {
        $csvContent = mb_convert_encoding($csvContent, 'UTF-8', 'auto');
    }
    
    // Parse CSV
    $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($tempFile, $csvContent);
    
    $handle = fopen($tempFile, 'r');
    if (!$handle) {
        throw new Exception('Cannot open CSV file');
    }
    
    // Get headers
    $headers = fgetcsv($handle);
    if (!$headers) {
        throw new Exception('CSV file is empty');
    }
    
    // Trim headers
    $headers = array_map('trim', $headers);
    
    // Define column mapping based on your debug output
    $columnMapping = [
        'po_number' => 'po_number',
        'region' => 'region', 
        'country' => 'country',
        'amount_requested' => 'amount_requested',
        'currency' => 'currency',
        'activity_title' => 'activity_title',
        'status' => 'status',
        'vendor' => 'vendor',
        'external_vendor' => 'vendor',
        'vendor_contact' => 'vendor',
        'account' => 'account',
        'sub_account' => 'account',
        'budget_category' => 'budget_category',
        'start_date' => 'start_date',
        'end_date' => 'end_date',
        'invoiced_date' => 'invoiced_date',
        'associated_epos_staff' => 'associated_epos_staff',
        'item_type' => 'item_type',
        'path' => 'path',
        'frequency_of_spend' => 'frequency_of_spend',
        'activity_description' => 'activity_description',
        'comments' => 'comments',
        'po_prefix' => 'po_prefix'
    ];
    
    // Database columns
    $dbColumns = [
        'id', 'po_number', 'po_prefix', 'region', 'country', 
        'start_date', 'end_date', 'invoiced_date', 'amount_requested',
        'currency', 'activity_title', 'status', 'frequency_of_spend',
        'vendor', 'external_vendor', 'vendor_contact', 'account',
        'sub_account', 'budget_category', 'activity_description',
        'comments', 'associated_epos_staff', 'department', 'project_code',
        'item_type', 'path', 'is_global', 'local_po_reference',
        'entry_creation_date', 'entry_updated_date'
    ];
    
    $results = [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
        'details' => []
    ];
    
    $rowNumber = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        
        // Skip empty rows
        if (empty(array_filter($row))) {
            $results['skipped']++;
            continue;
        }
        
        // Combine headers with data
        $rowData = array_combine($headers, array_pad($row, count($headers), ''));
        $rowData = array_map('trim', $rowData);
        
        // Prepare database row
        $dbRow = [
            'id' => null, // Auto-increment
            'po_number' => $rowData['po_number'] ?? '',
            'po_prefix' => $rowData['po_prefix'] ?? 'PO',
            'region' => $rowData['region'] ?? '',
            'country' => $rowData['country'] ?? '',
            'start_date' => !empty($rowData['start_date']) ? date('Y-m-d', strtotime(str_replace('/', '-', $rowData['start_date']))) : null,
            'end_date' => !empty($rowData['end_date']) ? date('Y-m-d', strtotime(str_replace('/', '-', $rowData['end_date']))) : null,
            'invoiced_date' => !empty($rowData['invoiced_date']) ? date('Y-m-d', strtotime(str_replace('/', '-', $rowData['invoiced_date']))) : null,
            'amount_requested' => floatval($rowData['amount_requested'] ?? 0),
            'currency' => $rowData['currency'] ?? 'GBP',
            'activity_title' => $rowData['activity_title'] ?? '',
            'status' => $rowData['status'] ?? 'Planned',
            'frequency_of_spend' => $rowData['frequency_of_spend'] ?? '',
            'vendor' => $rowData['vendor'] ?? '',
            'external_vendor' => $rowData['external_vendor'] ?? '',
            'vendor_contact' => $rowData['vendor_contact'] ?? '',
            'account' => $rowData['account'] ?? '',
            'sub_account' => $rowData['sub_account'] ?? '',
            'budget_category' => $rowData['budget_category'] ?? '',
            'activity_description' => $rowData['activity_description'] ?? '',
            'comments' => $rowData['comments'] ?? '',
            'associated_epos_staff' => $rowData['associated_epos_staff'] ?? '',
            'department' => '',
            'project_code' => '',
            'item_type' => $rowData['item_type'] ?? 'budget',
            'path' => $rowData['path'] ?? 'direct',
            'is_global' => 0,
            'local_po_reference' => '',
            'entry_creation_date' => date('Y-m-d H:i:s'),
            'entry_updated_date' => date('Y-m-d H:i:s')
        ];
        
        try {
            // Check if exists by po_number
            $checkStmt = $pdo->prepare("SELECT id FROM budget_items WHERE po_number = ?");
            $checkStmt->execute([$dbRow['po_number']]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Update existing
                $updateCols = [];
                $updateVals = [];
                
                foreach ($dbRow as $col => $val) {
                    if ($col !== 'id' && $col !== 'entry_creation_date') {
                        $updateCols[] = "$col = ?";
                        $updateVals[] = $val;
                    }
                }
                
                $updateVals[] = $existing['id']; // WHERE clause
                
                $updateSql = "UPDATE budget_items SET " . implode(', ', $updateCols) . " WHERE id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute($updateVals);
                
                $results['updated']++;
                $results['details'][] = [
                    'status' => 'updated',
                    'po_number' => $dbRow['po_number'],
                    'id' => $existing['id'],
                    'message' => 'Updated successfully'
                ];
            } else {
                // Insert new
                $insertCols = array_keys($dbRow);
                $insertPlaceholders = array_fill(0, count($insertCols), '?');
                $insertVals = array_values($dbRow);
                
                $insertSql = "INSERT INTO budget_items (" . implode(', ', $insertCols) . ") 
                              VALUES (" . implode(', ', $insertPlaceholders) . ")";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute($insertVals);
                
                $newId = $pdo->lastInsertId();
                $results['imported']++;
                $results['details'][] = [
                    'status' => 'imported',
                    'po_number' => $dbRow['po_number'],
                    'id' => $newId,
                    'message' => 'Imported successfully'
                ];
            }
            
        } catch (PDOException $e) {
            $results['errors'][] = "Row $rowNumber: " . $e->getMessage();
            $results['skipped']++;
        }
    }
    
    fclose($handle);
    unlink($tempFile);
    
    // Success response
    json_response([
        'success' => true,
        'message' => 'Import completed',
        'results' => $results
    ]);
    
} catch (Exception $e) {
    json_response([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
} catch (PDOException $e) {
    json_response([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}