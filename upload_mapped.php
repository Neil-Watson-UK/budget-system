<?php
// budget_import_mapped.php - Single file, no redirects
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'cmmbudget');
define('DB_USER', 'budgetadmin');
define('DB_PASS', 'NotReevesP13453');

// Enable errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize
$step = 1; // 1=upload, 2=preview/map, 3=import
$debugInfo = [];
$filePath = '';
$csvHeaders = [];
$previewData = [];
$mapping = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // STEP 1: File upload
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        // Save uploaded file
        $tempDir = sys_get_temp_dir();
        $filePath = $tempDir . '/upload_' . uniqid() . '.csv';
        move_uploaded_file($_FILES['csv_file']['tmp_name'], $filePath);
        
        // Read CSV for preview
        $handle = fopen($filePath, 'r');
        if ($handle) {
            // Read headers
            $csvHeaders = fgetcsv($handle);
            if ($csvHeaders !== false) {
                // Remove BOM
                if (isset($csvHeaders[0]) && substr($csvHeaders[0], 0, 3) == "\xEF\xBB\xBF") {
                    $csvHeaders[0] = substr($csvHeaders[0], 3);
                }
                
                // Clean headers (remove quotes)
                foreach ($csvHeaders as $i => $header) {
                    $csvHeaders[$i] = trim($header, '"\' ');
                }
                
                // Read first 5 rows for preview
                $previewData = [];
                $rowCount = 0;
                while (($row = fgetcsv($handle)) !== false && $rowCount < 5) {
                    $previewData[] = $row;
                    $rowCount++;
                }
                
                fclose($handle);
                $step = 2; // Go to mapping step
                
                // Auto-suggest mapping based on headers
                $mapping = autoSuggestMapping($csvHeaders);
            }
        }
    }
    
    // STEP 2: Process mapping and import
    elseif (isset($_POST['mapping']) && isset($_POST['file_path'])) {
        $filePath = $_POST['file_path'];
        $mapping = $_POST['mapping'];
        $skipDuplicates = isset($_POST['skip_duplicates']);
        
        if (file_exists($filePath)) {
            // Do the import
            $result = importCSV($filePath, $mapping, $skipDuplicates);
            $step = 3; // Show results
        }
    }
}

// Auto-suggest mapping function
function autoSuggestMapping($headers) {
    $suggestions = [];
    
    // Common header patterns
    $patterns = [
        '/po.?number/i' => 'po_number',
        '/region/i' => 'region',
        '/country/i' => 'country',
        '/amount/i' => 'amount_requested',
        '/currency/i' => 'currency',
        '/activity.?title/i' => 'activity_title',
        '/status/i' => 'status',
        '/start.?date/i' => 'start_date',
        '/end.?date/i' => 'end_date',
        '/vendor/i' => 'vendor',
        '/comments/i' => 'comments',
        '/department/i' => 'department',
        '/frequency/i' => 'frequency_of_spend',
        '/account/i' => 'account',
        '/budget.?category/i' => 'budget_category',
        '/activity.?description/i' => 'activity_description',
        '/associated/i' => 'associated_epos_staff',
        '/project.?code/i' => 'project_code',
        '/item.?type/i' => 'item_type',
        '/is.?global/i' => 'is_global',
        '/local.?po/i' => 'local_po_reference',
        '/entry.?creation/i' => 'entry_creation_date',
        '/entry.?updated/i' => 'entry_updated_date',
        '/invoiced/i' => 'invoiced_date',
        '/po.?prefix/i' => 'po_prefix',
        '/path/i' => 'path'
    ];
    
    foreach ($headers as $index => $header) {
        $headerLower = strtolower($header);
        $matched = false;
        
        foreach ($patterns as $pattern => $dbField) {
            if (preg_match($pattern, $headerLower)) {
                $suggestions[$dbField] = $index;
                $matched = true;
                break;
            }
        }
        
        // Special handling for epos. prefixed headers
        if (!$matched && strpos($headerLower, 'epos.') !== false) {
            $cleanHeader = str_replace('epos.', '', $headerLower);
            foreach ($patterns as $pattern => $dbField) {
                if (preg_match($pattern, $cleanHeader)) {
                    $suggestions[$dbField] = $index;
                    break;
                }
            }
        }
    }
    
    return $suggestions;
}

// Import function
function importCSV($filePath, $mapping, $skipDuplicates) {
    global $debugInfo;
    
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'error' => 'Cannot open file'];
        }
        
        // Skip header row
        fgetcsv($handle);
        
        $imported = 0;
        $updated = 0;
        $errors = [];
        $rowNum = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Prepare database data from mapping
            $dbData = [];
            foreach ($mapping as $dbField => $csvIndex) {
                if ($csvIndex !== '' && isset($row[$csvIndex])) {
                    $value = trim($row[$csvIndex]);
                    
                    // Data cleansing based on field type
                    switch ($dbField) {
                        case 'amount_requested':
                            $value = str_replace(['$', '€', '£', ',', ' '], '', $value);
                            $value = is_numeric($value) ? floatval($value) : 0;
                            break;
                            
                        case 'start_date':
                        case 'end_date':
                        case 'invoiced_date':
                            if (!empty($value)) {
                                $date = DateTime::createFromFormat('d/m/Y', $value);
                                $value = $date ? $date->format('Y-m-d') : null;
                            }
                            break;
                            
                        case 'currency':
                            $value = strtoupper($value);
                            break;
                            
                        case 'region':
                        case 'country':
                            $value = ucwords(strtolower($value));
                            break;
                            
                        case 'is_global':
                            $value = in_array(strtolower($value), ['yes', 'true', '1', 'y']) ? 1 : 0;
                            break;
                    }
                    
                    $dbData[$dbField] = $value !== '' ? $value : null;
                }
            }
            
            // Skip if no PO number
            if (empty($dbData['po_number'])) {
                $errors[] = "Row $rowNum: No PO Number";
                continue;
            }
            
            try {
                // Check if exists
                $checkStmt = $pdo->prepare("SELECT id FROM budget_items WHERE po_number = ?");
                $checkStmt->execute([$dbData['po_number']]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    if ($skipDuplicates) {
                        continue;
                    }
                    
                    // Update
                    $setParts = [];
                    foreach ($dbData as $col => $val) {
                        $setParts[] = "$col = :$col";
                    }
                    $setParts[] = "entry_updated_date = NOW()";
                    
                    $updateSql = "UPDATE budget_items SET " . implode(', ', $setParts) . " WHERE po_number = :po_number";
                    $stmt = $pdo->prepare($updateSql);
                    
                    foreach ($dbData as $col => $val) {
                        $stmt->bindValue(":$col", $val);
                    }
                    $stmt->bindValue(":po_number", $dbData['po_number']);
                    
                    $stmt->execute();
                    $updated++;
                } else {
                    // Insert
                    $dbData['entry_creation_date'] = date('Y-m-d H:i:s');
                    $dbData['entry_updated_date'] = date('Y-m-d H:i:s');
                    
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
                $errors[] = "Row $rowNum: " . $e->getMessage();
            }
        }
        
        fclose($handle);
        
        // Clean up temp file
        unlink($filePath);
        
        return [
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
            'total_rows' => $rowNum
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Budget Import with Column Mapping</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .step-indicator { display: flex; margin-bottom: 30px; }
        .step { flex: 1; text-align: center; padding: 15px; border-bottom: 3px solid #ddd; }
        .step.active { border-color: #007bff; font-weight: bold; color: #007bff; }
        .form-group { margin-bottom: 25px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; }
        input[type="file"] { padding: 12px; border: 2px solid #ddd; width: 100%; }
        button, .btn { padding: 12px 25px; background: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
        button:hover, .btn:hover { opacity: 0.9; }
        .btn-secondary { background: #6c757d; }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 20px; border-radius: 5px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f8f9fa; }
        .mapping-row { margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 5px; }
        .required { color: #dc3545; }
        .auto-match { color: #28a745; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Budget Import with Column Mapping</h1>
        
        <div class="step-indicator">
            <div class="step <?php echo $step == 1 ? 'active' : ''; ?>">1. Upload CSV</div>
            <div class="step <?php echo $step == 2 ? 'active' : ''; ?>">2. Map Columns</div>
            <div class="step <?php echo $step == 3 ? 'active' : ''; ?>">3. Import Results</div>
        </div>
        
        <?php if ($step == 1): ?>
            <!-- STEP 1: Upload Form -->
            <div class="info">
                <h3>Upload CSV File</h3>
                <p>The system will automatically detect column mappings. You can adjust them in the next step.</p>
            </div>
            
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="csv_file">Select CSV File:</label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                </div>
                
                <button type="submit">Upload & Preview</button>
            </form>
            
        <?php elseif ($step == 2): ?>
            <!-- STEP 2: Column Mapping -->
            <div class="info">
                <h3>Map CSV Columns to Database Fields</h3>
                <p>Review the auto-detected mappings below. You can change any mapping before importing.</p>
            </div>
            
            <!-- CSV Preview -->
            <h4>CSV Preview (First 5 rows)</h4>
            <table>
                <thead>
                    <tr>
                        <?php foreach ($csvHeaders as $header): ?>
                            <th><?php echo htmlspecialchars($header); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewData as $row): ?>
                        <tr>
                            <?php foreach ($row as $cell): ?>
                                <td><?php echo htmlspecialchars($cell); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Mapping Form -->
            <form method="post">
                <input type="hidden" name="file_path" value="<?php echo htmlspecialchars($filePath); ?>">
                
                <h4>Column Mapping</h4>
                
                <?php
                // Database fields to map to
                $dbFields = [
                    'po_number' => ['label' => 'PO Number', 'required' => true],
                    'po_prefix' => ['label' => 'PO Prefix', 'required' => false],
                    'region' => ['label' => 'Region', 'required' => true],
                    'country' => ['label' => 'Country', 'required' => true],
                    'start_date' => ['label' => 'Start Date', 'required' => false],
                    'end_date' => ['label' => 'End Date', 'required' => false],
                    'invoiced_date' => ['label' => 'Invoiced Date', 'required' => false],
                    'amount_requested' => ['label' => 'Amount Requested', 'required' => true],
                    'currency' => ['label' => 'Currency', 'required' => true],
                    'activity_title' => ['label' => 'Activity Title', 'required' => false],
                    'status' => ['label' => 'Status', 'required' => true],
                    'frequency_of_spend' => ['label' => 'Frequency of Spend', 'required' => false],
                    'vendor' => ['label' => 'Vendor', 'required' => false],
                    'external_vendor' => ['label' => 'External Vendor', 'required' => false],
                    'vendor_contact' => ['label' => 'Vendor Contact', 'required' => false],
                    'account' => ['label' => 'Account', 'required' => false],
                    'sub_account' => ['label' => 'Sub Account', 'required' => false],
                    'budget_category' => ['label' => 'Budget Category', 'required' => false],
                    'activity_description' => ['label' => 'Activity Description', 'required' => false],
                    'comments' => ['label' => 'Comments', 'required' => false],
                    'associated_epos_staff' => ['label' => 'Associated EPOS Staff', 'required' => false],
                    'department' => ['label' => 'Department', 'required' => false],
                    'project_code' => ['label' => 'Project Code', 'required' => false],
                    'item_type' => ['label' => 'Item Type', 'required' => false],
                    'path' => ['label' => 'Path', 'required' => false],
                    'is_global' => ['label' => 'Is Global', 'required' => false],
                    'local_po_reference' => ['label' => 'Local PO Reference', 'required' => false],
                ];
                ?>
                
                <?php foreach ($dbFields as $dbField => $fieldInfo): ?>
                    <div class="mapping-row">
                        <label>
                            <?php echo htmlspecialchars($fieldInfo['label']); ?>
                            <?php if ($fieldInfo['required']): ?><span class="required">*</span><?php endif; ?>
                        </label>
                        
                        <select name="mapping[<?php echo $dbField; ?>]" style="width: 100%; padding: 8px;">
                            <option value="">-- Don't import --</option>
                            <?php foreach ($csvHeaders as $index => $header): ?>
                                <?php 
                                $selected = '';
                                if (isset($mapping[$dbField]) && $mapping[$dbField] == $index) {
                                    $selected = 'selected';
                                    $autoMatch = true;
                                } elseif (stripos($header, $dbField) !== false || 
                                         stripos($header, str_replace('_', ' ', $dbField)) !== false) {
                                    $selected = 'selected';
                                    $autoMatch = true;
                                }
                                ?>
                                <option value="<?php echo $index; ?>" <?php echo $selected; ?>>
                                    Column <?php echo $index + 1; ?>: <?php echo htmlspecialchars($header); ?>
                                    <?php if (isset($autoMatch) && $autoMatch): ?>
                                        <span class="auto-match">(Auto-detected)</span>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="skip_duplicates" value="1" checked>
                        Skip duplicate records (based on PO Number)
                    </label>
                </div>
                
                <div style="margin-top: 30px;">
                    <button type="submit" name="import" value="1">Import Data</button>
                    <a href="?reset=1" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            
        <?php elseif ($step == 3 && isset($result)): ?>
            <!-- STEP 3: Results -->
            <?php if ($result['success']): ?>
                <div class="success">
                    <h2>✅ Import Completed Successfully!</h2>
                    
                    <div style="margin: 20px 0; padding: 20px; background: #e9ecef; border-radius: 5px;">
                        <h3>Import Summary</h3>
                        <ul>
                            <li><strong>New records imported:</strong> <?php echo $result['imported']; ?></li>
                            <li><strong>Existing records updated:</strong> <?php echo $result['updated']; ?></li>
                            <li><strong>Total rows processed:</strong> <?php echo $result['total_rows']; ?></li>
                            <li><strong>Errors:</strong> <?php echo count($result['errors']); ?></li>
                        </ul>
                        
                        <?php if (!empty($result['errors'])): ?>
                            <div style="background: #f8d7da; padding: 15px; border-radius: 4px; margin-top: 15px;">
                                <h4>Errors encountered:</h4>
                                <ul>
                                    <?php foreach ($result['errors'] as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="error">
                    <h2>❌ Import Failed</h2>
                    <p><?php echo htmlspecialchars($result['error']); ?></p>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 30px;">
                <a href="budget_import_mapped.php" class="btn">Upload Another File</a>
                <a href="view_data.php" class="btn btn-secondary">View Data</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>