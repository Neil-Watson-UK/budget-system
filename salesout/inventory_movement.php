<?php
// inventory_movement.php - Compare inventory between two snapshot dates
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/inventory_movement.php'));
    exit;
}

$pdo = getDBConnection();
$distributor = trim($_GET['distributor'] ?? '');
$aliases = getInventoryDistributorAliases();
$distNames = $distributor ? ($aliases[$distributor] ?? [$distributor]) : [];
$distPh = $distNames ? implode(',', array_fill(0, count($distNames), 'LOWER(?)')) : '';
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sortBy = $_GET['sort'] ?? 'delta_qty'; // delta_qty, delta_value, from_value, to_value
$tableExists = false;

try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
} catch (PDOException $e) { /* ignore */ }

$distributors = [];
$availableDates = [];
$movement = [];
$summary = ['from_value' => 0, 'to_value' => 0, 'from_units' => 0, 'to_units' => 0, 'delta_value' => 0, 'delta_units' => 0];

if ($tableExists) {
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_inventory ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);
    $availableDates = $pdo->query("SELECT DISTINCT snapshot_date FROM sales_out_inventory ORDER BY snapshot_date")->fetchAll(PDO::FETCH_COLUMN);

    if ($dateFrom && $dateTo && in_array($dateFrom, $availableDates) && in_array($dateTo, $availableDates) && $dateFrom !== $dateTo) {
        // Get from and to data in two queries, merge in PHP
        $fromData = [];
        $toData = [];
        $allKeys = [];

        $whereDist = $distPh ? " AND LOWER(TRIM(distributor_name)) IN ($distPh)" : "";
        $stmtF = $pdo->prepare("
            SELECT TRIM(REPLACE(sku,' ','')) as sku_norm, distributor_name, sku, sku_description,
                on_hand_qty, inventory_value
            FROM sales_out_inventory
            WHERE snapshot_date = ? $whereDist
        ");
        $stmtF->execute($distributor ? array_merge([$dateFrom], $distNames) : [$dateFrom]);
        while ($r = $stmtF->fetch(PDO::FETCH_ASSOC)) {
            $k = $r['distributor_name'] . '|' . $r['sku_norm'];
            $allKeys[$k] = ['distributor' => $r['distributor_name'], 'sku' => $r['sku'], 'desc' => $r['sku_description']];
            $fromData[$k] = ['qty' => (int)$r['on_hand_qty'], 'value' => (float)$r['inventory_value']];
        }

        $stmtT = $pdo->prepare("
            SELECT TRIM(REPLACE(sku,' ','')) as sku_norm, distributor_name, sku, sku_description,
                on_hand_qty, inventory_value
            FROM sales_out_inventory
            WHERE snapshot_date = ? $whereDist
        ");
        $stmtT->execute($distributor ? array_merge([$dateTo], $distNames) : [$dateTo]);
        while ($r = $stmtT->fetch(PDO::FETCH_ASSOC)) {
            $k = $r['distributor_name'] . '|' . $r['sku_norm'];
            if (!isset($allKeys[$k])) {
                $allKeys[$k] = ['distributor' => $r['distributor_name'], 'sku' => $r['sku'], 'desc' => $r['sku_description']];
            }
            $toData[$k] = ['qty' => (int)$r['on_hand_qty'], 'value' => (float)$r['inventory_value']];
        }

        foreach ($allKeys as $k => $meta) {
            $fq = ($fromData[$k] ?? [])['qty'] ?? 0;
            $fv = ($fromData[$k] ?? [])['value'] ?? 0;
            $tq = ($toData[$k] ?? [])['qty'] ?? 0;
            $tv = ($toData[$k] ?? [])['value'] ?? 0;
            $dq = $tq - $fq;
            $dv = $tv - $fv;
            if ($dq !== 0 || $dv !== 0 || $fq > 0 || $tq > 0) {
                $movement[] = array_merge($meta, [
                    'from_qty' => $fq, 'from_value' => $fv,
                    'to_qty' => $tq, 'to_value' => $tv,
                    'delta_qty' => $dq, 'delta_value' => $dv,
                ]);
            }
        }

        usort($movement, function($a, $b) use ($sortBy) {
            $av = (float)($a[$sortBy] ?? 0);
            $bv = (float)($b[$sortBy] ?? 0);
            if ($sortBy === 'delta_qty' || $sortBy === 'delta_value') {
                return abs($bv) <=> abs($av);
            }
            return $bv <=> $av;
        });

        foreach ($movement as $m) {
            $summary['from_value'] += $m['from_value'];
            $summary['to_value'] += $m['to_value'];
            $summary['from_units'] += $m['from_qty'];
            $summary['to_units'] += $m['to_qty'];
        }
        $summary['delta_value'] = $summary['to_value'] - $summary['from_value'];
        $summary['delta_units'] = $summary['to_units'] - $summary['from_units'];
    }
}

// Enrich with product names from products table
if (!empty($movement)) {
    $skuNorms = array_values(array_unique(array_map(function($m) { return trim(str_replace(' ', '', $m['sku'])); }, $movement)));
    $pNames = [];
    if (!empty($skuNorms)) {
        $placeholders = implode(',', array_fill(0, count($skuNorms), '?'));
        $pstmt = $pdo->prepare("SELECT TRIM(REPLACE(sku,' ','')) as snorm, product_name FROM sales_out_products WHERE TRIM(REPLACE(sku,' ','')) IN ($placeholders)");
        $pstmt->execute($skuNorms);
        while ($row = $pstmt->fetch(PDO::FETCH_ASSOC)) {
            $pNames[$row['snorm']] = $row['product_name'];
        }
    }
    foreach ($movement as &$m) {
        $m['product_name'] = $pNames[trim(str_replace(' ', '', $m['sku']))] ?? $m['desc'] ?? '—';
    }
}

require_once __DIR__ . '/header.php';
?>
<style>
.inv-move-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; }
.delta-pos { color: #059669; }
.delta-neg { color: #dc2626; }
</style>
<div class="container-xl py-4">
    <div class="inv-move-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-arrows-exchange me-2"></i>Stock Movement</h1>
            <p class="mb-0 opacity-75">Compare inventory between two snapshot dates. Shows quantity and value changes per SKU.</p>
        </div>
    </div>

    <?php if (!$tableExists): ?>
    <div class="alert alert-warning"><i class="ti ti-alert-triangle me-2"></i>Run <code>install/inventory_schema.sql</code>, then <a href="inventory_import.php">import inventory</a>.</div>
    <?php elseif (count($availableDates) < 2): ?>
    <div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>Import inventory from at least 2 different weeks to compare. <a href="inventory_import.php">Import inventory</a></div>
    <?php else: ?>

    <div class="card mb-4">
        <div class="card-header"><i class="ti ti-filter me-2"></i>Select dates to compare</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">From date</label>
                    <select name="date_from" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach ($availableDates as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $dateFrom === $d ? 'selected' : '' ?>><?= date('d M Y', strtotime($d)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">To date</label>
                    <select name="date_to" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach ($availableDates as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $dateTo === $d ? 'selected' : '' ?>><?= date('d M Y', strtotime($d)) ?></option>
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
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Compare</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($dateFrom && $dateTo && $dateFrom !== $dateTo): ?>
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <div class="h5 text-muted">£<?= number_format($summary['from_value'], 0) ?></div>
                    <div class="small"><?= number_format($summary['from_units'], 0) ?> units</div>
                    <div class="small text-muted"><?= date('d M', strtotime($dateFrom)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <div class="h5 text-primary">£<?= number_format($summary['to_value'], 0) ?></div>
                    <div class="small"><?= number_format($summary['to_units'], 0) ?> units</div>
                    <div class="small text-muted"><?= date('d M', strtotime($dateTo)) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card">
                <div class="card-body text-center">
                    <div class="h5 <?= $summary['delta_value'] >= 0 ? 'delta-pos' : 'delta-neg' ?>"><?= $summary['delta_value'] >= 0 ? '+' : '' ?>£<?= number_format($summary['delta_value'], 0) ?></div>
                    <div class="small <?= $summary['delta_units'] >= 0 ? 'delta-pos' : 'delta-neg' ?>"><?= $summary['delta_units'] >= 0 ? '+' : '' ?><?= number_format($summary['delta_units'], 0) ?> units</div>
                    <div class="small text-muted">Net change</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="ti ti-table me-2"></i>Movement by SKU <small class="text-muted">(<?= count($movement) ?> with changes)</small></span>
            <div>
                <span class="me-2">Sort by:</span>
                <a href="?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&distributor=<?= urlencode($distributor) ?>&sort=delta_qty" class="btn btn-sm btn-outline-secondary <?= $sortBy === 'delta_qty' ? 'active' : '' ?>">Qty change</a>
                <a href="?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&distributor=<?= urlencode($distributor) ?>&sort=delta_value" class="btn btn-sm btn-outline-secondary <?= $sortBy === 'delta_value' ? 'active' : '' ?>">Value change</a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Distributor</th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th class="text-end">From qty</th>
                            <th class="text-end">To qty</th>
                            <th class="text-end">Δ Qty</th>
                            <th class="text-end">From value</th>
                            <th class="text-end">To value</th>
                            <th class="text-end">Δ Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($movement, 0, 200) as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['distributor']) ?></td>
                            <td><a href="product_detail.php?sku=<?= urlencode($m['sku']) ?>"><code><?= htmlspecialchars($m['sku']) ?></code></a></td>
                            <td class="text-truncate" style="max-width:180px" title="<?= htmlspecialchars($m['product_name'] ?? '') ?>"><?= htmlspecialchars($m['product_name'] ?? '—') ?></td>
                            <td class="text-end"><?= number_format($m['from_qty'], 0) ?></td>
                            <td class="text-end"><?= number_format($m['to_qty'], 0) ?></td>
                            <td class="text-end <?= $m['delta_qty'] >= 0 ? 'delta-pos' : 'delta-neg' ?>"><?= $m['delta_qty'] >= 0 ? '+' : '' ?><?= number_format($m['delta_qty'], 0) ?></td>
                            <td class="text-end">£<?= number_format($m['from_value'], 0) ?></td>
                            <td class="text-end">£<?= number_format($m['to_value'], 0) ?></td>
                            <td class="text-end <?= $m['delta_value'] >= 0 ? 'delta-pos' : 'delta-neg' ?>"><?= $m['delta_value'] >= 0 ? '+' : '' ?>£<?= number_format($m['delta_value'], 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($movement)): ?>
                        <tr><td colspan="9" class="text-muted text-center">No movement between these dates. Select different dates or distributor.</td></tr>
                        <?php elseif (count($movement) > 200): ?>
                        <tr><td colspan="9" class="text-muted text-center">Showing top 200 by <?= $sortBy === 'delta_value' ? 'value change' : 'quantity change' ?>. <?= count($movement) - 200 ?> more.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <div class="mt-3">
        <a href="inventory_report.php" class="btn btn-outline-secondary"><i class="ti ti-package me-1"></i> Current stock</a>
        <a href="inventory_trends.php" class="btn btn-outline-secondary ms-2"><i class="ti ti-chart-line me-1"></i> Trends</a>
    </div>

    <?php endif; ?>
</div>
</div></div>
</body>
</html>
