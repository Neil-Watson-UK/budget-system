<?php
// data_editor.php - Search, edit, and delete sales_out_raw records
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/data_editor.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';

// Delete record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM sales_out_raw WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                $message = "Deleted record ID $id.";
            } else {
                $error = "Record ID $id not found.";
            }
        } catch (PDOException $e) {
            $error = 'Delete failed: ' . $e->getMessage();
        }
    }
}

// Update record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_id'])) {
    $id = (int)$_POST['update_id'];
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare("
                UPDATE sales_out_raw SET
                    report_date = ?,
                    distributor_name = ?,
                    reseller_name = ?,
                    distributor_reference = ?,
                    sku = ?,
                    product_name = ?,
                    quantity = ?,
                    unit_price = ?,
                    total_value = ?,
                    currency = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $_POST['report_date'] ?: null,
                trim($_POST['distributor_name'] ?? '') ?: null,
                trim($_POST['reseller_name'] ?? '') ?: null,
                trim($_POST['distributor_reference'] ?? '') ?: null,
                trim($_POST['sku'] ?? '') ?: null,
                trim($_POST['product_name'] ?? '') ?: null,
                (int)($_POST['quantity'] ?? 0),
                parseDecimalValue($_POST['unit_price'] ?? '0'),
                parseDecimalValue($_POST['total_value'] ?? '0'),
                trim($_POST['currency'] ?? '') ?: 'EUR',
                $id
            ]);
            $message = "Updated record ID $id.";
        } catch (PDOException $e) {
            $error = 'Update failed: ' . $e->getMessage();
        }
    }
}

// Search filters
$searchId = trim($_GET['id'] ?? '');
$searchDist = trim($_GET['distributor'] ?? '');
$searchReseller = trim($_GET['reseller'] ?? '');
$searchDate = trim($_GET['date'] ?? '');
$searchSku = trim($_GET['sku'] ?? '');
$searchDistRef = trim($_GET['dist_ref'] ?? '');

$where = ['1=1'];
$params = [];

if ($searchId !== '') {
    $where[] = 's.id = ?';
    $params[] = (int)$searchId;
}
if ($searchDist !== '') {
    $where[] = 's.distributor_name LIKE ?';
    $params[] = '%' . $searchDist . '%';
}
if ($searchReseller !== '') {
    $where[] = 's.reseller_name LIKE ?';
    $params[] = '%' . $searchReseller . '%';
}
if ($searchDate !== '') {
    $where[] = 's.report_date = ?';
    $params[] = $searchDate;
}
if ($searchSku !== '') {
    $where[] = 's.sku LIKE ?';
    $params[] = '%' . $searchSku . '%';
}
if ($searchDistRef !== '') {
    $where[] = 's.distributor_reference LIKE ?';
    $params[] = '%' . $searchDistRef . '%';
}

$whereClause = implode(' AND ', $where);
$records = [];

try {
    $stmt = $pdo->prepare("
        SELECT s.*, v.vendor_name as matched_vendor_name
        FROM sales_out_raw s
        LEFT JOIN vendors v ON s.matched_vendor_id = v.id
        WHERE $whereClause
        ORDER BY s.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>
<style>
.editor-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 10px; }
.filter-card { background: white; border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
.filter-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; }
.table-editor { font-size: 0.875rem; }
.table-editor td { vertical-align: middle; }
.btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
.modal-body .form-label { font-weight: 600; font-size: 0.875rem; margin-bottom: 0.25rem; }
</style>

<div class="container-xl py-4">
    <div class="editor-header">
        <h1 class="h2 mb-1"><i class="ti ti-edit me-2"></i>Sales Data Editor</h1>
        <p class="mb-0 opacity-75">Search, edit, or delete sales records. Limited to 200 results.</p>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="filter-card">
        <div class="card-header"><i class="ti ti-filter me-2"></i>Search</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">ID</label>
                    <input type="number" name="id" class="form-control form-control-sm" value="<?= htmlspecialchars($searchId) ?>" placeholder="6531">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Distributor</label>
                    <input type="text" name="distributor" class="form-control form-control-sm" value="<?= htmlspecialchars($searchDist) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Disti Ref</label>
                    <input type="text" name="dist_ref" class="form-control form-control-sm" value="<?= htmlspecialchars($searchDistRef) ?>" placeholder="Their code">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Reseller</label>
                    <input type="text" name="reseller" class="form-control form-control-sm" value="<?= htmlspecialchars($searchReseller) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($searchDate) ?>">
                </div>
                <div class="col-12"></div>
                <div class="col-md-2">
                    <label class="form-label">SKU</label>
                    <input type="text" name="sku" class="form-control form-control-sm" value="<?= htmlspecialchars($searchSku) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Search</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($records)): ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="ti ti-table me-2"></i>Results (<?= count($records) ?>)</span>
            <?php if (count($records) >= 200): ?><small class="text-muted">Showing first 200 results</small><?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped table-editor mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th><th>Date</th><th>Distributor</th><th>Disti Ref</th><th>Reseller</th><th>SKU</th><th>Product</th>
                            <th>Qty</th><th>Unit Price</th><th>Total</th><th>Matched</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                        <tr>
                            <td><code><?= (int)$r['id'] ?></code></td>
                            <td><?= htmlspecialchars($r['report_date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['distributor_name'] ?? '') ?></td>
                            <td><code class="small"><?= htmlspecialchars($r['distributor_reference'] ?? '—') ?></code></td>
                            <td><?= htmlspecialchars($r['reseller_name'] ?? '') ?></td>
                            <td><code class="small"><a href="product_detail.php?sku=<?= urlencode($r['sku'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($r['sku'] ?? '') ?></a></code></td>
                            <td class="text-truncate" style="max-width:150px" title="<?= htmlspecialchars($r['product_name'] ?? '') ?>"><a href="product_detail.php?sku=<?= urlencode($r['sku'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($r['product_name'] ?? '') ?></a></td>
                            <td><?= (int)($r['quantity'] ?? 0) ?></td>
                            <td>£<?= number_format((float)($r['unit_price'] ?? 0), 2) ?></td>
                            <td><strong>£<?= number_format((float)($r['total_value'] ?? 0), 2) ?></strong></td>
                            <td><?= !empty($r['matched_vendor_name']) ? '<span class="badge bg-success">' . htmlspecialchars($r['matched_vendor_name']) . '</span>' : '<span class="badge bg-secondary">Unmatched</span>' ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="editRecord(<?= htmlspecialchars(json_encode($r)) ?>)">
                                    <i class="ti ti-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteRecord(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['distributor_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($r['product_name'] ?? '')) ?>')">
                                    <i class="ti ti-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif ($searchId || $searchDist || $searchReseller || $searchDate || $searchSku || $searchDistRef): ?>
    <div class="alert alert-info">No records found matching your search criteria.</div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="update_id" id="edit_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">ID</label>
                            <input type="text" class="form-control form-control-sm" id="edit_id_display" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date *</label>
                            <input type="date" name="report_date" class="form-control form-control-sm" id="edit_date" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Distributor</label>
                            <input type="text" name="distributor_name" class="form-control form-control-sm" id="edit_distributor">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Reseller</label>
                            <input type="text" name="reseller_name" class="form-control form-control-sm" id="edit_reseller">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Disti Ref</label>
                            <input type="text" name="distributor_reference" class="form-control form-control-sm" id="edit_dist_ref" placeholder="Their code">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SKU</label>
                            <input type="text" name="sku" class="form-control form-control-sm" id="edit_sku">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Product Name</label>
                            <input type="text" name="product_name" class="form-control form-control-sm" id="edit_product">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-control form-control-sm" id="edit_quantity" min="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unit Price</label>
                            <input type="text" name="unit_price" class="form-control form-control-sm" id="edit_unit_price" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Total Value</label>
                            <input type="text" name="total_value" class="form-control form-control-sm" id="edit_total_value" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Currency</label>
                            <input type="text" name="currency" class="form-control form-control-sm" id="edit_currency" maxlength="3" placeholder="EUR">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Record</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="delete_id" id="delete_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete record <strong id="delete_id_display"></strong>?</p>
                    <div class="alert alert-warning">
                        <strong>Distributor:</strong> <span id="delete_distributor"></span><br>
                        <strong>Product:</strong> <span id="delete_product"></span>
                    </div>
                    <p class="text-danger mb-0"><strong>This action cannot be undone.</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editRecord(r) {
    document.getElementById('edit_id').value = r.id;
    document.getElementById('edit_id_display').value = r.id;
    document.getElementById('edit_date').value = r.report_date || '';
    document.getElementById('edit_distributor').value = r.distributor_name || '';
    document.getElementById('edit_reseller').value = r.reseller_name || '';
    document.getElementById('edit_dist_ref').value = r.distributor_reference || '';
    document.getElementById('edit_sku').value = r.sku || '';
    document.getElementById('edit_product').value = r.product_name || '';
    document.getElementById('edit_quantity').value = r.quantity || 0;
    document.getElementById('edit_unit_price').value = r.unit_price || '0.00';
    document.getElementById('edit_total_value').value = r.total_value || '0.00';
    document.getElementById('edit_currency').value = r.currency || 'EUR';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function deleteRecord(id, distributor, product) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_id_display').textContent = id;
    document.getElementById('delete_distributor').textContent = distributor || '(empty)';
    document.getElementById('delete_product').textContent = product || '(empty)';
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</div></div>
</body>
</html>
