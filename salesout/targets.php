<?php
// salesout/targets.php - Set sales targets by distributor or reseller
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/targets.php'));
    exit;
}

$pdo = getDBConnection();
$seasonality = getSeasonalityPercentages($pdo);
$targets = [];
$distributors = [];
$resellers = [];
$dbError = null;
$message = '';
$messageType = '';

// Handle POST: save target
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'delete' && !empty($_POST['id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM sales_out_targets WHERE id = ?");
            $stmt->execute([(int)$_POST['id']]);
            $message = 'Target deleted.';
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Failed to delete: ' . $e->getMessage();
            $messageType = 'danger';
        }
    } elseif ($action === 'save') {
        $targetType = $_POST['target_type'] ?? '';
        $entityKey = trim($_POST['entity_key'] ?? '');
        $year = (int)($_POST['year'] ?? 0);
        $annualTarget = (float)($_POST['annual_target'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if (!in_array($targetType, ['distributor', 'reseller']) || $entityKey === '' || $year < 2000 || $year > 2100) {
            $message = 'Please select a valid type, entity, and year (2000–2100).';
            $messageType = 'warning';
        } elseif ($annualTarget <= 0) {
            $message = 'Annual target must be greater than 0.';
            $messageType = 'warning';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO sales_out_targets (target_type, entity_key, year, annual_target, notes)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE annual_target = VALUES(annual_target), notes = VALUES(notes)
                ");
                $stmt->execute([$targetType, $entityKey, $year, $annualTarget, $notes ?: null]);
                $message = 'Target saved.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Failed to save: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

try {
    // Ensure targets table exists (run install script if needed)
    $pdo->query("SELECT 1 FROM sales_out_targets LIMIT 1");
} catch (PDOException $e) {
    $installPath = __DIR__ . '/install/sales_targets.sql';
    if (file_exists($installPath)) {
        $sql = file_get_contents($installPath);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if (preg_match('/^(CREATE|INSERT|ALTER|DROP)/i', $stmt)) {
                try { $pdo->exec($stmt); } catch (PDOException $ex) { /* ignore if already exists */ }
            }
        }
    }
}

try {
    $targets = $pdo->query("
        SELECT t.*,
            CASE
                WHEN t.target_type = 'distributor' THEN t.entity_key
                WHEN t.target_type = 'reseller' THEN v.vendor_name
                ELSE t.entity_key
            END as display_name
        FROM sales_out_targets t
        LEFT JOIN vendors v ON t.target_type = 'reseller' AND t.entity_key = v.id
        ORDER BY t.target_type, display_name, t.year DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw WHERE distributor_name != '' ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);

    // Show only vendors that have been matched to sales data
    $resellers = $pdo->query("
        SELECT DISTINCT v.id, v.vendor_name
        FROM vendors v
        INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
        WHERE s.matched_vendor_id IS NOT NULL
        ORDER BY v.vendor_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>
<style>
.targets-header { background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 50%, #0ea5e9 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.25); }
.target-card { border-radius: 12px; border: 1px solid #e1e5eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.target-card .card-header { background: #f8f9fa; border-bottom: 1px solid #e1e5eb; padding: 1rem 1.25rem; font-weight: 600; }
.seasonality-table { font-size: 0.9rem; }
</style>

<div class="container-xl py-4">
    <div class="targets-header">
        <div class="container-fluid">
            <div class="d-flex align-items-center mb-2">
                <i class="ti ti-target me-2" style="font-size: 1.5rem;"></i>
                <h1 class="h2 mb-0">Sales Targets</h1>
            </div>
            <p class="mb-0 opacity-75">Set annual sales targets by distributor or reseller. Targets are distributed over the year using the team seasonality pattern.</p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($dbError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php else: ?>

    <div class="row">
        <div class="col-lg-5">
            <div class="target-card">
                <div class="card-header"><i class="ti ti-plus me-2"></i>Add or Update Target</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save">
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="target_type" id="target_type" class="form-select" required>
                                <option value="">— Select —</option>
                                <option value="distributor">Distributor</option>
                                <option value="reseller">Reseller (Vendor)</option>
                            </select>
                        </div>
                        <div class="mb-3" id="wrap_entity" style="display:none">
                            <label class="form-label" id="entity_label">Entity</label>
                            <select name="entity_key" id="entity_key" class="form-select" required>
                                <option value="">— Select —</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Year</label>
                            <input type="number" name="year" class="form-control" min="2000" max="2100" value="<?= date('Y') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Annual Target (£)</label>
                            <input type="number" name="annual_target" class="form-control" min="0" step="0.01" placeholder="e.g. 500000" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (optional)</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional">
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i> Save Target</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="target-card">
                <div class="card-header"><i class="ti ti-chart-bar me-2"></i>Seasonality (default)</div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Used to distribute the annual target over months. Matches team Excel.</p>
                    <div class="table-responsive">
                        <table class="table table-sm seasonality-table table-bordered mb-0">
                            <thead class="table-light"><tr>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <th class="text-center"><?= date('M', mktime(0,0,0,$m,1)) ?></th>
                                <?php endfor; ?>
                            </tr></thead>
                            <tbody><tr>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <td class="text-center"><?= $seasonality[$m] ?>%</td>
                                <?php endfor; ?>
                            </tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="target-card">
                <div class="card-header"><i class="ti ti-list me-2"></i>Existing Targets</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped table-bordered mb-0">
                            <thead class="table-light"><tr><th>Type</th><th>Entity</th><th>Year</th><th>Annual Target</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($targets as $t): ?>
                                <tr>
                                    <td><span class="badge bg-<?= $t['target_type'] === 'distributor' ? 'primary' : 'info' ?>"><?= ucfirst($t['target_type']) ?></span></td>
                                    <td><?= htmlspecialchars($t['display_name'] ?? $t['entity_key']) ?></td>
                                    <td><?= (int)$t['year'] ?></td>
                                    <td>£<?= number_format((float)$t['annual_target'], 0) ?></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this target?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($targets)): ?>
                                <tr><td colspan="5" class="text-muted">No targets set yet. Add one above.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Prepare option structures for JS in a PHP 7.3–compatible way
$distributorOptions = [];
if (!empty($distributors) && is_array($distributors)) {
    foreach ($distributors as $d) {
        if (!empty($d) && is_string($d)) {
            $distributorOptions[] = ['value' => htmlspecialchars($d, ENT_QUOTES, 'UTF-8'), 'label' => htmlspecialchars($d, ENT_QUOTES, 'UTF-8')];
        }
    }
}
$resellerOptions = [];
if (!empty($resellers) && is_array($resellers)) {
    foreach ($resellers as $r) {
        if (is_array($r) && !empty($r['id']) && !empty($r['vendor_name'])) {
            $resellerOptions[] = [
                'value' => (string)(int)$r['id'], 
                'label' => htmlspecialchars($r['vendor_name'], ENT_QUOTES, 'UTF-8')
            ];
        }
    }
}
?>

<script>
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        var typeSel = document.getElementById('target_type');
        var wrapEntity = document.getElementById('wrap_entity');
        var entityLabel = document.getElementById('entity_label');
        var entityKey = document.getElementById('entity_key');

        if (!typeSel || !wrapEntity || !entityLabel || !entityKey) {
            alert('Error: Form elements not found. Please refresh the page.');
            return;
        }

        var distributors = <?php 
            $distJson = json_encode($distributorOptions ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($distJson === false) $distJson = '[]';
            echo $distJson;
        ?>;
        var resellers = <?php 
            $resJson = json_encode($resellerOptions ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($resJson === false) $resJson = '[]';
            echo $resJson;
        ?>;

        function toggleEntityInput() {
            var val = typeSel.value;
            if (!val) {
                wrapEntity.style.display = 'none';
                return;
            }
            
            wrapEntity.style.display = 'block';
            entityKey.innerHTML = '<option value="">— Select —</option>';
            
            if (val === 'distributor') {
                entityLabel.textContent = 'Distributor';
                if (distributors && distributors.length > 0) {
                    for (var i = 0; i < distributors.length; i++) {
                        var d = distributors[i];
                        if (d && d.value && d.label) {
                            var opt = document.createElement('option');
                            opt.value = d.value;
                            opt.textContent = d.label;
                            entityKey.appendChild(opt);
                        }
                    }
                } else {
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'No distributors found';
                    entityKey.appendChild(opt);
                }
            } else if (val === 'reseller') {
                entityLabel.textContent = 'Reseller (Vendor)';
                if (resellers && resellers.length > 0) {
                    for (var i = 0; i < resellers.length; i++) {
                        var r = resellers[i];
                        if (r && r.value && r.label) {
                            var opt = document.createElement('option');
                            opt.value = r.value;
                            opt.textContent = r.label;
                            entityKey.appendChild(opt);
                        }
                    }
                } else {
                    var opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'No vendors found';
                    entityKey.appendChild(opt);
                }
            }
        }
        
        typeSel.addEventListener('change', toggleEntityInput);
    });
})();
</script>
</div></div>
</body>
</html>
