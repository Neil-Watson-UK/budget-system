<?php
// missing_data.php - Data coverage check: see by year/month which distributors have data
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/missing_data.php'));
    exit;
}

$pdo = getDBConnection();
$yearFilter = $_GET['year'] ?? '';
$message = '';
$error = '';

// Delete records for a specific distributor + month
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_cell'])) {
    $distributor = trim($_POST['distributor'] ?? '');
    $year = (int) ($_POST['year'] ?? 0);
    $month = (int) ($_POST['month'] ?? 0);
    $redirectYear = $_POST['redirect_year'] ?? $_GET['year'] ?? '';
    if ($distributor && $year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12) {
        try {
            $stmt = $pdo->prepare("
                DELETE FROM sales_out_raw
                WHERE distributor_name = ? AND YEAR(report_date) = ? AND MONTH(report_date) = ?
            ");
            $stmt->execute([$distributor, $year, $month]);
            $deleted = $stmt->rowCount();
            $qs = ($redirectYear !== '' && preg_match('/^\d{4}$/', $redirectYear) ? 'year=' . $redirectYear . '&' : '') . 'deleted=' . $deleted . '&dist=' . urlencode($distributor) . '&ym=' . $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
            header('Location: missing_data.php?' . $qs);
            exit;
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Invalid parameters.';
    }
}

// Show success message from redirect
if (isset($_GET['deleted']) && isset($_GET['dist']) && isset($_GET['ym'])) {
    $message = "Deleted " . (int)$_GET['deleted'] . " rows from " . htmlspecialchars($_GET['dist']) . " for " . date('M Y', strtotime($_GET['ym'] . '-01')) . ". You can re-import to replace.";
}

$distributors = [];
$months = [];
$coverage = [];
$years = [];

try {
    $years = $pdo->query("SELECT DISTINCT YEAR(report_date) as y FROM sales_out_raw WHERE report_date IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);

    $where = "report_date IS NOT NULL";
    $params = [];
    if ($yearFilter !== '' && preg_match('/^\d{4}$/', $yearFilter)) {
        $where .= " AND YEAR(report_date) = ?";
        $params[] = $yearFilter;
    }

    $stmt = $pdo->prepare("
        SELECT YEAR(report_date) as yr, MONTH(report_date) as mo,
            distributor_name, COUNT(*) as row_count, COALESCE(SUM(total_value), 0) as total_value
        FROM sales_out_raw
        WHERE $where
        GROUP BY yr, mo, distributor_name
        ORDER BY yr DESC, mo DESC, distributor_name
    ");
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT);
        if (!isset($coverage[$key])) {
            $coverage[$key] = ['label' => date('M Y', strtotime($r['yr'] . '-' . $r['mo'] . '-01')), 'yr' => $r['yr'], 'mo' => $r['mo']];
        }
        $coverage[$key][$r['distributor_name']] = [
            'rows' => (int)$r['row_count'],
            'value' => (float)$r['total_value'],
        ];
    }

    $months = array_keys($coverage);
    usort($months, function($a, $b) { return strcmp($b, $a); });

} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$pageTitle = 'Missing Data Check';
require_once __DIR__ . '/header.php';
?>
<style>
.coverage-card { border-radius: 12px; border: 1px solid #e1e5eb; box-shadow: 0 2px 8px rgba(0,0,0,0.05); overflow: hidden; }
.coverage-card .card-header { background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); color: white; padding: 1rem 1.25rem; font-weight: 600; }
.cell-yes { color: #2ecc71; font-weight: 600; }
.cell-yes.cell-deletable:hover { background: rgba(231, 76, 60, 0.08); }
.cell-yes .btn-delete-cell { color: inherit; }
.cell-yes .btn-delete-cell:hover { color: #e74c3c; }
.cell-no { color: #dee2e6; }
.coverage-table th { font-size: 0.8rem; white-space: nowrap; }
.coverage-table td { font-size: 0.85rem; }
.coverage-table .month-col { font-weight: 500; min-width: 80px; }
</style>

<div class="container-xl py-4">
    <h1><i class="ti ti-clipboard-check me-2"></i>Missing Data Check</h1>
    <p class="text-muted">See which distributors have data for each year and month. Gaps indicate missing reports to chase.</p>

    <?php if (!empty($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <option value="">All years</option>
                        <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $yearFilter === (string)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="coverage-card">
        <div class="card-header">
            Data coverage by distributor
            <?php if ($yearFilter): ?> — <?= htmlspecialchars($yearFilter) ?><?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 coverage-table">
                <thead class="table-light">
                    <tr>
                        <th class="month-col">Year‑Month</th>
                        <?php foreach ($distributors as $d): ?>
                        <th class="text-center"><?= htmlspecialchars($d) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($months as $key): 
                        $row = $coverage[$key] ?? [];
                    ?>
                    <tr>
                        <td class="month-col"><?= htmlspecialchars($row['label'] ?? $key) ?></td>
                        <?php foreach ($distributors as $d): 
                            $cell = $row[$d] ?? null;
                            if ($cell): 
                                $yr = $row['yr'] ?? substr($key, 0, 4);
                                $mo = $row['mo'] ?? (int) substr($key, 5, 2);
                                $confirmMsg = "Delete " . number_format($cell['rows']) . " rows from " . htmlspecialchars($d) . " for " . ($row['label'] ?? date('M Y', strtotime("$yr-$mo-01"))) . "? You can re-import to replace.";
                        ?>
                        <td class="text-center cell-yes cell-deletable" title="<?= number_format($cell['rows']) ?> rows, £<?= number_format($cell['value'], 0) ?> — Click to preview and delete">
                            <form method="POST" class="d-inline delete-cell-form">
                                <input type="hidden" name="delete_cell" value="1">
                                <input type="hidden" name="distributor" value="<?= htmlspecialchars($d) ?>">
                                <input type="hidden" name="year" value="<?= (int)$yr ?>">
                                <input type="hidden" name="month" value="<?= (int)$mo ?>">
                                <?php if ($yearFilter): ?><input type="hidden" name="redirect_year" value="<?= htmlspecialchars($yearFilter) ?>"><?php endif; ?>
                                <button type="button" class="btn btn-link p-0 border-0 btn-delete-cell btn-preview-cell" title="Preview and delete" data-distributor="<?= htmlspecialchars($d) ?>" data-year="<?= (int)$yr ?>" data-month="<?= (int)$mo ?>"><i class="ti ti-check"></i> <?= number_format($cell['rows']) ?></button>
                            </form>
                        </td>
                        <?php else: ?>
                        <td class="text-center cell-no">—</td>
                        <?php endif; endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($months)): ?>
                    <tr><td colspan="<?= count($distributors) + 1 ?>" class="text-muted text-center py-4">No data. <a href="import.php">Import sales reports</a>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small">
            <i class="ti ti-info-circle me-1"></i> Green check = data present (hover for row count). <strong>Click a cell</strong> to preview and delete that month&apos;s data. Dash = no data.
        </div>
    </div>
</div>

<!-- Delete preview modal -->
<div class="modal modal-blur fade" id="deletePreviewModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-trash me-2"></i>Preview before delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="previewLoading" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 mb-0">Loading preview…</p></div>
                <div id="previewContent" style="display:none">
                    <p id="previewSummary" class="mb-3"></p>
                    <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                        <table class="table table-sm table-striped table-bordered mb-0">
                            <thead class="table-light sticky-top"><tr><th>Date</th><th>Reseller</th><th>SKU</th><th>Product</th><th>Qty</th><th>Value</th></tr></thead>
                            <tbody id="previewTable"></tbody>
                        </table>
                    </div>
                    <p id="previewMore" class="text-muted small mt-2 mb-0"></p>
                </div>
                <div id="previewError" class="alert alert-danger" style="display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="previewConfirmDelete"><i class="ti ti-trash me-1"></i>Delete these records</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    const modal = document.getElementById('deletePreviewModal');
    const loading = document.getElementById('previewLoading');
    const content = document.getElementById('previewContent');
    const errorEl = document.getElementById('previewError');
    const summaryEl = document.getElementById('previewSummary');
    const tableBody = document.getElementById('previewTable');
    const moreEl = document.getElementById('previewMore');
    const confirmBtn = document.getElementById('previewConfirmDelete');

    let pendingForm = null;

    document.querySelectorAll('.btn-preview-cell').forEach(btn => {
        btn.addEventListener('click', function() {
            pendingForm = this.closest('form');
            const d = this.dataset.distributor, y = this.dataset.year, m = this.dataset.month;
            loading.style.display = 'block';
            content.style.display = 'none';
            errorEl.style.display = 'none';
            new bootstrap.Modal(modal).show();

            fetch('missing_data_preview.php?distributor=' + encodeURIComponent(d) + '&year=' + y + '&month=' + m)
                .then(r => r.json())
                .then(data => {
                    loading.style.display = 'none';
                    if (data.error) {
                        errorEl.textContent = data.error;
                        errorEl.style.display = 'block';
                        return;
                    }
                    content.style.display = 'block';
                    summaryEl.textContent = 'About to delete ' + data.total_rows.toLocaleString() + ' rows from ' + data.distributor + ' for ' + data.label + ' (total £' + parseFloat(data.total_value).toLocaleString('en-GB', {maximumFractionDigits: 0}) + '). Preview below:';
                    tableBody.innerHTML = (data.preview || []).map(r => '<tr><td>' + escapeHtml(r.report_date || '') + '</td><td class="text-truncate" style="max-width:120px" title="' + escapeHtml(r.reseller_name || '') + '">' + escapeHtml(r.reseller_name || '') + '</td><td><code class="small">' + escapeHtml(r.sku || '') + '</code></td><td class="text-truncate" style="max-width:150px" title="' + escapeHtml(r.product_name || '') + '">' + escapeHtml(r.product_name || '') + '</td><td>' + (r.quantity || 0) + '</td><td>£' + parseFloat(r.total_value || 0).toLocaleString('en-GB', {maximumFractionDigits: 2}) + '</td></tr>').join('');
                    moreEl.textContent = data.preview.length < data.total_rows ? 'Showing first ' + data.preview.length + ' of ' + data.total_rows + ' rows.' : '';
                })
                .catch(err => {
                    loading.style.display = 'none';
                    errorEl.textContent = 'Failed to load preview: ' + err.message;
                    errorEl.style.display = 'block';
                });
        });
    });

    confirmBtn.addEventListener('click', function() {
        if (pendingForm) {
            pendingForm.submit();
        }
        bootstrap.Modal.getInstance(modal)?.hide();
    });

    function escapeHtml(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
})();
</script>
</div></div>
</body>
</html>
