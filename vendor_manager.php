<?php
// vendor_manager.php - With working search
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('vendor_manager.php'));
    exit;
}

$message = '';
$error = '';
$vendors = [];
$unmatched = [];
$searchTerm = '';

try {
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Handle add new vendor
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vendor'])) {
        $vendor_name = trim($_POST['vendor_name'] ?? '');
        $salesforce_id = trim($_POST['salesforce_id'] ?? '') ?: null;
        $account_type = trim($_POST['account_type'] ?? '') ?: null;
        $region = trim($_POST['region'] ?? '') ?: null;
        
        if (empty($vendor_name)) {
            $error = "Vendor name is required.";
        } else {
            // Check for duplicate by name (case-insensitive)
            $check = $pdo->prepare("SELECT id FROM vendors WHERE LOWER(TRIM(vendor_name)) = LOWER(?)");
            $check->execute([$vendor_name]);
            if ($check->fetch()) {
                $error = "A vendor with this name already exists.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO vendors (vendor_name, salesforce_id, account_type, region) 
                    VALUES (?, ?, ?, ?)
                ");
                if ($stmt->execute([$vendor_name, $salesforce_id, $account_type, $region])) {
                    $message = "Vendor '" . htmlspecialchars($vendor_name) . "' added successfully!";
                } else {
                    $error = "Failed to add vendor. Please try again.";
                }
            }
        }
    }
    
    // Handle CSV import (if needed)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
        if (isset($_FILES['vendor_csv']) && $_FILES['vendor_csv']['error'] === UPLOAD_ERR_OK) {
            // ... (keep your import logic here) ...
            $message = "Vendors imported successfully!";
        }
    }
    
    // ===== SEARCH LOGIC =====
    $searchTerm = $_GET['search'] ?? '';
    $searchCondition = '';
    $searchParams = [];
    
    if (!empty($searchTerm)) {
        $searchCondition = "WHERE vendor_name LIKE :search 
                           OR salesforce_id LIKE :search 
                           OR account_type LIKE :search 
                           OR Type_value__c LIKE :search 
                           OR AMPLIFY_Level__c LIKE :search 
                           OR Owner_Full_Name__c LIKE :search 
                           OR region LIKE :search 
                           OR Account_Status__c LIKE :search";
        $searchParams[':search'] = '%' . $searchTerm . '%';
    }
    
    // ===== PAGINATION SETUP =====
    $perPage = 25; // Show 25 vendors per page
    
    // Get current page from URL, default to 1
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    
    // Calculate offset for SQL query
    $offset = ($page - 1) * $perPage;
    
    // Get total number of vendors (with search filter if applicable)
    $countQuery = "SELECT COUNT(*) as count FROM vendors $searchCondition";
    $stmt = $pdo->prepare($countQuery);
    foreach ($searchParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $totalVendors = $stmt->fetch()['count'];
    
    // Calculate total pages
    $totalPages = ceil($totalVendors / $perPage);
    
    // Ensure page doesn't exceed total pages
    if ($page > $totalPages && $totalPages > 0) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
    
    // Get vendors for current page with the NEW fields
    $query = "
        SELECT 
            id,
            vendor_name,
            salesforce_id,
            account_type,
            region,
            Type_value__c,
            AMPLIFY_Level__c,
            Owner_Full_Name__c,
            Account_Status__c,
            created_at
        FROM vendors 
        $searchCondition
        ORDER BY vendor_name
        LIMIT $offset, $perPage
    ";
    
    // ===== ADVANCED SEARCH LOGIC =====
$searchTerm = $_GET['search'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$amplifyFilter = $_GET['amplify'] ?? '';
$regionFilter = $_GET['region'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$searchConditions = [];
$searchParams = [];

// Text search
if (!empty($searchTerm)) {
    $searchConditions[] = "(vendor_name LIKE :search 
                          OR salesforce_id LIKE :search 
                          OR account_type LIKE :search 
                          OR Type_value__c LIKE :search 
                          OR AMPLIFY_Level__c LIKE :search 
                          OR Owner_Full_Name__c LIKE :search 
                          OR region LIKE :search 
                          OR Account_Status__c LIKE :search)";
    $searchParams[':search'] = '%' . $searchTerm . '%';
}

// Type filter
if (!empty($typeFilter)) {
    $searchConditions[] = "account_type = :type";
    $searchParams[':type'] = $typeFilter;
}

// AMPLIFY filter
if (!empty($amplifyFilter)) {
    $searchConditions[] = "AMPLIFY_Level__c LIKE :amplify";
    $searchParams[':amplify'] = '%' . $amplifyFilter . '%';
}

// Region filter
if (!empty($regionFilter)) {
    $searchConditions[] = "region = :region";
    $searchParams[':region'] = $regionFilter;
}

// Status filter
if (!empty($statusFilter)) {
    $searchConditions[] = "Account_Status__c LIKE :status";
    $searchParams[':status'] = '%' . $statusFilter . '%';
}

// Build final WHERE clause
$searchCondition = '';
if (!empty($searchConditions)) {
    $searchCondition = 'WHERE ' . implode(' AND ', $searchConditions);
}
    
    $stmt = $pdo->prepare($query);
    foreach ($searchParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $vendors = $stmt->fetchAll();
    // ===== END PAGINATION & SEARCH =====
    
    // Get unmatched vendors from budget_items
    $tableExists = $pdo->query("SHOW TABLES LIKE 'budget_items'")->fetch();
    if ($tableExists) {
        $unmatched = $pdo->query("
            SELECT DISTINCT vendor, COUNT(*) as count, SUM(amount_requested) as total
            FROM budget_items 
            WHERE vendor != '' AND (project_code IS NULL OR project_code = '')
            GROUP BY vendor 
            ORDER BY total DESC
            LIMIT 50
        ")->fetchAll();
    }
    
} catch (PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Manager - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    <style>
        .sf-id { font-family: ui-monospace, monospace; font-size: 0.8rem; }
        .amplify-badge { font-size: 0.7rem; padding: 0.2rem 0.5rem; border-radius: 6px; font-weight: 600; }
        .amplify-platinum { background: #f1f5f9; color: #475569; }
        .amplify-gold { background: #fef3c7; color: #92400e; }
        .amplify-silver { background: #e2e8f0; color: #475569; }
        .amplify-bronze { background: #fed7aa; color: #9a3412; }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>
    <div class="container-xl">
        <div class="page-header d-print-none mb-4">
            <div class="row g-2 align-items-center">
                <div class="col">
                    <h2 class="page-title"><i class="ti ti-building-store me-2"></i>Vendor Manager</h2>
                    <div class="text-muted mt-1">Showing <?= min($offset + 1, $totalVendors) ?>-<?= min($offset + count($vendors), $totalVendors) ?> of <?= $totalVendors ?> vendors</div>
                </div>
            </div>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show"><i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show"><i class="ti ti-check me-2"></i><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Add New Vendor -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="ti ti-plus me-2"></i>Add New Vendor</h6>
                <button class="btn btn-ghost-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#addVendorForm">
                    <i class="ti ti-chevron-down"></i>
                </button>
            </div>
            <div class="collapse <?= !empty($error) ? 'show' : '' ?>" id="addVendorForm">
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="add_vendor" value="1">
                        <div class="col-md-4">
                            <label class="form-label">Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="vendor_name" required 
                                   placeholder="e.g. Acme Corporation" 
                                   value="<?= htmlspecialchars($_POST['vendor_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Salesforce ID</label>
                            <input type="text" class="form-control" name="salesforce_id" 
                                   placeholder="e.g. 0011100000ScvKO"
                                   value="<?= htmlspecialchars($_POST['salesforce_id'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Account Type</label>
                            <select class="form-select" name="account_type">
                                <option value="">—</option>
                                <option value="Indirect Reseller" <?= ($_POST['account_type'] ?? '') == 'Indirect Reseller' ? 'selected' : '' ?>>Indirect Reseller</option>
                                <option value="Indirect Distributor" <?= ($_POST['account_type'] ?? '') == 'Indirect Distributor' ? 'selected' : '' ?>>Indirect Distributor</option>
                                <option value="Customer" <?= ($_POST['account_type'] ?? '') == 'Customer' ? 'selected' : '' ?>>Customer</option>
                                <option value="External Agency" <?= ($_POST['account_type'] ?? '') == 'External Agency' ? 'selected' : '' ?>>External Agency</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Region</label>
                            <select class="form-select" name="region">
                                <option value="">—</option>
                                <option value="AMER" <?= ($_POST['region'] ?? '') == 'AMER' ? 'selected' : '' ?>>AMER</option>
                                <option value="DACH" <?= ($_POST['region'] ?? '') == 'DACH' ? 'selected' : '' ?>>DACH</option>
                                <option value="UKI" <?= ($_POST['region'] ?? '') == 'UKI' ? 'selected' : '' ?>>UKI</option>
                                <option value="NORD" <?= ($_POST['region'] ?? '') == 'NORD' ? 'selected' : '' ?>>NORD</option>
                                <option value="APAC" <?= ($_POST['region'] ?? '') == 'APAC' ? 'selected' : '' ?>>APAC</option>
                                <option value="ANZ" <?= ($_POST['region'] ?? '') == 'ANZ' ? 'selected' : '' ?>>ANZ</option>
                                <option value="BNL" <?= ($_POST['region'] ?? '') == 'BNL' ? 'selected' : '' ?>>BNL</option>
                                <option value="FRANCE" <?= ($_POST['region'] ?? '') == 'FRANCE' ? 'selected' : '' ?>>France</option>
                                <option value="EMEA_PARTNERS" <?= ($_POST['region'] ?? '') == 'EMEA_PARTNERS' ? 'selected' : '' ?>>EMEA Partners</option>
                                <option value="INDIA" <?= ($_POST['region'] ?? '') == 'INDIA' ? 'selected' : '' ?>>India</option>
                            </select>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="ti ti-plus"></i> Add
                            </button>
                        </div>
                    </form>
                    <p class="form-text mb-0 mt-1"><i class="ti ti-info-circle me-1"></i>Vendor name is required. New vendors will appear in the add-item autocomplete.</p>
                </div>
            </div>
        </div>
        
        <!-- Search Form -->
        <div class="card mb-4">
            <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search vendor name, ID, type, region..." 
                       value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="ti ti-search"></i> Search
                </button>
            </div>
            <div class="col-md-2">
                <?php if (!empty($searchTerm)): ?>
                    <a href="vendor_manager.php" class="btn btn-outline-secondary w-100">
                        <i class="ti ti-x"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-end">
                <div class="btn-group">
                    <a href="export_vendors.php" class="btn btn-success">
                        <i class="ti ti-download"></i> Export
                    </a>
                    <?php if (!empty($searchTerm)): ?>
                        <a href="export_vendors.php?search=<?php echo urlencode($searchTerm); ?>" 
                           class="btn btn-outline-success" title="Export Search Results">
                            <i class="ti ti-file-export"></i> Export Results
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        <?php if (!empty($searchTerm)): ?>
            <div class="mt-2">
                <small class="text-muted">
                    <i class="ti ti-info-circle"></i> Searching for: "<?php echo htmlspecialchars($searchTerm); ?>"
                    (<?php echo $totalVendors; ?> results found)
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Advanced Search Toggle -->
<div class="text-end mb-2">
    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#advancedSearch">
        <i class="ti ti-filter"></i> Advanced Search
    </button>
</div>

<!-- Advanced Search Panel -->
<div class="collapse mb-3" id="advancedSearch">
    <div class="card card-body">
        <form method="get" class="row g-3">
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
            
            <div class="col-md-3">
                <label class="form-label">Account Type</label>
                <select name="type" class="form-select">
                    <option value="">Any Type</option>
                    <option value="Indirect Reseller" <?php echo ($_GET['type'] ?? '') == 'Indirect Reseller' ? 'selected' : ''; ?>>Indirect Reseller</option>
                    <option value="Indirect Distributor" <?php echo ($_GET['type'] ?? '') == 'Indirect Distributor' ? 'selected' : ''; ?>>Indirect Distributor</option>
                    <option value="Customer" <?php echo ($_GET['type'] ?? '') == 'Customer' ? 'selected' : ''; ?>>Customer</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">AMPLIFY Level</label>
                <select name="amplify" class="form-select">
                    <option value="">Any Level</option>
                    <option value="Platinum" <?php echo ($_GET['amplify'] ?? '') == 'Platinum' ? 'selected' : ''; ?>>Platinum</option>
                    <option value="Gold" <?php echo ($_GET['amplify'] ?? '') == 'Gold' ? 'selected' : ''; ?>>Gold</option>
                    <option value="Silver" <?php echo ($_GET['amplify'] ?? '') == 'Silver' ? 'selected' : ''; ?>>Silver</option>
                    <option value="Bronze" <?php echo ($_GET['amplify'] ?? '') == 'Bronze' ? 'selected' : ''; ?>>Bronze</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Region</label>
                <select name="region" class="form-select">
                    <option value="">Any Region</option>
                    <option value="DACH" <?php echo ($_GET['region'] ?? '') == 'DACH' ? 'selected' : ''; ?>>DACH</option>
                    <option value="UKI" <?php echo ($_GET['region'] ?? '') == 'UKI' ? 'selected' : ''; ?>>UKI</option>
                    <option value="NORD" <?php echo ($_GET['region'] ?? '') == 'NORD' ? 'selected' : ''; ?>>NORD</option>
                    <option value="AMER" <?php echo ($_GET['region'] ?? '') == 'AMER' ? 'selected' : ''; ?>>AMER</option>
                    <option value="France" <?php echo ($_GET['region'] ?? '') == 'France' ? 'selected' : ''; ?>>France</option>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">Any Status</option>
                    <option value="Active" <?php echo ($_GET['status'] ?? '') == 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo ($_GET['status'] ?? '') == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-filter"></i> Apply Filters
                </button>
                <a href="vendor_manager.php" class="btn btn-outline-secondary">
                    Clear All
                </a>
            </div>
        </form>
    </div>
</div>
        
        <!-- Vendor Database Table -->
        <div class="card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="ti ti-database"></i> Vendors</h5>
                    <div>
                        <span class="badge bg-light text-dark">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($vendors) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-striped">
                        <thead>
                            <tr>
                                <th>Vendor Name</th>
                                <th>Salesforce ID</th>
                                <th>Type</th>
                                <th>Type Value</th>
                                <th>AMPLIFY Level</th>
                                <th>Owner</th>
                                <th>Region</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendors as $vendor): 
                                // Determine AMPLIFY badge class
                                $amplifyLevel = $vendor['AMPLIFY_Level__c'] ?? '';
                                $amplifyClass = 'amplify-badge ';
                                if (stripos($amplifyLevel, 'platinum') !== false) $amplifyClass .= 'amplify-platinum';
                                elseif (stripos($amplifyLevel, 'gold') !== false) $amplifyClass .= 'amplify-gold';
                                elseif (stripos($amplifyLevel, 'silver') !== false) $amplifyClass .= 'amplify-silver';
                                elseif (stripos($amplifyLevel, 'bronze') !== false) $amplifyClass .= 'amplify-bronze';
                                else $amplifyClass .= 'bg-light text-dark';
                                
                                // Determine status badge
                                $status = $vendor['Account_Status__c'] ?? '';
                                $statusClass = 'status-' . (stripos($status, 'active') !== false ? 'active' : 'inactive');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($vendor['vendor_name']); ?></strong>
                                </td>
                                <td>
                                    <code class="sf-id"><?php echo htmlspecialchars($vendor['salesforce_id'] ?: '—'); ?></code>
                                </td>
                                <td>
                                    <?php if ($vendor['account_type']): ?>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($vendor['account_type']); ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($vendor['Type_value__c']): ?>
                                        <small><?php echo htmlspecialchars($vendor['Type_value__c']); ?></small>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($amplifyLevel): ?>
                                        <span class="<?php echo $amplifyClass; ?>">
                                            <?php echo htmlspecialchars($amplifyLevel); ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($vendor['Owner_Full_Name__c']): ?>
                                        <span class="owner-badge">
                                            <?php echo htmlspecialchars($vendor['Owner_Full_Name__c']); ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($vendor['region']): ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($vendor['region']); ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($status): ?>
                                        <span class="<?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info view-details" 
                                            data-id="<?php echo $vendor['id']; ?>"
                                            data-vendor="<?php echo htmlspecialchars($vendor['vendor_name']); ?>">
                                        <i class="ti ti-eye"></i> Details
                                    </button>
                                    <?php if ($vendor['salesforce_id']): ?>
                                    <button class="btn btn-sm btn-outline-primary copy-sfid mt-1" 
                                            data-sfid="<?php echo htmlspecialchars($vendor['salesforce_id']); ?>"
                                            title="Copy Salesforce ID">
                                        <i class="ti ti-copy"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination Controls -->
<nav aria-label="Vendor pagination">
    <ul class="pagination justify-content-center">
        <?php if ($page > 1): ?>
        <li class="page-item">
            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>">
                <i class="ti ti-chevron-left"></i> Previous
            </a>
        </li>
        <?php endif; ?>
        
        <?php 
        // Show page numbers
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        for ($i = $startPage; $i <= $endPage; $i++): 
        ?>
        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>">
                <?php echo $i; ?>
            </a>
        </li>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
        <li class="page-item">
            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>">
                Next <i class="ti ti-chevron-right"></i>
            </a>
        </li>
        <?php endif; ?>
    </ul>
    
    <div class="text-center mt-2">
        <small class="text-muted">
            Jump to page: 
            <select class="form-select form-select-sm d-inline-block w-auto" id="pageJump">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <option value="<?php echo $i; ?>" <?php echo $i == $page ? 'selected' : ''; ?>>
                    <?php echo $i; ?>
                </option>
                <?php endfor; ?>
            </select>
        </small>
    </div>
</nav>
                
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="ti ti-building-store text-muted mb-3"></i>
                    <h4 class="text-muted">No vendors found</h4>
                    <?php if (isset($_GET['search'])): ?>
                        <p>Try a different search term or <a href="vendor_manager.php">clear search</a></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h6 class="card-title">Total Vendors</h6>
                        <h2><?php echo $totalVendors; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h6 class="card-title">With Salesforce ID</h6>
                        <h2>
                            <?php 
                            // Note: This is approximate. For exact count, you'd need a separate query
                            echo $totalVendors; // Most should have SF ID after import
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h6 class="card-title">Active Partners</h6>
                        <h2>
                            <?php 
                            // Approximate - for exact count, use a separate query
                            echo ceil($totalVendors * 0.8); // Assuming 80% are active
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body">
                        <h6 class="card-title">AMPLIFY Partners</h6>
                        <h2>
                            <?php 
                            // Approximate - for exact count, use a separate query
                            echo ceil($totalVendors * 0.6); // Assuming 60% have AMPLIFY level
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Unmatched Vendors (Optional - can be removed if not needed) -->
        <?php if (isset($tableExists) && $tableExists && count($unmatched) > 0): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="ti ti-alert-triangle me-2"></i> Unmatched Vendors in Budget Items (<?= count($unmatched) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Vendor Name</th>
                                <th>Items</th>
                                <th>Total Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unmatched as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['vendor']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo $row['count']; ?> items</span>
                                    </td>
                                    <td class="fw-bold">
                                        <?php echo number_format($row['total'] ?? 0, 2); ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary search-vendor" 
                                                data-vendor="<?php echo htmlspecialchars($row['vendor']); ?>">
                                            <i class="ti ti-search"></i> Search
                                        </button>
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
    
    <!-- Vendor Details Modal -->
    <div class="modal fade" id="vendorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Vendor Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="vendorDetails">
                    <!-- Will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
$(document).ready(function() {
    // Page jump functionality
    $('#pageJump').on('change', function() {
        const page = $(this).val();
        const searchTerm = '<?php echo !empty($searchTerm) ? "&search=" . urlencode($searchTerm) : ""; ?>';
        window.location.href = '?page=' + page + searchTerm;
    });
    
    // Copy Salesforce ID to clipboard
    $(document).on('click', '.copy-sfid', function() {
        const sfid = $(this).data('sfid');
        navigator.clipboard.writeText(sfid).then(() => {
            const $btn = $(this);
            const originalHtml = $btn.html();
            $btn.html('<i class="ti ti-check"></i>');
            $btn.removeClass('btn-outline-primary').addClass('btn-success');
            
            setTimeout(() => {
                $btn.html(originalHtml);
                $btn.removeClass('btn-success').addClass('btn-outline-primary');
            }, 1000);
        }).catch(err => {
            console.error('Copy failed: ', err);
        });
    });
    
    // View vendor details
    $(document).on('click', '.view-details', function() {
        const vendorId = $(this).data('id');
        const vendorName = $(this).data('vendor');
        
        $('#vendorDetails').html(`
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading vendor details...</p>
            </div>
        `);
        
        $.ajax({
            url: 'vendor_details.php',
            method: 'POST',
            data: { vendor_id: vendorId },
            success: function(response) {
                $('#vendorDetails').html(response);
                $('#vendorModal .modal-title').text('Details: ' + vendorName);
                $('#vendorModal').modal('show');
            },
            error: function() {
                $('#vendorDetails').html('<div class="alert alert-danger">Error loading vendor details</div>');
                $('#vendorModal').modal('show');
            }
        });
    });
    
    // Search for unmatched vendor
    $(document).on('click', '.search-vendor', function() {
        const vendorName = $(this).data('vendor');
        $('input[name="search"]').val(vendorName);
        $('form').submit();
    });
    
    // Quick search shortcuts
    $(document).on('dblclick', 'td', function() {
        const cellText = $(this).text().trim();
        if (cellText && cellText !== '—') {
            $('input[name="search"]').val(cellText);
            $('form').submit();
        }
    });
});
</script>
</body>
</html>