<?php
// opportunity_checker.php - Test page: fetch opportunities from Google Sheet, test matching by vendor and account manager
session_start();
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/opportunity_checker.php'));
    exit;
}

$pdo = getDBConnection();

if (!defined('SALESOUT_OPPORTUNITIES_ENABLED') || SALESOUT_OPPORTUNITIES_ENABLED !== true) {
    require_once __DIR__ . '/header.php';
    echo '<div class="container-xl py-4"><div class="alert alert-info">Opportunities are currently disabled in configuration.</div></div></div></div></body></html>';
    exit;
}

$testVendorId = trim($_GET['vendor_id'] ?? '');
$testOwner = trim($_GET['owner'] ?? '');

$result = fetchOpportunities();
$oppRows = $result['rows'];
$headers = $result['headers'];
$fetchError = $result['error'];

$matchedByVendor = [];
$matchedByOwner = [];
$vendorsWithSales = [];
$accountOwners = [];

if ($fetchError === null && !empty($oppRows)) {
    if ($testVendorId !== '') {
        $matchedByVendor = getOpportunitiesForVendor($oppRows, $pdo, (int) $testVendorId);
    }
    if ($testOwner !== '') {
        $matchedByOwner = getOpportunitiesForAccountManager($oppRows, $testOwner);
    }
}

// Vendor dropdown: vendors that have sales (same as reseller report)
try {
    $dateFrom = date('Y-m-01', strtotime('-5 years'));
    $stmt = $pdo->prepare("
        SELECT v.id, v.vendor_name, v.salesforce_id
        FROM vendors v
        INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
        WHERE s.report_date >= ?
        GROUP BY v.id, v.vendor_name, v.salesforce_id
        ORDER BY v.vendor_name
    ");
    $stmt->execute([$dateFrom]);
    $vendorsWithSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignore */ }

// Account manager dropdown: distinct Owner_Full_Name__c from vendors
$hasOwnerColumn = false;
try {
    $hasOwnerColumn = (bool) $pdo->query("SHOW COLUMNS FROM vendors LIKE 'Owner_Full_Name__c'")->fetch();
} catch (PDOException $e) { /* ignore */ }
if ($hasOwnerColumn) {
    $ownersStmt = $pdo->query("
        SELECT DISTINCT TRIM(Owner_Full_Name__c) as owner_name
        FROM vendors
        WHERE Owner_Full_Name__c IS NOT NULL AND TRIM(Owner_Full_Name__c) != ''
        ORDER BY owner_name
    ");
    $accountOwners = $ownersStmt->fetchAll(PDO::FETCH_COLUMN);
}

require_once __DIR__ . '/header.php';
if (ob_get_level()) { ob_flush(); }
flush();
?>
<style>
.report-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; margin-bottom: 1.5rem; }
.filter-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; }
.chart-card { border-radius: 10px; border: 1px solid #D7D2CB; margin-bottom: 1.5rem; background: white; }
.chart-card .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; }
.report-body { background: rgb(238, 239, 241); }
th.sortable { user-select: none; }
th.sortable:hover { background-color: rgba(0, 163, 153, 0.1) !important; }
th.sortable i { opacity: 0.5; font-size: 0.85em; }
th.sortable:hover i { opacity: 1; }
</style>

<div class="container-xl py-4 report-body">
    <div class="report-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-briefcase me-2"></i>Opportunity Checker</h1>
            <p class="mb-0 opacity-75">Test live pull from Google Sheet and matching by Vendor (Opportunity_Referred_by__c) and Account Manager (EPOS_Contact__c / EPOS_Email__c).</p>
        </div>
    </div>

    <?php if ($fetchError): ?>
    <div class="alert alert-warning">
        <i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($fetchError) ?>
        <p class="mb-0 mt-2 small">Sheet URL: <code><?= htmlspecialchars(getOpportunitiesSheetUrl()) ?></code></p>
    </div>
    <?php endif; ?>

    <div class="filter-card">
        <div class="card-header">Test matching</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">By Vendor (Opportunity_Referred_by__c)</label>
                    <select name="vendor_id" class="form-select">
                        <option value="">— Select vendor —</option>
                        <?php foreach ($vendorsWithSales as $v): ?>
                        <option value="<?= (int)$v['id'] ?>" <?= $testVendorId === (string)$v['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['vendor_name']) ?> (ID: <?= (int)$v['id'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">By Account Manager (EPOS_Contact__c / EPOS_Email__c)</label>
                    <select name="owner" class="form-select">
                        <option value="">— Select account manager —</option>
                        <?php foreach ($accountOwners as $am): ?>
                        <option value="<?= htmlspecialchars($am) ?>" <?= $testOwner === $am ? 'selected' : '' ?>><?= htmlspecialchars($am) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($fetchError === null): ?>
    <div class="chart-card">
        <div class="card-header">Sheet summary</div>
        <div class="card-body">
            <p class="mb-2"><strong>Columns (<?= count($headers) ?>):</strong> <code><?= htmlspecialchars(implode('</code>, <code>', $headers)) ?></code></p>
            <p class="mb-0 text-muted">Total rows: <?= count($oppRows) ?></p>
        </div>
    </div>

    <?php if ($testVendorId !== ''): ?>
    <div class="chart-card">
        <div class="card-header">Matched by Vendor (<?= count($matchedByVendor) ?> opportunities)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 sortable-table">
                    <thead class="table-light">
                        <tr>
                            <th class="sortable" data-sort="vendor">Vendor (Referred by) <i class="ti ti-arrows-sort ms-1"></i></th>
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
                        <?php foreach ($matchedByVendor as $row):
                            $d = opportunityDisplayFields($row);
                            $sfUrl = getSalesforceOpportunityUrl($d['id']);
                            $vendorDisplay = getVendorReferredByDisplay($pdo, $d['vendor_name']);
                        ?>
                        <tr data-vendor="<?= htmlspecialchars(strtolower($vendorDisplay['display'])) ?>" 
                            data-contact="<?= htmlspecialchars(strtolower($d['epos_contact'])) ?>" 
                            data-name="<?= htmlspecialchars(strtolower($d['name'])) ?>" 
                            data-project="<?= htmlspecialchars(strtolower($d['project_no'])) ?>" 
                            data-status="<?= htmlspecialchars(strtolower($d['status'])) ?>" 
                            data-stage="<?= htmlspecialchars(strtolower($d['stage_name'])) ?>" 
                            data-amount="<?= $d['amount'] ?? 0 ?>" 
                            data-age="<?= $d['age_days'] ?? '' ?>" 
                            data-owner="<?= htmlspecialchars(strtolower($d['account_owner'])) ?>">
                            <td>
                                <?php if ($vendorDisplay['vendor_id'] !== null): ?>
                                    <a href="reseller_report.php?vendor_id=<?= (int)$vendorDisplay['vendor_id'] ?>"><?= htmlspecialchars($vendorDisplay['display']) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($vendorDisplay['display']) ?>
                                <?php endif; ?>
                            </td>
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
                            <td><?= htmlspecialchars($d['status']) ?: '—' ?></td>
                            <td><?= htmlspecialchars($d['stage_name']) ?: '—' ?></td>
                            <td><?= $d['amount'] !== null ? '£' . number_format($d['amount'], 2) : '—' ?></td>
                            <td><?= $d['age_days'] !== null ? number_format($d['age_days'], 0) : '—' ?></td>
                            <td><?= htmlspecialchars($d['account_owner']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($matchedByVendor)): ?>
                        <tr><td colspan="9" class="text-muted">No opportunities matched. Check that Opportunity_Referred_by__c in the sheet equals this vendor’s name or Salesforce ID.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($testOwner !== ''): ?>
    <div class="chart-card">
        <div class="card-header">Matched by Account Manager (<?= count($matchedByOwner) ?> opportunities)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 sortable-table">
                    <thead class="table-light">
                        <tr>
                            <th class="sortable" data-sort="vendor">Vendor (Referred by) <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="contact">EPOS Contact <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="name">Opportunity Name <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="project">SPA/Deal ref <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="status">Status <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="stage">Stage Name <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="amount">Amount <i class="ti ti-arrows-sort ms-1"></i></th>
                            <th class="sortable" data-sort="owner">Opportunity Owner <i class="ti ti-arrows-sort ms-1"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matchedByOwner as $row):
                            $d = opportunityDisplayFields($row);
                            $sfUrl = getSalesforceOpportunityUrl($d['id']);
                            $vendorDisplay = getVendorReferredByDisplay($pdo, $d['vendor_name']);
                        ?>
                        <tr data-vendor="<?= htmlspecialchars(strtolower($vendorDisplay['display'])) ?>" 
                            data-contact="<?= htmlspecialchars(strtolower($d['epos_contact'])) ?>" 
                            data-name="<?= htmlspecialchars(strtolower($d['name'])) ?>" 
                            data-project="<?= htmlspecialchars(strtolower($d['project_no'])) ?>" 
                            data-status="<?= htmlspecialchars(strtolower($d['status'])) ?>" 
                            data-stage="<?= htmlspecialchars(strtolower($d['stage_name'])) ?>" 
                            data-amount="<?= $d['amount'] ?? 0 ?>" 
                            data-age="<?= $d['age_days'] ?? '' ?>" 
                            data-owner="<?= htmlspecialchars(strtolower($d['account_owner'])) ?>">
                            <td>
                                <?php if ($vendorDisplay['vendor_id'] !== null): ?>
                                    <a href="reseller_report.php?vendor_id=<?= (int)$vendorDisplay['vendor_id'] ?>"><?= htmlspecialchars($vendorDisplay['display']) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($vendorDisplay['display']) ?>
                                <?php endif; ?>
                            </td>
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
                            <td><?= htmlspecialchars($d['status']) ?: '—' ?></td>
                            <td><?= htmlspecialchars($d['stage_name']) ?: '—' ?></td>
                            <td><?= $d['amount'] !== null ? '£' . number_format($d['amount'], 2) : '—' ?></td>
                            <td><?= $d['age_days'] !== null ? number_format($d['age_days'], 0) : '—' ?></td>
                            <td><?= htmlspecialchars($d['account_owner']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($matchedByOwner)): ?>
                        <tr><td colspan="9" class="text-muted">No opportunities matched. Check that EPOS_Contact__c or EPOS_Email__c in the sheet matches the account manager name.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="chart-card">
        <div class="card-header">Raw sample (first 5 rows)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($headers as $h): ?>
                            <th><?= htmlspecialchars($h) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($oppRows, 0, 5) as $row): ?>
                        <tr>
                            <?php foreach ($headers as $h): ?>
                            <td><?= htmlspecialchars($row[$h] ?? '') ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <p class="mt-3"><a href="reseller_report.php" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Reseller Report</a> <a href="account_manager_report.php" class="btn btn-outline-secondary">Account Manager Report</a></p>
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
