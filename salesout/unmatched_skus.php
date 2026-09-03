<?php
// unmatched_skus.php - Export unmatched SKU rows & import corrected SKUs
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/unmatched_skus.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';

$year = $_GET['year'] ?? '';
$distributor = $_GET['distributor'] ?? '';

$where = "p.id IS NULL";
$params = [];
if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
    $where .= " AND YEAR(s.report_date) = ?";
    $params[] = $year;
}
if ($distributor !== '') {
    $where .= " AND s.distributor_name = ?";
    $params[] = $distributor;
}

// Export unmatched rows (CSV with id for correction workflow)
if (isset($_GET['download']) && $_GET['download'] === '1') {
    try {
        if (ob_get_level()) ob_end_clean();

        $sql = "
            SELECT s.id, s.report_date, s.distributor_name, s.reseller_name, s.sku, s.product_name,
                s.quantity, s.unit_price, s.total_value, s.currency
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $where
            ORDER BY s.total_value DESC, s.report_date DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $filename = 'unmatched_skus_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'report_date', 'distributor_name', 'reseller_name', 'sku', 'product_name', 'quantity', 'unit_price', 'total_value', 'currency', 'sku_corrected']);
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            fputcsv($out, [
                $r['id'],
                $r['report_date'],
                $r['distributor_name'],
                $r['reseller_name'],
                $r['sku'],
                $r['product_name'] ?? '',
                $r['quantity'],
                $r['unit_price'] ?? '',
                $r['total_value'],
                $r['currency'] ?? '',
                '', // sku_corrected - user fills this (or edits sku in place)
            ]);
        }
        fclose($out);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Import SKU corrections (CSV: id, sku_corrected OR id, sku if sku_corrected empty)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['corrections_file']) && $_FILES['corrections_file']['error'] === UPLOAD_ERR_OK) {
    try {
        $handle = fopen($_FILES['corrections_file']['tmp_name'], 'r');
        if (!$handle) throw new Exception('Could not read file.');

        // Read first line and strip BOM if present (from export)
        $firstLine = fgets($handle);
        if ($firstLine === false) throw new Exception('File is empty or invalid CSV.');
        // Remove BOM (UTF-8 BOM: EF BB BF)
        if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
            $firstLine = substr($firstLine, 3);
        }
        // Parse CSV header from first line
        $header = str_getcsv($firstLine);
        if (!$header) throw new Exception('File is empty or invalid CSV.');

        // Normalize headers (PHP 7.3 compatible)
        $norm = function($h) {
            // Remove BOM and any whitespace, convert to lowercase
            $h = trim($h);
            if (substr($h, 0, 3) === "\xEF\xBB\xBF") {
                $h = substr($h, 3);
            }
            return strtolower($h);
        };
        $cols = array_map($norm, $header);
        $idIdx = array_search('id', $cols);
        $skuCorrectedIdx = array_search('sku_corrected', $cols);
        $skuIdx = array_search('sku', $cols);

        if ($idIdx === false || ($skuCorrectedIdx === false && $skuIdx === false)) {
            throw new Exception('CSV must have columns: id and sku (or sku_corrected). Export the unmatched file, fill sku_corrected (or edit sku), and re-upload.');
        }

        $updated = 0;
        $skipped = 0;
        $notFound = 0;

        $checkStmt = $pdo->prepare("SELECT id FROM sales_out_raw WHERE id = ?");
        $updateStmt = $pdo->prepare("UPDATE sales_out_raw SET sku = ? WHERE id = ?");

        while (($row = fgetcsv($handle)) !== false) {
            $maxCol = max($idIdx, $skuCorrectedIdx !== false ? $skuCorrectedIdx : 0, $skuIdx !== false ? $skuIdx : 0);
            if (count($row) <= $maxCol) continue;
            $id = trim($row[$idIdx] ?? '');
            // Prefer sku_corrected; if empty, use sku (user may have edited sku in place)
            $sku = '';
            if ($skuCorrectedIdx !== false && trim($row[$skuCorrectedIdx] ?? '') !== '') {
                $sku = trim($row[$skuCorrectedIdx]);
            } elseif ($skuIdx !== false) {
                $sku = trim($row[$skuIdx] ?? '');
            }
            if ($id === '' || $sku === '') {
                $skipped++;
                continue;
            }
            if (!ctype_digit($id)) {
                $skipped++;
                continue;
            }

            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                $notFound++;
                continue;
            }

            $updateStmt->execute([$sku, $id]);
            if ($updateStmt->rowCount() > 0) $updated++;
        }
        fclose($handle);

        $msg = "Updated {$updated} row(s).";
        if ($skipped) $msg .= " Skipped {$skipped} (empty id/sku).";
        if ($notFound) $msg .= " {$notFound} id(s) not found in database.";
        $message = $msg;
        header('Location: unmatched_skus.php?msg=' . urlencode($message));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Summary stats
$summary = ['count' => 0, 'value' => 0];
try {
    $sumSql = "
        SELECT COUNT(*) as cnt, COALESCE(SUM(s.total_value), 0) as val
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
    ";
    $sumStmt = $pdo->prepare($sumSql);
    $sumStmt->execute($params);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $e->getMessage();
}

$years = $pdo->query("SELECT DISTINCT YEAR(report_date) as y FROM sales_out_raw WHERE report_date IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
$distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);

// Preview sample (first 50)
$preview = [];
try {
    $prevSql = "
        SELECT s.id, s.report_date, s.distributor_name, s.sku, s.product_name, s.quantity, s.total_value
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $where
        ORDER BY s.total_value DESC
        LIMIT 50
    ";
    $prevStmt = $pdo->prepare($prevSql);
    $prevStmt->execute($params);
    $preview = $prevStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignore */ }

require_once __DIR__ . '/header.php';
?>
<style>
.unmatched-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0, 163, 153, 0.25); }
.unmatched-kpi { background: white; border-radius: 10px; padding: 1.25rem; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); text-align: center; }
.unmatched-kpi .kpi-value { font-size: 1.75rem; font-weight: 700; color: #ff5549; }
.card-epos { border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); background: white; }
.card-epos .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.body-bg { background: rgb(238, 239, 241); }
</style>

<div class="container-xl py-4 body-bg">
    <div class="unmatched-header">
        <h1 class="h2 mb-1"><i class="ti ti-barcode-off me-2"></i>Unmatched SKUs</h1>
        <p class="mb-0 opacity-75">Sales rows with SKU not found in product master. Export, fix SKUs in Excel, then import corrections.</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-4">
            <div class="unmatched-kpi">
                <div class="kpi-value"><?= number_format((int)($summary['count'] ?? 0)) ?></div>
                <div class="small text-muted text-uppercase">Unmatched rows</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="unmatched-kpi">
                <div class="kpi-value">£<?= number_format((float)($summary['value'] ?? 0), 0) ?></div>
                <div class="small text-muted text-uppercase">Dist. reported value</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="unmatched-kpi">
                <a href="products.php" class="btn btn-outline-primary btn-sm"><i class="ti ti-package me-1"></i>Product master</a>
                <div class="small text-muted mt-1">Add missing SKUs</div>
            </div>
        </div>
    </div>

    <div class="card-epos mb-4">
        <div class="card-header"><i class="ti ti-filter me-2"></i>Export Unmatched</div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="download" value="1">
                <div class="col-md-3">
                    <label class="form-label">Year</label>
                    <select name="year" class="form-select">
                        <option value="">All years</option>
                        <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $year === (string)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Distributor</label>
                    <select name="distributor" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($distributors as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $distributor === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="ti ti-download me-1"></i>Export CSV</button>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">CSV includes <code>id</code> and <code>sku_corrected</code>. Fill <code>sku_corrected</code> with the correct SKU from product master, then use Import below.</small>
                </div>
            </form>
        </div>
    </div>

    <div class="card-epos mb-4">
        <div class="card-header"><i class="ti ti-upload me-2"></i>Import SKU Corrections</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Corrections CSV</label>
                    <input type="file" name="corrections_file" class="form-control" accept=".csv" required>
                    <small class="text-muted">Use the exported file: fill <code>sku_corrected</code> (or edit <code>sku</code>), save as CSV, upload here.</small>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success"><i class="ti ti-check me-1"></i>Apply corrections</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card-epos">
        <div class="card-header"><i class="ti ti-table me-2"></i>Preview (top 50 by value)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light"><tr><th>ID</th><th>Date</th><th>Distributor</th><th>SKU</th><th>Product</th><th>Qty</th><th>Value</th></tr></thead>
                    <tbody>
                        <?php foreach ($preview as $r): ?>
                        <tr>
                            <td><code><?= (int)$r['id'] ?></code></td>
                            <td><?= htmlspecialchars($r['report_date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['distributor_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['sku'] ?? '') ?></td>
                            <td><?= htmlspecialchars(substr($r['product_name'] ?? '', 0, 40)) ?><?= strlen($r['product_name'] ?? '') > 40 ? '…' : '' ?></td>
                            <td><?= (int)($r['quantity'] ?? 0) ?></td>
                            <td>£<?= number_format((float)($r['total_value'] ?? 0), 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($preview)): ?>
                        <tr><td colspan="7" class="text-muted">No unmatched SKUs. All sales rows have matching product master entries.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div></div>
</body>
</html>
