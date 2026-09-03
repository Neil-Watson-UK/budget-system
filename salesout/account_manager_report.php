<?php
// account_manager_report.php - Regional view: filter by Region and Account Manager, show relative performance
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/account_manager_report.php'));
    exit;
}

$pdo = getDBConnection();

$filterRegion = trim($_GET['region'] ?? '');
$filterOwner = trim($_GET['owner'] ?? '');

// Custom date range (defaults to last 5 years from start-of-month to today)
$rawFrom = trim($_GET['date_from'] ?? '');
$rawTo = trim($_GET['date_to'] ?? '');
$defaultFrom = date('Y-m-01', strtotime('-5 years'));
$defaultTo = date('Y-m-d');

$dateFrom = $defaultFrom;
$dateTo = $defaultTo;
if ($rawFrom !== '' && strtotime($rawFrom) !== false) {
    $dateFrom = date('Y-m-d', strtotime($rawFrom));
}
if ($rawTo !== '' && strtotime($rawTo) !== false) {
    $dateTo = date('Y-m-d', strtotime($rawTo));
}
// Ensure from <= to
if (strtotime($dateFrom) > strtotime($dateTo)) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$hasRegionColumn = false;
$hasOwnerColumn = false;
try {
    $hasRegionColumn = (bool) $pdo->query("SHOW COLUMNS FROM vendors LIKE 'region'")->fetch();
    $hasOwnerColumn = (bool) $pdo->query("SHOW COLUMNS FROM vendors LIKE 'Owner_Full_Name__c'")->fetch();
} catch (PDOException $e) { /* ignore */ }

$regions = [];
$accountManagersInRegion = [];
$managersSummary = [];
$selectedManagerDetail = null;
$topResellersRegion = [];
$opportunities = [];
$opportunitiesByVendor = [];
$opportunitiesError = null;
$dbError = null;

$valueKey = getSalesOutValueCompareKey(getSalesOutValueMode());
$valueTooltip = getSalesOutValueModeTooltip();

if ($hasRegionColumn && $hasOwnerColumn) {
    try {
        $regStmt = $pdo->prepare("
            SELECT DISTINCT v.region
            FROM vendors v
            INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
            WHERE v.region IS NOT NULL AND TRIM(v.region) != '' AND s.report_date >= ?
            ORDER BY v.region
        ");
        $regStmt->execute([$dateFrom]);
        $regions = $regStmt->fetchAll(PDO::FETCH_COLUMN);
        // Combine "United Kingdom" and "UKI" under UKI for dropdown
        $regions = array_values(array_unique(array_map('normalizeRegionForUKI', $regions)));
        sort($regions);

        if ($filterRegion !== '') {
            $regionNormalized = normalizeRegionForUKI($filterRegion);
            if ($regionNormalized === 'UKI') {
                $regionWhere = "v.region IN ('UKI', 'United Kingdom') AND s.report_date BETWEEN ? AND ?";
                $regionParams = [$dateFrom, $dateTo];
            } else {
                $regionWhere = "v.region = ? AND s.report_date BETWEEN ? AND ?";
                $regionParams = [$filterRegion, $dateFrom, $dateTo];
            }

            $ownerWhere = $regionWhere . " AND v.Owner_Full_Name__c IS NOT NULL AND TRIM(v.Owner_Full_Name__c) != ''";
            $ownersStmt = $pdo->prepare("
                SELECT DISTINCT TRIM(v.Owner_Full_Name__c) as owner_name
                FROM vendors v
                INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
                WHERE $ownerWhere
                ORDER BY owner_name
            ");
            $ownersStmt->execute($regionParams);
            $accountManagersInRegion = $ownersStmt->fetchAll(PDO::FETCH_COLUMN);

            $managersSql = "
                SELECT TRIM(v.Owner_Full_Name__c) as owner_name,
                    COUNT(DISTINCT v.id) as account_count,
                    COALESCE(SUM(s.total_value), 0) as dist_reported,
                    COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
                    COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
                FROM vendors v
                INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
                LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                WHERE $regionWhere
                GROUP BY TRIM(v.Owner_Full_Name__c)
                ORDER BY dist_reported DESC
            ";
            $managersStmt = $pdo->prepare($managersSql);
            $managersStmt->execute($regionParams);
            $managersSummary = $managersStmt->fetchAll(PDO::FETCH_ASSOC);

            $regionTotal = array_sum(array_map(function ($m) use ($valueKey) { return (float)($m[$valueKey] ?? 0); }, $managersSummary));

            if ($filterOwner !== '') {
                if ($regionNormalized === 'UKI') {
                    $detailWhere = "v.region IN ('UKI', 'United Kingdom') AND TRIM(COALESCE(v.Owner_Full_Name__c,'')) = ? AND s.report_date BETWEEN ? AND ?";
                    $detailParams = [$filterOwner, $dateFrom, $dateTo];
                } else {
                    $detailWhere = "v.region = ? AND TRIM(COALESCE(v.Owner_Full_Name__c,'')) = ? AND s.report_date BETWEEN ? AND ?";
                    $detailParams = [$filterRegion, $filterOwner, $dateFrom, $dateTo];
                }

                $accCountStmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT v.id) as c,
                        COALESCE(SUM(s.total_value), 0) as dist_reported,
                        COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade,
                        COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp
                    FROM vendors v
                    INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $detailWhere
                ");
                $accCountStmt->execute($detailParams);
                $row = $accCountStmt->fetch(PDO::FETCH_ASSOC);
                $selectedManagerDetail = [
                    'account_count' => (int)($row['c'] ?? 0),
                    'dist_reported' => (float)($row['dist_reported'] ?? 0),
                    'at_trade' => (float)($row['at_trade'] ?? 0),
                    'at_msrp' => (float)($row['at_msrp'] ?? 0),
                    'total_sales' => (float)($row[$valueKey] ?? 0),
                ];

                $catStmt = $pdo->prepare("
                    SELECT COALESCE(p.product_category, p.product_line, p.product_type, 'Uncategorised') as category,
                        COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s
                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $detailWhere
                    GROUP BY category ORDER BY total DESC
                ");
                $catStmt->execute($detailParams);
                $selectedManagerDetail['category_mix'] = $catStmt->fetchAll(PDO::FETCH_ASSOC);

                $prodStmt = $pdo->prepare("
                    SELECT COALESCE(p.product_line, p.product_type, 'Other') as product_line,
                        COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s
                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $detailWhere
                    GROUP BY product_line ORDER BY total DESC LIMIT 10
                ");
                $prodStmt->execute($detailParams);
                $selectedManagerDetail['product_mix'] = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

                $topResStmt = $pdo->prepare("
                    SELECT v.id as vendor_id, v.vendor_name, TRIM(COALESCE(v.Owner_Full_Name__c,'')) as owner_name, SUM(s.total_value) as total
                    FROM sales_out_raw s
                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    WHERE $detailWhere
                    GROUP BY v.id, v.vendor_name, owner_name
                    ORDER BY total DESC LIMIT 12
                ");
                $topResStmt->execute($detailParams);
                $selectedManagerDetail['top_resellers'] = $topResStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $topResellersRegion = $pdo->prepare("
                    SELECT v.id as vendor_id, v.vendor_name, TRIM(COALESCE(v.Owner_Full_Name__c,'')) as owner_name, SUM(s.total_value) as total
                    FROM sales_out_raw s
                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    WHERE $regionWhere
                    GROUP BY v.id, v.vendor_name, owner_name
                    ORDER BY total DESC LIMIT 12
                ");
                $topResellersRegion->execute($regionParams);
                $topResellersRegion = $topResellersRegion->fetchAll(PDO::FETCH_ASSOC);
            }

            // Opportunities (Salesforce or sheet) when enabled and an account manager is selected
            if (defined('SALESOUT_OPPORTUNITIES_ENABLED') && SALESOUT_OPPORTUNITIES_ENABLED && $filterOwner !== '') {
                $oppResult = fetchOpportunities();
                if ($oppResult['error'] === null) {
                    $opportunities = getOpportunitiesForAccountManager($oppResult['rows'], $filterOwner);
                    // Group by vendor for display
                    $opportunitiesByVendor = groupOpportunitiesByVendor($pdo, $opportunities);
                } else {
                    $opportunitiesError = $oppResult['error'];
                    $opportunitiesByVendor = [];
                }
            } else {
                $opportunitiesByVendor = [];
            }
        }
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

require_once __DIR__ . '/header.php';
if (ob_get_level()) { ob_flush(); }
flush();

if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}

if (!$hasRegionColumn || !$hasOwnerColumn) {
    echo '<div class="container-xl py-4"><div class="alert alert-warning">Account Manager report requires <code>region</code> and <code>Owner_Full_Name__c</code> on the vendors table. Add these columns and sync from Salesforce if needed.</div></div></div></div></body></html>';
    exit;
}
?>
<style>
.report-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; box-shadow: 0 4px 14px rgba(0, 163, 153, 0.25); }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; overflow: hidden; }
.filter-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
.kpi-card { text-align: center; padding: 1.25rem; border-radius: 10px; background: white; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); height: 100%; }
.kpi-value { font-size: 1.75rem; font-weight: 700; }
.kpi-label { font-size: 0.75rem; color: #666666; text-transform: uppercase; letter-spacing: 0.5px; }
.report-body { background: rgb(238, 239, 241); }
.chart-card { border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; color: #0f172a; }
th.sortable { user-select: none; }
th.sortable:hover { background-color: rgba(0, 163, 153, 0.1) !important; }
th.sortable i { opacity: 0.5; font-size: 0.85em; }
th.sortable:hover i { opacity: 1; }
</style>

<div class="container-xl py-4 report-body">
    <div class="report-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-users me-2"></i>Account Manager Report</h1>
            <p class="mb-0 opacity-75">Filter by Region and Account Manager to see relative performance: account count, sales, product and category mix, and top resellers with owner.</p>
        </div>
    </div>

    <div class="filter-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-filter me-2"></i>Filters</div>
            <a href="account_manager_report.php" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Region</label>
                    <select name="region" id="regionSelect" class="form-select">
                        <option value="">Select region</option>
                        <?php foreach ($regions as $r): ?>
                        <option value="<?= htmlspecialchars($r) ?>" <?= $filterRegion === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($filterRegion !== ''): ?>
                <div class="col-md-4">
                    <label class="form-label">Account Manager</label>
                    <select name="owner" class="form-select">
                        <option value="">All account managers (relative performance)</option>
                        <?php foreach ($accountManagersInRegion as $am): ?>
                        <option value="<?= htmlspecialchars($am) ?>" <?= $filterOwner === $am ? 'selected' : '' ?>><?= htmlspecialchars($am) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($filterRegion === ''): ?>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="ti ti-world" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Select a region to see account manager performance and top resellers.</p>
        </div>
    </div>
    <?php elseif (!empty($managersSummary)): ?>

    <?php if ($filterOwner === ''): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="ti ti-chart-bar me-2"></i>Relative performance by Account Manager - <?= htmlspecialchars($filterRegion) ?></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Account Manager</th>
                            <th class="text-end"># Accounts</th>
                            <th class="text-end" title="<?= htmlspecialchars($valueTooltip) ?>">Total sales (&pound;)</th>
                            <th class="text-end">% of region</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($managersSummary as $m):
                            $pct = $regionTotal > 0 ? round(100 * ($m[$valueKey] ?? 0) / $regionTotal, 1) : 0;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($m['owner_name']) ?></td>
                            <td class="text-end"><?= (int)$m['account_count'] ?></td>
                            <td class="text-end" title="<?= htmlspecialchars($valueTooltip) ?>">&pound;<?= number_format($m[$valueKey] ?? 0, 0) ?></td>
                            <td class="text-end"><?= $pct ?>%</td>
                            <td><a href="account_manager_report.php?region=<?= urlencode($filterRegion) ?>&owner=<?= urlencode($m['owner_name']) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-sm btn-outline-primary">View detail</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="ti ti-list me-2"></i>Top 12 resellers in region (with owner)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Reseller</th><th>Owner</th><th class="text-end" title="<?= htmlspecialchars($valueTooltip) ?>">Total (&pound;)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topResellersRegion as $r): ?>
                        <tr>
                            <td><a href="reseller_report.php?vendor_id=<?= urlencode($r['vendor_id'] ?? '') ?>"><?= htmlspecialchars($r['vendor_name']) ?></a></td>
                            <td><?= htmlspecialchars($r['owner_name'] ?? '') ?></td>
                            <td class="text-end">&pound;<?= number_format($r['total'], 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>

    <?php if ($selectedManagerDetail): ?>
    <p class="mb-3"><a href="account_manager_report.php?region=<?= urlencode($filterRegion) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Back to region view</a></p>
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-value text-primary"><?= (int)$selectedManagerDetail['account_count'] ?></div>
                <div class="kpi-label">Accounts managed</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-value text-info" title="<?= htmlspecialchars($valueTooltip) ?>">&pound;<?= number_format($selectedManagerDetail['total_sales'], 0) ?></div>
                <div class="kpi-label">Total sales</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="kpi-card">
                <div class="kpi-value"><?= htmlspecialchars($filterOwner) ?></div>
                <div class="kpi-label">Account Manager</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="chart-card">
                <div class="card-header">Category mix</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Category</th><th class="text-end" title="By Distributor reported">Total (&pound;)</th></tr></thead>
                            <tbody>
                                <?php foreach ($selectedManagerDetail['category_mix'] as $c): ?>
                                <tr><td><?= htmlspecialchars($c['category']) ?></td><td class="text-end">&pound;<?= number_format($c['total'], 0) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="chart-card">
                <div class="card-header">Product line mix</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Product line</th><th class="text-end" title="By Distributor reported">Total (&pound;)</th></tr></thead>
                            <tbody>
                                <?php foreach ($selectedManagerDetail['product_mix'] as $p): ?>
                                <tr><td><?= htmlspecialchars($p['product_line']) ?></td><td class="text-end">&pound;<?= number_format($p['total'], 0) ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><i class="ti ti-list me-2"></i>Top 12 resellers (with owner)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Reseller</th><th>Owner</th><th class="text-end" title="<?= htmlspecialchars($valueTooltip) ?>">Total (&pound;)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selectedManagerDetail['top_resellers'] as $r): ?>
                        <tr>
                            <td><a href="reseller_report.php?vendor_id=<?= urlencode($r['vendor_id'] ?? '') ?>"><?= htmlspecialchars($r['vendor_name']) ?></a></td>
                            <td><?= htmlspecialchars($r['owner_name'] ?? '') ?></td>
                            <td class="text-end">&pound;<?= number_format($r['total'], 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($opportunitiesError !== null || !empty($opportunitiesByVendor)): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="ti ti-briefcase me-2"></i>Opportunities by Reseller (EPOS Contact / Email match)</div>
            <a href="opportunity_checker.php?owner=<?= urlencode($filterOwner) ?>" class="btn btn-sm btn-outline-secondary">Test matching</a>
        </div>
        <?php if ($opportunitiesError): ?>
        <div class="card-body">
            <p class="text-muted mb-0"><?= htmlspecialchars($opportunitiesError) ?></p>
        </div>
        <?php elseif (empty($opportunitiesByVendor)): ?>
        <div class="card-body">
            <p class="text-muted mb-0">No opportunities matched for this account manager. <a href="opportunity_checker.php?owner=<?= urlencode($filterOwner) ?>">Check matching</a></p>
        </div>
        <?php else: ?>
        <div class="card-body p-0">
            <?php foreach ($opportunitiesByVendor as $vendorGroup): ?>
            <div class="border-bottom">
                <div class="p-3 bg-light">
                    <?php
                    $rawRef = trim($vendorGroup['opportunities'][0]['Opportunity_Referred_by__c'] ?? '');
                    $vendorLabel = $vendorGroup['vendor_name'] . ($rawRef !== '' ? ' (' . $rawRef . ')' : '');
                    ?>
                    <h6 class="mb-0">
                        <?php if ($vendorGroup['vendor_id'] !== null): ?>
                            <a href="reseller_report.php?vendor_id=<?= urlencode($vendorGroup['vendor_id']) ?>" class="text-decoration-none">
                                <?= htmlspecialchars($vendorLabel) ?>
                            </a>
                        <?php else: ?>
                            <?= htmlspecialchars($vendorLabel) ?> <span class="badge bg-warning text-dark">Unmatched</span>
                        <?php endif; ?>
                        <span class="badge bg-secondary ms-2"><?= count($vendorGroup['opportunities']) ?> opp<?= count($vendorGroup['opportunities']) !== 1 ? 's' : '' ?></span>
                    </h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 sortable-table">
                        <thead class="table-light">
                            <tr>
                                <th class="sortable" data-sort="contact">EPOS Contact <i class="ti ti-arrows-sort ms-1"></i></th>
                                <th class="sortable" data-sort="name">Opportunity Name <i class="ti ti-arrows-sort ms-1"></i></th>
                                <th class="sortable" data-sort="project">SPA/Deal ref <i class="ti ti-arrows-sort ms-1"></i></th>
                                <th class="sortable" data-sort="status">Status <i class="ti ti-arrows-sort ms-1"></i></th>
                                <th class="sortable" data-sort="stage">Stage Name <i class="ti ti-arrows-sort ms-1"></i></th>
                                <th class="sortable" data-sort="amount">Amount <i class="ti ti-arrows-sort ms-1"></i></th>
                                <th class="sortable" data-sort="age">Age (days) <i class="ti ti-arrows-sort ms-1"></i></th>
                                <th class="sortable" data-sort="owner">Opportunity Owner <i class="ti ti-arrows-sort ms-1"></i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vendorGroup['opportunities'] as $row):
                                $d = opportunityDisplayFields($row);
                                $sfUrl = getSalesforceOpportunityUrl($d['id']);
                            ?>
                            <tr data-contact="<?= htmlspecialchars(strtolower($d['epos_contact'])) ?>" 
                                data-name="<?= htmlspecialchars(strtolower($d['name'])) ?>" 
                                data-project="<?= htmlspecialchars(strtolower($d['project_no'])) ?>" 
                                data-status="<?= htmlspecialchars(strtolower($d['status'])) ?>" 
                                data-stage="<?= htmlspecialchars(strtolower($d['stage_name'])) ?>" 
                                data-amount="<?= $d['amount'] ?? 0 ?>" 
                                data-age="<?= $d['age_days'] ?? '' ?>" 
                                data-owner="<?= htmlspecialchars(strtolower($d['account_owner'])) ?>">
                                <td><?= htmlspecialchars($d['epos_contact']) ?></td>
                                <td>
                                    <?php if ($sfUrl): ?>
                                        <a href="<?= htmlspecialchars($sfUrl) ?>" target="_blank" class="text-decoration-none">
                                            <?= htmlspecialchars($d['name']) ?> <i class="ti ti-external-link small"></i>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($d['name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= htmlspecialchars($d['project_no']) ?></code></td>
                                <td><?= htmlspecialchars($d['status']) ?: '-' ?></td>
                                <td><?= htmlspecialchars($d['stage_name']) ?: '-' ?></td>
                                <td><?= $d['amount'] !== null ? '&pound;' . number_format($d['amount'], 2) : '-' ?></td>
                                <td><?= $d['age_days'] !== null ? number_format($d['age_days'], 0) : '-' ?></td>
                                <td><?= htmlspecialchars($d['account_owner']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <?php endif; ?>
    <?php endif; ?>
</div>
<script>
// Sortable table functionality for opportunities
(function() {
    function makeSortable(table) {
        if (!table) return;
        const headers = table.querySelectorAll('th.sortable');
        let currentSort = { col: null, dir: 'asc' };
        
        headers.forEach((th) => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function() {
                const col = this.dataset.sort;
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                // Toggle direction if same column
                if (currentSort.col === col) {
                    currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort.col = col;
                    currentSort.dir = 'asc';
                }
                
                // Update header icons
                headers.forEach(h => {
                    const icon = h.querySelector('i');
                    if (icon) {
                        icon.className = 'ti ti-arrows-sort ms-1';
                        if (h === th) {
                            icon.className = currentSort.dir === 'asc' ? 'ti ti-arrow-up ms-1' : 'ti ti-arrow-down ms-1';
                        }
                    }
                });
                
                // Sort rows
                rows.sort((a, b) => {
                    let aVal = a.dataset[col] || '';
                    let bVal = b.dataset[col] || '';
                    
                    // Numeric sort for amount
                    if (col === 'amount') {
                        aVal = parseFloat(aVal) || 0;
                        bVal = parseFloat(bVal) || 0;
                        return currentSort.dir === 'asc' ? aVal - bVal : bVal - aVal;
                    }
                    
                    // Numeric sort for age (days)
                    if (col === 'age') {
                        aVal = parseFloat(aVal) || 0;
                        bVal = parseFloat(bVal) || 0;
                        return currentSort.dir === 'asc' ? aVal - bVal : bVal - aVal;
                    }
                    
                    // String sort
                    if (aVal < bVal) return currentSort.dir === 'asc' ? -1 : 1;
                    if (aVal > bVal) return currentSort.dir === 'asc' ? 1 : -1;
                    return 0;
                });
                
                // Re-append sorted rows
                rows.forEach(row => tbody.appendChild(row));
            });
        });
    }
    
    document.querySelectorAll('.sortable-table').forEach(makeSortable);
})();
</script>
</div></div>
</body>
</html>
