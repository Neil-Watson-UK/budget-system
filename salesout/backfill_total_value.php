<?php
// backfill_total_value.php - Backfill total_value from trade_price * quantity when total_value is 0 or missing
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/backfill_total_value.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';
$preview = true;
$stats = ['found' => 0, 'updated' => 0, 'skipped_no_trade_price' => 0, 'skipped_zero_qty' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute'])) {
    $preview = false;
    try {
        // Find rows where total_value is 0 or NULL and we have a SKU and quantity
        $stmt = $pdo->prepare("
            SELECT s.id, s.sku, s.quantity, s.total_value, s.currency,
                   p.trade_price, p.currency as product_currency
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE (s.total_value IS NULL OR s.total_value = 0)
            AND s.sku IS NOT NULL AND s.sku != ''
            AND s.quantity > 0
            ORDER BY s.id
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats['found'] = count($rows);
        $updateStmt = $pdo->prepare("UPDATE sales_out_raw SET total_value = ? WHERE id = ?");
        
        foreach ($rows as $row) {
            $sku = trim($row['sku'] ?? '');
            $qty = (int)($row['quantity'] ?? 0);
            $tradePrice = isset($row['trade_price']) && $row['trade_price'] !== null && $row['trade_price'] !== '' 
                ? (float)$row['trade_price'] 
                : null;
            
            if ($qty <= 0) {
                $stats['skipped_zero_qty']++;
                continue;
            }
            
            if ($tradePrice === null || $tradePrice <= 0) {
                $stats['skipped_no_trade_price']++;
                continue;
            }
            
            $newTotalValue = $qty * $tradePrice;
            $updateStmt->execute([$newTotalValue, (int)$row['id']]);
            $stats['updated']++;
        }
        
        $message = "Backfill complete: Found {$stats['found']} rows with zero/missing total_value. Updated {$stats['updated']} rows using trade_price. Skipped {$stats['skipped_no_trade_price']} (no trade_price) and {$stats['skipped_zero_qty']} (zero quantity).";
    } catch (PDOException $e) {
        $error = 'Backfill failed: ' . $e->getMessage();
    }
} else {
    // Preview mode
    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.report_date, s.distributor_name, s.reseller_name, s.sku, s.product_name,
                   s.quantity, s.total_value, s.currency,
                   p.trade_price, p.currency as product_currency,
                   (s.quantity * COALESCE(p.trade_price, 0)) as calculated_value
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE (s.total_value IS NULL OR s.total_value = 0)
            AND s.sku IS NOT NULL AND s.sku != ''
            AND s.quantity > 0
            ORDER BY s.id DESC
            LIMIT 100
        ");
        $stmt->execute();
        $previewRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) as cnt
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE (s.total_value IS NULL OR s.total_value = 0)
            AND s.sku IS NOT NULL AND s.sku != ''
            AND s.quantity > 0
        ");
        $countStmt->execute();
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
        
        $stats['found'] = $totalCount;
        $stats['updated'] = count(array_filter($previewRows, function($r) {
            return isset($r['trade_price']) && $r['trade_price'] !== null && $r['trade_price'] > 0;
        }));
        $stats['skipped_no_trade_price'] = count(array_filter($previewRows, function($r) {
            return !isset($r['trade_price']) || $r['trade_price'] === null || $r['trade_price'] <= 0;
        }));
    } catch (PDOException $e) {
        $error = 'Preview failed: ' . $e->getMessage();
    }
}

$pageTitle = 'Backfill Total Value';
require_once __DIR__ . '/header.php';
?>
<style>
.report-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; margin-bottom: 1.5rem; }
.filter-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; }
.report-body { background: rgb(238, 239, 241); }
</style>

<div class="container-xl py-4 report-body">
    <div class="report-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-calculator me-2"></i>Backfill Total Value</h1>
            <p class="mb-0 opacity-75">Calculate total_value from trade_price × quantity for rows where total_value is 0 or missing.</p>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="filter-card">
        <div class="card-header">Summary</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="text-center p-3 bg-light rounded">
                        <div class="h4 mb-0"><?= number_format($stats['found']) ?></div>
                        <div class="small text-muted">Rows with zero/missing total_value</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-success bg-opacity-10 rounded">
                        <div class="h4 mb-0 text-success"><?= number_format($stats['updated']) ?></div>
                        <div class="small text-muted">Can be updated (have trade_price)</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-warning bg-opacity-10 rounded">
                        <div class="h4 mb-0 text-warning"><?= number_format($stats['skipped_no_trade_price']) ?></div>
                        <div class="small text-muted">No trade_price found</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 bg-info bg-opacity-10 rounded">
                        <div class="h4 mb-0 text-info"><?= number_format($stats['skipped_zero_qty']) ?></div>
                        <div class="small text-muted">Zero quantity</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($preview && isset($previewRows)): ?>
    <div class="filter-card">
        <div class="card-header">Preview (last 100 rows)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Distributor</th>
                            <th>Reseller</th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Current total_value</th>
                            <th>Trade Price</th>
                            <th>Calculated Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $r): ?>
                        <tr>
                            <td><a href="data_editor.php?id=<?= (int)$r['id'] ?>"><?= (int)$r['id'] ?></a></td>
                            <td><?= htmlspecialchars($r['report_date'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['distributor_name'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['reseller_name'] ?? '') ?></td>
                            <td><code><?= htmlspecialchars($r['sku'] ?? '') ?></code></td>
                            <td><?= htmlspecialchars($r['product_name'] ?? '') ?></td>
                            <td><?= (int)($r['quantity'] ?? 0) ?></td>
                            <td><?= number_format((float)($r['total_value'] ?? 0), 2) ?></td>
                            <td>
                                <?php if (isset($r['trade_price']) && $r['trade_price'] !== null && $r['trade_price'] > 0): ?>
                                    <?= htmlspecialchars($r['product_currency'] ?? 'GBP') ?> <?= number_format((float)$r['trade_price'], 2) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (isset($r['calculated_value']) && $r['calculated_value'] > 0): ?>
                                    <strong><?= htmlspecialchars($r['currency'] ?? 'GBP') ?> <?= number_format((float)$r['calculated_value'], 2) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($previewRows)): ?>
                        <tr><td colspan="10" class="text-muted text-center">No rows found with zero/missing total_value.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($stats['found'] > 0): ?>
    <div class="alert alert-warning">
        <form method="POST" class="d-inline">
            <p class="mb-2"><strong>Ready to update <?= number_format($stats['updated']) ?> rows?</strong></p>
            <p class="mb-3 small">This will set total_value = quantity × trade_price for all rows where total_value is 0 or NULL and a trade_price exists.</p>
            <button type="submit" name="execute" value="1" class="btn btn-primary" onclick="return confirm('Update <?= number_format($stats['updated']) ?> rows? This cannot be undone easily.');">
                <i class="ti ti-check me-1"></i>Execute Backfill
            </button>
            <a href="backfill_total_value.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <p class="mt-3"><a href="data_editor.php" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Data Editor</a></p>
</div>
</div></div>
</body>
</html>
