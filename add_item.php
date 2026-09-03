<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

// Get region from URL if provided
$preselected_region = $_GET['region'] ?? '';

// Get item ID from URL for viewing/editing
$item_id = $_GET['id'] ?? 0;
$edit_mode = isset($_GET['edit']) && $_GET['edit'] == 'true';
$view_mode = $item_id > 0;

// If viewing/editing existing item, fetch its data
$existing_item = null;
if ($view_mode) {
    $stmt = $pdo->prepare("
        SELECT * FROM budget_items 
        WHERE id = ?
    ");
    $stmt->execute([$item_id]);
    $existing_item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing_item) {
        header("Location: index.php?error=Item not found");
        exit;
    }
    
    // Set preselected region from existing item
    $preselected_region = $existing_item['region'];
}

// Budget accrual checkbox: default on (legacy behaviour); off = attribute spend to invoice year when set
$accrual_checked = !$view_mode || ((int)($existing_item['budget_accrual_approved'] ?? 1) === 1);

// Load attachments for existing items
$attachments = $view_mode ? getBudgetItemAttachments($pdo, $item_id) : [];

// Get form field options
$account_options = getFormFieldOptions('accounting_code');
$sub_account_options = getFormFieldOptions('sub_accounting_code');

$planner_year_get = (int) ($_GET['planner_year'] ?? date('Y'));
if ($planner_year_get < 2000 || $planner_year_get > 2100) {
    $planner_year_get = (int) date('Y');
}
$external_vendor_options = [];
if ($preselected_region !== '' && isset($REGIONAL_SETTINGS[$preselected_region])) {
    $external_vendor_options = getFormFieldOptions('external_vendor', $preselected_region);
}

// Handle form submission (for both new and edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    if (empty($_POST['region']) || empty($_POST['country'])) {
        $error = "Region and Country are required";
        if ($view_mode) {
            header("Location: add_item.php?id=$item_id&error=" . urlencode($error));
        } else {
            header("Location: add_item.php?error=" . urlencode($error));
        }
        exit;
    }
    
    // Generate or use existing PO number
    $region = $_POST['region'];
    $country = $_POST['country'];
    
    if ($view_mode && !empty($existing_item['po_number'])) {
        // Use existing PO number when editing
        $po_number = $existing_item['po_number'];
    } else {
        // Generate new PO number for new items
        $po_prefix = $_POST['po_prefix'] ?? 'PO';
        $po_number = generatePONumber($region, $country, $po_prefix);
    }
    
    // Get currency from form, or use region default if not provided
    $currency = $_POST['currency'] ?? $REGIONAL_SETTINGS[$region]['currency'] ?? 'EUR';
    $budget_accrual_approved = isset($_POST['budget_accrual_approved']) ? 1 : 0;
    
    if ($view_mode) {
        // UPDATE existing item
        $stmt = $pdo->prepare("
            UPDATE budget_items SET
                po_prefix = ?,
                region = ?,
                country = ?,
                start_date = ?,
                end_date = ?,
                invoiced_date = ?,
                budget_accrual_approved = ?,
                amount_requested = ?,
                currency = ?,
                activity_title = ?,
                status = ?,
                frequency_of_spend = ?,
                vendor = ?,
                external_vendor = ?,
                vendor_contact = ?,
                account = ?,
                sub_account = ?,
                budget_category = ?,
                activity_description = ?,
                comments = ?,
                associated_epos_staff = ?,
                item_type = ?,
                path = ?,
                entry_updated_date = NOW(),
                project_code = ?
            WHERE id = ?
        ");
        
        $params = [
            $_POST['po_prefix'],
            $_POST['region'],
            $_POST['country'],
            $_POST['start_date'] ?: null,
            $_POST['end_date'] ?: null,
            $_POST['invoiced_date'] ?: null,
            $budget_accrual_approved,
            $_POST['amount_requested'],
            $currency,
            $_POST['activity_title'],
            $_POST['status'],
            $_POST['frequency_of_spend'],
            $_POST['vendor'],
            $_POST['external_vendor'],
            $_POST['vendor_contact'],
            $_POST['account'],
            $_POST['sub_account'],
            $_POST['budget_category'],
            $_POST['activity_description'],
            $_POST['comments'],
            $_POST['associated_epos_staff'],
            $_POST['item_type'],
            $_POST['path'],
            $_POST['salesforce_id'] ?? null,
            $item_id
        ];
    } else {
        // INSERT new item
        $stmt = $pdo->prepare("
            INSERT INTO budget_items (
                po_number, po_prefix, region, country, start_date, end_date, invoiced_date, budget_accrual_approved,
                amount_requested, currency, activity_title, status, frequency_of_spend,
                vendor, external_vendor, vendor_contact, account, sub_account, budget_category,
                activity_description, comments, associated_epos_staff, item_type, path,
                entry_creation_date, entry_updated_date, project_code
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                NOW(), NOW(), ?
            )
        ");
        
        $params = [
            $po_number,
            $_POST['po_prefix'],
            $_POST['region'],
            $_POST['country'],
            $_POST['start_date'] ?: null,
            $_POST['end_date'] ?: null,
            $_POST['invoiced_date'] ?: null,
            $budget_accrual_approved,
            $_POST['amount_requested'],
            $currency,
            $_POST['activity_title'],
            $_POST['status'],
            $_POST['frequency_of_spend'],
            $_POST['vendor'],
            $_POST['external_vendor'],
            $_POST['vendor_contact'],
            $_POST['account'],
            $_POST['sub_account'],
            $_POST['budget_category'],
            $_POST['activity_description'],
            $_POST['comments'],
            $_POST['associated_epos_staff'],
            $_POST['item_type'],
            $_POST['path'],
            $_POST['salesforce_id'] ?? null
        ];
    }
    
    $stmt->execute($params);
    
    $saved_item_id = $view_mode ? $item_id : $pdo->lastInsertId();
    
    // Process file attachments
    if (!empty($_FILES['uploaded_files']['name'][0])) {
        $upload_dir = __DIR__ . '/uploads/budget_attachments/';
        foreach ($_FILES['uploaded_files']['name'] as $i => $name) {
            if (empty($name)) continue;
            $file = [
                'name' => $name,
                'type' => $_FILES['uploaded_files']['type'][$i] ?? '',
                'tmp_name' => $_FILES['uploaded_files']['tmp_name'][$i] ?? '',
                'error' => $_FILES['uploaded_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['uploaded_files']['size'][$i] ?? 0
            ];
            saveBudgetItemAttachment($pdo, $saved_item_id, $file, $upload_dir);
        }
    }
    
    $success_message = $view_mode ? "Item updated successfully" : "Item created successfully";
    $redirect = $view_mode ? "add_item.php?id=$saved_item_id&success=" . urlencode($success_message) : "index.php?success=" . urlencode($success_message);
    header("Location: $redirect");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $view_mode ? ($edit_mode ? 'Edit' : 'View') . ' Budget Item' : 'Add Budget Item' ?> - <?= APP_NAME ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tabler Core CSS (CDN) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    
    <!-- Tabler Icons (CDN) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f7fb 0%, #e4edf5 100%);
            color: #2a3547;
        }
        
        .container {
            padding-top: 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 163, 153, 0.15);
        }
        
        .page-title {
            font-weight: 700;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        
        .card {
            border-radius: 12px;
            border: 1px solid #e1e5eb;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            border-top-left-radius: 12px !important;
            border-top-right-radius: 12px !important;
        }
        
        .card-body {
            padding: 2rem;
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.625rem 1.25rem;
            transition: all 0.2s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            border: none;
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #009389 0%, #002a30 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 163, 153, 0.3);
            color: white;
        }
        
        .btn-outline-primary {
            color: #00a399;
            border-color: #00a399;
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white;
            border-color: transparent;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e1e5eb;
            padding: 0.625rem 1rem;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #00a399;
            box-shadow: 0 0 0 3px rgba(0, 163, 153, 0.15);
        }
        
        .form-control[readonly],
        .form-select[readonly] {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            cursor: not-allowed;
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 1px solid #e1e5eb;
            color: #6c757d;
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 1rem 1.25rem;
        }
        
        .badge {
            border-radius: 20px;
            padding: 0.375rem 0.75rem;
            font-weight: 500;
        }
        
        .form-label {
            font-weight: 600;
            color: #2a3547;
            margin-bottom: 0.5rem;
        }
        
        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .view-mode-badge {
            position: absolute;
            top: -10px;
            right: 20px;
            z-index: 100;
        }
        
        .action-buttons {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            margin-top: 2rem;
            border-top: 1px solid #e1e5eb;
            position: sticky;
            bottom: 0;
            z-index: 50;
        }
        
        .po-number-display {
            font-size: 1.5rem;
            font-weight: 700;
            color: #00a399;
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e1e5eb;
        }
        
        .form-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2a3547;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #00a399;
        }
        
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        /* Vendor autocomplete dropdown */
        .autocomplete-items {
            position: absolute;
            border: 1px solid #e1e5eb;
            border-bottom: none;
            border-top: none;
            z-index: 99;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .autocomplete-items div {
            padding: 10px;
            cursor: pointer;
            border-bottom: 1px solid #e1e5eb;
        }
        
        .autocomplete-items div:hover {
            background-color: #f8f9fa;
        }
        
        .autocomplete-active {
            background-color: #00a399 !important;
            color: white;
        }
        
        .vendor-details {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 2px;
        }
        
        .vendor-match-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            z-index: 10;
        }
        
        .vendor-input-wrapper {
            position: relative;
        }
        
        .file-upload-area {
            border: 2px dashed #e1e5eb;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .file-upload-area:hover { border-color: #00a399; background: #e8f6ec; }
        .file-upload-area.dragover { border-color: #00a399; background: #d4edda; }
        .uploaded-file-item {
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #00a399;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .page-header {
                padding: 1.5rem 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .row {
                margin-left: -0.5rem;
                margin-right: -0.5rem;
            }
            
            .row > [class*="col-"] {
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="container">
        <!-- Error Message -->
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="ti ti-alert-circle me-2"></i>
                <?= htmlspecialchars($_GET['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Success Message (for when redirected from edit) -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="ti ti-circle-check me-2"></i>
                <?= htmlspecialchars($_GET['success']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card position-relative">
                    <?php if ($view_mode && !$edit_mode): ?>
                        <span class="badge bg-info view-mode-badge">View Mode</span>
                    <?php endif; ?>
                    
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h4 class="mb-0">
                            <i class="ti ti-<?= $view_mode ? ($edit_mode ? 'edit' : 'eye') : 'plus' ?> me-2"></i>
                            <?= $view_mode ? ($edit_mode ? 'Edit' : 'View') . ' Budget Item' : 'Add New Budget Item' ?>
                            <?php if ($view_mode): ?>
                                <small class="ms-2 opacity-75">#<?= htmlspecialchars($existing_item['po_number']) ?></small>
                            <?php endif; ?>
                        </h4>
                        <?php if ($view_mode && $edit_mode): ?>
                            <button type="submit" form="budgetForm" class="btn btn-light btn-sm">
                                <i class="ti ti-device-floppy me-1"></i> Save Changes
                            </button>
                        <?php elseif (!$view_mode): ?>
                            <span class="badge bg-success">New</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($view_mode && !$edit_mode): ?>
                            <!-- View Mode - Show read-only info -->
                            <div class="mb-4">
                                <h5 class="form-section-title">PO Information</h5>
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>PO Number:</strong><br>
                                        <span class="po-number-display"><?= htmlspecialchars($existing_item['po_number']) ?></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Created:</strong><br>
                                        <?= date('d/m/Y H:i', strtotime($existing_item['entry_creation_date'])) ?>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Last Updated:</strong><br>
                                        <?= date('d/m/Y H:i', strtotime($existing_item['entry_updated_date'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" id="budgetForm" enctype="multipart/form-data">
                            <!-- PO Number and Activity Title -->
                            <div class="form-section">
                                <h5 class="form-section-title">Basic Information</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">PO Prefix</label>
                                        <input type="text" 
                                               name="po_prefix" 
                                               class="form-control" 
                                               value="<?= $view_mode ? htmlspecialchars($existing_item['po_prefix'] ?? 'PO') : 'PO' ?>" 
                                               placeholder="e.g., MKTG, OPS, IT"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>>
                                        <div class="form-text">
                                            <?php if ($view_mode && !$edit_mode): ?>
                                                Full PO: <?= htmlspecialchars($existing_item['po_number']) ?>
                                            <?php else: ?>
                                                PO Number will be auto-generated
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Activity Title</label>
                                        <input type="text" 
                                               name="activity_title" 
                                               class="form-control" 
                                               required 
                                               placeholder="Enter activity title"
                                               value="<?= $view_mode ? htmlspecialchars($existing_item['activity_title']) : '' ?>"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>>
                                    </div>
                                </div>
                            </div>

                            <!-- Staff & Type (early: drives allocation + partner context) -->
                            <div class="form-section">
                                <h5 class="form-section-title">Staff &amp; Type</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Associated EPOS Staff</label>
                                        <select name="associated_epos_staff" 
                                                class="form-select" 
                                                required 
                                                id="staff_select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="">Select Staff Member</option>
                                            <!-- Staff will be populated by JavaScript -->
                                            <?php if ($view_mode): ?>
                                                <option value="<?= htmlspecialchars($existing_item['associated_epos_staff']) ?>" selected>
                                                    <?= htmlspecialchars($existing_item['associated_epos_staff']) ?>
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Item Type</label>
                                        <select name="item_type" 
                                                class="form-select"
                                                id="item_type_select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="Reseller" <?= ($view_mode && $existing_item['item_type'] == 'Reseller') ? 'selected' : '' ?>>Reseller</option>
                                            <option value="Distributor" <?= ($view_mode && $existing_item['item_type'] == 'Distributor') ? 'selected' : '' ?>>Distributor</option>
                                            <option value="End User" <?= ($view_mode && $existing_item['item_type'] == 'End User') ? 'selected' : '' ?>>End User</option>
                                            <option value="Other" <?= ($view_mode && $existing_item['item_type'] == 'Other') ? 'selected' : '' ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <?php if (!$view_mode || $edit_mode): ?>
                                <div class="border rounded-2 bg-light p-3 mb-0" id="budget_allocation_panel" style="display:none;">
                                    <div class="small text-muted mb-1"><i class="ti ti-chart-pie me-1"></i> Budget vs your Item Type (planner year <span id="planner_year_label"><?= (int) $planner_year_get ?></span>)</div>
                                    <div id="budget_allocation_content" class="small">Select region and item type to see allocation remaining.</div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Region and Country -->
                            <div class="form-section">
                                <h5 class="form-section-title">Location</h5>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Region</label>
                                        <select name="region" 
                                                class="form-select" 
                                                required 
                                                id="region_select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="">Select Region</option>
                                            <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>
                                            <option value="<?= $region ?>" 
                                                <?= ($preselected_region == $region || ($view_mode && $existing_item['region'] == $region)) ? 'selected' : '' ?>>
                                                <?= $region ?> (Default: <?= $settings['currency'] ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-field">Country</label>
                                        <select name="country" 
                                                class="form-select" 
                                                required 
                                                id="country_select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="">Select Country</option>
                                            <!-- Countries will be populated by JavaScript -->
                                            <?php if ($view_mode): ?>
                                                <option value="<?= htmlspecialchars($existing_item['country']) ?>" selected>
                                                    <?= htmlspecialchars($existing_item['country']) ?>
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Amount, Status, Frequency -->
                            <div class="form-section">
                                <h5 class="form-section-title">Financial Details</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Amount Requested</label>
                                        <div class="input-group">
                                            <input type="number" 
                                                   name="amount_requested" 
                                                   class="form-control" 
                                                   step="0.01" 
                                                   required 
                                                   placeholder="0.00" 
                                                   id="amount_requested"
                                                   value="<?= $view_mode ? htmlspecialchars($existing_item['amount_requested']) : '' ?>"
                                                   <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>>
                                            <select class="form-select" 
                                                    id="currency" 
                                                    name="currency" 
                                                    style="width: auto; min-width: 120px;"
                                                    <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                                <!-- Options will be populated by JavaScript based on region -->
                                            </select>
                                        </div>
                                        <small class="form-text text-muted">
                                            Default currency: <span id="default_currency_display"></span>
                                            <?php if (!$view_mode || $edit_mode): ?>
                                                <br>Select different currency if needed
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label required-field">Status</label>
                                        <select name="status" 
                                                class="form-select" 
                                                required
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="Planned" <?= ($view_mode && $existing_item['status'] == 'Planned') ? 'selected' : '' ?>>Planned</option>
                                            <option value="Invoiced" <?= ($view_mode && $existing_item['status'] == 'Invoiced') ? 'selected' : '' ?>>Invoiced</option>
                                            <option value="Executed" <?= ($view_mode && $existing_item['status'] == 'Executed') ? 'selected' : '' ?>>Executed</option>
                                            <option value="Cancelled" <?= ($view_mode && $existing_item['status'] == 'Cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                            <option value="Allocated" <?= ($view_mode && $existing_item['status'] == 'Allocated') ? 'selected' : '' ?>>Allocated</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Frequency</label>
                                        <select name="frequency_of_spend" 
                                                class="form-select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="One-off" <?= ($view_mode && $existing_item['frequency_of_spend'] == 'One-off') ? 'selected' : '' ?>>One-off</option>
                                            <option value="Monthly" <?= ($view_mode && $existing_item['frequency_of_spend'] == 'Monthly') ? 'selected' : '' ?>>Monthly</option>
                                            <option value="Quarterly" <?= ($view_mode && $existing_item['frequency_of_spend'] == 'Quarterly') ? 'selected' : '' ?>>Quarterly</option>
                                            <option value="Annually" <?= ($view_mode && $existing_item['frequency_of_spend'] == 'Annually') ? 'selected' : '' ?>>Annually</option>
                                            <option value="Bi-Annually" <?= ($view_mode && $existing_item['frequency_of_spend'] == 'Bi-Annually') ? 'selected' : '' ?>>Bi-Annually</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Vendors with Salesforce Matching -->
                            <div class="form-section">
                                <h5 class="form-section-title">Vendor Information</h5>
                                <div class="row mb-3">
                                    <div class="col-md-8">
                                        <div class="vendor-input-wrapper">
                                            <label class="form-label">Vendor</label>
                                            <input type="text" 
                                                   name="vendor" 
                                                   class="form-control" 
                                                   id="vendor_input"
                                                   placeholder="Start typing vendor name..."
                                                   value="<?= $view_mode ? htmlspecialchars($existing_item['vendor']) : '' ?>"
                                                   <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>
                                                   autocomplete="off">
                                            <div id="vendor_autocomplete" class="autocomplete-items"></div>
                                            <div class="form-text">
                                                <i class="ti ti-info-circle"></i> Start typing to search vendors from database
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Salesforce Account ID</label>
                                        <input type="text" 
                                               name="salesforce_id" 
                                               id="salesforce_id_input" 
                                               class="form-control" 
                                               placeholder="Will auto-fill from vendor selection"
                                               value="<?= $view_mode ? htmlspecialchars($existing_item['project_code']) : '' ?>"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>
                                               readonly>
                                        <div class="form-text" id="vendor_match_status">
                                            <?php if ($view_mode): ?>
                                                <span id="match_status_text">
                                                    <?= !empty($existing_item['project_code']) ? 'Matched to Salesforce' : 'No Salesforce match' ?>
                                                </span>
                                                <?php if (!empty($existing_item['project_code'])): ?>
                                                    <span class="badge bg-success">? Matched</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span id="match_status_text">Search for a vendor to auto-fill Salesforce ID</span>
                                                <span id="match_status_badge"></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- External Vendor and Contact -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">External Vendor (if different)</label>
                                        <input type="text" 
                                               name="external_vendor" 
                                               id="external_vendor_input"
                                               class="form-control" 
                                               list="external_vendor_datalist"
                                               placeholder="Type or pick from known external vendors"
                                               value="<?= $view_mode ? htmlspecialchars($existing_item['external_vendor']) : '' ?>"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>
                                               autocomplete="off">
                                        <datalist id="external_vendor_datalist">
                                            <?php foreach ($external_vendor_options as $ev): ?>
                                            <option value="<?= htmlspecialchars($ev['field_label'] ?: $ev['field_value']) ?>"></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                        <div class="form-text">Matches names from Form Manager &quot;External Vendors&quot;; you can still enter a new name.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Vendor Contact</label>
                                        <input type="text" 
                                               name="vendor_contact" 
                                               class="form-control" 
                                               placeholder="Contact person"
                                               value="<?= $view_mode ? htmlspecialchars($existing_item['vendor_contact']) : '' ?>"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>>
                                    </div>
                                </div>
                                <?php if (!$view_mode || $edit_mode): ?>
                                <div class="row mb-2" id="partner_history_row" style="display:none;">
                                    <div class="col-12">
                                        <div class="card border-0 bg-light">
                                            <div class="card-body py-2">
                                                <h6 class="small fw-semibold mb-2"><i class="ti ti-history me-1"></i> Partner spend history (this region)</h6>
                                                <div id="partner_history_content" class="small text-muted">Enter or select a vendor or external vendor to see matching past spend.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Accounting -->
                            <div class="form-section">
                                <h5 class="form-section-title">Accounting Details</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Account</label>
                                        <select name="account" 
                                                class="form-select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="">Select Account</option>
                                            <?php foreach ($account_options as $option): ?>
                                                <option value="<?= htmlspecialchars($option['field_value']) ?>"
                                                    <?= ($view_mode && $existing_item['account'] == $option['field_value']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($option['field_label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Sub Account</label>
                                        <select name="sub_account" 
                                                class="form-select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="">Select Sub Account</option>
                                            <?php foreach ($sub_account_options as $option): ?>
                                                <option value="<?= htmlspecialchars($option['field_value']) ?>"
                                                    <?= ($view_mode && $existing_item['sub_account'] == $option['field_value']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($option['field_label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Budget Category</label>
                                        <select name="budget_category" 
                                                class="form-select"
                                                <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                            <option value="">Select Category</option>
                                            <option value="advertising" <?= ($view_mode && $existing_item['budget_category'] == 'advertising') ? 'selected' : '' ?>>Advertising</option>
                                            <option value="catalogue" <?= ($view_mode && $existing_item['budget_category'] == 'catalogue') ? 'selected' : '' ?>>Catalogue</option>
                                            <option value="digital" <?= ($view_mode && $existing_item['budget_category'] == 'digital') ? 'selected' : '' ?>>Digital</option>
                                            <option value="email" <?= ($view_mode && $existing_item['budget_category'] == 'email') ? 'selected' : '' ?>>Email</option>
                                            <option value="event" <?= ($view_mode && $existing_item['budget_category'] == 'event') ? 'selected' : '' ?>>Event</option>
                                            <option value="gift" <?= ($view_mode && $existing_item['budget_category'] == 'gift') ? 'selected' : '' ?>>Gift</option>
                                            <option value="other" <?= ($view_mode && $existing_item['budget_category'] == 'other') ? 'selected' : '' ?>>Other</option>
                                            <option value="product" <?= ($view_mode && $existing_item['budget_category'] == 'product') ? 'selected' : '' ?>>Product</option>
                                            <option value="shipping" <?= ($view_mode && $existing_item['budget_category'] == 'shipping') ? 'selected' : '' ?>>Shipping</option>
                                            <option value="sponsorship" <?= ($view_mode && $existing_item['budget_category'] == 'sponsorship') ? 'selected' : '' ?>>Sponsorship</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Dates -->
                            <div class="form-section">
                                <h5 class="form-section-title">Dates</h5>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" 
                                               name="start_date" 
                                               class="form-control" 
                                               id="start_date"
                                               value="<?= $view_mode && $existing_item['start_date'] ? date('Y-m-d', strtotime($existing_item['start_date'])) : '' ?>"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">End Date</label>
                                        <input type="date" 
                                               name="end_date" 
                                               class="form-control"
                                               value="<?= $view_mode && $existing_item['end_date'] ? date('Y-m-d', strtotime($existing_item['end_date'])) : '' ?>"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Invoiced Date</label>
                                        <input type="date" 
                                               name="invoiced_date" 
                                               class="form-control"
                                               value="<?= $view_mode && $existing_item['invoiced_date'] ? date('Y-m-d', strtotime($existing_item['invoiced_date'])) : '' ?>"
                                               <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" name="budget_accrual_approved" value="1" class="form-check-input" id="budget_accrual_approved"
                                                   <?= $accrual_checked ? 'checked' : '' ?>
                                                   <?= ($view_mode && !$edit_mode) ? 'disabled' : '' ?>>
                                            <label class="form-check-label" for="budget_accrual_approved">Budget accrual approved</label>
                                            <small class="d-block text-muted mt-1">When checked, spend is counted against the activity dates only. Uncheck to count spend against the <strong>invoice date</strong> year when invoicing lands in a later budget year (late invoice).</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Description & Comments -->
                            <div class="form-section">
                                <h5 class="form-section-title">Descriptions</h5>
                                <div class="mb-3">
                                    <label class="form-label">Activity Description</label>
                                    <textarea name="activity_description" 
                                              class="form-control" 
                                              rows="3" 
                                              placeholder="Describe the activity"
                                              <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>><?= $view_mode ? htmlspecialchars($existing_item['activity_description']) : '' ?></textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Comments</label>
                                    <textarea name="comments" 
                                              class="form-control" 
                                              rows="2" 
                                              placeholder="Additional comments"
                                              <?= ($view_mode && !$edit_mode) ? 'readonly' : '' ?>><?= $view_mode ? htmlspecialchars($existing_item['comments']) : '' ?></textarea>
                                </div>

                                <!-- Path -->
                                <div class="mb-3">
                                    <label class="form-label">Path</label>
                                    <select name="path" 
                                            class="form-select"
                                            <?= ($view_mode && !$edit_mode) ? 'readonly disabled' : '' ?>>
                                        <option value="direct" <?= ($view_mode && ($existing_item['path'] ?? '') == 'direct') ? 'selected' : '' ?>>Direct</option>
                                        <option value="channel" <?= ($view_mode && ($existing_item['path'] ?? '') == 'channel') ? 'selected' : '' ?>>Channel</option>
                                        <option value="partner" <?= ($view_mode && ($existing_item['path'] ?? '') == 'partner') ? 'selected' : '' ?>>Partner</option>
                                    </select>
                                </div>
                            </div>

                            <!-- File Attachments -->
                            <div class="form-section">
                                <h5 class="form-section-title">File Attachments</h5>
                                <?php if ($view_mode && !$edit_mode): ?>
                                    <!-- View mode: show existing only -->
                                    <?php if (!empty($attachments)): ?>
                                        <div class="uploaded-files-list mt-2">
                                            <?php foreach ($attachments as $att): ?>
                                            <div class="uploaded-file-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="ti ti-file me-2"></i><?= htmlspecialchars($att['file_name']) ?>
                                                    <small class="text-muted ms-2"><?= number_format($att['file_size']/1024, 1) ?> KB</small>
                                                </div>
                                                <a href="download_attachment.php?id=<?= $att['id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="ti ti-download"></i> Download
                                                </a>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">No attachments</p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Edit/Add mode: upload + existing -->
                                    <div class="file-upload-area mb-3" id="fileUploadArea" onclick="document.getElementById('fileInput').click()">
                                        <i class="ti ti-cloud-upload ti-xxl text-muted mb-2"></i>
                                        <p class="mb-1">Click or drag files here to attach</p>
                                        <p class="text-muted small mb-0">Max 10MB. PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, TXT</p>
                                        <input type="file" name="uploaded_files[]" id="fileInput" multiple class="d-none"
                                               accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt">
                                    </div>
                                    <div id="filePreview" class="mb-3"></div>
                                    <?php if (!empty($attachments)): ?>
                                    <h6 class="mt-3">Existing files</h6>
                                    <div class="uploaded-files-list">
                                        <?php foreach ($attachments as $att): ?>
                                        <div class="uploaded-file-item d-flex justify-content-between align-items-center">
                                            <div><i class="ti ti-file me-2"></i><?= htmlspecialchars($att['file_name']) ?></div>
                                            <div>
                                                <a href="download_attachment.php?id=<?= $att['id'] ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank"><i class="ti ti-download"></i></a>
                                                <a href="delete_attachment.php?id=<?= $att['id'] ?>&return_id=<?= $item_id ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this attachment?');"><i class="ti ti-trash"></i></a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- Action Buttons (sticky at bottom for long forms) -->
                        <div class="action-buttons">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <?php if ($view_mode && !$edit_mode): ?>
                                    <!-- View Mode Buttons -->
                                    <a href="index.php" class="btn btn-secondary me-md-2">
                                        <i class="ti ti-arrow-left"></i> Back to List
                                    </a>
                                    <a href="add_item.php?id=<?= $item_id ?>&edit=true" class="btn btn-primary me-md-2">
                                        <i class="ti ti-edit"></i> Edit Item
                                    </a>
                                    
                                    <!-- DELETE FORM - SEPARATE FROM MAIN FORM -->
                                    <form method="POST" 
                                          action="delete.php" 
                                          id="deleteForm"
                                          onsubmit="return confirmDelete();"
                                          style="display: inline;">
                                        <input type="hidden" name="id" value="<?= $item_id ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="ti ti-trash"></i> Delete Item
                                        </button>
                                    </form>
                                    
                                <?php elseif ($view_mode && $edit_mode): ?>
                                    <!-- Edit Mode Buttons -->
                                    <a href="add_item.php?id=<?= $item_id ?>" class="btn btn-secondary me-md-2">
                                        <i class="ti ti-x"></i> Cancel
                                    </a>
                                    <button type="submit" form="budgetForm" class="btn btn-primary">
                                        <i class="ti ti-device-floppy"></i> Save Changes
                                    </button>
                                <?php else: ?>
                                    <!-- Add Mode Buttons -->
                                    <a href="index.php" class="btn btn-secondary me-md-2">
                                        <i class="ti ti-x"></i> Cancel
                                    </a>
                                    <button type="submit" form="budgetForm" class="btn btn-primary">
                                        <i class="ti ti-plus"></i> Create Budget Item
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabler JavaScript (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
    
    <!-- Bootstrap Bundle (Tabler depends on Bootstrap) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Regional groups with their countries - MATCHING YOUR DATABASE ENUM
       const regionGroups = {
    'AMER': ['America', 'Canada'],
    'DACH': ['Germany', 'Austria', 'Switzerland'],
    'UKI': ['UK', 'Ireland'],
    'APAC': ['Hong Kong', 'Singapore', 'Japan', 'China'],
    'ANZ': ['Australia', 'New Zealand'],
    'NORD': ['Denmark', 'Sweden', 'Norway', 'Finland', 'Iceland'], // UPDATED
    'BNL': ['Belgium', 'Netherlands', 'Luxembourg'], // UPDATED
    'FRANCE': ['France'],
    'EMEA_PARTNERS': ['Italy', 'Spain', 'Portugal', 'Greece', 'Poland', 'Czech Republic', 'Hungary', 'Romania', 'Russia', 'South Africa', 'UAE', 'Saudi Arabia'],
    'INDIA': ['India']
};
        // Currency mapping for default display
        const regionGroupCurrencies = {
            'AMER': 'USD',
            'DACH': 'EUR', 
            'UKI': 'GBP',
            'APAC': 'USD',
            'ANZ': 'AUD',
            'NORD': 'EUR',
            'BNL': 'EUR',
            'FRANCE': 'EUR',
            'EMEA_PARTNERS': 'EUR',
            'INDIA': 'INR'
        };

        const regionAllowedCurrencies = <?php 
            $allowedCurrencies = [];
            foreach ($REGIONAL_SETTINGS as $region => $settings) {
                $allowedCurrencies[$region] = $settings['allowed_currencies'] ?? [$settings['currency']];
            }
            echo json_encode($allowedCurrencies); 
        ?>;

        const currencySymbols = <?= json_encode($CURRENCY_SYMBOLS) ?>;

        // Map countries to their preferred currencies
        const countryPreferredCurrency = {
    // AMER region
    'Canada': 'CAD',
    'America': 'USD',
    
    // UKI region
    'UK': 'GBP',
    'Ireland': 'GBP',
    
    // ANZ region
    'Australia': 'AUD',
    'New Zealand': 'NZD',
    
    // INDIA region
    'India': 'INR',
    
    // APAC region
    'Hong Kong': 'HKD',
    'Singapore': 'SGD', 
    'Japan': 'JPY',
    'China': 'CNY',
    
    // DACH region
    'Germany': 'EUR',
    'Austria': 'EUR',
    'Switzerland': 'CHF',
    
    // NORD region - UPDATED
    'Denmark': 'EUR',
    'Sweden': 'EUR',
    'Norway': 'EUR',
    'Finland': 'EUR', // ADDED
    'Iceland': 'EUR', // ADDED
    
    // BNL region - UPDATED
    'Belgium': 'EUR',
    'Netherlands': 'EUR',
    'Luxembourg': 'EUR', // ADDED
    
    // FRANCE region
    'France': 'EUR',
    
    // EMEA_PARTNERS region
    'Italy': 'EUR',
    'Spain': 'EUR',
    'Portugal': 'EUR',
    'Greece': 'EUR',
    'Poland': 'EUR',
    'Czech Republic': 'EUR',
    'Hungary': 'EUR',
    'Romania': 'EUR',
    'Russia': 'EUR',
    'South Africa': 'EUR',
    'UAE': 'EUR',
    'Saudi Arabia': 'EUR'
};        
        // Track if user manually changed currency
        let userChangedCurrency = <?= ($view_mode && isset($existing_item['currency'])) ? 'true' : 'false' ?>;

        // Delete confirmation function
        function confirmDelete() {
            const poNumber = '<?= addslashes($existing_item['po_number'] ?? '') ?>';
            const title = '<?= addslashes($existing_item['activity_title'] ?? '') ?>';
            
            return confirm('Are you absolutely sure you want to delete this item?\n\nPO: ' + poNumber + '\nTitle: ' + title + '\n\nThis cannot be undone!');
        }

        // Function to load staff based on region
        async function loadStaff(region) {
            const staffSelect = document.getElementById('staff_select');
            
            if (!region) {
                staffSelect.innerHTML = '<option value="">Select Staff Member</option>';
                return;
            }

            try {
                const response = await fetch('get_staff.php?region=' + region);
                const staff = await response.json();
                
                staffSelect.innerHTML = '<option value="">Select Staff Member</option>';
                staff.forEach(function(person) {
                    const option = document.createElement('option');
                    option.value = person.field_value;
                    option.textContent = person.field_label;
                    staffSelect.appendChild(option);
                });
                
                // Add "not specified" option
                const notSpecifiedOption = document.createElement('option');
                notSpecifiedOption.value = 'notspecified@epos.com';
                notSpecifiedOption.textContent = 'Not Specified';
                staffSelect.appendChild(notSpecifiedOption);
                
                // Set existing staff if in view/edit mode
                <?php if ($view_mode && isset($existing_item['associated_epos_staff'])): ?>
                    const existingStaff = '<?= addslashes($existing_item['associated_epos_staff']) ?>';
                    if (existingStaff) {
                        staffSelect.value = existingStaff;
                    }
                <?php endif; ?>
            } catch (error) {
                console.error('Error loading staff:', error);
                staffSelect.innerHTML = '<option value="">Error loading staff</option>';
            }
        }

        // Enhanced getCurrencySymbol function
        function getCurrencySymbol(currency) {
            return currencySymbols[currency] || currency;
        }

        // Function to update currency options based on region
        function updateCurrencyOptions(region) {
            const currencySelect = document.getElementById('currency');
            const defaultCurrencyDisplay = document.getElementById('default_currency_display');
            const countrySelect = document.getElementById('country_select');
            const selectedCountry = countrySelect ? countrySelect.value : '';
            
            if (!region) {
                currencySelect.innerHTML = '<option value="">Select Region First</option>';
                if (defaultCurrencyDisplay) {
                    defaultCurrencyDisplay.textContent = '';
                }
                return;
            }
            
            const allowedCurrencies = regionAllowedCurrencies[region] || [];
            const defaultCurrency = regionGroupCurrencies[region];
            
            // Update default currency display if it exists
            if (defaultCurrencyDisplay) {
                defaultCurrencyDisplay.textContent = defaultCurrency + ' (' + getCurrencySymbol(defaultCurrency) + ')';
            }
            
            if (allowedCurrencies.length === 0) {
                // Fallback to default currency only
                const symbol = getCurrencySymbol(defaultCurrency);
                currencySelect.innerHTML = '<option value="' + defaultCurrency + '">' + defaultCurrency + ' (' + symbol + ')</option>';
                return;
            }

            // Clear and rebuild currency select
            currencySelect.innerHTML = '';
            
            // Add each allowed currency
            allowedCurrencies.forEach(function(currencyCode) {
                const option = document.createElement('option');
                option.value = currencyCode;
                option.textContent = currencyCode + ' (' + getCurrencySymbol(currencyCode) + ')';
                
                // Select based on priority:
                // 1. Existing item currency (if in view/edit mode)
                // 2. Country preferred currency (if not manually changed)
                // 3. Region default currency
                
                <?php if ($view_mode && isset($existing_item['currency'])): ?>
                    if (currencyCode === '<?= $existing_item['currency'] ?>') {
                        option.selected = true;
                    }
                <?php else: ?>
                    if (!userChangedCurrency && selectedCountry) {
                        const preferredCurrency = countryPreferredCurrency[selectedCountry];
                        if (preferredCurrency === currencyCode) {
                            option.selected = true;
                        }
                    }
                <?php endif; ?>
                
                currencySelect.appendChild(option);
            });
            
            // If no currency selected yet, select the region default
            if (!currencySelect.value && allowedCurrencies.length > 0) {
                const defaultExists = allowedCurrencies.includes(defaultCurrency);
                
                if (defaultExists && !userChangedCurrency) {
                    currencySelect.value = defaultCurrency;
                } else if (allowedCurrencies.length > 0) {
                    // Select first allowed currency
                    currencySelect.selectedIndex = 0;
                }
            }
        }

        // When currency select changes, mark as user-changed
        document.getElementById('currency').addEventListener('change', function() {
            userChangedCurrency = true;
        });

        // When region changes, update countries and currency
        document.getElementById('region_select').addEventListener('change', function() {
            const region = this.value;
            const countrySelect = document.getElementById('country_select');
            const currencySelect = document.getElementById('currency');
            
            if (region && regionGroups[region]) {
                // Populate countries
                countrySelect.innerHTML = '<option value="">Select Country</option>';
                regionGroups[region].forEach(function(country) {
                    const option = document.createElement('option');
                    option.value = country;
                    option.textContent = country;
                    countrySelect.appendChild(option);
                });

                // Reset user changed flag when region changes
                userChangedCurrency = false;
                
                // Update currency options
                updateCurrencyOptions(region);
                
                // Load staff for this region
                loadStaff(region);
                if (typeof refreshExternalVendorDatalist === 'function') {
                    refreshExternalVendorDatalist(region);
                }
                if (typeof refreshBudgetAllocation === 'function') {
                    refreshBudgetAllocation();
                }
                if (typeof refreshPartnerHistory === 'function') {
                    refreshPartnerHistory();
                }
            } else {
                countrySelect.innerHTML = '<option value="">Select Country</option>';
                currencySelect.innerHTML = '<option value="">Select Currency</option>';
                
                // Clear staff select too
                const staffSelect = document.getElementById('staff_select');
                if (staffSelect) {
                    staffSelect.innerHTML = '<option value="">Select Staff Member</option>';
                }
            }
        });

        // When country changes, auto-select appropriate currency (only if user hasn't manually changed)
        document.getElementById('country_select').addEventListener('change', function() {
            const country = this.value;
            const region = document.getElementById('region_select').value;
            const currencySelect = document.getElementById('currency');
            
            if (!country || !region || userChangedCurrency) return;
            
            // Get the preferred currency for this country
            const preferredCurrency = countryPreferredCurrency[country];
            
            if (preferredCurrency) {
                // Check if this currency is allowed for the selected region
                const allowedCurrencies = regionAllowedCurrencies[region] || [];
                
                if (allowedCurrencies.includes(preferredCurrency)) {
                    // Auto-select the preferred currency
                    currencySelect.value = preferredCurrency;
                }
            }
        });

        // Vendor autocomplete functionality
        function setupVendorAutocomplete() {
            const vendorInput = document.getElementById('vendor_input');
            const salesforceIdInput = document.getElementById('salesforce_id_input');
            const matchStatusText = document.getElementById('match_status_text');
            const matchStatusBadge = document.getElementById('match_status_badge');
            let currentVendors = [];
            
            if (!vendorInput) return;
            
            // Fetch vendors from database
            async function fetchVendors(searchTerm = '') {
                try {
                    const response = await fetch('get_vendors.php?q=' + encodeURIComponent(searchTerm));
                    const data = await response.json();
                    // Handle API error response (e.g. database error)
                    if (data && typeof data === 'object' && data.error) {
                        return { error: data.error, vendors: data.vendors || [] };
                    }
                    // Ensure we always return an array for normal responses
                    const vendors = Array.isArray(data) ? data : (data.vendors || []);
                    currentVendors = vendors;
                    return vendors;
                } catch (error) {
                    console.error('Error fetching vendors:', error);
                    return [];
                }
            }
            
            // Display autocomplete suggestions
            function showAutocompleteSuggestions(vendors) {
                const autocompleteContainer = document.getElementById('vendor_autocomplete');
                if (!autocompleteContainer) return;
                
                // Clear previous suggestions
                autocompleteContainer.innerHTML = '';
                
                if (vendors.length === 0) {
                    autocompleteContainer.style.display = 'none';
                    return;
                }
                
                vendors.forEach(function(vendor, index) {
                    const div = document.createElement('div');
                    div.innerHTML = `
                        <strong>${escapeHtml(vendor.vendor_name)}</strong>
                        ${vendor.salesforce_id ? `<div class="vendor-details">Salesforce: ${vendor.salesforce_id}</div>` : ''}
                        ${vendor.country ? `<div class="vendor-details">Country: ${vendor.country}</div>` : ''}
                    `;
                    div.addEventListener('click', function() {
                        // Set vendor name
                        vendorInput.value = vendor.vendor_name;
                        
                        // Set Salesforce ID if available
                        if (vendor.salesforce_id) {
                            salesforceIdInput.value = vendor.salesforce_id;
                            matchStatusText.textContent = 'Matched to Salesforce';
                            if (matchStatusBadge) {
                                matchStatusBadge.innerHTML = '<span class="badge bg-success">? Matched</span>';
                            }
                        } else {
                            salesforceIdInput.value = '';
                            matchStatusText.textContent = 'No Salesforce match found';
                            if (matchStatusBadge) {
                                matchStatusBadge.innerHTML = '<span class="badge bg-warning">No Match</span>';
                            }
                        }
                        
                        // Hide autocomplete
                        autocompleteContainer.innerHTML = '';
                        autocompleteContainer.style.display = 'none';
                        if (typeof refreshPartnerHistory === 'function') {
                            refreshPartnerHistory();
                        }
                    });
                    
                    autocompleteContainer.appendChild(div);
                });
                
                autocompleteContainer.style.display = 'block';
            }
            
            // Helper function to escape HTML
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
            
            // Handle vendor input
            vendorInput.addEventListener('input', async function() {
                const searchTerm = this.value.trim();
                
                // Clear Salesforce ID when vendor changes
                salesforceIdInput.value = '';
                matchStatusText.textContent = 'Searching...';
                if (matchStatusBadge) {
                    matchStatusBadge.innerHTML = '';
                }
                
                if (searchTerm.length < 2) {
                    const autocompleteContainer = document.getElementById('vendor_autocomplete');
                    if (autocompleteContainer) {
                        autocompleteContainer.innerHTML = '';
                        autocompleteContainer.style.display = 'none';
                    }
                    matchStatusText.textContent = 'Type at least 2 characters to search';
                    return;
                }
                
                // Fetch and show suggestions
                const result = await fetchVendors(searchTerm);
                const vendors = Array.isArray(result) ? result : (result.vendors || []);
                const errorMsg = result && result.error ? result.error : null;
                
                showAutocompleteSuggestions(vendors);
                
                // Update match status
                if (errorMsg) {
                    matchStatusText.textContent = errorMsg;
                    if (matchStatusBadge) {
                        matchStatusBadge.innerHTML = '<span class="badge bg-danger">Error</span>';
                    }
                } else if (vendors.length === 0) {
                    matchStatusText.textContent = 'No matching vendors found';
                    if (matchStatusBadge) {
                        matchStatusBadge.innerHTML = '<span class="badge bg-warning">Not Found</span>';
                    }
                } else if (vendors.length === 1 && vendors[0].salesforce_id) {
                    // Auto-select if only one match with Salesforce ID
                    vendorInput.value = vendors[0].vendor_name;
                    salesforceIdInput.value = vendors[0].salesforce_id;
                    matchStatusText.textContent = 'Auto-matched to Salesforce';
                    if (matchStatusBadge) {
                        matchStatusBadge.innerHTML = '<span class="badge bg-success">? Matched</span>';
                    }
                    
                    const autocompleteContainer = document.getElementById('vendor_autocomplete');
                    if (autocompleteContainer) {
                        autocompleteContainer.innerHTML = '';
                        autocompleteContainer.style.display = 'none';
                    }
                } else {
                    matchStatusText.textContent = `${vendors.length} matches found. Click to select.`;
                    if (matchStatusBadge) {
                        matchStatusBadge.innerHTML = `<span class="badge bg-info">${vendors.length} Found</span>`;
                    }
                }
            });
            
            // Close autocomplete when clicking elsewhere
            document.addEventListener('click', function(e) {
                const autocompleteContainer = document.getElementById('vendor_autocomplete');
                if (autocompleteContainer && !vendorInput.contains(e.target) && !autocompleteContainer.contains(e.target)) {
                    autocompleteContainer.innerHTML = '';
                    autocompleteContainer.style.display = 'none';
                }
            });
            
            // Handle keyboard navigation
            vendorInput.addEventListener('keydown', function(e) {
                const autocompleteContainer = document.getElementById('vendor_autocomplete');
                if (!autocompleteContainer) return;
                
                const items = autocompleteContainer.getElementsByTagName('div');
                let activeItem = autocompleteContainer.querySelector('.autocomplete-active');
                
                // Down arrow
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (!activeItem) {
                        items[0]?.classList.add('autocomplete-active');
                    } else {
                        activeItem.classList.remove('autocomplete-active');
                        const nextItem = activeItem.nextElementSibling || items[0];
                        nextItem.classList.add('autocomplete-active');
                    }
                }
                // Up arrow
                else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!activeItem) {
                        items[items.length - 1]?.classList.add('autocomplete-active');
                    } else {
                        activeItem.classList.remove('autocomplete-active');
                        const prevItem = activeItem.previousElementSibling || items[items.length - 1];
                        prevItem.classList.add('autocomplete-active');
                    }
                }
                // Enter key
                else if (e.key === 'Enter' && activeItem) {
                    e.preventDefault();
                    activeItem.click();
                }
                // Escape key
                else if (e.key === 'Escape') {
                    autocompleteContainer.innerHTML = '';
                    autocompleteContainer.style.display = 'none';
                }
            });
            
            // Initialize existing vendor data if in view mode
            <?php if ($view_mode && !empty($existing_item['vendor'])): ?>
                window.addEventListener('load', function() {
                    // Set initial match status for existing items
                    const existingSalesforceId = '<?= addslashes($existing_item['project_code'] ?? '') ?>';
                    if (existingSalesforceId) {
                        matchStatusText.textContent = 'Matched to Salesforce';
                        if (matchStatusBadge) {
                            matchStatusBadge.innerHTML = '<span class="badge bg-success">? Matched</span>';
                        }
                    }
                });
            <?php endif; ?>
        }

        const __plannerYear = <?= (int) $planner_year_get ?>;
        const __excludeItemId = <?= $view_mode ? (int) $item_id : 0 ?>;

        function escapeHtmlGlobal(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
        }

        async function refreshExternalVendorDatalist(region) {
            const dl = document.getElementById('external_vendor_datalist');
            if (!dl || !region) return;
            try {
                const res = await fetch('get_form_options.php?region_group=' + encodeURIComponent(region) + '&country=');
                const data = await res.json();
                const opts = data.external_vendors || [];
                dl.innerHTML = '';
                opts.forEach(function (o) {
                    const op = document.createElement('option');
                    op.value = o.field_label || o.field_value || '';
                    dl.appendChild(op);
                });
            } catch (e) {
                console.error(e);
            }
        }

        async function refreshBudgetAllocation() {
            const panel = document.getElementById('budget_allocation_panel');
            const content = document.getElementById('budget_allocation_content');
            const yearLabel = document.getElementById('planner_year_label');
            const region = document.getElementById('region_select') ? document.getElementById('region_select').value : '';
            const itemTypeEl = document.getElementById('item_type_select');
            const itemType = itemTypeEl ? itemTypeEl.value : '';
            if (!panel || !content) return;
            if (!region || !itemType) {
                panel.style.display = 'none';
                return;
            }
            if (yearLabel) yearLabel.textContent = String(__plannerYear);
            try {
                const res = await fetch('budget_planner_api.php?region=' + encodeURIComponent(region) + '&year=' + encodeURIComponent(__plannerYear));
                const data = await res.json();
                if (data.error) {
                    content.innerHTML = '<span class="text-danger">' + escapeHtmlGlobal(data.error) + '</span>';
                    panel.style.display = 'block';
                    return;
                }
                const types = data.types || [];
                let row = null;
                for (let i = 0; i < types.length; i++) {
                    if (types[i].item_type === itemType) { row = types[i]; break; }
                }
                if (!row) {
                    panel.style.display = 'none';
                    return;
                }
                const ccy = data.currency || '';
                const fmt = function (n) {
                    return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                };
                let html = '<strong>Cap</strong> ' + ccy + ' ' + fmt(row.cap) +
                    ' &middot; <strong>Spent (this type)</strong> ' + ccy + ' ' + fmt(row.spent) +
                    ' &middot; <strong>Remaining</strong> <span class="' + (row.remaining < 0 ? 'text-danger' : 'text-success') + ' fw-semibold">' + ccy + ' ' + fmt(row.remaining) + '</span>';
                if (data.unspecified_spent > 0.01) {
                    html += '<div class="mt-1 text-muted">Some spend has no Item Type: ' + ccy + ' ' + fmt(data.unspecified_spent) + '</div>';
                }
                content.innerHTML = html;
                panel.style.display = 'block';
            } catch (e) {
                content.textContent = 'Could not load budget planner.';
                panel.style.display = 'block';
            }
        }

        async function refreshPartnerHistory() {
            const row = document.getElementById('partner_history_row');
            const content = document.getElementById('partner_history_content');
            const regionEl = document.getElementById('region_select');
            const vendorEl = document.getElementById('vendor_input');
            const extEl = document.getElementById('external_vendor_input');
            if (!row || !content) return;
            const region = regionEl ? regionEl.value : '';
            const vendor = vendorEl ? vendorEl.value.trim() : '';
            const ext = extEl ? extEl.value.trim() : '';
            if (!region || (!vendor && !ext)) {
                row.style.display = 'none';
                return;
            }
            try {
                let url = 'partner_spend_history.php?region=' + encodeURIComponent(region) +
                    '&vendor=' + encodeURIComponent(vendor) +
                    '&external_vendor=' + encodeURIComponent(ext);
                if (__excludeItemId) url += '&exclude_id=' + __excludeItemId;
                const res = await fetch(url);
                const data = await res.json();
                const items = data.items || [];
                if (items.length === 0) {
                    content.innerHTML = '<span class="text-muted">No other matching spend found for this partner in this region.</span>';
                    row.style.display = 'block';
                    return;
                }
                let html = '<div class="table-responsive"><table class="table table-sm table-bordered mb-0 bg-white"><thead><tr><th>PO</th><th>Activity</th><th class="text-end">Amount</th><th>Status</th><th>Type</th><th>Start</th></tr></thead><tbody>';
                items.forEach(function (it) {
                    const title = (it.activity_title || '').length > 45 ? (it.activity_title || '').substring(0, 45) + '\u2026' : (it.activity_title || '');
                    html += '<tr><td>' + escapeHtmlGlobal(it.po_number) + '</td><td>' + escapeHtmlGlobal(title) + '</td><td class="text-end">' +
                        escapeHtmlGlobal(it.currency) + ' ' + escapeHtmlGlobal(String(it.amount_requested)) + '</td><td>' +
                        escapeHtmlGlobal(it.status) + '</td><td>' + escapeHtmlGlobal(it.item_type) + '</td><td>' +
                        escapeHtmlGlobal((it.start_date || '').substring(0, 10)) + '</td></tr>';
                });
                html += '</tbody></table></div>';
                content.innerHTML = html;
                row.style.display = 'block';
            } catch (e) {
                content.textContent = 'Could not load partner history.';
                row.style.display = 'block';
            }
        }

        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide dismissible flash messages only (not inline info panels that reuse .alert)
            document.querySelectorAll('.alert.alert-dismissible').forEach(function (alertEl) {
                setTimeout(function () {
                    try {
                        const bsAlert = new bootstrap.Alert(alertEl);
                        bsAlert.close();
                    } catch (e) { /* ignore */ }
                }, 5000);
            });
            
            // Set default date to today for new items only
            <?php if (!$view_mode): ?>
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('start_date').value = today;
            <?php endif; ?>
            
            // Get current region value
            const regionSelect = document.getElementById('region_select');
            const currentRegion = regionSelect.value;
            
            // If region is already selected (from URL or existing item), initialize everything
            if (currentRegion) {
                // Initialize currency options first
                updateCurrencyOptions(currentRegion);
                
                // If we have an existing item, also set the country
                <?php if ($view_mode && isset($existing_item['country'])): ?>
                    const countrySelect = document.getElementById('country_select');
                    const existingCountry = '<?= addslashes($existing_item['country']) ?>';
                    
                    // Populate countries for the region
                    if (regionGroups[currentRegion]) {
                        countrySelect.innerHTML = '<option value="">Select Country</option>';
                        regionGroups[currentRegion].forEach(function(country) {
                            const option = document.createElement('option');
                            option.value = country;
                            option.textContent = country;
                            if (country === existingCountry) {
                                option.selected = true;
                            }
                            countrySelect.appendChild(option);
                        });
                    }
                    
                    // Load staff for this region
                    loadStaff(currentRegion);
                <?php else: ?>
                    // For new items, trigger the region change to populate countries
                    regionSelect.dispatchEvent(new Event('change'));
                <?php endif; ?>
            } else {
                // No region selected, initialize with default
                updateCurrencyOptions('');
            }
            
            // Initialize vendor autocomplete
            setupVendorAutocomplete();

            const itemTypeSel = document.getElementById('item_type_select');
            if (itemTypeSel) {
                itemTypeSel.addEventListener('change', function () {
                    refreshBudgetAllocation();
                });
            }
            const vendorInp = document.getElementById('vendor_input');
            if (vendorInp) {
                vendorInp.addEventListener('blur', function () {
                    setTimeout(function () { refreshPartnerHistory(); }, 300);
                });
            }
            const extInp = document.getElementById('external_vendor_input');
            if (extInp) {
                extInp.addEventListener('blur', function () {
                    setTimeout(function () { refreshPartnerHistory(); }, 300);
                });
            }
            if (currentRegion) {
                refreshExternalVendorDatalist(currentRegion);
                setTimeout(function () {
                    refreshBudgetAllocation();
                    refreshPartnerHistory();
                }, 500);
            }
        });
    </script>
</body>
</html>