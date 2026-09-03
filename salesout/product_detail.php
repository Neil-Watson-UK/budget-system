<?php
// product_detail.php - Product-centric performance view: trends, forecasting, distributors
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/product_detail.php?' . http_build_query($_GET)));
    exit;
}

$pdo = getDBConnection();

$sku = trim($_GET['sku'] ?? '');
if (!empty($sku)) {
    $sku = str_replace(' ', '', $sku);
}
$productId = (int)($_GET['product_id'] ?? 0);

$showSearchOnly = (empty($sku) && $productId === 0);

$productInfo = null;
$monthlyTrend = [];
$yearlyTrend = [];
$topDistributors = [];
$topResellers = [];
$forecast = null;
$growthMetrics = [];
$recentTopDeals = [];
$familyProducts = [];
$familyMonthlyTrend = [];
$dbError = null;

try {
    // Get product info from product master if available
    if (!empty($sku)) {
        // Normalize SKU for lookup (remove all spaces and trim)
        $normalizedSku = trim(str_replace(' ', '', $sku));
        if (!empty($normalizedSku)) {
            // Try product master first
            $prodStmt = $pdo->prepare("SELECT * FROM sales_out_products WHERE TRIM(REPLACE(sku,' ','')) = ? LIMIT 1");
            $prodStmt->execute([$normalizedSku]);
            $productInfo = $prodStmt->fetch(PDO::FETCH_ASSOC);
            
            // If not found in product master, try sales data
            if (!$productInfo) {
                $salesStmt = $pdo->prepare("
                    SELECT DISTINCT sku, product_name
                    FROM sales_out_raw
                    WHERE TRIM(REPLACE(sku,' ','')) = ?
                    LIMIT 1
                ");
                $salesStmt->execute([$normalizedSku]);
                $salesRow = $salesStmt->fetch(PDO::FETCH_ASSOC);
                if ($salesRow) {
                    $productInfo = ['sku' => $salesRow['sku'], 'product_name' => $salesRow['product_name']];
                    $sku = $salesRow['sku'];
                }
            }
        }
    } elseif ($productId > 0) {
        // Fallback to product_id lookup
        $salesStmt = $pdo->prepare("
            SELECT DISTINCT sku, product_name
            FROM sales_out_raw
            WHERE id = ?
            LIMIT 1
        ");
        $salesStmt->execute([$productId]);
        $salesRow = $salesStmt->fetch(PDO::FETCH_ASSOC);
        if ($salesRow) {
            $productInfo = ['sku' => $salesRow['sku'], 'product_name' => $salesRow['product_name']];
            $sku = $salesRow['sku'];
        }
    }
    
    if (!$productInfo) {
        $dbError = 'Product not found.';
    } else {
        $searchSku = $productInfo['sku'] ?? '';
        if (empty($searchSku)) {
            $dbError = 'Product SKU not found.';
        } else {
            // Monthly trend (last 24 months)
            $dateFrom = date('Y-m-01', strtotime('-24 months'));
            $monthStmt = $pdo->prepare("
                SELECT DATE_FORMAT(report_date, '%Y-%m') as month,
                    SUM(quantity) as total_qty,
                    SUM(total_value) as total_value,
                    COUNT(*) as row_count
                FROM sales_out_raw
                WHERE TRIM(REPLACE(sku,' ','')) = TRIM(REPLACE(?,' ',''))
                AND report_date >= ?
                GROUP BY month
                ORDER BY month
            ");
            $monthStmt->execute([$searchSku, $dateFrom]);
            $monthlyTrend = $monthStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Yearly trend - only from 2020 onwards (earliest valid data)
            $yearStmt = $pdo->prepare("
                SELECT YEAR(report_date) as year,
                    SUM(quantity) as total_qty,
                    SUM(total_value) as total_value,
                    COUNT(*) as row_count
                FROM sales_out_raw
                WHERE TRIM(REPLACE(sku,' ','')) = TRIM(REPLACE(?,' ',''))
                AND YEAR(report_date) >= 2020
                GROUP BY year
                ORDER BY year DESC
            ");
            $yearStmt->execute([$searchSku]);
            $yearlyTrend = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top distributors for this product
            $distStmt = $pdo->prepare("
                SELECT distributor_name,
                    SUM(quantity) as total_qty,
                    SUM(total_value) as total_value,
                    COUNT(*) as row_count
                FROM sales_out_raw
                WHERE TRIM(REPLACE(sku,' ','')) = TRIM(REPLACE(?,' ',''))
                GROUP BY distributor_name
                ORDER BY total_value DESC
                LIMIT 10
            ");
            $distStmt->execute([$searchSku]);
            $topDistributors = $distStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top resellers (matched vendors) for this product
            $resStmt = $pdo->prepare("
                SELECT v.vendor_name, v.id as vendor_id,
                    SUM(s.quantity) as total_qty,
                    SUM(s.total_value) as total_value,
                    COUNT(*) as row_count
                FROM sales_out_raw s
                INNER JOIN vendors v ON s.matched_vendor_id = v.id
                WHERE TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(?,' ',''))
                GROUP BY v.id, v.vendor_name
                ORDER BY total_value DESC
                LIMIT 10
            ");
            $resStmt->execute([$searchSku]);
            $topResellers = $resStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Growth metrics: compare recent 6 months vs prior 6 months
            $recentStart = date('Y-m-01', strtotime('-6 months'));
            $priorStart = date('Y-m-01', strtotime('-12 months'));
            $priorEnd = date('Y-m-t', strtotime('-7 months'));
            
            $growthStmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN report_date >= ? THEN total_value ELSE 0 END) as recent_value,
                    SUM(CASE WHEN report_date >= ? AND report_date <= ? THEN total_value ELSE 0 END) as prior_value,
                    SUM(CASE WHEN report_date >= ? THEN quantity ELSE 0 END) as recent_qty,
                    SUM(CASE WHEN report_date >= ? AND report_date <= ? THEN quantity ELSE 0 END) as prior_qty
                FROM sales_out_raw
                WHERE TRIM(REPLACE(sku,' ','')) = TRIM(REPLACE(?,' ',''))
            ");
            $growthStmt->execute([$recentStart, $priorStart, $priorEnd, $recentStart, $priorStart, $priorEnd, $searchSku]);
            $growthRow = $growthStmt->fetch(PDO::FETCH_ASSOC);
            $recentVal = (float)($growthRow['recent_value'] ?? 0);
            $priorVal = (float)($growthRow['prior_value'] ?? 0);
            $recentQty = (float)($growthRow['recent_qty'] ?? 0);
            $priorQty = (float)($growthRow['prior_qty'] ?? 0);
            
            $growthMetrics = [
                'value_growth_pct' => $priorVal > 0 ? round(100 * ($recentVal - $priorVal) / $priorVal, 1) : ($recentVal > 0 ? 100 : 0),
                'qty_growth_pct' => $priorQty > 0 ? round(100 * ($recentQty - $priorQty) / $priorQty, 1) : ($recentQty > 0 ? 100 : 0),
                'recent_value' => $recentVal,
                'prior_value' => $priorVal,
                'recent_qty' => $recentQty,
                'prior_qty' => $priorQty,
            ];
            
            // Forecast: next 6 months using same-month historical average
            if (count($monthlyTrend) >= 3) {
                $forecastMonths = 6;
                $histStmt = $pdo->prepare("
                    SELECT mo, AVG(month_val) as avg_val
                    FROM (
                        SELECT YEAR(report_date) as yr, MONTH(report_date) as mo, SUM(total_value) as month_val
                        FROM sales_out_raw
                        WHERE TRIM(REPLACE(sku,' ','')) = TRIM(REPLACE(?,' ',''))
                        AND report_date >= ?
                        GROUP BY yr, mo
                    ) sub
                    GROUP BY mo
                    ORDER BY mo
                ");
                $histStmt->execute([$searchSku, $dateFrom]);
                $histData = $histStmt->fetchAll(PDO::FETCH_ASSOC);
                $byMonth = [];
                foreach ($histData as $h) {
                    $byMonth[(int)$h['mo']] = (float)($h['avg_val'] ?? 0);
                }
                
                // Find last data month
                $lastStmt = $pdo->prepare("
                    SELECT YEAR(report_date) as yr, MONTH(report_date) as mo
                    FROM sales_out_raw
                    WHERE TRIM(REPLACE(sku,' ','')) = TRIM(REPLACE(?,' ',''))
                    ORDER BY report_date DESC
                    LIMIT 1
                ");
                $lastStmt->execute([$searchSku]);
                $lastRow = $lastStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($lastRow) {
                    $lastYr = (int)$lastRow['yr'];
                    $lastMo = (int)$lastRow['mo'];
                    $d = new DateTime($lastYr . '-' . sprintf('%02d', $lastMo) . '-01');
                    $d->modify('+1 month');
                    $projections = [];
                    for ($i = 0; $i < $forecastMonths; $i++) {
                        $mo = (int)$d->format('n');
                        $avg = $byMonth[$mo] ?? (array_sum($byMonth) > 0 ? array_sum($byMonth) / count($byMonth) : 0);
                        $projections[] = [
                            'month' => $d->format('Y-m'),
                            'label' => $d->format('M Y'),
                            'value' => round($avg, 2),
                        ];
                        $d->modify('+1 month');
                    }
                    if (!empty($projections)) {
                        $forecast = [
                            'projections' => $projections,
                            'last_data' => $lastYr . '-' . sprintf('%02d', $lastMo),
                        ];
                    }
                }
            }
            
            // Recent top deals: largest 20 deals in past 6 months
            $dealsDateFrom = date('Y-m-01', strtotime('-6 months'));
            $dealsStmt = $pdo->prepare("
                SELECT 
                    s.id,
                    s.report_date,
                    s.distributor_name,
                    s.reseller_name,
                    s.matched_vendor_id,
                    v.vendor_name as matched_reseller_name,
                    s.quantity,
                    s.unit_price,
                    s.total_value,
                    s.currency
                FROM sales_out_raw s
                LEFT JOIN vendors v ON s.matched_vendor_id = v.id
                WHERE TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(?,' ',''))
                AND s.report_date >= ?
                ORDER BY s.total_value DESC
                LIMIT 20
            ");
            $dealsStmt->execute([$searchSku, $dealsDateFrom]);
            $recentTopDeals = $dealsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Family comparison: get products in same product series/family
            // Try product_series first, fallback to product_line if series not available
            $productSeries = $productInfo['product_series'] ?? null;
            $productLine = $productInfo['product_line'] ?? null;
            $useSeries = !empty($productSeries);
            $familyValue = $useSeries ? $productSeries : $productLine;
            
            if (!empty($familyValue)) {
                // Get all products in the same family with their total sales
                if ($useSeries) {
                    $familyStmt = $pdo->prepare("
                        SELECT DISTINCT
                            p.sku,
                            p.product_name,
                            COALESCE(SUM(s.total_value), 0) as total_value,
                            COALESCE(SUM(s.quantity), 0) as total_qty
                        FROM sales_out_products p
                        LEFT JOIN sales_out_raw s ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                        WHERE p.product_series = ?
                        AND p.sku IS NOT NULL
                        AND p.sku != ''
                        GROUP BY p.sku, p.product_name
                        ORDER BY total_value DESC
                    ");
                } else {
                    $familyStmt = $pdo->prepare("
                        SELECT DISTINCT
                            p.sku,
                            p.product_name,
                            COALESCE(SUM(s.total_value), 0) as total_value,
                            COALESCE(SUM(s.quantity), 0) as total_qty
                        FROM sales_out_products p
                        LEFT JOIN sales_out_raw s ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                        WHERE p.product_line = ?
                        AND p.sku IS NOT NULL
                        AND p.sku != ''
                        GROUP BY p.sku, p.product_name
                        ORDER BY total_value DESC
                    ");
                }
                $familyStmt->execute([$familyValue]);
                $familyProducts = $familyStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Ensure current product is included even if it has no sales
                $currentSkuNormalized = trim(str_replace(' ', '', $searchSku));
                $currentInResults = false;
                foreach ($familyProducts as $fp) {
                    if (trim(str_replace(' ', '', $fp['sku'])) === $currentSkuNormalized) {
                        $currentInResults = true;
                        break;
                    }
                }
                
                // If current product not in results, add it
                if (!$currentInResults && !empty($productInfo['sku'])) {
                    $familyProducts[] = [
                        'sku' => $productInfo['sku'],
                        'product_name' => $productInfo['product_name'] ?? '',
                        'total_value' => array_sum(array_column($yearlyTrend, 'total_value')),
                        'total_qty' => array_sum(array_column($yearlyTrend, 'total_qty')),
                    ];
                    // Re-sort by total_value
                    usort($familyProducts, function($a, $b) {
                        return ($b['total_value'] ?? 0) <=> ($a['total_value'] ?? 0);
                    });
                }
                
                // Find current product's position
                $currentPosition = 0;
                foreach ($familyProducts as $idx => $fp) {
                    if (trim(str_replace(' ', '', $fp['sku'])) === $currentSkuNormalized) {
                        $currentPosition = $idx + 1;
                        break;
                    }
                }
                
                // Get monthly trend for entire family (sum of all products in family)
                $familyDateFrom = date('Y-m-01', strtotime('-24 months'));
                if ($useSeries) {
                    $familyMonthStmt = $pdo->prepare("
                        SELECT DATE_FORMAT(s.report_date, '%Y-%m') as month,
                            SUM(s.total_value) as total_value
                        FROM sales_out_raw s
                        INNER JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                        WHERE p.product_series = ?
                        AND s.report_date >= ?
                        GROUP BY month
                        ORDER BY month
                    ");
                } else {
                    $familyMonthStmt = $pdo->prepare("
                        SELECT DATE_FORMAT(s.report_date, '%Y-%m') as month,
                            SUM(s.total_value) as total_value
                        FROM sales_out_raw s
                        INNER JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                        WHERE p.product_line = ?
                        AND s.report_date >= ?
                        GROUP BY month
                        ORDER BY month
                    ");
                }
                $familyMonthStmt->execute([$familyValue, $familyDateFrom]);
                $familyMonthlyTrend = $familyMonthStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#cccccc', '#f59e0b'];

require_once __DIR__ . '/header.php';
if ($dbError) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}
?>
<style>
.product-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0, 163, 153, 0.25); }
.kpi-card { text-align: center; padding: 1.25rem; border-radius: 10px; background: white; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); height: 100%; }
.kpi-value { font-size: 1.75rem; font-weight: 700; }
.kpi-label { font-size: 0.75rem; color: #666666; text-transform: uppercase; letter-spacing: 0.5px; }
.chart-card { border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.chart-container { height: 320px; }
.prod-thumb { width: 64px; height: 64px; object-fit: contain; border-radius: 8px; background: #f1f5f9; }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
#productSearchResults { background: white; border: 1px solid #D7D2CB; border-radius: 8px; }
#productSearchResults .list-group-item { background: white; border-color: #e9ecef; }
#productSearchResults .list-group-item:hover { background: rgb(238, 239, 241); }
</style>

<div class="container-xl py-4">
    <!-- Product search: autocomplete by name -->
    <div class="filter-card">
        <h5 class="mb-3"><i class="ti ti-search me-2"></i>Find Product</h5>
        <div class="row g-2 align-items-end">
            <div class="col-md-8 position-relative">
                <label class="form-label small text-muted">Product name or SKU</label>
                <input type="text" id="productSearch" class="form-control" placeholder="Type to search..." autocomplete="off"
                       value="<?= !empty($productInfo) ? htmlspecialchars($productInfo['product_name'] ?? $sku) : '' ?>">
                <div id="productSearchResults" class="list-group position-absolute start-0 end-0 mt-1 shadow" style="z-index: 1050; display: none; max-height: 280px; overflow-y: auto;"></div>
            </div>
        </div>
    </div>

    <?php if ($showSearchOnly): ?>
    <div class="alert alert-info">
        <i class="ti ti-info-circle me-2"></i>Type a product name or SKU above to view its performance, or <a href="insights.php">browse products on Insights</a>.
    </div>
    <?php else: ?>
    <div class="product-header">
        <div class="d-flex align-items-start gap-3">
            <?php if (!empty($productInfo['image_thumb'])): ?>
            <img src="<?= htmlspecialchars($productInfo['image_thumb']) ?>" alt="" class="prod-thumb flex-shrink-0" loading="lazy">
            <?php endif; ?>
            <div class="flex-grow-1">
                <h1 class="h2 mb-1"><?= htmlspecialchars($productInfo['product_name'] ?? $productInfo['sku'] ?? 'Product') ?></h1>
                <div class="d-flex flex-wrap gap-3 align-items-center mt-2">
                    <code class="bg-white bg-opacity-20 px-2 py-1 rounded"><?= htmlspecialchars($productInfo['sku'] ?? '') ?></code>
                    <?php if (!empty($productInfo['product_category'])): ?><span class="badge bg-light bg-opacity-20"><?= htmlspecialchars($productInfo['product_category']) ?></span><?php endif; ?>
                    <?php if (!empty($productInfo['product_line'])): ?><span class="badge bg-light bg-opacity-20"><?= htmlspecialchars($productInfo['product_line']) ?></span><?php endif; ?>
                    <?php if (!empty($productInfo['product_series'])): ?><span class="badge bg-light bg-opacity-20"><?= htmlspecialchars($productInfo['product_series']) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value" style="color:#00a399">£<?= number_format(array_sum(array_column($yearlyTrend, 'total_value')), 0) ?></div>
                <div class="kpi-label">Total Sales (all time)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value" style="color: <?= ($growthMetrics['value_growth_pct'] ?? 0) >= 0 ? '#00a399' : '#ff5549' ?>">
                    <?= ($growthMetrics['value_growth_pct'] ?? 0) >= 0 ? '+' : '' ?><?= $growthMetrics['value_growth_pct'] ?? 0 ?>%
                </div>
                <div class="kpi-label">Value Growth (6mo vs prior)</div>
                <small class="text-muted">£<?= number_format($growthMetrics['recent_value'] ?? 0, 0) ?> vs £<?= number_format($growthMetrics['prior_value'] ?? 0, 0) ?></small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value" style="color: <?= ($growthMetrics['qty_growth_pct'] ?? 0) >= 0 ? '#00a399' : '#ff5549' ?>">
                    <?= ($growthMetrics['qty_growth_pct'] ?? 0) >= 0 ? '+' : '' ?><?= $growthMetrics['qty_growth_pct'] ?? 0 ?>%
                </div>
                <div class="kpi-label">Quantity Growth (6mo vs prior)</div>
                <small class="text-muted"><?= number_format($growthMetrics['recent_qty'] ?? 0, 0) ?> vs <?= number_format($growthMetrics['prior_qty'] ?? 0, 0) ?> units</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value" style="color:#00353d"><?= count($yearlyTrend) ?></div>
                <div class="kpi-label">Years of data</div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><i class="ti ti-chart-line me-2"></i>Monthly Sales Trend (24 months)</div>
                    <?php if (!empty($familyProducts) && count($familyProducts) > 0): ?>
                    <button type="button" id="toggleFamily" class="btn btn-sm btn-outline-secondary">
                        <i class="ti ti-users me-1"></i> Show Family
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
            <?php if (!empty($familyProducts) && count($familyProducts) > 0): ?>
            <div class="chart-card mt-3" id="familyTableCard" style="display: none;">
                <div class="card-header"><i class="ti ti-table me-2"></i>Products in <?= htmlspecialchars($productSeries ?? $productLine ?? 'Family') ?> (by Total Sales)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>SKU</th>
                                    <th>Product Name</th>
                                    <th class="text-end">Total Sales</th>
                                    <th class="text-end">Quantity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $currentSkuNormalized = trim(str_replace(' ', '', $searchSku));
                                foreach ($familyProducts as $idx => $fp): 
                                    $fpSkuNormalized = trim(str_replace(' ', '', $fp['sku']));
                                    $isCurrent = ($fpSkuNormalized === $currentSkuNormalized);
                                ?>
                                <tr class="<?= $isCurrent ? 'table-primary' : '' ?>">
                                    <td><strong><?= $idx + 1 ?></strong></td>
                                    <td>
                                        <?php if ($isCurrent): ?>
                                            <code class="bg-primary bg-opacity-10 px-1 rounded"><?= htmlspecialchars($fp['sku']) ?></code>
                                        <?php else: ?>
                                            <a href="product_detail.php?sku=<?= urlencode($fp['sku']) ?>">
                                                <code><?= htmlspecialchars($fp['sku']) ?></code>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isCurrent): ?>
                                            <strong><?= htmlspecialchars($fp['product_name'] ?? '') ?></strong>
                                        <?php else: ?>
                                            <a href="product_detail.php?sku=<?= urlencode($fp['sku']) ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($fp['product_name'] ?? '') ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">£<?= number_format($fp['total_value'] ?? 0, 0) ?></td>
                                    <td class="text-end"><?= number_format($fp['total_qty'] ?? 0, 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-chart-bar me-2"></i>Yearly Totals</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="yearlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($forecast && count($forecast['projections']) > 0): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-trending-up me-2"></i>6-Month Forecast</div>
        <div class="card-body">
            <div class="chart-container">
                <canvas id="forecastChart"></canvas>
            </div>
            <p class="text-muted small mt-2 mb-0">Projects from <?= htmlspecialchars($forecast['last_data'] ?? 'last data') ?> using same-month historical average.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>Top Distributors</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="table-light"><tr><th>Distributor</th><th>Qty</th><th>Value</th></tr></thead>
                            <tbody>
                                <?php foreach ($topDistributors as $d): ?>
                                <tr>
                                    <td><?= htmlspecialchars($d['distributor_name']) ?></td>
                                    <td><?= number_format($d['total_qty'], 0) ?></td>
                                    <td>£<?= number_format($d['total_value'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topDistributors)): ?>
                                <tr><td colspan="3" class="text-muted">No distributor data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header"><i class="ti ti-table me-2"></i>Top Resellers (Matched)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="table-light"><tr><th>Reseller</th><th>Qty</th><th>Value</th></tr></thead>
                            <tbody>
                                <?php foreach ($topResellers as $r): ?>
                                <tr>
                                    <td><a href="reseller_report.php?vendor_id=<?= (int)$r['vendor_id'] ?>"><?= htmlspecialchars($r['vendor_name']) ?></a></td>
                                    <td><?= number_format($r['total_qty'], 0) ?></td>
                                    <td>£<?= number_format($r['total_value'], 0) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topResellers)): ?>
                                <tr><td colspan="3" class="text-muted">No matched reseller data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($recentTopDeals)): ?>
    <div class="chart-card">
        <div class="card-header"><i class="ti ti-receipt me-2"></i>Recent Top Deals (Past 6 Months)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Distributor</th>
                            <th>Reseller</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <th>Currency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTopDeals as $deal): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d M Y', strtotime($deal['report_date']))) ?></td>
                            <td><?= htmlspecialchars($deal['distributor_name']) ?></td>
                            <td>
                                <?php if (!empty($deal['matched_reseller_name'])): ?>
                                    <a href="reseller_report.php?vendor_id=<?= (int)($deal['matched_vendor_id'] ?? 0) ?>">
                                        <?= htmlspecialchars($deal['matched_reseller_name']) ?>
                                    </a>
                                    <?php if ($deal['reseller_name'] !== $deal['matched_reseller_name']): ?>
                                        <br><small class="text-muted">(<?= htmlspecialchars($deal['reseller_name']) ?>)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($deal['reseller_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($deal['quantity'], 0) ?></td>
                            <td>
                                <?php if ($deal['unit_price'] > 0): ?>
                                    <?= htmlspecialchars($deal['currency'] ?? 'EUR') ?> <?= number_format($deal['unit_price'], 2) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($deal['currency'] ?? 'EUR') ?> <?= number_format($deal['total_value'], 2) ?></strong></td>
                            <td><?= htmlspecialchars($deal['currency'] ?? 'EUR') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; // End showSearchOnly check ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fmt = v => '£' + parseFloat(v).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    
    <?php if (!empty($monthlyTrend)): ?>
    const monthlyCtx = document.getElementById('monthlyChart');
    let monthlyChart = null;
    if (monthlyCtx) {
        const monthlyData = <?= json_encode($monthlyTrend) ?>;
        const familyMonthlyData = <?= json_encode($familyMonthlyTrend ?? []) ?>;
        const labels = monthlyData.map(d => {
            const [y, m] = d.month.split('-');
            return new Date(y, m-1).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' });
        });
        
        const datasets = [{
            label: 'This Product',
            data: monthlyData.map(d => d.total_value),
            borderColor: '#00a399',
            backgroundColor: 'rgba(0, 163, 153, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.3
        }];
        
        monthlyChart = new Chart(monthlyCtx.getContext('2d'), {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => fmt(c.raw) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => fmt(v) } }
                }
            }
        });
        
        // Toggle family comparison
        <?php if (!empty($familyProducts) && count($familyProducts) > 0 && !empty($familyMonthlyTrend)): ?>
        const toggleBtn = document.getElementById('toggleFamily');
        const familyTable = document.getElementById('familyTableCard');
        let showFamily = false;
        
        if (toggleBtn && familyTable) {
            // Create family data array aligned with monthly labels
            const familyDataMap = {};
            familyMonthlyData.forEach(d => {
                const [y, m] = d.month.split('-');
                const key = y + '-' + m;
                familyDataMap[key] = d.total_value;
            });
            
            const familyDataArray = monthlyData.map(d => familyDataMap[d.month] || 0);
            
            toggleBtn.addEventListener('click', function() {
                showFamily = !showFamily;
                toggleBtn.innerHTML = showFamily 
                    ? '<i class="ti ti-eye-off me-1"></i> Hide Family'
                    : '<i class="ti ti-users me-1"></i> Show Family';
                
                if (showFamily) {
                    monthlyChart.data.datasets.push({
                        label: 'Family Total',
                        data: familyDataArray,
                        borderColor: 'rgba(0, 53, 61, 0.6)',
                        backgroundColor: 'rgba(0, 53, 61, 0.1)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        fill: true,
                        tension: 0.3
                    });
                    familyTable.style.display = 'block';
                } else {
                    monthlyChart.data.datasets = [monthlyChart.data.datasets[0]];
                    familyTable.style.display = 'none';
                }
                monthlyChart.update();
            });
        }
        <?php endif; ?>
    }
    <?php endif; ?>
    
    <?php if (!empty($yearlyTrend)): ?>
    const yearlyCtx = document.getElementById('yearlyChart');
    if (yearlyCtx) {
        const yearlyData = <?= json_encode(array_reverse($yearlyTrend)) ?>;
        new Chart(yearlyCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: yearlyData.map(d => '' + d.year),
                datasets: [{
                    label: 'Annual Sales',
                    data: yearlyData.map(d => d.total_value),
                    backgroundColor: '#00353d',
                    borderColor: '#00353d',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => fmt(c.raw) } }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { callback: v => fmt(v) } }
                }
            }
        });
    }
    <?php endif; ?>
    
    <?php if ($forecast && count($forecast['projections']) > 0): ?>
    const fcCtx = document.getElementById('forecastChart');
    if (fcCtx) {
        const proj = <?= json_encode($forecast['projections']) ?>;
        new Chart(fcCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: proj.map(p => p.label),
                datasets: [{
                    label: 'Forecast',
                    data: proj.map(p => p.value),
                    backgroundColor: 'rgba(0, 163, 153, 0.7)',
                    borderColor: '#00a399',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => fmt(c.raw) } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: v => fmt(v) } }
                }
            }
        });
    }
    <?php endif; ?>
});

// Product autocomplete
(function() {
    const input = document.getElementById('productSearch');
    const resultsEl = document.getElementById('productSearchResults');
    if (!input || !resultsEl) return;
    let debounceTimer = null;
    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = input.value.trim();
        if (q.length < 2) {
            resultsEl.style.display = 'none';
            resultsEl.innerHTML = '';
            return;
        }
        debounceTimer = setTimeout(function() {
            fetch('product_search.php?q=' + encodeURIComponent(q))
                .then(function(r) {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(function(data) {
                    resultsEl.innerHTML = '';
                    if (data.error) {
                        resultsEl.innerHTML = '<div class="list-group-item text-danger">Error: ' + (data.error || 'Unknown error') + '</div>';
                    } else if (!data || data.length === 0) {
                        resultsEl.innerHTML = '<div class="list-group-item text-muted">No products found</div>';
                    } else {
                        data.forEach(function(item) {
                            if (!item.sku) return;
                            const a = document.createElement('a');
                            a.href = 'product_detail.php?sku=' + encodeURIComponent(item.sku);
                            a.className = 'list-group-item list-group-item-action';
                            a.textContent = item.label + (item.sku && item.sku !== item.label ? ' (' + item.sku + ')' : '');
                            resultsEl.appendChild(a);
                        });
                    }
                    resultsEl.style.display = 'block';
                })
                .catch(function(err) {
                    console.error('Product search error:', err);
                    resultsEl.innerHTML = '<div class="list-group-item text-danger">Search failed: ' + (err.message || 'Unknown error') + '</div>';
                    resultsEl.style.display = 'block';
                });
        }, 200);
    });
    input.addEventListener('blur', function() {
        setTimeout(function() { resultsEl.style.display = 'none'; }, 150);
    });
    input.addEventListener('focus', function() {
        if (resultsEl.innerHTML) resultsEl.style.display = 'block';
    });
})();
</script>
</div></div>
</body>
</html>
