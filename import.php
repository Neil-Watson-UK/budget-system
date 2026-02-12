<?php
// csvclean.php - Tabler-styled CSV Import with Login Integration
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Database configuration
$host = '92.205.6.240';
$dbname = 'cmmbudget';
$username = 'budgetadmin';
$password = 'NotReevesP13453';

// Define APP_NAME if not defined
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Budget System');
}

// Initialize variables
$success = false;
$message = '';
$results = null;
$fileName = '';
$fileSize = '';

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Process file upload if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    error_log("CSV upload started");
    
    try {
        $file = $_FILES['csv_file'];
        error_log("File received: " . print_r($file, true));
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        // Check file type
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($fileExt !== 'csv') {
            throw new Exception('Please upload a CSV file.');
        }
        
        // Get file info for display
        $fileName = htmlspecialchars($file['name']);
        $fileSize = formatBytes($file['size']);
        
        error_log("Reading CSV content");
        // Read and clean CSV
        $csvContent = file_get_contents($file['tmp_name']);
        
        if ($csvContent === false) {
            throw new Exception('Cannot read uploaded file.');
        }
        
        // Remove UTF-8 BOM
        $csvContent = str_replace("\xEF\xBB\xBF", '', $csvContent);
        
        // Convert to UTF-8 if needed
        if (!mb_check_encoding($csvContent, 'UTF-8')) {
            $csvContent = mb_convert_encoding($csvContent, 'UTF-8', 'auto');
        }
        
        // Debug: Save raw content to see what we're getting
        error_log("CSV Content length: " . strlen($csvContent));
        file_put_contents('/tmp/csv_debug.txt', $csvContent);
        
        error_log("Connecting to database");
        // Database connection
        try {
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
            error_log("Database connection successful");
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
        
        // Parse CSV
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tempFile, $csvContent);
        
        error_log("Opening CSV file: $tempFile");
        $handle = fopen($tempFile, 'r');
        if (!$handle) {
            throw new Exception('Cannot open CSV file');
        }
        
        // Get headers
        $headers = fgetcsv($handle);
        error_log("Headers: " . print_r($headers, true));
        
        if (!$headers) {
            throw new Exception('CSV file is empty');
        }
        
        // Trim headers
        $headers = array_map('trim', $headers);
        $headerCount = count($headers);
        error_log("Header count: $headerCount");
        
        // Track results
        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'total_rows' => 0,
            'headers' => $headers,
            'preview' => []
        ];
        
        // Read first 5 rows for preview
        $previewRows = [];
        $rowNumber = 0;
        
        while (($row = fgetcsv($handle)) !== false && $rowNumber < 5) {
            $rowNumber++;
            if (!empty(array_filter($row))) {
                // Pad row if needed
                if (count($row) < $headerCount) {
                    $row = array_pad($row, $headerCount, '');
                } elseif (count($row) > $headerCount) {
                    $row = array_slice($row, 0, $headerCount);
                }
                $previewRows[] = $row;
            }
        }
        
        $results['preview'] = $previewRows;
        error_log("Preview rows collected: " . count($previewRows));
        
        // Reset file pointer for full import
        fclose($handle);
        $handle = fopen($tempFile, 'r');
        fgetcsv($handle); // Skip header
        
        // Full import
        $rowNumber = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $results['total_rows']++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                $results['skipped']++;
                continue;
            }
            
            // Pad row if needed
            if (count($row) < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif (count($row) > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }
            
            // Combine headers with data
            $rowData = array_combine($headers, $row);
            $rowData = array_map('trim', $rowData);
            
            // Debug: Log row data
            error_log("Processing row $rowNumber: " . print_r($rowData, true));
            
            // Prepare database row - SIMPLIFIED VERSION
            $dbRow = [
                'po_number' => $rowData['po_number'] ?? ($rowData['PO Number'] ?? ''),
                'amount_requested' => 0,
                'currency' => $rowData['currency'] ?? ($rowData['Currency'] ?? 'GBP'),
                'entry_creation_date' => date('Y-m-d H:i:s'),
                'entry_updated_date' => date('Y-m-d H:i:s')
            ];
            
            // Clean amount
            $amount = $rowData['amount_requested'] ?? ($rowData['Amount'] ?? ($rowData['amount'] ?? '0'));
            if (!empty($amount)) {
                $amount = str_replace(['$', '€', '£', ',', ' ', ' ', chr(160)], '', $amount);
                
                // Handle European decimal format
                if (strpos($amount, ',') !== false && strpos($amount, '.') !== false) {
                    $amount = str_replace(',', '', $amount);
                } elseif (strpos($amount, ',') !== false) {
                    $amount = str_replace(',', '.', $amount);
                }
                
                $dbRow['amount_requested'] = floatval($amount);
            }
            
            // Map other common fields if they exist
            $fieldMapping = [
                'region' => ['region', 'Region'],
                'country' => ['country', 'Country'],
                'start_date' => ['start_date', 'start date', 'Start Date'],
                'end_date' => ['end_date', 'end date', 'End Date'],
                'invoice_date' => ['invoice_date', 'invoice date', 'Invoice Date'],
                'activity_title' => ['activity_title', 'activity title', 'Activity Title'],
                'status' => ['status', 'Status'],
                'vendor' => ['vendor', 'Vendor'],
                'account' => ['account', 'Account'],
                'comments' => ['comments', 'Comments']
            ];
            
            foreach ($fieldMapping as $dbField => $possibleHeaders) {
                foreach ($possibleHeaders as $possibleHeader) {
                    if (isset($rowData[$possibleHeader]) && !empty(trim($rowData[$possibleHeader]))) {
                        $dbRow[$dbField] = trim($rowData[$possibleHeader]);
                        break;
                    }
                }
            }
            
            // Process dates
            if (!empty($dbRow['start_date'])) {
                $dbRow['start_date'] = date('Y-m-d', strtotime(str_replace('/', '-', $dbRow['start_date'])));
            }
            if (!empty($dbRow['end_date'])) {
                $dbRow['end_date'] = date('Y-m-d', strtotime(str_replace('/', '-', $dbRow['end_date'])));
            }
            if (!empty($dbRow['invoice_date'])) {
                $dbRow['invoice_date'] = date('Y-m-d', strtotime(str_replace('/', '-', $dbRow['invoice_date'])));
            }
            
            try {
                // Check if exists by po_number
                if (!empty($dbRow['po_number'])) {
                    $checkStmt = $pdo->prepare("SELECT id FROM budget_entries WHERE po_number = ?");
                    $checkStmt->execute([$dbRow['po_number']]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // Update existing - SIMPLIFIED UPDATE
                        $updateSql = "UPDATE budget_entries SET 
                            amount_requested = ?, 
                            currency = ?, 
                            entry_updated_date = ? 
                            WHERE id = ?";
                        $updateStmt = $pdo->prepare($updateSql);
                        $updateStmt->execute([
                            $dbRow['amount_requested'],
                            $dbRow['currency'],
                            $dbRow['entry_updated_date'],
                            $existing['id']
                        ]);
                        
                        $results['updated']++;
                        error_log("Updated record: " . $dbRow['po_number']);
                    } else {
                        // Insert new - SIMPLIFIED INSERT
                        $insertSql = "INSERT INTO budget_entries (po_number, amount_requested, currency, entry_creation_date, entry_updated_date) 
                                      VALUES (?, ?, ?, ?, ?)";
                        $insertStmt = $pdo->prepare($insertSql);
                        $insertStmt->execute([
                            $dbRow['po_number'],
                            $dbRow['amount_requested'],
                            $dbRow['currency'],
                            $dbRow['entry_creation_date'],
                            $dbRow['entry_updated_date']
                        ]);
                        
                        $results['imported']++;
                        error_log("Inserted new record: " . $dbRow['po_number']);
                    }
                } else {
                    $results['skipped']++;
                    $results['errors'][] = "Row $rowNumber: Missing PO number";
                    error_log("Row $rowNumber skipped: Missing PO number");
                }
                
            } catch (PDOException $e) {
                $errorMsg = "Row $rowNumber: " . $e->getMessage();
                $results['errors'][] = $errorMsg;
                $results['skipped']++;
                error_log("Database error: " . $errorMsg);
            }
        }
        
        fclose($handle);
        unlink($tempFile);
        
        $success = true;
        $message = 'Import completed successfully!';
        error_log("Import completed: " . print_r($results, true));
        
    } catch (Exception $e) {
        $success = false;
        $message = 'Error: ' . $e->getMessage();
        error_log("Import error: " . $e->getMessage());
    }
}

// Debug: Check what we have
error_log("Final state - Success: " . ($success ? 'true' : 'false'));
error_log("Message: " . $message);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Import - <?= APP_NAME ?></title>
    
    <!-- Tabler Core CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    
    <style>
        body {
            background: #f5f7fb;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
            padding-top: 80px;
        }
        
        .container {
            max-width: 1000px;
        }
        
        .upload-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
            overflow: hidden;
            background: white;
            margin-bottom: 2rem;
        }
        
        .upload-header {
            background: linear-gradient(135deg, #00a399, #00353d);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .upload-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }
        
        .upload-body {
            padding: 2rem;
        }
        
        .upload-area {
            border: 3px dashed #00a399;
            border-radius: 12px;
            padding: 3rem;
            text-align: center;
            background: rgba(0, 163, 153, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .upload-area:hover {
            background: rgba(0, 163, 153, 0.1);
            border-color: #00353d;
        }
        
        .upload-area.dragover {
            background: rgba(0, 163, 153, 0.15);
            border-color: #00a399;
            transform: scale(1.02);
        }
        
        .file-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            display: none;
        }
        
        .results-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        .stat-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2a3547;
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
        }
        
        .error-item {
            color: #e74c3c;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .preview-table {
            font-size: 0.85rem;
        }
        
        .preview-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            body {
                padding-top: 100px;
            }
            
            .upload-body {
                padding: 1rem;
            }
            
            .upload-area {
                padding: 2rem 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Simple navigation bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="ti ti-csv me-2"></i>CSV Import
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="index.php">
                    <i class="ti ti-home me-1"></i>Dashboard
                </a>
                <span class="nav-link">
                    <i class="ti ti-user me-1"></i><?= htmlspecialchars($_SESSION['display_name'] ?? 'User') ?>
                </span>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Debug info (remove in production) -->
        <?php if (isset($_GET['debug'])): ?>
        <div class="alert alert-info">
            <h5>Debug Info:</h5>
            <pre><?php 
                echo "Success: " . ($success ? 'true' : 'false') . "\n";
                echo "Message: " . $message . "\n";
                echo "Results: " . print_r($results, true) . "\n";
                echo "POST: " . print_r($_POST, true) . "\n";
                echo "FILES: " . print_r($_FILES, true) . "\n";
            ?></pre>
        </div>
        <?php endif; ?>
        
        <!-- Upload Form -->
        <?php if (!$success || ($success && isset($_GET['new']))): ?>
        <div class="upload-card">
            <div class="upload-header">
                <i class="upload-icon ti ti-cloud-upload"></i>
                <h2 class="mb-2">Import CSV File</h2>
                <p class="mb-0">Upload your budget data in CSV format</p>
            </div>
            
            <div class="upload-body">
                <?php if (!empty($message) && !$success): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="ti ti-alert-circle me-2"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <form method="post" enctype="multipart/form-data" id="uploadForm">
                    <div class="upload-area" id="uploadArea" onclick="document.getElementById('csv_file').click()">
                        <i class="ti ti-cloud-upload" style="font-size: 4rem; color: #00a399; margin-bottom: 1rem;"></i>
                        <h4 class="mb-3">Click to select CSV file</h4>
                        <p class="text-muted mb-3">or drag and drop here</p>
                        
                        <input type="file" name="csv_file" id="csv_file" 
                               class="d-none" accept=".csv" required>
                        
                        <button type="button" class="btn btn-primary btn-lg" onclick="document.getElementById('csv_file').click()">
                            <i class="ti ti-folder me-2"></i> Browse Files
                        </button>
                    </div>
                    
                    <div id="fileInfo" class="file-info">
                        <div class="d-flex align-items-center">
                            <i class="ti ti-file-text text-primary me-3 fs-3"></i>
                            <div>
                                <h6 class="mb-1" id="fileName">No file selected</h6>
                                <p class="text-muted mb-0" id="fileDetails">Select a CSV file to import</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-4">
                        <h6><i class="ti ti-info-circle me-2"></i> Import Guidelines</h6>
                        <ul class="mb-0">
                            <li>File must be in CSV format (comma-separated values)</li>
                            <li>First row should contain column headers</li>
                            <li>Required columns: <strong>PO Number, Amount, Currency</strong></li>
                            <li>Dates in DD-MM-YYYY, DD/MM/YYYY, or YYYY-MM-DD format</li>
                            <li>Existing records with matching PO numbers will be updated</li>
                        </ul>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-success btn-lg px-5" id="uploadBtn" disabled>
                            <i class="ti ti-upload me-2"></i> Start Import
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Results Display -->
        <?php if ($success && $results): ?>
        <div class="results-card">
            <div class="text-center mb-4">
                <div class="mb-3">
                    <i class="ti ti-check text-success" style="font-size: 4rem;"></i>
                </div>
                <h2 class="mb-2">Import Successful!</h2>
                <p class="text-muted mb-4">Your CSV file has been imported successfully.</p>
            </div>
            
            <!-- Statistics -->
            <div class="row mb-5">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?= $results['imported'] ?></div>
                        <div class="stat-label">New Items</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?= $results['updated'] ?></div>
                        <div class="stat-label">Updated Items</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?= $results['skipped'] ?></div>
                        <div class="stat-label">Skipped Rows</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?= $results['total_rows'] ?></div>
                        <div class="stat-label">Total Processed</div>
                    </div>
                </div>
            </div>
            
            <!-- Data Preview -->
            <?php if (!empty($results['preview'])): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="ti ti-eye me-2"></i>Data Preview
                    </h5>
                    <div class="table-responsive">
                        <table class="table table-bordered preview-table">
                            <thead>
                                <tr>
                                    <?php foreach ($results['headers'] as $header): ?>
                                        <th><?= htmlspecialchars($header) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results['preview'] as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $cell): ?>
                                            <td><?= htmlspecialchars($cell) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Errors (if any) -->
            <?php if (!empty($results['errors'])): ?>
            <div class="card mb-4 border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="card-title mb-0">
                        <i class="ti ti-alert-triangle me-2"></i>Errors (<?= count($results['errors']) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <div style="max-height: 200px; overflow-y: auto;">
                        <?php foreach (array_slice($results['errors'], 0, 10) as $error): ?>
                            <div class="error-item">• <?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                        <?php if (count($results['errors']) > 10): ?>
                            <div class="text-muted text-center mt-2">
                                ... and <?= count($results['errors']) - 10 ?> more errors
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Action Buttons -->
            <div class="text-center mt-5">
                <a href="csvclean.php?new=1" class="btn btn-primary me-3">
                    <i class="ti ti-upload me-2"></i> Import Another File
                </a>
                <a href="index.php" class="btn btn-outline-primary">
                    <i class="ti ti-home me-2"></i> Back to Dashboard
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded');
            
            // File upload handling
            const fileInput = document.getElementById('csv_file');
            const uploadArea = document.getElementById('uploadArea');
            const fileInfo = document.getElementById('fileInfo');
            const fileName = document.getElementById('fileName');
            const fileDetails = document.getElementById('fileDetails');
            const uploadBtn = document.getElementById('uploadBtn');
            
            if (fileInput) {
                console.log('File input found');
                
                // Click file input
                fileInput.addEventListener('change', function() {
                    console.log('File selected:', this.files);
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        updateFileInfo(file);
                    }
                });
                
                // Drag and drop
                if (uploadArea) {
                    uploadArea.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        uploadArea.classList.add('dragover');
                    });
                    
                    uploadArea.addEventListener('dragleave', () => {
                        uploadArea.classList.remove('dragover');
                    });
                    
                    uploadArea.addEventListener('drop', (e) => {
                        e.preventDefault();
                        uploadArea.classList.remove('dragover');
                        
                        if (e.dataTransfer.files.length) {
                            console.log('File dropped:', e.dataTransfer.files[0]);
                            fileInput.files = e.dataTransfer.files;
                            fileInput.dispatchEvent(new Event('change'));
                        }
                    });
                }
            }
            
            function updateFileInfo(file) {
                console.log('Updating file info:', file.name, file.size);
                fileName.textContent = file.name;
                fileDetails.textContent = formatBytes(file.size) + ' • CSV file';
                fileInfo.style.display = 'block';
                
                if (uploadBtn) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="ti ti-upload me-2"></i> Import ' + file.name;
                }
            }
            
            function formatBytes(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
            }
            
            // Form validation
            const form = document.getElementById('uploadForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    console.log('Form submitted');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="ti ti-loader me-2"></i> Importing...';
                    }
                });
            }
        });
    </script>
    
    <!-- Bootstrap & Tabler JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
</body>
</html>