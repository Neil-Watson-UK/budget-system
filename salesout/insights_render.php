<?php
// Renders header, filters, executive strip, and Section 1 (Key metrics) for insights page
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;1,8..60,400&display=swap" rel="stylesheet">
<style>
/* Report-style layout inspired by structured research indices */
.insights-body { background: #f8f9fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.insights-body .report-serif { font-family: 'Source Serif 4', Georgia, serif; }

.dashboard-header {
  background: linear-gradient(135deg, #00353d 0%, #004d57 50%, #00a399 100%);
  color: white;
  padding: 2.5rem 0;
  margin: 0 -1.5rem 2rem -1.5rem;
  border-radius: 0;
  box-shadow: 0 2px 8px rgba(0,53,61,0.15);
}
.dashboard-header .report-title { font-size: 1.75rem; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 0.25rem; }
.dashboard-header .report-subtitle { font-size: 0.95rem; opacity: 0.9; font-weight: 400; }
.dashboard-header .report-meta { font-size: 0.8rem; opacity: 0.8; margin-top: 0.5rem; }

.filter-card { background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); margin-bottom: 2rem; overflow: hidden; }
.filter-card .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 1rem 1.5rem; font-weight: 600; font-size: 0.9rem; color: #0f172a; }
.filter-card .card-body { padding: 1.5rem; }

/* Section dividers - report chapter style */
.report-section { margin-bottom: 3rem; }
.report-section-title {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: #64748b;
  margin-bottom: 1rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid #e2e8f0;
}
.report-section-title .section-num { color: #00a399; margin-right: 0.5rem; }

/* Executive summary / key metrics strip */
.executive-strip {
  background: white;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 1.5rem 2rem;
  margin-bottom: 2rem;
  display: flex;
  flex-wrap: wrap;
  gap: 2rem 3rem;
  align-items: flex-start;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.executive-metric { min-width: 0; }
.executive-metric-value { font-size: 1.75rem; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; color: #0f172a; }
.executive-metric-label { font-size: 0.8rem; color: #64748b; margin-top: 0.25rem; font-weight: 500; }
.executive-metric-note { font-size: 0.75rem; color: #94a3b8; margin-top: 0.15rem; }

.kpi-card {
  text-align: center;
  padding: 1.5rem;
  border-radius: 8px;
  background: white;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
  height: 100%;
}
.kpi-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
.kpi-value { font-size: 1.75rem; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; margin-bottom: 0.35rem; }
.kpi-label { font-size: 0.8rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500; }

.chart-card {
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  margin-bottom: 1.5rem;
  background: white;
  overflow: hidden;
}
.chart-card .card-header {
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  padding: 1rem 1.25rem;
  font-weight: 600;
  font-size: 0.95rem;
  color: #0f172a;
}
.chart-card .card-body { padding: 1.25rem; }
.chart-container { position: relative; height: 300px; width: 100%; }
.speedometer-container { height: 220px; width: 100%; }

.btn-filter-reset { border: 1px dashed #cbd5e1; color: #64748b; background: transparent; }
.btn-filter-reset:hover { border-color: #00a399; color: #00a399; background: rgba(0,163,153,0.06); }

.target-combined {
  background: white;
  border-radius: 8px;
  padding: 1.5rem;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  margin-bottom: 1.5rem;
}
.target-combined-main { display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap; }
.target-combined-left { flex: 1; min-width: 0; }
.target-combined-right { flex: 0 0 25%; min-width: 140px; text-align: center; padding: 1rem; border-radius: 8px; }
.target-combined-value { font-size: 2rem; font-weight: 700; letter-spacing: -0.02em; line-height: 1.2; margin-bottom: 0.5rem; }
.target-combined-label { font-size: 0.8rem; color: #64748b; margin-bottom: 0.75rem; }
.target-combined-bar-wrap { position: relative; height: 12px; background: #e2e8f0; border-radius: 6px; overflow: visible; margin-bottom: 0.5rem; }
.target-combined-bar-fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 6px; transition: width 0.4s ease; z-index: 1; }
.target-combined-ref-line { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(0,0,0,0.4); z-index: 2; }
.target-combined-meta { font-size: 0.75rem; color: #94a3b8; display: flex; flex-wrap: wrap; gap: 0.5rem 1rem; align-items: center; }
.target-combined-pct { font-size: 1.5rem; font-weight: 700; }
.target-combined-pct-label { font-size: 0.75rem; color: #64748b; }

/* Report-style tables */
.insights-body .table { font-size: 0.9rem; }
.insights-body .table thead th { font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; padding: 0.75rem 1rem; border-color: #e2e8f0; background: #f8fafc; }
.insights-body .table tbody td { padding: 0.75rem 1rem; border-color: #e2e8f0; vertical-align: middle; }
.insights-body .table tbody tr:hover { background: #f8fafc; }
.insights-body .table .text-muted { font-family: 'Source Serif 4', Georgia, serif; font-style: italic; }

/* Data period badge */
.data-period-badge { font-size: 0.7rem; padding: 0.2rem 0.5rem; background: rgba(255,255,255,0.2); border-radius: 4px; }
</style>

<div class="container-xl py-4 insights-body">
    <div class="dashboard-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="report-title mb-0">Sales Out Insights &amp; Forecasting</h1>
                    <p class="report-subtitle mb-0">Category analysis, product families, trends &amp; forecasting</p>
                    <p class="report-meta mb-0">
                        <span class="data-period-badge"><?= htmlspecialchars($dateFrom) ?> â€“ <?= htmlspecialchars($dateTo) ?></span>
                        <span class="ms-2 opacity-75">Generated <?= date('j M Y, H:i') ?></span>
                        <?php if (!empty($distributor)): ?><span class="mx-2">â€¢</span><?= htmlspecialchars($distributor) ?><?php endif; ?>
                        <?php if (!empty($category)): ?><span class="mx-2">â€¢</span><?= htmlspecialchars($category) ?><?php endif; ?>
                        <?php if (!empty($productSeries)): ?><span class="mx-2">â€¢</span>Series: <?= htmlspecialchars($productSeries) ?><?php endif; ?>
                        <?php if (!empty($productName)): ?><span class="mx-2">â€¢</span><?= htmlspecialchars($productName) ?><?php endif; ?>
                        <?php if (!empty($productSku)): ?><span class="mx-2">â€¢</span><?= htmlspecialchars($productSku) ?><?php endif; ?>
                    </p>
                </div>
                <div class="col-md-4 text-md-end">
                    <button class="btn btn-outline-light btn-sm" onclick="window.print()"><i class="ti ti-printer me-1"></i> Export / Print</button>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-filter me-2"></i>Filters</div>
            <button type="button" class="btn btn-sm btn-filter-reset" onclick="window.location.href='insights.php'"><i class="ti ti-refresh me-1"></i> Reset</button>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Distributor</label>
                    <select name="distributor" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($distributors as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $distributor === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Product series</label>
                    <select name="product_series" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($seriesList ?? [] as $ps): ?>
                        <option value="<?= htmlspecialchars($ps) ?>" <?= $productSeries === $ps ? 'selected' : '' ?>><?= htmlspecialchars($ps) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Product name</label>
                    <input type="text" name="product_name" class="form-control" placeholder="Search…" value="<?= htmlspecialchars($productName) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Product / SKU code</label>
                    <input type="text" name="product_sku" class="form-control" placeholder="Search…" value="<?= htmlspecialchars($productSku) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Forecast months</label>
                    <select name="forecast_months" class="form-select">
                        <option value="0" <?= $forecastMonths === 0 ? 'selected' : '' ?>>Off</option>
                        <option value="1" <?= $forecastMonths === 1 ? 'selected' : '' ?>>1</option>
                        <option value="3" <?= $forecastMonths === 3 ? 'selected' : '' ?>>3</option>
                        <option value="6" <?= $forecastMonths === 6 ? 'selected' : '' ?>>6</option>
                        <option value="12" <?= $forecastMonths === 12 ? 'selected' : '' ?>>12</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Executive summary: key metrics at a glance -->
    <div class="executive-strip">
        <div class="executive-metric">
            <div class="executive-metric-value" style="color:#00a399"><?= number_format($totalRows) ?></div>
            <div class="executive-metric-label">Total orders</div>
            <div class="executive-metric-note">Orders in period</div>
        </div>
        <div class="executive-metric">
            <div class="executive-metric-value" style="color:#00353d">£<?= number_format($totalDistReported, 0) ?></div>
            <div class="executive-metric-label">Distributor reported</div>
            <div class="executive-metric-note">Value as reported by distributors</div>
        </div>
        <div class="executive-metric">
            <div class="executive-metric-value" style="color:#00a399">£<?= number_format($totalAtTrade, 0) ?></div>
            <div class="executive-metric-label">At trade price</div>
            <div class="executive-metric-note">Estimated value at trade</div>
        </div>
        <?php if ($inventorySummary !== null): ?>
        <div class="executive-metric">
            <div class="executive-metric-value" style="color:#00353d">£<?= number_format($inventorySummary['total_value'], 0) ?></div>
            <div class="executive-metric-label">Inventory (trade)</div>
            <div class="executive-metric-note"><a href="inventory_report.php<?= !empty($distributor) ? '?distributor=' . urlencode($distributor) : '' ?>" class="text-muted">View report</a></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($insightsTargetInfo)): ?>
        <div class="executive-metric">
            <div class="executive-metric-value" style="color:<?= $insightsTargetInfo['vs_time'] >= 100 ? '#00a399' : ($insightsTargetInfo['vs_time'] >= 80 ? '#f59e0b' : '#ff5549') ?>"><?= $insightsTargetInfo['vs_time'] ?>%</div>
            <div class="executive-metric-label">vs time (<?= htmlspecialchars($insightsTargetInfo['label']) ?>)</div>
            <div class="executive-metric-note">Target to date</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="report-section">
        <h2 class="report-section-title"><span class="section-num">1</span> Key metrics</h2>
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,163,153,0.12);"><i class="ti ti-list" style="font-size:1.5rem;color:#00a399"></i></div>
                <div class="kpi-value" style="color:#00a399"><?= number_format($totalRows) ?></div>
                <div class="kpi-label">Total Orders</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-cash" style="font-size:1.5rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d">£<?= number_format($totalDistReported, 0) ?></div>
                <div class="kpi-label">Dist. Reported</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,163,153,0.12);"><i class="ti ti-discount" style="font-size:1.5rem;color:#00a399"></i></div>
                <div class="kpi-value" style="color:#00a399">£<?= number_format($totalAtTrade, 0) ?></div>
                <div class="kpi-label">At Trade Price</div>
            </div>
        </div>
        <?php if ($inventorySummary !== null): ?>
        <div class="col-md-4">
            <a href="inventory_report.php<?= !empty($distributor) ? '?distributor=' . urlencode($distributor) : '' ?>" class="text-decoration-none">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-package" style="font-size:1.5rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d">£<?= number_format($inventorySummary['total_value'], 0) ?></div>
                <div class="kpi-label">Inventory (trade)</div>
            </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="inventory_report.php<?= !empty($distributor) ? '?distributor=' . urlencode($distributor) : '' ?>" class="text-decoration-none">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-box" style="font-size:1.5rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d"><?= number_format($inventorySummary['total_units'], 0) ?></div>
                <div class="kpi-label">Units on hand</div>
            </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="inventory_report.php<?= !empty($distributor) ? '?distributor=' . urlencode($distributor) : '' ?>" class="text-decoration-none">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-clock" style="font-size:1.5rem;color:#00353d"></i></div>
                <div class="kpi-value" style="color:#00353d"><?= $inventorySummary['avg_weeks'] !== null ? number_format($inventorySummary['avg_weeks'], 1) . 'w' : 'â€”' ?></div>
                <div class="kpi-label">Avg weeks of stock</div>
            </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if (!empty($insightsTargetInfo)): ?>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,163,153,0.12);"><i class="ti ti-target" style="font-size:1.5rem;color:#00a399"></i></div>
                <div class="kpi-value <?= $insightsTargetInfo['pct'] >= 100 ? 'text-success' : ($insightsTargetInfo['pct'] >= 80 ? 'text-warning' : 'text-danger') ?>" style="<?= $insightsTargetInfo['pct'] >= 100 ? 'color:#00a399' : ($insightsTargetInfo['pct'] >= 80 ? 'color:#f59e0b' : 'color:#ff5549') ?>"><?= $insightsTargetInfo['pct'] ?>%</div>
                <div class="kpi-label">Target (<?= htmlspecialchars($insightsTargetInfo['label']) ?>)</div>
                <small class="text-muted">£<?= number_format($insightsTargetInfo['actual'], 0) ?> / £<?= number_format($insightsTargetInfo['annual_target'], 0) ?></small>
            </div>
        </div>
        <?php if ($insightsTargetInfo['time_elapsed']['is_current_year'] ?? false): ?>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="metric-icon mx-auto mb-2 rounded d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:rgba(0,53,61,0.12);"><i class="ti ti-calendar" style="font-size:1.5rem;color:#00353d"></i></div>
                <div class="kpi-value <?= $insightsTargetInfo['vs_time'] >= 100 ? 'text-success' : ($insightsTargetInfo['vs_time'] >= 80 ? 'text-warning' : 'text-danger') ?>" style="<?= $insightsTargetInfo['vs_time'] >= 100 ? 'color:#00a399' : ($insightsTargetInfo['vs_time'] >= 80 ? 'color:#f59e0b' : 'color:#ff5549') ?>"><?= $insightsTargetInfo['vs_time'] ?>%</div>
                <div class="kpi-label">vs Time (<?= $insightsTargetInfo['time_elapsed']['pct'] ?>% elapsed)</div>
                <small class="text-muted">£<?= number_format($insightsTargetInfo['actual'], 0) ?> / £<?= number_format($insightsTargetInfo['target_to_date'], 0) ?> to date</small>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if (!empty($insightsTargetInfo) && ($insightsTargetInfo['time_elapsed']['is_current_year'] ?? false)): ?>
    <div class="target-combined mb-4">
        <div class="target-combined-main">
            <div class="target-combined-left">
                <div class="target-combined-label">Target Performance (<?= htmlspecialchars($insightsTargetInfo['label']) ?>, <?= $currentYear ?>)</div>
                <div class="target-combined-bar-wrap">
                    <div class="target-combined-bar-fill" style="width: <?= min(100, $insightsTargetInfo['vs_time']) ?>%; background: <?= $insightsTargetInfo['vs_time'] >= 100 ? '#00a399' : ($insightsTargetInfo['vs_time'] >= 80 ? '#f59e0b' : '#ff5549') ?>;"></div>
                    <div class="target-combined-ref-line" style="left: <?= $insightsTargetInfo['time_elapsed']['pct'] ?>%;"></div>
                </div>
                <div class="target-combined-meta">
                    <span>£<?= number_format($insightsTargetInfo['actual'], 0) ?> actual</span>
                    <span>£<?= number_format($insightsTargetInfo['target_to_date'], 0) ?> target to date</span>
                    <span><?= $insightsTargetInfo['time_elapsed']['pct'] ?>% of year elapsed</span>
                </div>
                <p class="mb-0 mt-2 small"><a href="targets.php">Edit targets</a></p>
            </div>
            <div class="target-combined-right">
                <div class="target-combined-pct" style="color: <?= $insightsTargetInfo['vs_time'] >= 100 ? '#00a399' : ($insightsTargetInfo['vs_time'] >= 80 ? '#f59e0b' : '#ff5549') ?>;"><?= $insightsTargetInfo['vs_time'] ?>%</div>
                <div class="target-combined-pct-label">vs Time</div>
            </div>
        </div>
    </div>
    <?php elseif ($dateFromYear === $currentYear): ?>
    <div class="alert alert-info mb-4"><i class="ti ti-info-circle me-2"></i><a href="targets.php">Set targets</a> for <?= $currentYear ?> to track performance vs time.</div>
    <?php endif; ?>
    </div><!-- /report-section 1 -->
