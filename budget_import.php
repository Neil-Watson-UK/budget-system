<?php
// budget_import.php - FIXED VERSION WITH csv_clean.php LOGIC INTEGRATED
session_start();

// Force fresh start on GET request (when just loading the page)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    unset($_SESSION['import_data']);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Database configuration
require_once __DIR__ . '/config.php';

// Enable errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize variables
$importResult = null;
$importError = null;
$step = 'upload'; // Always start at upload

// Remove BOM function - IMPROVED
function removeBOM($text) {
    $bom = pack('H*', 'EFBBBF');
    $text = preg_replace("/^$bom/", '', $text);
    
    // Also try other BOM formats
    $text = str_replace("\xEF\xBB\xBF", '', $text); // UTF-8 BOM
    $text = str_replace("\xFE\xFF", '', $text); // UTF-16 BE BOM
    $text = str_replace("\xFF\xFE", '', $text); // UTF-16 LE BOM
    
    return $text;
}

// Convert date to YYYY-MM-DD - SIMPLIFIED AND MORE ROBUST
function convertToMySQLDate($dateString) {
    if (empty($dateString) || trim($dateString) === '') {
        return null;
    }
    
    $dateString = trim($dateString);
    
    // First try common European formats
    $formatsToTry = [
        'd-m-Y', 'd/m/Y', 'd.m.Y',  // European formats
        'Y-m-d', 'Y/m/d', 'Y.m.d',  // ISO formats
        'm-d-Y', 'm/d/Y', 'm.d.Y',  // US formats
        'd M Y', 'j M Y', 'd F Y', 'j F Y' // Text formats
    ];
    
    foreach ($formatsToTry as $format) {
        $date = DateTime::createFromFormat($format, $dateString);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }
    
    // Try strtotime as fallback
    $timestamp = strtotime(str_replace('/', '-', $dateString));
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    return null;
}

// Clean amount function - IMPROVED FROM csv_clean.php
function cleanAmount($amount) {
    if (empty($amount) && $amount !== '0' && $amount !== 0) {
        return null;
    }
    
    // Convert to string if not already
    $amount = (string)$amount;
    
    // Remove all currency symbols and whitespace
    $amount = str_replace(['$', '€', '£', '¥', '₹', '₽', ' ', ' ', chr(160), '\'', '"'], '', $amount);
    
    // Remove thousands separators (comma or dot depending on format)
    if (strpos($amount, ',') !== false && strpos($amount, '.') !== false) {
        // Has both comma and dot - assume comma is thousands, dot is decimal
        $amount = str_replace(',', '', $amount);
    } elseif (strpos($amount, ',') !== false && strpos($amount, '.') === false) {
        // Only comma - check if it's likely a decimal separator
        $lastCommaPos = strrpos($amount, ',');
        $afterComma = substr($amount, $lastCommaPos + 1);
        
        if (strlen($afterComma) === 2) {
            // Likely decimal separator (e.g., 123,45)
            $amount = str_replace(',', '.', $amount);
        } else {
            // Likely thousands separator (e.g., 1,234)
            $amount = str_replace(',', '', $amount);
        }
    }
    
    // Remove any remaining non-numeric characters except minus sign and dot
    $amount = preg_replace('/[^0-9\.\-]/', '', $amount);
    
    // Check if empty or invalid
    if ($amount === '' || $amount === '-') {
        return null;
    }
    
    // Convert to float
    $floatAmount = floatval($amount);
    
    return $floatAmount;
}

// Smart column name matching - SIMPLIFIED
function matchColumnName($header, $targets) {
    $header = strtolower(trim($header));
    
    // Remove common prefixes
    $header = preg_replace('/^(epos\.|budget\.|system\.|csv\.)/i', '', $header);
    
    foreach ($targets as $target) {
        $target = strtolower(trim($target));
        
        // Exact match
        if ($header === $target) {
            return true;
        }
        
        // Contains match
        if (strpos($header, $target) !== false) {
            return true;
        }
        
        // Common abbreviations
        $abbreviations = [
            'amount' => ['amt', 'total', 'sum', 'value', 'cost', 'price'],
            'currency' => ['curr', 'ccy', 'mon'],
            'region' => ['area', 'zone', 'loc'],
            'po' => ['purchase order', 'pono', 'order'],
            'date' => ['dt', 'day'],
            'start' => ['begin', 'from'],
            'end' => ['finish', 'to', 'until'],
            'vendor' => ['supplier', 'provider', 'company'],
            'title' => ['name', 'desc', 'description']
        ];
        
        foreach ($abbreviations as $key => $abbrList) {
            if ($target === $key || in_array($target, $abbrList)) {
                foreach ($abbrList as $abbr) {
                    if (strpos($header, $abbr) !== false) {
                        return true;
                    }
                }
            }
        }
    }
    
    return false;
}

// Handle Step 1: Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'upload') {
    try {
        // Clear any old session data
        unset($_SESSION['import_data']);
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Please select a valid CSV file.');
        }
        
        $fileInfo = $_FILES['csv_file'];
        
        // Validate file type
        $fileExt = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        if ($fileExt !== 'csv') {
            throw new Exception('Please upload a CSV file.');
        }
        
        // Read the entire CSV content
        $csvContent = file_get_contents($fileInfo['tmp_name']);
        if ($csvContent === false) {
            throw new Exception('Cannot read uploaded file.');
        }
        
        // Remove BOM if present
        $csvContent = removeBOM($csvContent);
        
        // Convert to UTF-8 if needed
        if (!mb_check_encoding($csvContent, 'UTF-8')) {
            $csvContent = mb_convert_encoding($csvContent, 'UTF-8', 'auto');
        }
        
        // Parse CSV into lines
        $lines = explode("\n", $csvContent);
        if (empty($lines)) {
            throw new Exception('CSV file is empty.');
        }
        
        // Parse headers
        $firstLine = trim($lines[0]);
        if (empty($firstLine)) {
            throw new Exception('First line (headers) is empty.');
        }
        
        $headers = str_getcsv($firstLine);
        if (empty($headers)) {
            throw new Exception('Cannot parse CSV headers.');
        }
        
        // Clean headers
        $cleanHeaders = [];
        foreach ($headers as $header) {
            $header = trim($header, " \t\n\r\0\x0B\"'");
            // Remove common prefixes
            $header = preg_replace('/^(epos\.|budget\.|system\.)/i', '', $header);
            $cleanHeaders[] = $header;
        }
        
        // Parse preview rows (first 5 rows after header)
        $previewRows = [];
        for ($i = 1; $i < min(6, count($lines)); $i++) {
            if (!empty(trim($lines[$i]))) {
                $row = str_getcsv($lines[$i]);
                // Pad row if needed
                if (count($row) < count($cleanHeaders)) {
                    $row = array_pad($row, count($cleanHeaders), '');
                }
                $previewRows[] = $row;
            }
        }
        
        // Store in session
        $_SESSION['import_data'] = [
            'csv_content' => $csvContent,
            'headers' => $cleanHeaders,
            'preview_rows' => $previewRows,
            'file_name' => $fileInfo['name'],
            'total_lines' => count($lines) - 1
        ];
        
        $step = 'map';
        
    } catch (Exception $e) {
        $importError = $e->getMessage();
        $step = 'upload';
    }
}

// Handle Step 2: Process Import - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'process') {
    try {
        if (!isset($_SESSION['import_data'])) {
            throw new Exception('Session expired. Please upload the file again.');
        }
        
        $importData = $_SESSION['import_data'];
        $csvContent = $importData['csv_content'];
        $headers = $importData['headers'];
        
        // Get user mappings with defaults
        $amountColumn = $_POST['amount_column'] ?? '';
        $currencyColumn = $_POST['currency_column'] ?? '';
        $poColumn = $_POST['po_column'] ?? 'AUTO_GENERATE';
        $regionColumn = $_POST['region_column'] ?? '';
        
        if (empty($amountColumn) || empty($currencyColumn)) {
            throw new Exception('Please map Amount and Currency columns.');
        }
        
        // Auto-generate PO if selected
        $autoGeneratePO = ($poColumn === 'AUTO_GENERATE');
        
        // Connect to database
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Parse CSV lines
        $lines = explode("\n", $csvContent);
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $generatedPOs = [];
        
        // Optional columns mapping
        $startDateColumn = $_POST['start_date_column'] ?? '';
        $endDateColumn = $_POST['end_date_column'] ?? '';
        $invoicedDateColumn = $_POST['invoiced_date_column'] ?? '';
        $statusColumn = $_POST['status_column'] ?? '';
        
        // Map additional optional columns from form
        $optionalColumns = [
            'activity_title' => $_POST['activity_title_column'] ?? '',
            'vendor' => $_POST['vendor_column'] ?? '',
            'budget_category' => $_POST['budget_category_column'] ?? '',
            'country' => $_POST['country_column'] ?? '',
        ];
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;
            
            $rowNum = $i + 1; // Human-readable row number
            $row = str_getcsv($line);
            
            // Pad row if needed
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            } elseif (count($row) > count($headers)) {
                // Trim excess columns
                $row = array_slice($row, 0, count($headers));
            }
            
            $rowData = array_combine($headers, $row);
            
            // Extract required values
            $rawAmount = trim($rowData[$amountColumn] ?? '');
            $currency = trim($rowData[$currencyColumn] ?? '');
            $poNumber = $autoGeneratePO ? '' : trim($rowData[$poColumn] ?? '');
            $region = !empty($regionColumn) ? trim($rowData[$regionColumn] ?? '') : '';
            
            // Clean and validate amount
            $amount = cleanAmount($rawAmount);
            if ($amount === null) {
                $errors[] = "Row $rowNum: Invalid amount format: " . htmlspecialchars($rawAmount);
                $skipped++;
                continue;
            }
            
            // Validate currency
            if (empty($currency)) {
                $errors[] = "Row $rowNum: Missing currency";
                $skipped++;
                continue;
            }
            
            // Generate PO number if needed
            if (empty($poNumber)) {
                $poPrefix = 'PO';
                if (!empty($region)) {
                    $poPrefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $region), 0, 3)) . '-PO';
                }
                
                // Find next available PO number
                $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(po_number, LENGTH(?) + 1) AS UNSIGNED)) as max_num 
                                       FROM budget_items WHERE po_number LIKE ?");
                $likePattern = $poPrefix . '%';
                $stmt->execute([$poPrefix . '-', $likePattern . '%']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $nextNum = ($result['max_num'] ?? 0) + 1;
                $poNumber = $poPrefix . '-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
                $generatedPOs[] = $poNumber;
            }
            
            // Prepare database data
            $dbData = [
                'po_number' => $poNumber,
                'amount_requested' => $amount,
                'currency' => strtoupper($currency),
                'region' => $region ?: null,
                'entry_creation_date' => date('Y-m-d H:i:s'),
                'entry_updated_date' => date('Y-m-d H:i:s')
            ];
            
            // Add optional date columns
            if (!empty($startDateColumn) && !empty(trim($rowData[$startDateColumn] ?? ''))) {
                $dbData['start_date'] = convertToMySQLDate(trim($rowData[$startDateColumn]));
            }
            
            if (!empty($endDateColumn) && !empty(trim($rowData[$endDateColumn] ?? ''))) {
                $dbData['end_date'] = convertToMySQLDate(trim($rowData[$endDateColumn]));
            }
            
            if (!empty($invoicedDateColumn) && !empty(trim($rowData[$invoicedDateColumn] ?? ''))) {
                $dbData['invoiced_date'] = convertToMySQLDate(trim($rowData[$invoicedDateColumn]));
            }
            
            // Add other optional columns
            if (!empty($statusColumn) && !empty(trim($rowData[$statusColumn] ?? ''))) {
                $dbData['status'] = trim($rowData[$statusColumn]);
            } else {
                $dbData['status'] = 'Planned'; // Default
            }
            
            // Map other optional columns
            foreach ($optionalColumns as $dbCol => $csvCol) {
                if (!empty($csvCol) && !empty(trim($rowData[$csvCol] ?? ''))) {
                    $dbData[$dbCol] = trim($rowData[$csvCol]);
                }
            }
            
            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT id FROM budget_items WHERE po_number = ?");
            $checkStmt->execute([$poNumber]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            try {
                if ($existing) {
                    // Update existing record
                    $setParts = [];
                    foreach ($dbData as $col => $val) {
                        if ($col !== 'entry_creation_date') {
                            $setParts[] = "$col = :$col";
                        }
                    }
                    
                    $updateSql = "UPDATE budget_items SET " . implode(', ', $setParts) . " WHERE po_number = :po_number";
                    $stmt = $pdo->prepare($updateSql);
                    
                    foreach ($dbData as $col => $val) {
                        if ($col !== 'entry_creation_date') {
                            $stmt->bindValue(":$col", $val);
                        }
                    }
                    
                    $stmt->execute();
                    $updated++;
                } else {
                    // Insert new record
                    $columns = implode(', ', array_keys($dbData));
                    $placeholders = ':' . implode(', :', array_keys($dbData));
                    
                    $insertSql = "INSERT INTO budget_items ($columns) VALUES ($placeholders)";
                    $stmt = $pdo->prepare($insertSql);
                    
                    foreach ($dbData as $col => $val) {
                        $stmt->bindValue(":$col", $val);
                    }
                    
                    $stmt->execute();
                    $imported++;
                }
                
            } catch (PDOException $e) {
                $errors[] = "Row $rowNum: Error with PO $poNumber: " . $e->getMessage();
                $skipped++;
            }
        }
        
        // Clear session data
        unset($_SESSION['import_data']);
        
        // Prepare results
        $importResult = [
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'generated_pos' => array_slice($generatedPOs, 0, 20), // Show first 20 only
            'total_rows' => $importData['total_lines']
        ];
        
        $step = 'results';
        
    } catch (Exception $e) {
        $importError = $e->getMessage();
        $step = 'map'; // Go back to mapping if error
    }
}

// Check if we have session data for mapping
if (isset($_SESSION['import_data']) && empty($importResult) && empty($importError)) {
    $step = 'map';
    $importData = $_SESSION['import_data'];
    $csvHeaders = $importData['headers'];
    $csvPreview = $importData['preview_rows'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import CSV - Budget System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    
    <style>
        .upload-area {
            border: 3px dashed #00a399;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            background: rgba(0, 163, 153, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            background: rgba(0, 163, 153, 0.1);
            border-color: #00353d;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }
        
        .step {
            text-align: center;
            padding: 0 1rem;
            position: relative;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e1e5eb;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
        }
        
        .step.active .step-circle {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white;
        }
        
        .step.completed .step-circle {
            background: #27ae60;
            color: white;
        }
        
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
            margin: 10px 0;
            font-family: monospace;
            font-size: 12px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .date-format-note {
            background: #e8f4fd;
            border-left: 4px solid #0d6efd;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
        }
        
        .auto-match {
            color: #28a745;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>
    
    <div class="page-wrapper">
        <main class="page-content">
            <header class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h3 mb-0">Import CSV</h1>
                        <p class="text-muted mb-0">Upload and process budget data</p>
                    </div>
                    <?php if ($step === 'map'): ?>
                    <a href="budget_import.php?reset=1" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-refresh me-1"></i> Start Over
                    </a>
                    <?php endif; ?>
                </div>
            </header>
            
            <div class="page-body">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step <?= $step === 'upload' ? 'active' : ($step === 'map' || $step === 'results' ? 'completed' : '') ?>">
                        <div class="step-circle">1</div>
                        <div class="small">Upload</div>
                    </div>
                    <div class="step <?= $step === 'map' ? 'active' : ($step === 'results' ? 'completed' : '') ?>">
                        <div class="step-circle">2</div>
                        <div class="small">Map Columns</div>
                    </div>
                    <div class="step <?= $step === 'results' ? 'active' : '' ?>">
                        <div class="step-circle">3</div>
                        <div class="small">Results</div>
                    </div>
                </div>
                
                <!-- Error Display -->
                <?php if ($importError): ?>
                <div class="alert alert-danger">
                    <div class="d-flex align-items-center">
                        <i class="ti ti-alert-circle fs-3 me-3"></i>
                        <div>
                            <h4 class="mb-1">Error</h4>
                            <p class="mb-0"><?= htmlspecialchars($importError) ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- STEP 1: Upload -->
                <?php if ($step === 'upload'): ?>
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-primary text-white">
                                <h3 class="card-title mb-0">
                                    <i class="ti ti-upload me-2"></i>
                                    Step 1: Upload CSV File
                                </h3>
                            </div>
                            
                            <div class="card-body">
                                <form method="post" enctype="multipart/form-data">
                                    <input type="hidden" name="step" value="upload">
                                    
                                    <div class="upload-area" onclick="document.getElementById('csv_file').click()">
                                        <i class="ti ti-cloud-upload fs-1 text-primary mb-3"></i>
                                        <h4>Click to select CSV file</h4>
                                        <p class="text-muted">or drag and drop here</p>
                                        
                                        <input type="file" name="csv_file" id="csv_file" 
                                               class="d-none" accept=".csv" required>
                                        
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-primary" 
                                                    onclick="document.getElementById('csv_file').click()">
                                                <i class="ti ti-folder me-1"></i> Browse Files
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div id="fileInfo" class="mt-3" style="display: none;">
                                        <div class="alert alert-info">
                                            <i class="ti ti-file me-2"></i>
                                            <span id="fileName"></span>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info mt-3">
                                        <h6><i class="ti ti-info-circle me-2"></i> Important Notes</h6>
                                        <ul class="mb-0">
                                            <li>File must be in CSV format</li>
                                            <li>First row should contain column headers</li>
                                            <li>Dates can be in DD-MM-YYYY, DD/MM/YYYY, or YYYY-MM-DD format</li>
                                            <li>Amounts can have commas or dots as decimal separators</li>
                                            <li>Currency symbols ($, €, £) are automatically removed</li>
                                        </ul>
                                    </div>
                                    
                                    <div class="text-center mt-4">
                                        <button type="submit" class="btn btn-primary btn-lg" id="uploadBtn" disabled>
                                            <i class="ti ti-arrow-right me-2"></i>
                                            Continue to Column Mapping
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- STEP 2: Mapping -->
                <?php if ($step === 'map'): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-success text-white">
                                <h3 class="mb-2">
                                    <i class="ti ti-columns me-2"></i>
                                    Step 2: Map CSV Columns
                                </h3>
                                <p class="mb-0">File: <?= htmlspecialchars($importData['file_name'] ?? '') ?> 
                                (<?= $importData['total_lines'] ?? 0 ?> rows)</p>
                            </div>
                            
                            <div class="card-body">
                                <!-- CSV Preview -->
                                <div class="mb-4">
                                    <h5>CSV Preview (first <?= count($csvPreview) ?> rows)</h5>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm">
                                            <thead class="table-light">
                                                <tr>
                                                    <?php foreach ($csvHeaders as $header): ?>
                                                        <th><?= htmlspecialchars($header) ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($csvPreview as $row): ?>
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
                                
                                <!-- Mapping Form -->
                                <form method="post">
                                    <input type="hidden" name="step" value="process">
                                    
                                    <h5>Required Columns</h5>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Amount Requested *</label>
                                            <select name="amount_column" class="form-select" required>
                                                <option value="">-- Select Column --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Amount', 'Amount Requested', 'Total', 'Cost', 'Price', 'Value']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Currency *</label>
                                            <select name="currency_column" class="form-select" required>
                                                <option value="">-- Select Column --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Currency', 'Curr', 'CCY']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mt-4">Optional Columns</h5>
                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">PO Number</label>
                                            <select name="po_column" class="form-select">
                                                <option value="AUTO_GENERATE" selected>⚡ Auto-generate PO numbers</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['PO Number', 'PO', 'Purchase Order']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">Leave as "Auto-generate" to create PO numbers automatically</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Region</label>
                                            <select name="region_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Region', 'Area', 'Zone']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Status</label>
                                            <select name="status_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Status']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-4">
                                        <div class="col-md-3">
                                            <label class="form-label">Activity Title</label>
                                            <select name="activity_title_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Activity Title', 'Title', 'Description']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Vendor</label>
                                            <select name="vendor_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Vendor', 'Supplier']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Budget Category</label>
                                            <select name="budget_category_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Budget Category', 'Category', 'Type']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Country</label>
                                            <select name="country_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Country', 'Nation']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <h6 class="mt-4">Date Columns</h6>
                                    <div class="row mb-4">
                                        <div class="col-md-4">
                                            <label class="form-label">Start Date</label>
                                            <select name="start_date_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Start Date', 'Start', 'From Date']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">End Date</label>
                                            <select name="end_date_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['End Date', 'End', 'To Date']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Invoiced Date</label>
                                            <select name="invoiced_date_column" class="form-select">
                                                <option value="">-- Not Required --</option>
                                                <?php foreach ($csvHeaders as $header): ?>
                                                    <?php 
                                                    $isMatch = matchColumnName($header, ['Invoiced Date', 'Invoice Date']);
                                                    $selected = $isMatch ? 'selected' : '';
                                                    ?>
                                                    <option value="<?= htmlspecialchars($header) ?>" <?= $selected ?>>
                                                        <?= htmlspecialchars($header) ?>
                                                        <?= $isMatch ? '<span class="auto-match"> ✓ Auto-detected</span>' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <h6><i class="ti ti-info-circle me-2"></i> Import Notes</h6>
                                        <ul class="mb-0">
                                            <li>Only <strong>Amount</strong> and <strong>Currency</strong> are required</li>
                                            <li>PO numbers will be auto-generated if not mapped</li>
                                            <li>Dates in any common format will be converted automatically</li>
                                            <li>Existing records with matching PO numbers will be updated</li>
                                        </ul>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mt-4">
                                        <a href="budget_import.php" class="btn btn-outline-secondary">
                                            <i class="ti ti-arrow-left me-1"></i> Upload Different File
                                        </a>
                                        
                                        <button type="submit" class="btn btn-success">
                                            <i class="ti ti-play me-1"></i> Start Import
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- STEP 3: Results -->
                <?php if ($step === 'results' && $importResult): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-success text-white">
                                <h3 class="mb-0">
                                    <i class="ti ti-check me-2"></i>
                                    Import Completed Successfully!
                                </h3>
                            </div>
                            
                            <div class="card-body">
                                <div class="row mb-4">
                                    <div class="col-md-3 text-center">
                                        <div class="fs-2 fw-bold text-success"><?= $importResult['imported'] ?></div>
                                        <div class="text-muted">New Items</div>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <div class="fs-2 fw-bold text-info"><?= $importResult['updated'] ?></div>
                                        <div class="text-muted">Updated Items</div>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <div class="fs-2 fw-bold text-warning"><?= $importResult['skipped'] ?></div>
                                        <div class="text-muted">Skipped Rows</div>
                                    </div>
                                    <div class="col-md-3 text-center">
                                        <div class="fs-2 fw-bold text-primary"><?= $importResult['total_rows'] ?></div>
                                        <div class="text-muted">Total Processed</div>
                                    </div>
                                </div>
                                
                                <?php if (!empty($importResult['generated_pos'])): ?>
                                <div class="mb-4">
                                    <h5><i class="ti ti-sparkles me-2"></i> Generated PO Numbers (first 20)</h5>
                                    <div class="bg-light p-3 rounded">
                                        <?php foreach ($importResult['generated_pos'] as $po): ?>
                                            <span class="badge bg-white text-dark border me-1 mb-1"><?= htmlspecialchars($po) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($importResult['errors'])): ?>
                                <div class="mb-4">
                                    <h5 class="text-danger"><i class="ti ti-alert-triangle me-2"></i> Errors (<?= count($importResult['errors']) ?>)</h5>
                                    <div class="bg-light p-3 rounded" style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach (array_slice($importResult['errors'], 0, 20) as $error): ?>
                                            <div class="text-danger mb-1 small">• <?= htmlspecialchars($error) ?></div>
                                        <?php endforeach; ?>
                                        <?php if (count($importResult['errors']) > 20): ?>
                                            <div class="text-muted small">... and <?= count($importResult['errors']) - 20 ?> more errors</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="text-center">
                                    <a href="budget_import.php" class="btn btn-primary me-2">
                                        <i class="ti ti-upload me-1"></i> Import Another File
                                    </a>
                                    <a href="index.php" class="btn btn-outline-primary">
                                        <i class="ti ti-home me-1"></i> Back to Dashboard
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    
    <!-- JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // File upload handling
            const fileInput = document.getElementById('csv_file');
            const fileInfo = document.getElementById('fileInfo');
            const fileName = document.getElementById('fileName');
            const uploadBtn = document.getElementById('uploadBtn');
            
            if (fileInput) {
                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        fileName.textContent = file.name + ' (' + formatBytes(file.size) + ')';
                        fileInfo.style.display = 'block';
                        if (uploadBtn) uploadBtn.disabled = false;
                    }
                });
                
                // Drag and drop
                const uploadArea = document.querySelector('.upload-area');
                
                if (uploadArea) {
                    uploadArea.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        uploadArea.style.background = 'rgba(0, 163, 153, 0.1)';
                    });
                    
                    uploadArea.addEventListener('dragleave', () => {
                        uploadArea.style.background = 'rgba(0, 163, 153, 0.05)';
                    });
                    
                    uploadArea.addEventListener('drop', (e) => {
                        e.preventDefault();
                        uploadArea.style.background = 'rgba(0, 163, 153, 0.05)';
                        
                        if (e.dataTransfer.files.length) {
                            fileInput.files = e.dataTransfer.files;
                            fileInput.dispatchEvent(new Event('change'));
                        }
                    });
                }
            }
            
            function formatBytes(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
        });
    </script>
</body>
</html>