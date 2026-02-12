<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// ===== ADDED: Field name mappings for user-friendly display =====
$fieldLabels = [
    'po_number' => 'PO Number',
    'po_prefix' => 'PO Prefix',
    'region' => 'Region',
    'country' => 'Country',
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'invoiced_date' => 'Invoice Date',
    'amount_requested' => 'Amount',
    'currency' => 'Currency',
    'activity_title' => 'Activity',
    'status' => 'Status',
    'frequency_of_spend' => 'Frequency',
    'vendor' => 'Vendor Name',
    'external_vendor' => 'External Vendor',
    'vendor_contact' => 'Contact',
    'account' => 'GL Account',
    'sub_account' => 'Sub Account',
    'budget_category' => 'Category',
    'activity_description' => 'Description',
    'comments' => 'Comments',
    'associated_epos_staff' => 'Owner',
    'department' => 'Department',
    'project_code' => 'Salesforce ID',  // RENAMED from "Project Code"
    'item_type' => 'Type',
    'path' => 'Path',
    'is_global' => 'Global',
    'local_po_reference' => 'Local Ref',
    'entry_creation_date' => 'Created',
    'entry_updated_date' => 'Updated'
];

// Status color mapping
$statusColors = [
    'Invoiced' => 'success',
    'Planned' => 'warning',
    'Executed' => 'info',
    'Cancelled' => 'danger',
    'Allocated' => 'primary'
];

// Region color mapping
$regionColors = [
    'DACH' => 'primary',
    'UKI' => 'info',
    'NORD' => 'success',
    'AMER' => 'warning',
    'FRANCE' => 'danger',
    'ANZ' => 'secondary'
];
// ===== END NEW MAPPINGS =====

$pdo = getDBConnection();

// ===== FIXED: Get parameters with proper "All Years" handling =====
$selected_region = $_GET['region'] ?? 'AMER';
$selected_year = $_GET['year'] ?? 'all';  // Changed from '' to 'all'
$status_filter = $_GET['status'] ?? '';

// Validate region exists
global $REGIONAL_SETTINGS;
if (!isset($REGIONAL_SETTINGS[$selected_region])) {
    $selected_region = 'AMER'; // Fallback to AMER if invalid region
}

// Collect advanced filters
$advanced_filters = [
    'amount_min' => $_GET['amount_min'] ?? '',
    'amount_max' => $_GET['amount_max'] ?? '',
    'account' => $_GET['account'] ?? '',
    'sub_account' => $_GET['sub_account'] ?? '',
    'start_date_from' => $_GET['start_date_from'] ?? '',
    'start_date_to' => $_GET['start_date_to'] ?? '',
    'end_date_from' => $_GET['end_date_from'] ?? '',
    'end_date_to' => $_GET['end_date_to'] ?? '',
    'invoiced_date_from' => $_GET['invoiced_date_from'] ?? '',
    'invoiced_date_to' => $_GET['invoiced_date_to'] ?? '',
    'associated_epos_staff' => $_GET['associated_epos_staff'] ?? '',
    'country' => $_GET['country'] ?? '',
    'po_number' => $_GET['po_number'] ?? '',
    'vendor' => $_GET['vendor'] ?? '',
    'external_vendor' => $_GET['external_vendor'] ?? '',
    'activity_title' => $_GET['activity_title'] ?? '',
    'frequency' => $_GET['frequency'] ?? '',
    'budget_category' => $_GET['budget_category'] ?? '',
    'item_type' => $_GET['item_type'] ?? '',
    'sf_match' => $_GET['sf_match'] ?? ''
];

// Get regional data using the new functions
$regional_items = getRegionalItems($pdo, $selected_region, $selected_year, $status_filter, $advanced_filters);
$regional_summary = getRegionalSummary($pdo, $selected_region, $selected_year);
$status_distribution = getRegionalStatusDistribution($pdo, $selected_region, $selected_year);
$available_years = getAvailableYearsForRegion($pdo, $selected_region);

// Get currency info
$currency = $REGIONAL_SETTINGS[$selected_region]['currency'] ?? 'EUR';
$currency_symbol = $CURRENCY_SYMBOLS[$currency] ?? '€';

// For backward compatibility with existing code
$regional_spent = $regional_summary['total_amount'];
$budget_limit = $regional_summary['budget_limit'];
$remaining_budget = $regional_summary['remaining_budget'];
$usage_percentage = $regional_summary['utilization_percentage'];

// Calculate Salesforce match rate
$matchedCount = count(array_filter($regional_items, function($item) {
    return !empty($item['project_code']);
}));
$matchRate = count($regional_items) > 0 ? round(($matchedCount / count($regional_items)) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $selected_region ?> Regional Budget - <?= APP_NAME ?></title>
    
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
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }
        
        .card-header {
            background: #f8f9fa;
            border-bottom: 1px solid #e1e5eb;
            padding: 1rem 1.25rem;
            font-weight: 600;
            color: #2a3547;
            border-top-left-radius: 12px !important;
            border-top-right-radius: 12px !important;
        }
        
        .stat-card {
            border: none;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        
        .stat-card-primary {
            background: linear-gradient(135deg, #00a399 0%, #00353d 100%);
            color: white;
        }
        
        .stat-card-success {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
        }
        
        .stat-card-warning {
            background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
            color: #212529;
        }
        
        .stat-card-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        
        .stat-number {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
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
        
        .table {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table th {
            font-weight: 600;
            background: #f8f9fa;
            border-bottom: 2px solid #e1e5eb;
            padding: 0.875rem 1rem;
        }
        
        .table td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }
        
        .badge {
            border-radius: 20px;
            padding: 0.375rem 0.75rem;
            font-weight: 500;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
        }
        
        .progress-bar {
            border-radius: 4px;
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
        
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }
        
        .year-badge {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 0.375rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .vendor-match { 
            background: #d4edda; 
            padding: 0.25rem 0.5rem; 
            border-radius: 4px; 
            display: inline-block;
            font-size: 0.875rem;
        }
        
        .vendor-unmatched { 
            background: #f8d7da; 
            padding: 0.25rem 0.5rem; 
            border-radius: 4px; 
            display: inline-block;
            font-size: 0.875rem;
        }
        
        .amount-cell { 
            font-family: 'Courier New', monospace; 
            font-weight: bold; 
        }
        
        .sf-id-match {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            background: #f8f9fa;
            padding: 0.125rem 0.375rem;
            border-radius: 4px;
        }
        
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
        }
        
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #e1e5eb;
            border-top-left-radius: 12px !important;
            border-top-right-radius: 12px !important;
        }
        
        .page-header-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
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
            
            .stat-number {
                font-size: 1.75rem;
            }
            
            .page-header-buttons {
                margin-top: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Include the header navigation -->
    <?php require_once 'header.php'; ?>

    <div class="container">
        <!-- Region Header -->
        <div class="page-header">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                <div>
                    <div class="d-flex align-items-center mb-2">
                        <h1 class="page-title mb-0"><?= $selected_region ?> Regional Budget</h1>
                        <span class="year-badge ms-3"><?= $selected_year == 'all' ? 'All Years' : $selected_year ?></span>
                    </div>
                    <p class="mb-0 opacity-90">Budget Intelligence Dashboard for <?= $selected_region ?> region</p>
                    <?php if ($status_filter): ?>
                    <div class="mt-2">
                        <small>Filtered by status: </small>
                        <?= getStatusBadge($status_filter) ?>
                        <a href="?region=<?= $selected_region ?>&year=<?= $selected_year ?>" class="btn btn-sm btn-outline-light ms-2">
                            <i class="ti ti-x"></i> Clear
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="page-header-buttons">
                    <a href="add_item.php?region=<?= $selected_region ?>" class="btn btn-success">
                        <i class="ti ti-plus"></i> Add Item
                    </a>
                    <a href="javascript:void(0)" onclick="exportFilteredData()" class="btn btn-outline-light">
                        <i class="ti ti-download"></i> Export
                    </a>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-card">
            <form method="GET" class="row g-3 align-items-center" id="filterForm">
                <!-- Basic Filters -->
                <div class="col-md-3">
                    <label class="form-label fw-bold"><i class="ti ti-world me-1"></i>Region</label>
                    <select name="region" class="form-select">
                        <?php foreach ($REGIONAL_SETTINGS as $region => $settings): ?>
                        <option value="<?= $region ?>" <?= $selected_region == $region ? 'selected' : '' ?>>
                            <?= $region ?> (<?= $settings['currency'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <input type="hidden" name="form_submitted" value="1">
                
                <div class="col-md-3">
                    <label class="form-label fw-bold"><i class="ti ti-calendar me-1"></i>Year</label>
                    <select name="year" class="form-select">
                        <option value="all" <?= $selected_year == 'all' ? 'selected' : '' ?>>All Years</option>
                        <?php foreach ($available_years as $year): ?>
                        <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label fw-bold"><i class="ti ti-tag me-1"></i>Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <?php foreach (['Planned', 'Invoiced', 'Executed', 'Cancelled', 'Allocated'] as $status): ?>
                        <option value="<?= $status ?>" <?= $status_filter == $status ? 'selected' : '' ?>>
                            <?= $status ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2">
                        <i class="ti ti-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()" title="Clear all filters">
                        <i class="ti ti-x"></i>
                    </button>
                </div>
                
                <!-- Advanced Filters (collapsible) -->
                <div class="col-12 mt-3">
                    <button class="btn btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters" aria-expanded="false" aria-controls="advancedFilters">
                        <i class="ti ti-adjustments-horizontal me-1"></i> Advanced Filters
                        <i class="ti ti-chevron-down ms-1"></i>
                    </button>
                </div>
                
                <!-- Advanced Filters (collapsible) -->
                <div class="collapse mt-3" id="advancedFilters">
                    <div class="col-md-12 mb-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="filter_logic" id="filter_and" value="AND" checked>
                            <label class="form-check-label" for="filter_and">
                                <i class="ti ti-filter"></i> Match ALL filters (AND)
                            </label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="filter_logic" id="filter_or" value="OR">
                            <label class="form-check-label" for="filter_or">
                                <i class="ti ti-search"></i> Match ANY filter (OR)
                            </label>
                        </div>
                    </div>
                    
                    <div class="card card-body border-0 p-4" style="background: rgba(0,0,0,0.03);">
                        <div class="row g-3">
                            <!-- Row 1: Amount Range & Account Filters -->
                            <div class="col-md-3">
                                <label class="form-label">Amount Range (Min)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= $currency_symbol ?></span>
                                    <input type="number" name="amount_min" class="form-control" placeholder="Min" 
                                           value="<?= $_GET['amount_min'] ?? '' ?>" step="0.01" min="0">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Amount Range (Max)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= $currency_symbol ?></span>
                                    <input type="number" name="amount_max" class="form-control" placeholder="Max" 
                                           value="<?= $_GET['amount_max'] ?? '' ?>" step="0.01" min="0">
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">GL Account</label>
                                <?php 
                                // Get unique accounts for this region
                                $accountStmt = $pdo->prepare("SELECT DISTINCT account FROM budget_items WHERE region = ? AND account IS NOT NULL AND account != '' ORDER BY account");
                                $accountStmt->execute([$selected_region]);
                                $accounts = $accountStmt->fetchAll(PDO::FETCH_COLUMN);
                                ?>
                                <select name="account" class="form-select">
                                    <option value="">All Accounts</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option value="<?= htmlspecialchars($account) ?>" <?= ($_GET['account'] ?? '') == $account ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($account) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Sub Account</label>
                                <?php 
                                // Get unique sub-accounts for this region
                                $subAccountStmt = $pdo->prepare("SELECT DISTINCT sub_account FROM budget_items WHERE region = ? AND sub_account IS NOT NULL AND sub_account != '' ORDER BY sub_account");
                                $subAccountStmt->execute([$selected_region]);
                                $sub_accounts = $subAccountStmt->fetchAll(PDO::FETCH_COLUMN);
                                ?>
                                <select name="sub_account" class="form-select">
                                    <option value="">All Sub Accounts</option>
                                    <?php foreach ($sub_accounts as $sub_account): ?>
                                        <option value="<?= htmlspecialchars($sub_account) ?>" <?= ($_GET['sub_account'] ?? '') == $sub_account ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sub_account) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Row 2: Date Filters -->
                            <div class="col-md-3">
                                <label class="form-label">Start Date From</label>
                                <input type="date" name="start_date_from" class="form-control" 
                                       value="<?= $_GET['start_date_from'] ?? '' ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Start Date To</label>
                                <input type="date" name="start_date_to" class="form-control" 
                                       value="<?= $_GET['start_date_to'] ?? '' ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">End Date From</label>
                                <input type="date" name="end_date_from" class="form-control" 
                                       value="<?= $_GET['end_date_from'] ?? '' ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">End Date To</label>
                                <input type="date" name="end_date_to" class="form-control" 
                                       value="<?= $_GET['end_date_to'] ?? '' ?>">
                            </div>
                            
                            <!-- Row 3: More Date Filters and Staff -->
                            <div class="col-md-3">
                                <label class="form-label">Invoiced Date From</label>
                                <input type="date" name="invoiced_date_from" class="form-control" 
                                       value="<?= $_GET['invoiced_date_from'] ?? '' ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Invoiced Date To</label>
                                <input type="date" name="invoiced_date_to" class="form-control" 
                                       value="<?= $_GET['invoiced_date_to'] ?? '' ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Associated EPOS Staff</label>
                                <?php 
                                // Get unique staff for this region
                                $staffStmt = $pdo->prepare("SELECT DISTINCT associated_epos_staff FROM budget_items WHERE region = ? AND associated_epos_staff IS NOT NULL AND associated_epos_staff != '' ORDER BY associated_epos_staff");
                                $staffStmt->execute([$selected_region]);
                                $staff_members = $staffStmt->fetchAll(PDO::FETCH_COLUMN);
                                ?>
                                <select name="associated_epos_staff" class="form-select">
                                    <option value="">All Staff</option>
                                    <?php foreach ($staff_members as $staff): ?>
                                        <option value="<?= htmlspecialchars($staff) ?>" <?= ($_GET['associated_epos_staff'] ?? '') == $staff ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($staff) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Country</label>
                                <?php 
                                // Get unique countries for this region
                                $countryStmt = $pdo->prepare("SELECT DISTINCT country FROM budget_items WHERE region = ? AND country IS NOT NULL AND country != '' ORDER BY country");
                                $countryStmt->execute([$selected_region]);
                                $countries = $countryStmt->fetchAll(PDO::FETCH_COLUMN);
                                ?>
                                <select name="country" class="form-select">
                                    <option value="">All Countries</option>
                                    <?php foreach ($countries as $country): ?>
                                        <option value="<?= htmlspecialchars($country) ?>" <?= ($_GET['country'] ?? '') == $country ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($country) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Row 4: Search Filters -->
                            <div class="col-md-3">
                                <label class="form-label">PO Number</label>
                                <input type="text" name="po_number" class="form-control" placeholder="PO number..." 
                                       value="<?= htmlspecialchars($_GET['po_number'] ?? '') ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Vendor Name</label>
                                <input type="text" name="vendor" class="form-control" placeholder="Vendor name..." 
                                       value="<?= htmlspecialchars($_GET['vendor'] ?? '') ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">External Vendor</label>
                                <input type="text" name="external_vendor" class="form-control" placeholder="External vendor..." 
                                       value="<?= htmlspecialchars($_GET['external_vendor'] ?? '') ?>">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Activity Title</label>
                                <input type="text" name="activity_title" class="form-control" placeholder="Activity title..." 
                                       value="<?= htmlspecialchars($_GET['activity_title'] ?? '') ?>">
                            </div>
                            
                            <!-- Row 5: Dropdown Filters -->
                            <div class="col-md-3">
                                <label class="form-label">Frequency</label>
                                <?php 
                                // Get unique frequencies for this region
                                $frequencyStmt = $pdo->prepare("SELECT DISTINCT frequency_of_spend FROM budget_items WHERE region = ? AND frequency_of_spend IS NOT NULL AND frequency_of_spend != '' ORDER BY frequency_of_spend");
                                $frequencyStmt->execute([$selected_region]);
                                $frequencies = $frequencyStmt->fetchAll(PDO::FETCH_COLUMN);
                                ?>
                                <select name="frequency" class="form-select">
                                    <option value="">All Frequencies</option>
                                    <?php foreach ($frequencies as $frequency): ?>
                                        <option value="<?= htmlspecialchars($frequency) ?>" <?= ($_GET['frequency'] ?? '') == $frequency ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($frequency) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Budget Category</label>
                                <?php 
                                // Get unique categories for this region
                                $categoryStmt = $pdo->prepare("SELECT DISTINCT budget_category FROM budget_items WHERE region = ? AND budget_category IS NOT NULL AND budget_category != '' ORDER BY budget_category");
                                $categoryStmt->execute([$selected_region]);
                                $categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);
                                ?>
                                <select name="budget_category" class="form-select">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category) ?>" <?= ($_GET['budget_category'] ?? '') == $category ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Salesforce Match</label>
                                <select name="sf_match" class="form-select">
                                    <option value="">All</option>
                                    <option value="matched" <?= ($_GET['sf_match'] ?? '') == 'matched' ? 'selected' : '' ?>>Matched Only</option>
                                    <option value="unmatched" <?= ($_GET['sf_match'] ?? '') == 'unmatched' ? 'selected' : '' ?>>Unmatched Only</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Item Type</label>
                                <?php 
                                // Get unique item types for this region
                                $typeStmt = $pdo->prepare("SELECT DISTINCT item_type FROM budget_items WHERE region = ? AND item_type IS NOT NULL AND item_type != '' ORDER BY item_type");
                                $typeStmt->execute([$selected_region]);
                                $item_types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);
                                ?>
                                <select name="item_type" class="form-select">
                                    <option value="">All Types</option>
                                    <?php foreach ($item_types as $type): ?>
                                        <option value="<?= htmlspecialchars($type) ?>" <?= ($_GET['item_type'] ?? '') == $type ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Reset and Apply Buttons -->
                            <div class="col-md-12 mt-3">
                                <div class="d-flex justify-content-end">
                                    <button type="button" class="btn btn-outline-secondary me-2" onclick="clearAdvancedFilters()">
                                        <i class="ti ti-rotate-clockwise"></i> Clear Advanced
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="ti ti-search"></i> Apply Advanced Filters
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hidden inputs for current filters -->
                <input type="hidden" name="page" value="regional_view">
            </form>
        </div>

        <!-- Active Filters Display -->
        <?php 
        $active_filters = [];
        if ($status_filter) $active_filters[] = "Status: " . htmlspecialchars($status_filter);
        if (!empty($advanced_filters['amount_min'])) $active_filters[] = "Min Amount: " . formatCurrency($advanced_filters['amount_min'], $currency);
        if (!empty($advanced_filters['amount_max'])) $active_filters[] = "Max Amount: " . formatCurrency($advanced_filters['amount_max'], $currency);
        if (!empty($advanced_filters['account'])) $active_filters[] = "Account: " . htmlspecialchars($advanced_filters['account']);
        if (!empty($advanced_filters['sub_account'])) $active_filters[] = "Sub Account: " . htmlspecialchars($advanced_filters['sub_account']);
        if (!empty($advanced_filters['associated_epos_staff'])) $active_filters[] = "Staff: " . htmlspecialchars($advanced_filters['associated_epos_staff']);
        if (!empty($advanced_filters['country'])) $active_filters[] = "Country: " . htmlspecialchars($advanced_filters['country']);
        if (!empty($advanced_filters['po_number'])) $active_filters[] = "PO: " . htmlspecialchars($advanced_filters['po_number']);
        if (!empty($advanced_filters['vendor'])) $active_filters[] = "Vendor: " . htmlspecialchars($advanced_filters['vendor']);
        if (!empty($advanced_filters['external_vendor'])) $active_filters[] = "Ext Vendor: " . htmlspecialchars($advanced_filters['external_vendor']);
        if (!empty($advanced_filters['activity_title'])) $active_filters[] = "Activity: " . htmlspecialchars($advanced_filters['activity_title']);
        if (!empty($advanced_filters['frequency'])) $active_filters[] = "Frequency: " . htmlspecialchars($advanced_filters['frequency']);
        if (!empty($advanced_filters['budget_category'])) $active_filters[] = "Category: " . htmlspecialchars($advanced_filters['budget_category']);
        if (!empty($advanced_filters['item_type'])) $active_filters[] = "Type: " . htmlspecialchars($advanced_filters['item_type']);
        if (!empty($advanced_filters['sf_match'])) $active_filters[] = "SF Match: " . ucfirst($advanced_filters['sf_match']);

        // Date filters
        $date_filters = [];
        if (!empty($advanced_filters['start_date_from'])) $date_filters[] = "Start ≥ " . htmlspecialchars($advanced_filters['start_date_from']);
        if (!empty($advanced_filters['start_date_to'])) $date_filters[] = "Start ≤ " . htmlspecialchars($advanced_filters['start_date_to']);
        if (!empty($advanced_filters['end_date_from'])) $date_filters[] = "End ≥ " . htmlspecialchars($advanced_filters['end_date_from']);
        if (!empty($advanced_filters['end_date_to'])) $date_filters[] = "End ≤ " . htmlspecialchars($advanced_filters['end_date_to']);
        if (!empty($advanced_filters['invoiced_date_from'])) $date_filters[] = "Invoice ≥ " . htmlspecialchars($advanced_filters['invoiced_date_from']);
        if (!empty($advanced_filters['invoiced_date_to'])) $date_filters[] = "Invoice ≤ " . htmlspecialchars($advanced_filters['invoiced_date_to']);

        if (!empty($date_filters)) {
            $active_filters[] = "Dates: " . implode(", ", $date_filters);
        }

        if (!empty($active_filters)): ?>
        <div class="alert alert-info alert-dismissible fade show mt-3" role="alert">
            <div class="d-flex align-items-center">
                <i class="ti ti-filter me-2"></i>
                <div>
                    <strong>Active Filters:</strong>
                    <?php foreach ($active_filters as $filter): ?>
                        <span class="badge bg-primary me-1 mb-1"><?= $filter ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <a href="?region=<?= $selected_region ?>&year=<?= $selected_year ?>" class="btn btn-sm btn-outline-light ms-3">
                <i class="ti ti-x"></i> Clear All
            </a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Budget Progress -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="ti ti-chart-line me-2"></i>Budget Utilization <?= $selected_year == 'all' ? '(All Years)' : "for $selected_year" ?>
                    <?php if ($status_filter): ?>
                    <small class="text-muted">(<?= $status_filter ?> only)</small>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="progress budget-progress mb-2">
                            <div class="progress-bar 
                                <?= $usage_percentage > 90 ? 'bg-danger' : ($usage_percentage > 75 ? 'bg-warning' : 'bg-success') ?>" 
                                role="progressbar" 
                                style="width: <?= $usage_percentage ?>%"
                                aria-valuenow="<?= $usage_percentage ?>" 
                                aria-valuemin="0" 
                                aria-valuemax="100">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <small class="text-muted">
                                <?= number_format($usage_percentage, 1) ?>% used
                            </small>
                            <small class="text-muted">
                                <?= formatCurrency($regional_spent, $currency) ?> of <?= formatCurrency($budget_limit, $currency) ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="h2 mb-1 <?= $remaining_budget < ($budget_limit * 0.1) ? 'text-danger' : 'text-success' ?>">
                            <?= formatCurrency($remaining_budget, $currency) ?>
                        </div>
                        <small class="text-muted">Remaining Budget</small>
                        <?php if ($remaining_budget < 0): ?>
                        <div class="alert alert-danger mt-2 p-2">
                            <i class="ti ti-alert-triangle me-1"></i> Budget exceeded by <?= formatCurrency(abs($remaining_budget), $currency) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Regional Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card stat-card-primary">
                    <div class="card-body text-center">
                        <i class="ti ti-wallet fa-2x mb-2"></i>
                        <h6>Budget Limit <?= $selected_year == 'all' ? '(All Years)' : "($selected_year)" ?></h6>
                        <div class="stat-number"><?= formatCurrency($budget_limit, $currency) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card stat-card-success">
                    <div class="card-body text-center">
                        <i class="ti ti-currency-dollar fa-2x mb-2"></i>
                        <h6>Amount Spent <?= $selected_year == 'all' ? '(All Years)' : "($selected_year)" ?></h6>
                        <div class="stat-number"><?= formatCurrency($regional_spent, $currency) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card stat-card-warning">
                    <div class="card-body text-center">
                        <i class="ti ti-pig-money fa-2x mb-2"></i>
                        <h6>Remaining <?= $selected_year == 'all' ? '(All Years)' : "($selected_year)" ?></h6>
                        <div class="stat-number"><?= formatCurrency($remaining_budget, $currency) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card stat-card-danger">
                    <div class="card-body text-center">
                        <i class="ti ti-brand-salesforce fa-2x mb-2"></i>
                        <h6>SFDC Matched</h6>
                        <div class="stat-number"><?= $matchRate ?>%</div>
                        <small><?= $matchedCount ?> of <?= count($regional_items) ?> items</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Distribution -->
        <?php if (!empty($status_distribution) && $selected_year != 'all'): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="ti ti-chart-pie me-2"></i>Status Distribution for <?= $selected_year ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($status_distribution as $status_data): ?>
                    <div class="col-md-2 mb-2">
                        <div class="text-center">
                            <div class="mb-1">
                                <?= getStatusBadge($status_data['status']) ?>
                            </div>
                            <div class="h5 mb-1"><?= $status_data['count'] ?></div>
                            <small class="text-muted">
                                <?= formatCurrency($status_data['total'], $currency) ?>
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Regional Items Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="ti ti-list me-2"></i> Budget Items for <?= $selected_region ?> <?= $selected_year == 'all' ? '(All Years)' : "($selected_year)" ?>
                </h5>
                <div>
                    <span class="badge bg-light text-dark me-2"><?= count($regional_items) ?> items</span>
                    <span class="badge bg-primary">Total: <?= formatCurrency(array_sum(array_column($regional_items, 'amount_requested')), $currency) ?></span>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($regional_items) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>PO Number</th>
                                <th>Activity</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                                <th>Vendor</th>
                                <th>Dates</th>
                                <th>SF ID</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($regional_items as $item): 
                                $item_year = $item['start_date'] ? date('Y', strtotime($item['start_date'])) : date('Y', strtotime($item['entry_creation_date']));
                                // Check if vendor has Salesforce ID
                                $hasSalesforceId = !empty($item['project_code']);
                                $vendorClass = $hasSalesforceId ? 'vendor-match' : 'vendor-unmatched';
                                
                                // Check delete permissions for this item
                                $canDelete = false;
                                $deleteReason = '';
                                
                                if ($_SESSION['role'] === 'admin') {
                                    // Admin can delete anything
                                    $canDelete = true;
                                    $deleteReason = 'Admin can delete any item';
                                } elseif ($_SESSION['role'] === 'manager') {
                                    // Manager can only delete items from their region
                                    $userRegion = $_SESSION['user_region'] ?? null;
                                    
                                    if ($userRegion && $item['region'] === $userRegion) {
                                        $canDelete = true;
                                        $deleteReason = 'Manager can delete items from their region';
                                    } else {
                                        $deleteReason = 'Manager can only delete items from ' . htmlspecialchars($userRegion ?? 'their assigned region');
                                    }
                                } else {
                                    $deleteReason = 'No delete permissions for your role';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong class="text-primary"><?= htmlspecialchars($item['po_number']) ?></strong>
                                    <?php if (!empty($item['po_prefix'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($item['po_prefix']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($item['activity_title']) ?>
                                    <?php if ($selected_year != 'all' && $item_year != $selected_year): ?>
                                        <small class="text-muted ms-1">(<?= $item_year ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold amount-cell">
                                    <?= formatCurrency($item['amount_requested'], $currency) ?>
                                </td>
                                <td><?= getStatusBadge($item['status']) ?></td>
                                <td>
                                    <div class="<?= $vendorClass ?>">
                                        <?= htmlspecialchars($item['vendor']) ?>
                                        <?php if ($hasSalesforceId): ?>
                                            <br><small class="text-success"><i class="ti ti-check"></i> Matched</small>
                                        <?php else: ?>
                                            <br><small class="text-danger"><i class="ti ti-alert-circle"></i> Unmatched</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($item['start_date']): ?>
                                        <?= date('M y', strtotime($item['start_date'])) ?>
                                    <?php endif; ?>
                                    <?php if ($item['end_date']): ?>
                                        <br><small>to <?= date('M y', strtotime($item['end_date'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($hasSalesforceId): ?>
                                        <code class="text-success sf-id-match"><?= htmlspecialchars($item['project_code']) ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="edit_item.php?id=<?= $item['id'] ?>" class="btn btn-outline-primary" title="Edit">
                                            <i class="ti ti-edit"></i>
                                        </a>
                                        <a href="add_item.php?id=<?= $item['id'] ?>" class="btn btn-outline-info" title="View">
                                            <i class="ti ti-eye"></i>
                                        </a>
                                        <a href="vendor_match.php?po=<?= urlencode($item['po_number']) ?>&vendor=<?= urlencode($item['vendor']) ?>" 
                                           class="btn btn-outline-success" title="Match to Salesforce">
                                            <i class="ti ti-link"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="ti ti-inbox fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No budget items found for <?= $selected_region ?> <?= $selected_year == 'all' ? '' : "in $selected_year" ?></h4>
                    <p class="text-muted mb-4">
                        <?php if ($status_filter): ?>
                        Try removing the status filter or select a different year
                        <?php else: ?>
                        Get started by adding your first budget item
                        <?php endif; ?>
                    </p>
                    <a href="add_item.php?region=<?= $selected_region ?>" class="btn btn-success btn-lg">
                        <i class="ti ti-plus"></i> Add First Item
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabler JavaScript (CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
    
    <!-- Bootstrap Bundle (Tabler depends on Bootstrap) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Update the page title with region name
            let title = "<?= $selected_region ?> Regional Budget";
            let year = "<?= $selected_year ?>";
            let status = "<?= $status_filter ?>";
            
            if (year && year != 'all') title += " - " + year;
            if (status) title += " (" + status + ")";
            title += " - <?= APP_NAME ?>";
            
            document.title = title;
            
            // Highlight rows with unmatched vendors
            document.querySelectorAll('.vendor-unmatched').forEach(el => {
                const row = el.closest('tr');
                if (row) {
                    row.classList.add('table-warning');
                }
            });
            
            // Highlight rows with matched vendors
            document.querySelectorAll('.vendor-match').forEach(el => {
                const row = el.closest('tr');
                if (row) {
                    row.classList.add('table-success');
                }
            });
            
            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
    </script>

    <!-- Filter JavaScript -->
    <script>
        // Clear all filters
        function clearFilters() {
            window.location.href = '?region=<?= $selected_region ?>&year=all';
        }

        // Clear only advanced filters
        function clearAdvancedFilters() {
            const form = document.getElementById('filterForm');
            const advancedInputs = form.querySelectorAll('#advancedFilters input, #advancedFilters select');
            
            advancedInputs.forEach(input => {
                if (input.type === 'text' || input.type === 'number' || input.type === 'date') {
                    input.value = '';
                } else if (input.tagName === 'SELECT') {
                    input.selectedIndex = 0;
                }
            });
            
            // Remove advanced filter parameters from URL and submit
            const url = new URL(window.location);
            const params = new URLSearchParams(url.search);
            
            // Remove advanced filter parameters
            const advancedParams = [
                'amount_min', 'amount_max', 'account', 'sub_account', 'start_date_from', 
                'start_date_to', 'end_date_from', 'end_date_to', 'invoiced_date_from',
                'invoiced_date_to', 'associated_epos_staff', 'country', 'po_number',
                'vendor', 'external_vendor', 'activity_title', 'frequency', 
                'budget_category', 'item_type', 'sf_match'
            ];
            
            advancedParams.forEach(param => {
                params.delete(param);
            });
            
            window.location.href = '?' + params.toString();
        }

        // Toggle advanced filters icon
        document.addEventListener('DOMContentLoaded', function() {
            const advancedFiltersToggle = document.querySelector('[data-bs-target="#advancedFilters"]');
            const advancedFiltersCollapse = document.getElementById('advancedFilters');
            
            if (advancedFiltersToggle && advancedFiltersCollapse) {
                advancedFiltersCollapse.addEventListener('show.bs.collapse', function() {
                    const icon = advancedFiltersToggle.querySelector('.ti-chevron-down, .ti-chevron-up');
                    if (icon) {
                        icon.classList.replace('ti-chevron-down', 'ti-chevron-up');
                    }
                });
                
                advancedFiltersCollapse.addEventListener('hide.bs.collapse', function() {
                    const icon = advancedFiltersToggle.querySelector('.ti-chevron-down, .ti-chevron-up');
                    if (icon) {
                        icon.classList.replace('ti-chevron-up', 'ti-chevron-down');
                    }
                });
                
                // If any advanced filters are active, open the panel
                const urlParams = new URLSearchParams(window.location.search);
                const advancedParams = [
                    'amount_min', 'amount_max', 'account', 'sub_account', 'start_date_from', 
                    'start_date_to', 'end_date_from', 'end_date_to', 'invoiced_date_from',
                    'invoiced_date_to', 'associated_epos_staff', 'country', 'po_number',
                    'vendor', 'external_vendor', 'activity_title', 'frequency', 
                    'budget_category', 'item_type', 'sf_match', 'status'
                ];
                
                const hasAdvancedFilters = advancedParams.some(param => {
                    const value = urlParams.get(param);
                    return value !== null && value !== '' && value !== 'all';
                });
                
                if (hasAdvancedFilters) {
                    const bsCollapse = new bootstrap.Collapse(advancedFiltersCollapse, {
                        toggle: false
                    });
                    bsCollapse.show();
                }
            }
            
            // Auto-submit form when basic filters change (optional)
            const basicFilters = document.querySelectorAll('select[name="region"], select[name="year"], select[name="status"]');
            basicFilters.forEach(select => {
                select.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            });
        });

        // Export filtered data
        function exportFilteredData() {
            const form = document.getElementById('filterForm');
            const exportForm = document.createElement('form');
            exportForm.method = 'GET';
            exportForm.action = 'export_region.php';
            exportForm.style.display = 'none';
            
            // Copy all form inputs
            const inputs = form.querySelectorAll('input, select');
            inputs.forEach(input => {
                if (input.name && input.value) {
                    const clone = input.cloneNode(true);
                    exportForm.appendChild(clone);
                }
            });
            
            document.body.appendChild(exportForm);
            exportForm.submit();
        }
    </script>
</body>
</html>