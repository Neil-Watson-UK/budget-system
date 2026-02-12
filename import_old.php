<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// import.php - Excel/CSV Import Functionality
session_start();
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

// Check permissions (admin/manager only)
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager') {
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';
$import_results = [];
$total_imported = 0;
$total_skipped = 0;
$total_updated = 0;

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload error: " . $file['error'];
    } elseif ($file['size'] > 10485760) { // 10MB limit
        $error = "File is too large. Maximum size is 10MB.";
    } else {
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check file extension
        if (!in_array($file_ext, ['csv', 'xls', 'xlsx'])) {
            $error = "Please upload a CSV or Excel file (.csv, .xls, .xlsx)";
        } else {
            try {
                // Get import options from form
                $import_mode = $_POST['import_mode'] ?? 'insert_new';
                $duplicate_action = $_POST['duplicate_action'] ?? 'overwrite';
                
                // Get column mapping from form (if provided)
                $column_mapping = null;
                if (!empty($_POST['column_mapping_json'])) {
                    $column_mapping = json_decode($_POST['column_mapping_json'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new Exception("Invalid column mapping format.");
                    }
                }
                
                // Process the file
                $import_results = processImportFile($pdo, $file['tmp_name'], $file_ext, $import_mode, $duplicate_action, $column_mapping);
                
                $total_imported = $import_results['imported'] ?? 0;
                $total_skipped = $import_results['skipped'] ?? 0;
                $total_updated = $import_results['updated'] ?? 0;
                
                if ($total_imported > 0 || $total_updated > 0) {
                    $message = "Import completed: $total_imported new items added, $total_updated items updated, $total_skipped items skipped.";
                } else {
                    $message = "No new items were imported. All items may already exist or file may be empty.";
                }
                
            } catch (Exception $e) {
                $error = "Import failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Budget Data - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .glass-card-header {
            background: rgba(52, 152, 219, 0.8);
            color: white;
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .glass-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .glass-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            transform: translateY(-2px);
        }
        
        .file-upload-area {
            border: 2px dashed rgba(52, 152, 219, 0.5);
            border-radius: 10px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .file-upload-area:hover {
            border-color: rgba(52, 152, 219, 0.8);
            background: rgba(52, 152, 219, 0.05);
        }
        
        .file-upload-area.dragover {
            border-color: var(--success-color);
            background: rgba(39, 174, 96, 0.1);
        }
        
        .file-list-item {
            background: rgba(255, 255, 255, 0.8);
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-table {
            font-size: 0.9rem;
        }
        
        .preview-table th {
            background: rgba(52, 152, 219, 0.1);
        }
        
        .import-summary-card {
            border-left: 5px solid var(--success-color);
            background: rgba(255, 255, 255, 0.95);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: rgba(44, 62, 80, 0.95);">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-globe-americas"></i> <?= APP_NAME ?>
            </a>
            <div class="navbar-nav ms-auto">
                <a href="index.php" class="nav-link glass-btn me-2">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="regional_view.php" class="nav-link glass-btn">
                    <i class="fas fa-map-marked-alt"></i> Regional View
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <!-- Import Card -->
                <div class="glass-card">
                    <div class="glass-card-header">
                        <h4 class="mb-0">
                            <i class="fas fa-file-import me-2"></i>Import Budget Data
                        </h4>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- File Upload Form -->
                        <form method="POST" enctype="multipart/form-data" id="importForm">
                            <div class="mb-4">
                                <label class="form-label fw-bold mb-3">
                                    <i class="fas fa-file-excel me-2"></i>Select File to Import
                                </label>
                                
                                <!-- Drag & Drop Area -->
                                <div class="file-upload-area" id="dropArea">
                                    <div class="py-4">
                                        <i class="fas fa-cloud-upload-alt fa-3x mb-3 text-primary"></i>
                                        <h5>Drag & drop your file here</h5>
                                        <p class="text-muted mb-3">or click to browse</p>
                                        <input type="file" name="import_file" id="importFile" 
                                               accept=".csv,text/csv"
                                               class="d-none">
                                        <label for="importFile" class="btn btn-primary">
                                            <i class="fas fa-folder-open me-2"></i>Browse Files
                                        </label>
                                        <div class="mt-2 small text-muted">
                                            Supported formats: CSV (Export from Excel as CSV)
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Selected File Info -->
                                <div id="fileInfo" class="mt-3" style="display: none;">
                                    <div class="file-list-item">
                                        <div>
                                            <i class="fas fa-file-excel text-success me-2"></i>
                                            <span id="fileName"></span>
                                            <small class="text-muted ms-2" id="fileSize"></small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearFile()">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Import Options -->
                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-cog me-2"></i>Import Options
                                </label>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Import Mode</label>
                                        <select name="import_mode" class="form-select">
                                            <option value="insert_new">Insert New Items Only</option>
                                            <option value="update_existing">Update Existing Items</option>
                                            <option value="upsert">Insert New & Update Existing</option>
                                        </select>
                                        <div class="form-text">
                                            <small>
                                                <i class="fas fa-info-circle me-1"></i>
                                                Items are matched by PO Number
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">On Duplicate PO</label>
                                        <select name="duplicate_action" class="form-select">
                                            <option value="skip">Skip (Leave Existing)</option>
                                            <option value="overwrite">Overwrite Existing</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="validate_data" id="validateData" checked>
                                    <label class="form-check-label" for="validateData">
                                        Validate data before import (recommended)
                                    </label>
                                </div>
                            </div>
                            <!-- Add this in import.php after the file upload area -->
<div class="mt-3">
    <a href="download_import_template.php" class="btn btn-outline-success">
        <i class="fas fa-file-download me-2"></i>Download Import Template
    </a>
    <a href="export.php" class="btn btn-outline-primary ms-2">
        <i class="fas fa-download me-2"></i>Export Current Data for Editing
    </a>
</div>
                            <!-- Column Mapping Section -->
<div class="accordion mb-4" id="columnMapping">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#mappingDetails">
                <i class="fas fa-columns me-2"></i>Column Mapping (Advanced)
            </button>
        </h2>
        <div id="mappingDetails" class="accordion-collapse collapse" data-bs-parent="#columnMapping">
            <div class="accordion-body">
                <div id="columnMappingInfo" class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    The system will automatically map CSV columns to database columns. 
                    You can adjust the mapping below if needed.
                </div>
                
                <div id="mappingContainer">
                    <!-- Will be populated by JavaScript -->
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Upload a file first to see column mapping</p>
                    </div>
                </div>
                
                <!-- Hidden field to store mapping JSON -->
                <input type="hidden" name="column_mapping_json" id="columnMappingJson" value="">
            </div>
        </div>
    </div>
</div>
                            
                            <!-- File Format Instructions -->
                            <div class="accordion mb-4" id="formatHelp">
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#formatDetails">
                                            <i class="fas fa-question-circle me-2"></i>Expected File Format
                                        </button>
                                    </h2>
                                    <div id="formatDetails" class="accordion-collapse collapse" data-bs-parent="#formatHelp">
                                        <div class="accordion-body">
                                            <p>Your CSV file should have these columns (case-sensitive):</p>
                                            <table class="table table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>Column Name</th>
                                                        <th>Required</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td><strong>PO Number</strong></td>
                                                        <td>Yes</td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Region</strong></td>
                                                        <td>Yes</td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Amount Requested</strong></td>
                                                        <td>Yes</td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Currency</strong></td>
                                                        <td>Yes</td>
                                                    </tr>
                                                    <tr>
                                                        <td><strong>Activity Title</strong></td>
                                                        <td>Yes</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Cancel
                                </a>
                                <button type="submit" name="import" class="btn btn-success">
                                    <i class="fas fa-file-import me-2"></i>Start Import
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Import Results (if available) -->
                <?php if (isset($import_results['details']) && count($import_results['details']) > 0): ?>
                <div class="glass-card import-summary-card mt-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-clipboard-check me-2"></i>Import Results
                        </h5>
                        
                        <div class="row text-center mb-4">
                            <div class="col-md-4">
                                <div class="h3 text-success"><?= $total_imported ?></div>
                                <small>New Items Added</small>
                            </div>
                            <div class="col-md-4">
                                <div class="h3 text-primary"><?= $total_updated ?></div>
                                <small>Items Updated</small>
                            </div>
                            <div class="col-md-4">
                                <div class="h3 text-warning"><?= $total_skipped ?></div>
                                <small>Items Skipped</small>
                            </div>
                        </div>
                        
                        <!-- Detailed Results Table -->
                        <div class="table-responsive">
                            <table class="table preview-table">
                                <thead>
                                    <tr>
                                        <th>PO Number</th>
                                        <th>Action</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($import_results['details'] as $detail): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($detail['po_number']) ?></strong></td>
                                        <td>
                                            <?php if ($detail['status'] == 'imported'): ?>
                                                <span class="badge bg-success">Imported</span>
                                            <?php elseif ($detail['status'] == 'updated'): ?>
                                                <span class="badge bg-primary">Updated</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Skipped</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($detail['message']) ?>
                                                <?php if (isset($detail['id'])): ?>
                                                    <a href="edit_item.php?id=<?= $detail['id'] ?>" class="ms-2">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // File upload handling
        var dropArea = document.getElementById('dropArea');
        var fileInput = document.getElementById('importFile');
        var fileInfo = document.getElementById('fileInfo');
        var fileName = document.getElementById('fileName');
        var fileSize = document.getElementById('fileSize');
        
        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(eventName) {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        // Highlight drop area when item is dragged over it
        ['dragenter', 'dragover'].forEach(function(eventName) {
            dropArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(function(eventName) {
            dropArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            dropArea.classList.add('dragover');
        }
        
        function unhighlight() {
            dropArea.classList.remove('dragover');
        }
        
        // Handle dropped files
        dropArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            var dt = e.dataTransfer;
            var files = dt.files;
            
            if (files.length > 0) {
                fileInput.files = files;
                updateFileInfo(files[0]);
            }
        }
        
        // Handle file input change
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                updateFileInfo(this.files[0]);
            }
        });
        
        function updateFileInfo(file) {
            fileName.textContent = file.name;
            fileSize.textContent = formatFileSize(file.size);
            fileInfo.style.display = 'block';
            
            // Validate file type
            var validTypes = ['.csv'];
            var fileExt = '.' + file.name.split('.').pop().toLowerCase();
            
            if (!validTypes.includes(fileExt)) {
                alert('Please select a CSV file.');
                clearFile();
            }
        }
        
        function clearFile() {
            fileInput.value = '';
            fileInfo.style.display = 'none';
        }
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Form submission validation
        var importForm = document.getElementById('importForm');
        importForm.addEventListener('submit', function(e) {
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Please select a file to import.');
                return false;
            }
            
            // Show loading indicator
            var submitBtn = this.querySelector('button[type="submit"]');
            var originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            submitBtn.disabled = true;
        });
        
        // Column mapping functionality
        
                function remove_utf8_bom($text) {
    $bom = pack('H*', 'EFBBBF');
    $text = preg_replace("/^$bom/", '', $text);
    return $text;
}

function setupColumnMapping(file) {
    const reader = new FileReader();
    
    reader.onload = function(e) {
        const csvContent = e.target.result;
        const lines = csvContent.split('\n');
        const headers = lines[0].split(',').map(h => h.trim().replace(/"/g, ''));
        
        // Get database columns via AJAX (or use known columns)
        fetchDatabaseColumns(headers);
    };
    
    reader.readAsText(file);
    $headers = fgetcsv($handle);
$headers = array_map('remove_utf8_bom', $headers);
}

function fetchDatabaseColumns(csvHeaders) {
    // In a real implementation, you'd fetch this from the server
    // For now, we'll use known database columns
    const knownDbColumns = [
        'id', 'po_number', 'region', 'currency', 'amount_requested', 
        'activity_title', 'status', 'vendor', 'account', 'sub_account',
        'start_date', 'end_date', 'invoiced_date', 'entry_creation_date', 'last_updated'
    ];
    
    displayColumnMapping(csvHeaders, knownDbColumns);
}

function displayColumnMapping(csvHeaders, dbColumns) {
    const container = document.getElementById('mappingContainer');
    const mappingJson = document.getElementById('columnMappingJson');
    
    let html = '<div class="table-responsive">';
    html += '<table class="table table-sm">';
    html += '<thead><tr><th>CSV Column</th><th>Map to Database Column</th><th>Sample Value</th></tr></thead>';
    html += '<tbody>';
    
    const mappings = {};
    
    // Create a sample row (second line if available)
    const sampleRow = getSampleRow();
    
    csvHeaders.forEach((header, index) => {
        const sampleValue = sampleRow[index] || '';
        
        // Try to auto-detect the best match
        let bestMatch = '';
        const headerLower = header.toLowerCase();
        
        // Look for direct matches first
        dbColumns.forEach(dbCol => {
            const dbColLower = dbCol.toLowerCase();
            if (headerLower.includes(dbColLower) || dbColLower.includes(headerLower)) {
                bestMatch = dbCol;
            }
        });
        
        // Special cases
        if (!bestMatch) {
            if (headerLower.includes('po') && headerLower.includes('number')) {
                bestMatch = 'po_number';
            } else if (headerLower.includes('amount')) {
                bestMatch = 'amount_requested';
            } else if (headerLower.includes('activity') || headerLower.includes('title')) {
                bestMatch = 'activity_title';
            } else if (headerLower.includes('start')) {
                bestMatch = 'start_date';
            } else if (headerLower.includes('end')) {
                bestMatch = 'end_date';
            }
        }
        
        mappings[header] = bestMatch;
        
        html += '<tr>';
        html += `<td><strong>${header}</strong></td>`;
        html += '<td>';
        html += `<select class="form-select form-select-sm column-map-select" data-csv="${header}">`;
        html += '<option value="">-- Do Not Import --</option>';
        
        dbColumns.forEach(dbCol => {
            const selected = dbCol === bestMatch ? 'selected' : '';
            html += `<option value="${dbCol}" ${selected}>${dbCol}</option>`;
        });
        
        html += '</select>';
        html += '</td>';
        html += `<td><small class="text-muted">${sampleValue.substring(0, 30)}${sampleValue.length > 30 ? '...' : ''}</small></td>`;
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    
    container.innerHTML = html;
    mappingJson.value = JSON.stringify(mappings);
    
    // Add event listeners to mapping selects
    document.querySelectorAll('.column-map-select').forEach(select => {
        select.addEventListener('change', updateMappingJson);
    });
}

function updateMappingJson() {
    const mappings = {};
    document.querySelectorAll('.column-map-select').forEach(select => {
        const csvCol = select.dataset.csv;
        mappings[csvCol] = select.value;
    });
    
    document.getElementById('columnMappingJson').value = JSON.stringify(mappings);
}

function getSampleRow() {
    // This would ideally read the second line of the CSV
    // For now, return empty array
    return [];
}

// Update file input handler to show column mapping
fileInput.addEventListener('change', function() {
    if (this.files.length > 0) {
        updateFileInfo(this.files[0]);
        setupColumnMapping(this.files[0]);
    }
});

// Also handle drop
dropArea.addEventListener('drop', function(e) {
    var dt = e.dataTransfer;
    var files = dt.files;
    
    if (files.length > 0) {
        fileInput.files = files;
        updateFileInfo(files[0]);
        setupColumnMapping(files[0]);
    }
});
    </script>
</body>
</html>