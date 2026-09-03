<?php
// inventory_trends.php - Inventory value and units over time by distributor
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/inventory_trends.php'));
    exit;
}

$pdo = getDBConnection();
$distributor = trim($_GET['distributor'] ?? '');
$tableExists = false;
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
} catch (PDOException $e) { /* ignore */ }

$distributors = [];
$dates = [];
$seriesByValue = [];
$seriesByUnits = [];
$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#f59e0b', '#3498db', '#9b59b6'];

if ($tableExists) {
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_inventory ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);

    $aliases = getInventoryDistributorAliases();
    $distNames = $distributor ? ($aliases[$distributor] ?? [$distributor]) : [];
    $distPh = $distNames ? implode(',', array_fill(0, count($distNames), 'LOWER(?)')) : '';
    $where = $distPh ? " WHERE LOWER(TRIM(distributor_name)) IN ($distPh)" : "";
    $params = $distNames;

    $datesStmt = $pdo->prepare("SELECT DISTINCT snapshot_date FROM sales_out_inventory $where ORDER BY snapshot_date");
    $datesStmt->execute($params);
    $dates = $datesStmt->fetchAll(PDO::FETCH_COLUMN);

    $distsToShow = $distributor ? [$distributor] : $distributors;
    foreach ($distsToShow as $i => $d) {
        $seriesByValue[$d] = array_fill_keys($dates, 0);
        $seriesByUnits[$d] = array_fill_keys($dates, 0);
    }

    $aggStmt = $pdo->prepare("
        SELECT snapshot_date, distributor_name, SUM(inventory_value) as val, SUM(on_hand_qty) as qty
        FROM sales_out_inventory
        WHERE 1=1 " . ($distPh ? " AND LOWER(TRIM(distributor_name)) IN ($distPh)" : "") . "
        GROUP BY snapshot_date, distributor_name
        ORDER BY snapshot_date, distributor_name
    ");
    $aggStmt->execute($distributor ? $params : []);
    while ($r = $aggStmt->fetch(PDO::FETCH_ASSOC)) {
        $dt = $r['snapshot_date'];
        $d = $r['distributor_name'];
        $displayKey = $distributor ? $distributor : $d;
        if (isset($seriesByValue[$displayKey][$dt])) {
            $seriesByValue[$displayKey][$dt] += (float)$r['val'];
            $seriesByUnits[$displayKey][$dt] += (int)$r['qty'];
        }
    }
}

$dateLabels = json_encode(array_map(function($d) { return date('d M y', strtotime($d)); }, $dates));
$datasetsValue = [];
$datasetsUnits = [];
$idx = 0;
foreach ($seriesByValue as $distName => $vals) {
    $color = $chartColors[$idx % count($chartColors)];
    $datasetsValue[] = [
        'label' => $distName,
        'data' => array_values($vals),
        'borderColor' => $color,
        'backgroundColor' => $color . '40',
        'fill' => false,
        'tension' => 0.2,
    ];
    $idx++;
}
$idx = 0;
foreach ($seriesByUnits as $distName => $vals) {
    $color = $chartColors[$idx % count($chartColors)];
    $datasetsUnits[] = [
        'label' => $distName,
        'data' => array_values($vals),
        'borderColor' => $color,
        'backgroundColor' => $color . '40',
        'fill' => false,
        'tension' => 0.2,
    ];
    $idx++;
}
$datasetsValueJson = json_encode($datasetsValue);
$datasetsUnitsJson = json_encode($datasetsUnits);

require_once __DIR__ . '/header.php';
?>
<style>
.inv-trends-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 2rem 0; margin-bottom: 1.5rem; border-radius: 10px; }
.chart-container { height: 320px; }
</style>
<div class="container-xl py-4">
    <div class="inv-trends-header">
        <div class="container-fluid">
            <h1 class="h2 mb-1"><i class="ti ti-chart-line me-2"></i>Inventory Trends</h1>
            <p class="mb-0 opacity-75">Inventory value and units over time by distributor. Requires multiple snapshot imports.</p>
        </div>
    </div>

    <?php if (!$tableExists): ?>
    <div class="alert alert-warning"><i class="ti ti-alert-triangle me-2"></i>Run <code>install/inventory_schema.sql</code>, then <a href="inventory_import.php">import inventory</a>.</div>
    <?php elseif (count($dates) < 2): ?>
    <div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>Import inventory from at least 2 different weeks to see trends. <a href="inventory_import.php">Import inventory</a></div>
    <?php else: ?>

    <div class="card mb-4">
        <div class="card-header"><i class="ti ti-filter me-2"></i>Filter</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Distributor</label>
                    <select name="distributor" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($distributors as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $distributor === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-4">
            <div class="card">
                <div class="card-header"><i class="ti ti-currency-pound me-2"></i>Inventory value over time</div>
                <div class="card-body">
                    <div class="chart-container"><canvas id="valueChart"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><i class="ti ti-box me-2"></i>Units on hand over time</div>
                <div class="card-body">
                    <div class="chart-container"><canvas id="unitsChart"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <a href="inventory_report.php" class="btn btn-outline-secondary"><i class="ti ti-package me-1"></i> Current stock</a>
        <a href="inventory_movement.php" class="btn btn-outline-secondary ms-2"><i class="ti ti-arrows-exchange me-1"></i> Stock movement</a>
    </div>

    <?php endif; ?>
</div>
<?php if ($tableExists && count($dates) >= 2): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {
    var dateLabels = <?= $dateLabels ?>;
    var datasetsValue = <?= $datasetsValueJson ?>;
    var datasetsUnits = <?= $datasetsUnitsJson ?>;

    if (document.getElementById('valueChart') && datasetsValue.length) {
        new Chart(document.getElementById('valueChart').getContext('2d'), {
            type: 'line',
            data: { labels: dateLabels, datasets: datasetsValue },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return '£' + v; } } } } }
        });
    }
    if (document.getElementById('unitsChart') && datasetsUnits.length) {
        new Chart(document.getElementById('unitsChart').getContext('2d'), {
            type: 'line',
            data: { labels: dateLabels, datasets: datasetsUnits },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
        });
    }
})();
</script>
<?php endif; ?>
</div></div>
</body>
</html>
