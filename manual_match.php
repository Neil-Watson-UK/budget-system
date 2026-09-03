<?php
// manual_match.php - Manual vendor matching
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
require_once __DIR__ . '/config.php';

$results = [];

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Handle manual match
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_vendor'])) {
        $budgetVendor = $_POST['budget_vendor'];
        $salesforceId = $_POST['salesforce_id'];
        
        $stmt = $pdo->prepare("
            UPDATE budget_items 
            SET project_code = ? 
            WHERE vendor = ? AND (project_code IS NULL OR project_code = '')
        ");
        $stmt->execute([$salesforceId, $budgetVendor]);
        
        $results['matched'] = $stmt->rowCount();
        $results['vendor'] = $budgetVendor;
        $results['sf_id'] = $salesforceId;
    }
    
    // Get top unmatched vendors
    $unmatched = $pdo->query("
        SELECT DISTINCT vendor, COUNT(*) as count, SUM(amount_requested) as total
        FROM budget_items 
        WHERE vendor != '' AND (project_code IS NULL OR project_code = '')
        GROUP BY vendor 
        ORDER BY total DESC
        LIMIT 50
    ")->fetchAll();
    
} catch (PDOException $e) {
    $results['error'] = "Database Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manual Vendor Match - EPOS Budget</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container mt-4">
        <h1><i class="fas fa-hand-pointer"></i> Manual Vendor Matching</h1>
        
        <?php if (isset($results['matched'])): ?>
            <div class="alert alert-success">
                Successfully matched "<?php echo htmlspecialchars($results['vendor']); ?>" 
                to Salesforce ID: <code><?php echo htmlspecialchars($results['sf_id']); ?></code><br>
                Updated <?php echo $results['matched']; ?> budget items.
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Unmatched Vendors (Top 50 by Amount)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
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
                                            <span class="badge bg-secondary"><?php echo $row['count']; ?></span>
                                        </td>
                                        <td class="fw-bold">
                                            <?php echo number_format($row['total'], 2); ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary select-vendor" 
                                                    data-vendor="<?php echo htmlspecialchars($row['vendor']); ?>">
                                                <i class="fas fa-check"></i> Select
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Match Vendor</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" id="matchForm">
                            <div class="mb-3">
                                <label class="form-label">Vendor from Budget</label>
                                <input type="text" name="budget_vendor" id="budgetVendor" class="form-control" required readonly>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Find Salesforce Vendor</label>
                                <input type="text" id="vendorSearch" class="form-control" placeholder="Search vendor database...">
                                <small class="text-muted">Start typing to search vendors</small>
                            </div>
                            
                            <div id="vendorResults" style="max-height: 300px; overflow-y: auto; display: none;">
                                <!-- Search results will appear here -->
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Selected Salesforce ID</label>
                                <input type="text" name="salesforce_id" id="salesforceId" class="form-control" required>
                                <small class="text-muted">Paste or select from search results</small>
                            </div>
                            
                            <button type="submit" name="match_vendor" class="btn btn-success w-100">
                                <i class="fas fa-link"></i> Create Match
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-3 text-center">
            <a href="match_vendors.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Back to Auto Matching
            </a>
            <a href="vendor_manager.php" class="btn btn-outline-secondary">
                <i class="fas fa-building"></i> Vendor Database
            </a>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Select vendor from list
            $('.select-vendor').click(function() {
                const vendor = $(this).data('vendor');
                $('#budgetVendor').val(vendor);
            });
            
            // Search vendors
            $('#vendorSearch').on('input', function() {
                const query = $(this).val();
                if (query.length < 2) {
                    $('#vendorResults').hide().empty();
                    return;
                }
                
                $.ajax({
                    url: 'search_vendors.php',
                    method: 'GET',
                    data: { q: query },
                    success: function(response) {
                        $('#vendorResults').html(response).show();
                    }
                });
            });
            
            // Vendor selection
            $(document).on('click', '.select-vendor-result', function() {
                const vendorName = $(this).data('name');
                const sfId = $(this).data('sfid');
                
                $('#vendorSearch').val(vendorName);
                $('#salesforceId').val(sfId);
                $('#vendorResults').hide().empty();
            });
        });
    </script>
</body>
</html>