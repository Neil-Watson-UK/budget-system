<?php
// salesout/mapping.php - Reseller to Vendor mapping
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/mapping.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_mapping'])) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO sales_out_reseller_mapping (reseller_name_raw, vendor_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id)
            ");
            $stmt->execute([trim($_POST['reseller_name']), (int) $_POST['vendor_id']]);
            $message = 'Mapping saved.';
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['delete_mapping'])) {
        $pdo->prepare("DELETE FROM sales_out_reseller_mapping WHERE id = ?")->execute([(int) $_POST['id']]);
        $message = 'Mapping removed.';
    }
}

$mappings = $pdo->query("
    SELECT m.*, v.vendor_name 
    FROM sales_out_reseller_mapping m 
    JOIN vendors v ON m.vendor_id = v.id 
    ORDER BY m.reseller_name_raw
")->fetchAll(PDO::FETCH_ASSOC);

$vendors = $pdo->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name")->fetchAll(PDO::FETCH_ASSOC);

// Unmatched resellers from raw data
$unmatched = $pdo->query("
    SELECT reseller_name, COUNT(*) as cnt, SUM(total_value) as total
    FROM sales_out_raw 
    WHERE reseller_name != '' AND matched_vendor_id IS NULL
    GROUP BY reseller_name ORDER BY total DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4">
    <h1>Reseller Mapping</h1>
    <p class="text-muted">Map distributor reseller names to known vendors for aggregation</p>
    
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    
    <div class="card mt-4">
        <div class="card-header">Add Mapping</div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Reseller Name (as in report)</label>
                    <input type="text" name="reseller_name" class="form-control" required list="unmatchedList" placeholder="e.g. Acme Ltd">
                    <datalist id="unmatchedList">
                        <?php foreach ($unmatched as $u): ?>
                        <option value="<?= htmlspecialchars($u['reseller_name']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Map to Vendor</label>
                    <select name="vendor_id" class="form-select" required>
                        <option value="">Select vendor...</option>
                        <?php foreach ($vendors as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['vendor_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="add_mapping" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Current Mappings</div>
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered">
                        <thead class="table-light"><tr><th>Reseller (raw)</th><th>→ Vendor</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($mappings as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['reseller_name_raw']) ?></td>
                                <td><?= htmlspecialchars($m['vendor_name']) ?></td>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove?')">
                                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                        <button type="submit" name="delete_mapping" class="btn btn-sm btn-outline-danger">×</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($mappings)): ?>
                            <tr><td colspan="3" class="text-muted">No mappings yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Unmatched Resellers (top by value)</div>
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered">
                        <thead class="table-light"><tr><th>Reseller</th><th>Rows</th><th>Value</th></tr></thead>
                        <tbody>
                            <?php foreach ($unmatched as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['reseller_name']) ?></td>
                                <td><?= $u['cnt'] ?></td>
                                <td>£<?= number_format($u['total'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($unmatched)): ?>
                            <tr><td colspan="3" class="text-muted">All resellers matched.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div></div>
</body>
</html>
