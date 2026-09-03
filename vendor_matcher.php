<?php
// match_vendors.php - Match vendors in budget_items with vendor database
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
require_once __DIR__ . '/config.php';

$results = [
    'matched' => 0,
    'unmatched' => 0,
    'details' => []
];

try {
    // Create PDO connection
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Check if budget_items table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'budget_items'")->fetch();
    if (!$tableExists) {
        $results['error'] = "budget_items table not found!";
    } else {
        // Get all unmatched vendors from budget_items
        $unmatchedVendors = $pdo->query("
            SELECT DISTINCT vendor 
            FROM budget_items 
            WHERE vendor != '' AND (project_code IS NULL OR project_code = '')
            ORDER BY vendor
        ")->fetchAll(PDO::FETCH_COLUMN, 0);
        
        $results['total_unmatched'] = count($unmatchedVendors);
        
        // Get all vendors from vendor database
        $vendorDb = $pdo->query("SELECT vendor_name, salesforce_id FROM vendors")->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Process matching
        foreach ($unmatchedVendors as $budgetVendor) {
            $budgetVendor = trim($budgetVendor);
            $budgetVendorLower = strtolower($budgetVendor);
            $matched = false;
            $matchedVendor = '';
            $salesforceId = '';
            
            // Try different matching strategies
            foreach ($vendorDb as $dbVendor => $sfId) {
                $dbVendorLower = strtolower(trim($dbVendor));
                
                // Strategy 1: Exact match (case-insensitive)
                if ($budgetVendorLower === $dbVendorLower) {
                    $matched = true;
                    $matchedVendor = $dbVendor;
                    $salesforceId = $sfId;
                    break;
                }
                
                // Strategy 2: Contains match (budget vendor contains db vendor or vice versa)
                if (strpos($budgetVendorLower, $dbVendorLower) !== false || 
                    strpos($dbVendorLower, $budgetVendorLower) !== false) {
                    $matched = true;
                    $matchedVendor = $dbVendor;
                    $salesforceId = $sfId;
                    break;
                }
                
                // Strategy 3: Remove common suffixes/prefixes and try again
                $cleanBudgetVendor = preg_replace('/\b(ltd|limited|llc|inc|corporation|corp|gmbh|ag|sa|pte|plc)\b/i', '', $budgetVendorLower);
                $cleanBudgetVendor = trim(preg_replace('/[^\w\s]/', ' ', $cleanBudgetVendor));
                $cleanDbVendor = preg_replace('/\b(ltd|limited|llc|inc|corporation|corp|gmbh|ag|sa|pte|plc)\b/i', '', $dbVendorLower);
                $cleanDbVendor = trim(preg_replace('/[^\w\s]/', ' ', $cleanDbVendor));
                
                if ($cleanBudgetVendor === $cleanDbVendor) {
                    $matched = true;
                    $matchedVendor = $dbVendor;
                    $salesforceId = $sfId;
                    break;
                }
                
                // Strategy 4: Similar text (80%+ match)
                similar_text($budgetVendorLower, $dbVendorLower, $percent);
                if ($percent > 80) {
                    $matched = true;
                    $matchedVendor = $dbVendor;
                    $salesforceId = $sfId;
                    break;
                }
            }
            
            if ($matched && !empty($salesforceId)) {
                // Update budget_items with Salesforce ID
                $stmt = $pdo->prepare("
                    UPDATE budget_items 
                    SET project_code = ? 
                    WHERE vendor = ? AND (project_code IS NULL OR project_code = '')
                ");
                $stmt->execute([$salesforceId, $budgetVendor]);
                $affectedRows = $stmt->rowCount();
                
                $results['matched']++;
                $results['details'][] = [
                    'budget_vendor' => $budgetVendor,
                    'matched_to' => $matchedVendor,
                    'salesforce_id' => $salesforceId,
                    'updated_rows' => $affectedRows
                ];
            } else {
                $results['unmatched']++;
                $results['details'][] = [
                    'budget_vendor' => $budgetVendor,
                    'matched_to' => null,
                    'salesforce_id' => null,
                    'updated_rows' => 0
                ];
            }
        }
        
        // Get updated stats
        $updatedStats = $pdo->query("
            SELECT 
                COUNT(*) as total_items,
                COUNT(CASE WHEN project_code != '' AND project_code IS NOT NULL THEN 1 END) as matched_items,
                COUNT(CASE WHEN project_code = '' OR project_code IS NULL THEN 1 END) as unmatched_items
            FROM budget_items 
            WHERE vendor != ''
        ")->fetch();
        
        $results['stats'] = $updatedStats;
    }
    
} catch (PDOException $e) {
    $results['error'] = "Database Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Match Vendors - EPOS Budget</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .match-success { background-color: #d4edda; }
        .match-failed { background-color: #f8d7da; }
        .progress-bar-custom { height: 25px; font-size: 14px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1><i class="fas fa-link"></i> Match Vendors Automatically</h1>
        <p class="text-muted">Match vendors in budget items with Salesforce vendor database</p>
        
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-cogs"></i> Run Vendor Matching</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle"></i> What this does:</h6>
                    <ul class="mb-0">
                        <li>Finds all unmatched vendors in <code>budget_items</code> table</li>
                        <li>Compares them with vendors in <code>vendors</code> database</li>
                        <li>Updates <code>project_code</code> field with Salesforce ID when matched</li>
                        <li>Uses multiple matching strategies (exact, contains, fuzzy)</li>
                    </ul>
                </div>
                
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Matching Strategy</label>
                        <select name="strategy" class="form-select">
                            <option value="auto" selected>Auto (Try all strategies)</option>
                            <option value="exact">Exact match only</option>
                            <option value="contains">Contains match</option>
                            <option value="fuzzy">Fuzzy match (80%+)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="dry_run" id="dryRun" checked>
                        <label class="form-check-label" for="dryRun">
                            <strong>Dry Run</strong> (Show what would be matched without updating database)
                        </label>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" name="run_matching" class="btn btn-success btn-lg">
                            <i class="fas fa-play-circle"></i> Run Vendor Matching
                        </button>
                        <a href="vendor_manager.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Vendor Manager
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (isset($results['total_unmatched']) || isset($results['error'])): ?>
        <div class="card">
            <div class="card-header <?php echo isset($results['error']) ? 'bg-danger' : ($results['matched'] > 0 ? 'bg-success' : 'bg-warning'); ?> text-white">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar"></i> Matching Results
                    <?php if (isset($_POST['dry_run'])): ?>
                        <span class="badge bg-light text-dark">Dry Run</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (isset($results['error'])): ?>
                    <div class="alert alert-danger">
                        <h6>Error:</h6>
                        <?php echo $results['error']; ?>
                    </div>
                <?php else: ?>
                    <!-- Summary Stats -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card text-white bg-primary">
                                <div class="card-body text-center">
                                    <h6>Total Unmatched</h6>
                                    <h2><?php echo $results['total_unmatched']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-white bg-success">
                                <div class="card-body text-center">
                                    <h6>Successfully Matched</h6>
                                    <h2><?php echo $results['matched']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-white bg-warning">
                                <div class="card-body text-center">
                                    <h6>Still Unmatched</h6>
                                    <h2><?php echo $results['unmatched']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-white bg-info">
                                <div class="card-body text-center">
                                    <h6>Success Rate</h6>
                                    <h2>
                                        <?php 
                                        $rate = $results['total_unmatched'] > 0 ? 
                                                round(($results['matched'] / $results['total_unmatched']) * 100) : 0;
                                        echo $rate . '%';
                                        ?>
                                    </h2>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <h6>Matching Progress</h6>
                        <div class="progress progress-bar-custom">
                            <div class="progress-bar bg-success" 
                                 style="width: <?php echo $results['total_unmatched'] > 0 ? ($results['matched'] / $results['total_unmatched']) * 100 : 0; ?>%">
                                Matched (<?php echo $results['matched']; ?>)
                            </div>
                            <div class="progress-bar bg-warning" 
                                 style="width: <?php echo $results['total_unmatched'] > 0 ? ($results['unmatched'] / $results['total_unmatched']) * 100 : 0; ?>%">
                                Unmatched (<?php echo $results['unmatched']; ?>)
                            </div>
                        </div>
                    </div>
                    
                    <?php if (isset($results['stats'])): ?>
                    <!-- Updated Database Stats -->
                    <div class="alert alert-secondary">
                        <h6><i class="fas fa-database"></i> Database Status After Matching:</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Total Budget Items:</strong> <?php echo $results['stats']['total_items']; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Matched Items:</strong> <?php echo $results['stats']['matched_items']; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Unmatched Items:</strong> <?php echo $results['stats']['unmatched_items']; ?>
                            </div>
                        </div>
                        <?php if ($results['stats']['total_items'] > 0): ?>
                        <div class="mt-2">
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-success" 
                                     style="width: <?php echo ($results['stats']['matched_items'] / $results['stats']['total_items']) * 100; ?>%">
                                </div>
                                <div class="progress-bar bg-warning" 
                                     style="width: <?php echo ($results['stats']['unmatched_items'] / $results['stats']['total_items']) * 100; ?>%">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Matched Details (First 20) -->
                    <h6>Matching Details (First 20):</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Budget Vendor</th>
                                    <th>Matched To</th>
                                    <th>Salesforce ID</th>
                                    <th>Status</th>
                                    <th>Rows Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $displayLimit = min(20, count($results['details']));
                                for ($i = 0; $i < $displayLimit; $i++): 
                                    $detail = $results['details'][$i];
                                ?>
                                <tr class="<?php echo $detail['matched_to'] ? 'match-success' : 'match-failed'; ?>">
                                    <td><?php echo htmlspecialchars($detail['budget_vendor']); ?></td>
                                    <td>
                                        <?php if ($detail['matched_to']): ?>
                                            <?php echo htmlspecialchars($detail['matched_to']); ?>
                                        <?php else: ?>
                                            <span class="text-danger">No match found</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($detail['salesforce_id']): ?>
                                            <code><?php echo htmlspecialchars($detail['salesforce_id']); ?></code>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($detail['matched_to']): ?>
                                            <span class="badge bg-success">Matched</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Unmatched</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $detail['updated_rows']; ?>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php if (count($results['details']) > 20): ?>
                        <p class="text-muted">... and <?php echo count($results['details']) - 20; ?> more matches</p>
                    <?php endif; ?>
                    
                    <!-- Action Buttons -->
                    <div class="mt-4">
                        <div class="btn-group">
                            <?php if (!$results['unmatched']): ?>
                                <button class="btn btn-success" disabled>
                                    <i class="fas fa-check-circle"></i> All Vendors Matched!
                                </button>
                            <?php else: ?>
                                <a href="manual_match.php" class="btn btn-warning">
                                    <i class="fas fa-hand-pointer"></i> Manual Match Remaining
                                </a>
                            <?php endif; ?>
                            <a href="export_unmatched.php" class="btn btn-outline-danger">
                                <i class="fas fa-file-export"></i> Export Unmatched List
                            </a>
                            <a href="vendor_manager.php" class="btn btn-outline-primary">
                                <i class="fas fa-redo"></i> Refresh Vendor Manager
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Quick Stats from Database -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-pie"></i> Current Matching Status</h5>
            </div>
            <div class="card-body">
                <?php
                try {
                    $currentStats = $pdo->query("
                        SELECT 
                            COUNT(*) as total_items,
                            COUNT(CASE WHEN project_code != '' AND project_code IS NOT NULL THEN 1 END) as matched_items,
                            COUNT(CASE WHEN project_code = '' OR project_code IS NULL THEN 1 END) as unmatched_items,
                            COUNT(DISTINCT vendor) as unique_vendors,
                            COUNT(DISTINCT CASE WHEN project_code != '' AND project_code IS NOT NULL THEN vendor END) as matched_vendors
                        FROM budget_items 
                        WHERE vendor != ''
                    ")->fetch();
                    
                    if ($currentStats['total_items'] > 0):
                ?>
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Budget Items:</h6>
                            <ul>
                                <li>Total Items: <?php echo $currentStats['total_items']; ?></li>
                                <li>Matched Items: <?php echo $currentStats['matched_items']; ?> 
                                    (<?php echo round(($currentStats['matched_items'] / $currentStats['total_items']) * 100); ?>%)</li>
                                <li>Unmatched Items: <?php echo $currentStats['unmatched_items']; ?> 
                                    (<?php echo round(($currentStats['unmatched_items'] / $currentStats['total_items']) * 100); ?>%)</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Unique Vendors:</h6>
                            <ul>
                                <li>Total Vendors: <?php echo $currentStats['unique_vendors']; ?></li>
                                <li>Matched Vendors: <?php echo $currentStats['matched_vendors']; ?></li>
                                <li>Unmatched Vendors: <?php echo $currentStats['unique_vendors'] - $currentStats['matched_vendors']; ?></li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No budget items found in the database.</p>
                <?php endif; ?>
                <?php } catch (Exception $e) { ?>
                    <p class="text-danger">Unable to load current stats: <?php echo $e->getMessage(); ?></p>
                <?php } ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>