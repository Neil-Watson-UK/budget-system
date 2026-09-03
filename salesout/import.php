<?php
// salesout/import.php - Import distributor sales reports (CSV or Excel)
// Two-step: 1) Upload & map columns, 2) Confirm & import
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/import.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';
$step = 'upload';
$importWarning = null;

// Column mapping hints - used for auto-detect
$commonHeaders = [
    'date' => ['date', 'report date', 'period', 'sales date', 'invoice date', 'sales invoice date'],
    'distributor_reference' => ['distributor ref', 'disti code', 'disti ref', 'distributor code', 'their ref', 'their reference', 'customer ref', 'supplier ref', 'vendor ref', 'ref', 'reference', 'external ref', 'our ref', 'invoice number', 'invoice no', 'order number'],
    'reseller' => ['reseller', 'reseller name', 'customer', 'end customer', 'buyer', 'dealer',
        'account name', 'bill to name', 'ship to name', 'end user sold-to', 'sold-to name'],
    'sku' => ['sku', 'sku code', 'part number', 'manufacturer part', 'mpn', 'product code', 'prod code', 'item', 'part no'],
    'product' => ['product', 'product name', 'sku name', 'description', 'item name', 'prod cat'],
    'quantity' => ['qty', 'quantity', 'units', 'volume', 'units sold', 'sales quantity'],
    'value' => ['value', 'total value', 'amount', 'cogs', 'revenue', 'sales', 'net amount', 'extended amount',
        'total prch', 'total purch', 'prch rate', 'purchase rate', 'line total'],
];

// Clear stale import data (cancel or reset)
if (isset($_GET['reset']) || (isset($_POST['cancel_mapping']))) {
    if (!empty($_SESSION['import_file']['path']) && file_exists($_SESSION['import_file']['path'])) {
        @unlink($_SESSION['import_file']['path']);
    }
    unset($_SESSION['import_file']);
    header('Location: import.php');
    exit;
}

// Step 1: Handle file upload - parse and show mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sales_file']) && $_FILES['sales_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['sales_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $distributorName = trim($_POST['distributor_name'] ?? 'Unknown');
    $importRegion = trim($_POST['import_region'] ?? 'UKI');
    $importCurrency = trim($_POST['import_currency'] ?? 'GBP');
    if (!isset($REGIONAL_SETTINGS[$importRegion])) {
        $importRegion = 'UKI';
    }
    $allowedCurrencies = [];
    foreach ($REGIONAL_SETTINGS as $r) {
        $allowedCurrencies[] = $r['currency'] ?? 'EUR';
        foreach ($r['allowed_currencies'] ?? [] as $c) {
            $allowedCurrencies[] = $c;
        }
    }
    $allowedCurrencies = array_unique($allowedCurrencies);
    sort($allowedCurrencies);
    if (!in_array($importCurrency, $allowedCurrencies, true)) {
        $importCurrency = $REGIONAL_SETTINGS[$importRegion]['currency'] ?? 'GBP';
    }

    $maxExcel = defined('SALESOUT_MAX_EXCEL_UPLOAD') ? SALESOUT_MAX_EXCEL_UPLOAD : (8 * 1024 * 1024);
    $maxCsv = defined('SALESOUT_MAX_CSV_UPLOAD') ? SALESOUT_MAX_CSV_UPLOAD : (50 * 1024 * 1024);
    if (in_array($ext, ['xlsx', 'xls']) && $file['size'] > $maxExcel) {
        $error = 'Excel file too large (' . number_format($file['size'] / 1024 / 1024, 1) . ' MB). Maximum ' . round($maxExcel / 1024 / 1024) . ' MB for Excel. Export to CSV from Excel instead (File → Save As → CSV).';
    } elseif ($ext === 'csv' && $file['size'] > $maxCsv) {
        $error = 'CSV file too large. Maximum ' . round($maxCsv / 1024 / 1024) . ' MB.';
    } else {
    try {
        if (in_array($ext, ['xlsx', 'xls'])) {
            require_once __DIR__ . '/bootstrap.php';
            @ini_set('memory_limit', '512M'); // Large Excel files (e.g. 5 years) need more memory
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
            $reader->setReadDataOnly(true); // Faster, less memory — skip formatting
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();
        } elseif ($ext === 'csv') {
            $handle = fopen($file['tmp_name'], 'r');
            $rows = [];
            while (($r = fgetcsv($handle)) !== false) $rows[] = $r;
            fclose($handle);
            if (!empty($rows[0][0])) {
                $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
            }
        } else {
            throw new Exception('Supported formats: CSV, XLSX, XLS');
        }

        if (empty($rows)) {
            throw new Exception('File appears empty.');
        }

        $headers = array_map('trim', $rows[0]);
        $dataRows = array_slice($rows, 1);

        // Save file to temp for step 2
        $tempDir = sys_get_temp_dir() . '/salesout_import_' . session_id();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0700, true);
        }
        $tempPath = $tempDir . '/' . basename($file['name']);
        if (!copy($file['tmp_name'], $tempPath)) {
            throw new Exception('Could not save file for processing.');
        }

        // Auto-detect mapping
        $map = ['date' => null, 'distributor_reference' => null, 'reseller' => null, 'sku' => null, 'product' => null, 'quantity' => null, 'value' => null];
        foreach ($commonHeaders as $field => $aliases) {
            foreach ($headers as $i => $h) {
                $norm = normaliseHeader($h);
                if ($field === 'value' && (strpos($norm, 'quantity') !== false || strpos($norm, 'qty') !== false || strpos($norm, 'units') !== false)) {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if (strpos($norm, $alias) !== false || strpos($alias, $norm) !== false) {
                        $map[$field] = $i;
                        break 2;
                    }
                }
            }
        }

        $_SESSION['import_file'] = [
            'path' => $tempPath,
            'filename' => $file['name'],
            'ext' => $ext,
            'distributor' => $distributorName,
            'region' => $importRegion,
            'currency' => $importCurrency,
            'headers' => $headers,
            'map' => $map,
            'sample_rows' => array_slice($dataRows, 0, 5),
            'row_count' => count($dataRows),
        ];
        $step = 'mapping';

    } catch (Exception $e) {
        $error = $e->getMessage();
        if (strpos($error, 'memory') !== false || strpos($error, 'Allowed memory') !== false) {
            $error .= ' Try exporting to CSV from Excel (File → Save As → CSV), or split the file.';
        }
    }
    }
}

// Step 2: Run import with user-confirmed mapping
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && isset($_SESSION['import_file'])) {
    $imp = $_SESSION['import_file'];
    if (empty($imp['path']) || !file_exists($imp['path'])) {
        $error = 'Upload expired. Please upload the file again.';
        unset($_SESSION['import_file']);
    } else {
        $map = [
            'date' => isset($_POST['map_date']) && $_POST['map_date'] !== '' ? (int) $_POST['map_date'] : null,
            'distributor_reference' => isset($_POST['map_distributor_reference']) && $_POST['map_distributor_reference'] !== '' ? (int) $_POST['map_distributor_reference'] : null,
            'reseller' => isset($_POST['map_reseller']) && $_POST['map_reseller'] !== '' ? (int) $_POST['map_reseller'] : null,
            'sku' => isset($_POST['map_sku']) && $_POST['map_sku'] !== '' ? (int) $_POST['map_sku'] : null,
            'product' => isset($_POST['map_product']) && $_POST['map_product'] !== '' ? (int) $_POST['map_product'] : null,
            'quantity' => isset($_POST['map_quantity']) && $_POST['map_quantity'] !== '' ? (int) $_POST['map_quantity'] : null,
            'value' => isset($_POST['map_value']) && $_POST['map_value'] !== '' ? (int) $_POST['map_value'] : null,
        ];

        try {
            $ext = $imp['ext'];
            if (in_array($ext, ['xlsx', 'xls'])) {
                require_once __DIR__ . '/bootstrap.php';
                @ini_set('memory_limit', '512M');
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($imp['path']);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
            } else {
                $handle = fopen($imp['path'], 'r');
                $rows = [];
                while (($r = fgetcsv($handle)) !== false) $rows[] = $r;
                fclose($handle);
                if (!empty($rows[0][0])) {
                    $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
                }
            }

            $dataRows = array_slice($rows, 1);

            // Cross-check with existing data: warn if we already have this distributor + month
            $overlapMonths = [];
            if ($map['date'] !== null && empty($_POST['confirm_duplicate_import'])) {
                $importMonths = [];
                foreach ($dataRows as $row) {
                    if (empty(array_filter($row))) continue;
                    $reportDate = null;
                    if (isset($row[$map['date']]) && $row[$map['date']] !== '') {
                        $d = $row[$map['date']];
                        if (is_numeric($d) && in_array($ext, ['xlsx', 'xls'])) {
                            $reportDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($d)->format('Y-m-d');
                        } else {
                            $reportDate = parseFlexibleDate((string) $d);
                        }
                    }
                    if ($reportDate) {
                        $importMonths[date('Y-m', strtotime($reportDate))] = true;
                    }
                }
                if (!empty($importMonths)) {
                    $placeholders = implode(',', array_fill(0, count($importMonths), '?'));
                    $importRegionCheck = $imp['region'] ?? 'UKI';
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT DATE_FORMAT(report_date, '%Y-%m') as ym
                        FROM sales_out_raw
                        WHERE distributor_name = ? AND report_date IS NOT NULL
                        AND (region IS NULL OR region = ?)
                        AND DATE_FORMAT(report_date, '%Y-%m') IN ($placeholders)
                    ");
                    $stmt->execute(array_merge([$imp['distributor'], $importRegionCheck], array_keys($importMonths)));
                    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($existing as $ym) {
                        $overlapMonths[] = date('M Y', strtotime($ym . '-01'));
                    }
                }
            }

            if (!empty($overlapMonths) && empty($_POST['confirm_duplicate_import'])) {
                $importWarning = 'You already have data from <strong>' . htmlspecialchars($imp['distributor']) . '</strong> for: ' . implode(', ', $overlapMonths) . '. This may create duplicates. Check the <a href="missing_data.php">Missing Data</a> page to confirm.';
                $step = 'mapping';
            } else {
            $importRegion = $imp['region'] ?? 'UKI';
            $importCurrency = $imp['currency'] ?? 'GBP';
            $stmt = $pdo->prepare("
                INSERT INTO sales_out_raw 
                (report_date, distributor_name, reseller_name, sku, product_name, quantity, total_value, matched_vendor_id, region, currency, distributor_reference)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    report_date = VALUES(report_date),
                    reseller_name = VALUES(reseller_name),
                    sku = VALUES(sku),
                    product_name = VALUES(product_name),
                    quantity = VALUES(quantity),
                    total_value = VALUES(total_value),
                    matched_vendor_id = VALUES(matched_vendor_id),
                    distributor_reference = VALUES(distributor_reference)
            ");
            $importStmt = $pdo->prepare("INSERT INTO sales_out_imports (filename, distributor_name) VALUES (?, ?)");
            $importStmt->execute([$imp['filename'], $imp['distributor']]);
            $importId = $pdo->lastInsertId();

            $count = 0;
            $totalValue = 0;
            foreach ($dataRows as $row) {
                if (empty(array_filter($row))) continue;

                $reportDate = null;
                if ($map['date'] !== null && isset($row[$map['date']]) && $row[$map['date']] !== '') {
                    $d = $row[$map['date']];
                    if (is_numeric($d) && in_array($ext, ['xlsx', 'xls'])) {
                        $reportDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($d)->format('Y-m-d');
                    } else {
                        $reportDate = parseFlexibleDate((string) $d);
                    }
                }
                $reportDate = $reportDate ?: date('Y-m-d');

                $reseller = ($map['reseller'] !== null && isset($row[$map['reseller']])) ? trim((string) $row[$map['reseller']]) : '';
                $matchedVendorId = $reseller ? matchResellerToVendor($pdo, $reseller) : null;

                $qty = 0;
                if ($map['quantity'] !== null && isset($row[$map['quantity']])) {
                    $qty = (int) preg_replace('/[^0-9\-]/', '', (string) $row[$map['quantity']]);
                }

                $value = 0;
                if ($map['value'] !== null && isset($row[$map['value']])) {
                    $value = parseDecimalValue((string) $row[$map['value']]);
                }

                $sku = ($map['sku'] !== null && isset($row[$map['sku']])) ? trim((string) $row[$map['sku']]) : '';
                $product = ($map['product'] !== null && isset($row[$map['product']])) ? trim((string) $row[$map['product']]) : '';
                
                // If value is 0 or missing, try to calculate from trade_price
                if ($value <= 0 && $sku !== '' && $qty > 0) {
                    $tradeStmt = $pdo->prepare("SELECT trade_price FROM sales_out_products WHERE TRIM(REPLACE(sku,' ','')) = TRIM(REPLACE(?,' ','')) LIMIT 1");
                    $tradeStmt->execute([$sku]);
                    $tradeRow = $tradeStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tradeRow && isset($tradeRow['trade_price']) && $tradeRow['trade_price'] !== null && (float)$tradeRow['trade_price'] > 0) {
                        $value = $qty * (float)$tradeRow['trade_price'];
                    }
                }
                
                $totalValue += $value;

                $distributorRef = null;
                if ($map['distributor_reference'] !== null && isset($row[$map['distributor_reference']])) {
                    $dr = trim((string) $row[$map['distributor_reference']]);
                    if ($dr !== '') $distributorRef = $dr;
                }

                $stmt->execute([
                    $reportDate,
                    $imp['distributor'],
                    $reseller,
                    $sku,
                    $product,
                    $qty,
                    $value,
                    $matchedVendorId,
                    $importRegion,
                    $importCurrency,
                    $distributorRef
                ]);
                $count++;
            }

            $pdo->prepare("UPDATE sales_out_imports SET row_count = ? WHERE id = ?")->execute([$count, $importId]);
            // Sync Salesforce IDs from vendors (same as reapply)
            try {
                $pdo->exec("
                    UPDATE sales_out_raw s
                    INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    SET s.salesforce_id = v.salesforce_id
                    WHERE s.matched_vendor_id IS NOT NULL
                ");
            } catch (Throwable $_) { /* column may not exist yet */ }
            @unlink($imp['path']);
            unset($_SESSION['import_file']);

            $sym = $CURRENCY_SYMBOLS[$importCurrency] ?? $importCurrency . ' ';
            $message = "Imported $count rows successfully ($importRegion, $importCurrency). Total value: " . $sym . number_format($totalValue, 2);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (strpos($error, 'memory') !== false || strpos($error, 'Allowed memory') !== false) {
                $error .= ' Try exporting to CSV from Excel, or split the file.';
            }
        }
    }
}

// Check if we're in mapping step from session
if (isset($_SESSION['import_file']) && empty($_POST)) {
    $step = 'mapping';
}

// Build currency list from budget regions (for import dropdown)
$importCurrenciesList = [];
foreach ($REGIONAL_SETTINGS as $r) {
    $importCurrenciesList[] = $r['currency'] ?? 'EUR';
    foreach ($r['allowed_currencies'] ?? [] as $c) {
        $importCurrenciesList[] = $c;
    }
}
$importCurrenciesList = array_unique($importCurrenciesList);
sort($importCurrenciesList);

$pageTitle = 'Import Sales Report';
require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4">
    <h1><?= $pageTitle ?></h1>
    <p class="text-muted">Upload distributor sales reports (CSV or Excel). Map columns before import. Select region and currency to match budget.</p>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($step === 'upload'): ?>
    <div class="card">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">Distributor Name</label>
                    <input type="text" name="distributor_name" class="form-control" list="distributor_list" placeholder="e.g. Hypertec, Nuvias, Nimans" value="<?= htmlspecialchars($_POST['distributor_name'] ?? '') ?>" required>
                    <datalist id="distributor_list">
                        <option value="Corptel"><option value="Hypertec"><option value="Ingram"><option value="Nimans"><option value="Nuvias"><option value="Westcoast">
                    </datalist>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Region</label>
                        <select name="import_region" class="form-select">
                            <?php foreach ($REGIONAL_SETTINGS as $reg => $settings): ?>
                            <option value="<?= htmlspecialchars($reg) ?>" <?= ($_POST['import_region'] ?? 'UKI') === $reg ? 'selected' : '' ?>><?= htmlspecialchars($reg) ?> (<?= htmlspecialchars($settings['currency'] ?? '') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Matches budget regional view.</small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Currency</label>
                        <select name="import_currency" class="form-select">
                            <?php foreach ($importCurrenciesList as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= ($_POST['import_currency'] ?? 'GBP') === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Currency of amounts in the file.</small>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Sales Report File (CSV, XLSX, XLS)</label>
                    <input type="file" name="sales_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                    <small class="text-muted">Max: 8 MB for Excel, 50 MB for CSV. Large Excel files? Export to CSV (File → Save As → CSV). <a href="diagnose_file.php">Diagnose a failing file</a></small>
                </div>
                <button type="submit" class="btn btn-primary">Upload &amp; Map Columns</button>
                <a href="index.php" class="btn btn-secondary">Cancel</a>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($step === 'mapping' && isset($_SESSION['import_file'])): 
        $imp = $_SESSION['import_file'];
        $headers = $imp['headers'];
        $map = $imp['map'];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['date','distributor_reference','reseller','sku','product','quantity','value'] as $f) {
                if (isset($_POST["map_$f"]) && $_POST["map_$f"] !== '') $map[$f] = (int)$_POST["map_$f"];
            }
        }
        $sample = $imp['sample_rows'];
    ?>
    <?php if ($importWarning): ?>
    <div class="alert alert-warning">
        <i class="ti ti-alert-triangle me-2"></i><?= $importWarning ?>
        <p class="mb-0 mt-2">Tick "Import anyway" below to proceed if this is intentional (e.g. corrective re-import).</p>
    </div>
    <?php endif; ?>
    <div class="card mb-3">
        <div class="card-header">Confirm column mapping — <?= htmlspecialchars($imp['filename']) ?> (<?= htmlspecialchars($imp['distributor']) ?>) · Region: <?= htmlspecialchars($imp['region'] ?? 'UKI') ?> · Currency: <?= htmlspecialchars($imp['currency'] ?? 'GBP') ?></div>
        <div class="card-body">
            <p class="text-muted">Check that each field points to the correct column. <strong>Value</strong> must be the currency column (e.g. amount in <?= htmlspecialchars($imp['currency'] ?? 'GBP') ?>), not quantity. <strong>Distributor Ref</strong> (optional) enables de‑duplication and lookups: same distributor + ref updates existing rows. Map it for lookups when the distributor asks by their ref/code.</p>
            <form method="POST">
                <input type="hidden" name="confirm_import" value="1">
                <div class="row g-3 mb-4">
                    <?php $valLabel = 'Value / Amount (' . ($imp['currency'] ?? 'GBP') . ')'; foreach (['date' => 'Date', 'distributor_reference' => 'Distributor Ref / Disti Code (de‑dup & lookup)', 'reseller' => 'Reseller / Customer', 'sku' => 'SKU / Part No', 'product' => 'Product Name', 'quantity' => 'Quantity', 'value' => $valLabel] as $field => $label): ?>
                    <div class="col-md-4">
                        <label class="form-label"><strong><?= htmlspecialchars($label) ?></strong></label>
                        <select name="map_<?= $field ?>" class="form-select">
                            <option value="">— Don't use —</option>
                            <?php foreach ($headers as $i => $h): 
                                $sel = (isset($map[$field]) && $map[$field] === $i) ? ' selected' : '';
                            ?>
                            <option value="<?= $i ?>"<?= $sel ?>><?= htmlspecialchars($h ?: "(Column " . ($i+1) . ")") ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-hover table-striped table-bordered">
                        <thead class="table-light">
                            <tr>
                                <?php foreach ($headers as $h): ?>
                                <th class="small"><?= htmlspecialchars($h ?: '-') ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sample as $row): ?>
                            <tr>
                                <?php for ($i = 0; $i < count($headers); $i++): ?>
                                <td class="small"><?= htmlspecialchars($row[$i] ?? '') ?></td>
                                <?php endfor; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($importWarning): ?>
                <div class="form-check mb-3">
                    <input type="checkbox" name="confirm_duplicate_import" value="1" class="form-check-input" id="confirm_dup">
                    <label class="form-check-label" for="confirm_dup">Import anyway (I understand this may create duplicates)</label>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary">Import <?= (int) ($imp['row_count'] ?? 0) ?> rows</button>
                <button type="submit" name="cancel_mapping" value="1" class="btn btn-outline-secondary">Cancel</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
</div></div>
</body>
</html>
