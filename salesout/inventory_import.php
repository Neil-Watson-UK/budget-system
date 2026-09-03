<?php
// inventory_import.php - Import distributor inventory CSVs (WC, HYPERTEC, NIMANS, CORPTEL formats)
session_start();
$debugImport = isset($_GET['debug']) || isset($_POST['debug']);
if ($debugImport) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/inventory_import.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';
$distributorCodeMap = ['1003' => 'Nimans', '1005' => 'Corptel'];

// Check table exists
$tableExists = false;
try {
    $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
} catch (PDOException $e) { /* ignore */ }

if (!$tableExists) {
    $error = 'Run <code>install/inventory_schema.sql</code> in phpMyAdmin first to create the inventory table.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['inventory_file']) && $_FILES['inventory_file']['error'] === UPLOAD_ERR_OK && $tableExists) {
    try {
    $file = $_FILES['inventory_file'];
    $filename = basename($file['name']);
    $distributorOverride = trim($_POST['distributor_name'] ?? '');
    $snapshotDateOverride = trim($_POST['snapshot_date'] ?? '');

    // Detect distributor from filename (e.g. "WC WEEK 2 INVENTORY.csv" -> WC)
    $base = preg_replace('/\s*WEEK\s*\d+.*$/i', '', pathinfo($filename, PATHINFO_FILENAME));
    $detectedDist = $base ?: 'Unknown';

    $content = @file_get_contents($file['tmp_name']);
    if ($content === false) {
        $error = 'Could not read uploaded file. File may be too large or was removed.';
    } else {
    $content = preg_replace('/\r\n|\r/', "\n", $content); // normalize line endings (Windows/Mac)
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // remove BOM
    $lines = explode("\n", $content);

    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $rows[] = str_getcsv($line);
    }

    if (count($rows) < 2) {
        $error = 'File has no data rows. (Found ' . count($rows) . ' row(s). Check file has header + data and uses comma separator.)';
    } else {
            $headers = array_map(function($h) { return trim(trim((string)($h ?? '')), '"'); }, $rows[0]);
            $dataRows = array_slice($rows, 1);
            $inserted = 0;
            $format = null;

            // Detect format
            $h0 = strtolower(implode(' ', $headers));
            if (strpos($h0, 'report date') !== false && strpos($h0, 'product code') !== false && strpos($h0, 'qty') !== false) {
                $format = 'wc';
            } elseif (strpos($h0, 'quantity in stock') !== false && strpos($h0, 'code') !== false && strpos($h0, 'stock value') !== false) {
                $format = 'hypertec';
            } elseif (strpos($h0, 'distribution code') !== false && strpos($h0, 'inventory snapshot date') !== false && strpos($h0, 'sku code') !== false) {
                $format = 'nimans_corptel';
            }

            if (!$format) {
                $error = 'Unknown format. Expected: WC (Report Date, Product Code, Qty...), HYPERTEC (Code, Quantity in Stock...), or NIMANS/CORPTEL (Distribution code, Inventory snapshot date, SKU code...).';
            } else {
                $distributorName = $distributorOverride ?: $detectedDist;
                $stmt = $pdo->prepare("
                    INSERT INTO sales_out_inventory (snapshot_date, distributor_name, sku, sku_description, on_hand_qty, unit_cost, inventory_value, currency, source_file)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($dataRows as $row) {
                    $sku = '';
                    $desc = '';
                    $qty = 0;
                    $unitCost = 0;
                    $value = 0;
                    $date = null;

                    if ($format === 'wc') {
                        $dateIdx = findHeaderIdx($headers, ['report date', 'report_date']);
                        $codeIdx = findHeaderIdx($headers, ['product code', 'product_code', 'prod code']);
                        $descIdx = findHeaderIdx($headers, ['product description', 'product_description', 'description']);
                        $qtyIdx = findHeaderIdx($headers, ['qty', 'quantity']);
                        $costIdx = findHeaderIdx($headers, ['unit cost', 'unit_cost']);
                        $valIdx = findHeaderIdx($headers, ['value']);
                        $col = function($i) use ($row) { return $i !== null && isset($row[$i]) ? trim($row[$i]) : ''; };
                        $date = $dateIdx !== null ? parseDate($row[$dateIdx] ?? '') : null;
                        $sku = trim(str_replace(' ', '', $col($codeIdx)));
                        $desc = $col($descIdx);
                        $qty = (int)preg_replace('/[^0-9\-]/', '', $col($qtyIdx));
                        $unitCost = (float)preg_replace('/[^0-9.\-]/', '', $col($costIdx));
                        $value = (float)preg_replace('/[^0-9.\-]/', '', $col($valIdx));
                    } elseif ($format === 'hypertec') {
                        $codeIdx = findHeaderIdx($headers, [' code', 'code']);
                        $qtyIdx = findHeaderIdx($headers, ['quantity in stock', 'quantity']);
                        $valIdx = findHeaderIdx($headers, ['stock value', 'value']);
                        $col = function($i) use ($row) { return isset($row[$i]) ? trim($row[$i]) : ''; };
                        $sku = trim(str_replace(' ', '', $col($codeIdx)));
                        $qty = (int)preg_replace('/[^0-9\-]/', '', $col($qtyIdx));
                        $value = (float)preg_replace('/[^0-9.\-]/', '', $col($valIdx));
                        $unitCost = $qty > 0 ? $value / $qty : 0;
                        $date = $snapshotDateOverride ? parseDate($snapshotDateOverride) : (parseDate($filename) ?? date('Y-m-d'));
                    } elseif ($format === 'nimans_corptel') {
                        $distIdx = findHeaderIdx($headers, ['distribution code']) ?? 0;
                        $dateIdx = findHeaderIdx($headers, ['inventory snapshot date']) ?? 1;
                        $skuIdx = findHeaderIdx($headers, ['sku code']) ?? 2;
                        $nameIdx = findHeaderIdx($headers, ['sku name']);
                        $priceIdx = findHeaderIdx($headers, ['unit purchase price', 'unit purchase']) ?? 4;
                        $qtyIdx = findHeaderIdx($headers, ['on-hand qty', 'on-hand']) ?? 6;
                        $col = function($i) use ($row) { return isset($row[$i]) ? trim($row[$i]) : ''; };
                        $distCode = $col($distIdx);
                        if ($distributorOverride) {
                            $distributorName = $distributorOverride;
                        } else {
                            $distributorName = $distributorCodeMap[$distCode] ?? $detectedDist;
                        }
                        $date = parseDate($col($dateIdx));
                        $sku = trim(str_replace(' ', '', $col($skuIdx)));
                        $desc = $nameIdx !== null ? $col($nameIdx) : '';
                        $qty = (int)preg_replace('/[^0-9\-]/', '', $col($qtyIdx));
                        $unitCost = (float)preg_replace('/[^0-9.\-]/', '', $col($priceIdx));
                        $value = $qty * $unitCost;
                    }

                    if (empty($sku)) continue;
                    if (!$date) $date = $snapshotDateOverride ? parseDate($snapshotDateOverride) : date('Y-m-d');

                    try {
                        $stmt->execute([$date, $distributorName, $sku, $desc ?: null, $qty, $unitCost, $value, 'GBP', $filename]);
                        $inserted++;
                    } catch (PDOException $e) { /* skip duplicates or bad rows */ }
                }

                $message = "Imported $inserted inventory rows from " . htmlspecialchars($filename) . " for " . htmlspecialchars($distributorName) . ".";
            }
        }
    }
    } catch (Throwable $e) {
        $error = $debugImport
            ? 'Import error: ' . htmlspecialchars($e->getMessage()) . ' in ' . $e->getFile() . ':' . $e->getLine()
            : 'Import failed. Add ?debug=1 to the URL and try again to see details.';
    }
}

function findHeaderIdx($headers, array $aliases) {
    foreach ($headers as $i => $h) {
        $norm = strtolower(trim($h));
        foreach ($aliases as $a) {
            if ($norm === $a || strpos($norm, $a) !== false) return $i;
        }
    }
    return null;
}

function parseDate($s) {
    $s = trim($s);
    if (empty($s)) return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return $m[0];
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) return $m[3] . '-' . str_pad($m[2], 2, '0') . '-' . str_pad($m[1], 2, '0');
    return null;
}

$distributors = [];
if ($tableExists) {
    try {
        $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw WHERE distributor_name != '' ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $distributors = [];
    }
    try {
        $invDist = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_inventory ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);
        $distributors = array_values(array_unique(array_merge($distributors, $invDist)));
        sort($distributors);
    } catch (PDOException $e) {
        // keep $distributors from sales_out_raw if that worked
    }
}

require_once __DIR__ . '/header.php';
?>
<style>
.inv-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 10px; }
</style>
<div class="container-xl py-4">
    <div class="inv-header">
        <h1 class="h2 mb-1"><i class="ti ti-package-import me-2"></i>Import Inventory</h1>
        <p class="mb-0 opacity-75">Upload distributor inventory CSVs (WC, HYPERTEC, NIMANS, CORPTEL). Run <code>install/inventory_schema.sql</code> first if needed. <?php if (!$debugImport): ?><a href="?debug=1" class="text-white text-decoration-underline">Having import issues? Enable debug</a><?php endif; ?></p>
    </div>

    <?php if ($debugImport): ?><div class="alert alert-info"><i class="ti ti-bug me-2"></i>Debug mode is on. Errors will be shown below.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="ti ti-upload me-2"></i>Upload inventory CSV</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="row g-3">
                <?php if ($debugImport): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label">CSV file</label>
                    <input type="file" name="inventory_file" class="form-control" accept=".csv" required>
                    <small class="text-muted">Supports: WC (Report Date, Product Code, Qty...), HYPERTEC (Code, Quantity in Stock...), NIMANS/CORPTEL (Distribution code, Inventory snapshot date, SKU code...)</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Distributor</label>
                    <input type="text" name="distributor_name" class="form-control" placeholder="From filename or override">
                    <small class="text-muted">Auto-detected from filename (e.g. WC, HYPERTEC). Override if needed.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Snapshot date (if missing in file)</label>
                    <input type="date" name="snapshot_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    <small class="text-muted">Used for HYPERTEC format (no date in file).</small>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="ti ti-upload me-1"></i> Import</button>
                    <a href="inventory_report.php" class="btn btn-outline-secondary ms-2"><i class="ti ti-chart-bar me-1"></i> View report</a>
                </div>
            </form>
        </div>
    </div>
</div>
</div></div>
</body>
</html>
