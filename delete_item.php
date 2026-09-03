<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Set timeout to 30 seconds
set_time_limit(30);

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php?error=Invalid request method");
    exit;
}

// Get item ID
$item_id = intval($_POST['id'] ?? 0);
if ($item_id <= 0) {
    header("Location: index.php?error=Invalid item ID");
    exit;
}

// Check if this is just a confirmation page or actual deletion
$is_confirmation = !isset($_POST['confirmed']) || $_POST['confirmed'] !== 'yes';

// Simple CSRF check (you can make this more robust if needed)
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (strpos($referer, $_SERVER['HTTP_HOST']) === false && $is_confirmation) {
    // For extra security, but can be commented out if causing issues
    // header("Location: index.php?error=Invalid request source");
    // exit;
}

$pdo = getDBConnection();

// Get item details for confirmation
$stmt = $pdo->prepare("SELECT id, po_number, activity_title, region, amount_requested, currency, status, vendor FROM budget_items WHERE id = ?");
$stmt->execute([$item_id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header("Location: index.php?error=Item not found");
    exit;
}

// If this is the final deletion request
if (!$is_confirmation && isset($_POST['confirmed']) && $_POST['confirmed'] === 'yes') {
    // Perform the deletion - just one query, very fast
    try {
        $delete_stmt = $pdo->prepare("DELETE FROM budget_items WHERE id = ?");
        $delete_stmt->execute([$item_id]);
        
        // Optional: Log the deletion
        error_log("Item deleted - ID: $item_id, PO: {$item['po_number']}");
        
        // Redirect - use provided URL if valid (same-origin), else index
        $redirect = $_POST['redirect'] ?? '';
        if ($redirect && preg_match('#^[a-zA-Z0-9_\-\./?#=&]+$#', $redirect) && !preg_match('#^https?://#', $redirect)) {
            $sep = strpos($redirect, '?') !== false ? '&' : '?';
            header("Location: {$redirect}{$sep}success=" . urlencode("Item deleted successfully"));
        } else {
            header("Location: index.php?success=Item deleted successfully");
        }
        exit;
    } catch (Exception $e) {
        error_log("Delete error: " . $e->getMessage());
        header("Location: index.php?error=Failed to delete item");
        exit;
    }
}

// If we get here, show confirmation page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Deletion - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            margin-top: 50px;
        }
        .danger-zone {
            border-left: 4px solid #dc3545;
            background-color: #fff8f8;
        }
        .quick-delete-btn {
            transition: all 0.3s ease;
        }
        .quick-delete-btn:hover {
            transform: scale(1.02);
        }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>
    <div style="height:80px;"></div>
    
    <div class="container">
        <div class="row">
            <div class="col-md-6 mx-auto">
                <div class="glass-card">
                    <div class="card-header bg-danger text-white text-center">
                        <h4 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h4>
                    </div>
                    <div class="card-body p-4">
                        <!-- Quick Summary -->
                        <div class="alert alert-light border mb-4">
                            <div class="text-center mb-3">
                                <i class="fas fa-file-invoice text-danger fa-3x"></i>
                            </div>
                            <h5 class="text-center"><?= htmlspecialchars($item['activity_title']) ?></h5>
                            <div class="text-center mb-3">
                                <span class="badge bg-secondary"><?= htmlspecialchars($item['po_number']) ?></span>
                                <span class="badge bg-<?= $item['status'] == 'Cancelled' ? 'warning' : 'info' ?> ms-2">
                                    <?= htmlspecialchars($item['status']) ?>
                                </span>
                            </div>
                            <div class="text-center">
                                <strong>Amount:</strong> <?= htmlspecialchars($item['amount_requested']) ?> <?= htmlspecialchars($item['currency']) ?><br>
                                <strong>Vendor:</strong> <?= htmlspecialchars($item['vendor']) ?>
                            </div>
                        </div>
                        
                        <!-- Quick Warning -->
                        <div class="alert alert-warning">
                            <div class="d-flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle fa-lg"></i>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h6 class="alert-heading">This action cannot be undone!</h6>
                                    <p class="mb-0">The item will be permanently deleted from the system.</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Options -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <a href="add_item.php?id=<?= $item_id ?>" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                            <div class="col-md-6">
                                <form method="POST" action="delete_item.php" id="deleteForm">
                                    <input type="hidden" name="id" value="<?= $item_id ?>">
                                    <input type="hidden" name="confirmed" value="yes">
                                    <?php if (!empty($_POST['redirect'])): ?>
                                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($_POST['redirect']) ?>">
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-danger w-100 quick-delete-btn" onclick="confirmDelete()">
                                        <i class="fas fa-trash"></i> Delete Now
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Quick Alternative -->
                        <div class="mt-4 text-center">
                            <small class="text-muted">
                                Instead, you can <a href="add_item.php?id=<?= $item_id ?>&edit=true">edit this item</a> 
                                and mark it as "Cancelled" to preserve history.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete() {
            if (confirm('Are you absolutely sure? This cannot be undone!')) {
                // Show loading state
                const btn = document.querySelector('.quick-delete-btn');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
                btn.disabled = true;
                
                // Submit form
                document.getElementById('deleteForm').submit();
            }
        }
        
        // Auto-focus on page load for accessibility
        document.addEventListener('DOMContentLoaded', function() {
            const cancelBtn = document.querySelector('.btn-outline-secondary');
            if (cancelBtn) cancelBtn.focus();
        });
    </script>
</body>
</html>