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

// Column mapping hints - used for auto-detect
$commonHeaders = [
    'date' => ['date', 'report date', 'period', 'sales date', 'invoice date', 'sales invoice date'],
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

    try {
        if (in_array($ext, ['xlsx', 'xls'])) {
            require_once __DIR__ . '/bootstrap.php';
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
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
        $map = ['date' => null, 'reseller' => null, 'sku' => null, 'product' => null, 'quantity' => null, 'value' => null];
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
            'headers' => $headers,
            'map' => $map,
            'sample_rows' => array_slice($dataRows, 0, 5),
            'row_count' => count($dataRows),
        ];
        $step = 'mapping';

    } catch (Exception $e) {
        $error = $e->getMessage();
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
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
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
            $stmt = $pdo->prepare("
                INSERT INTO sales_out_raw 
                (report_date, distributor_name, reseller_name, sku, product_name, quantity, total_value, matched_vendor_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
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
                        $reportDate = date('Y-m-d', strtotime(str_replace('/', '-', (string) $d)));
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
                $totalValue += $value;

                $sku = ($map['sku'] !== null && isset($row[$map['sku']])) ? trim((string) $row[$map['sku']]) : '';
                $product = ($map['product'] !== null && isset($row[$map['product']])) ? trim((string) $row[$map['product']]) : '';

                $stmt->execute([
                    $reportDate,
                    $imp['distributor'],
                    $reseller,
                    $sku,
                    $product,
                    $qty,
                    $value,
                    $matchedVendorId
                ]);
                $count++;
            }

            $pdo->prepare("UPDATE sales_out_imports SET row_count = ? WHERE id = ?")->execute([$count, $importId]);
            @unlink($imp['path']);
            unset($_SESSION['import_file']);

            $message = "Imported $count rows successfully. Total value: £" . number_format($totalValue, 2);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Check if we're in mapping step from session
if (isset($_SESSION['import_file']) && empty($_POST)) {
    $step = 'mapping';
}

$pageTitle = 'Import Sales Report';
require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4">
    <h1><?= $pageTitle ?></h1>
    <p class="text-muted">Upload distributor sales reports (CSV or Excel). Map columns before import.</p>

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
                <div class="mb-3">
                    <label class="form-label">Sales Report File (CSV, XLSX, XLS)</label>
                    <input type="file" name="sales_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                    <small class="text-muted">Tip: If the file picker crashes, copy the file to Desktop first.</small>
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
        $sample = $imp['sample_rows'];
    ?>
    <div class="card mb-3">
        <div class="card-header">Confirm column mapping — <?= htmlspecialchars($imp['filename']) ?> (<?= htmlspecialchars($imp['distributor']) ?>)</div>
        <div class="card-body">
            <p class="text-muted">Check that each field points to the correct column. <strong>Value</strong> must be the currency column (e.g. £ amount), not quantity.</p>
            <form method="POST">
                <input type="hidden" name="confirm_import" value="1">
                <div class="row g-3 mb-4">
                    <?php foreach (['date' => 'Date', 'reseller' => 'Reseller / Customer', 'sku' => 'SKU / Part No', 'product' => 'Product Name', 'quantity' => 'Quantity', 'value' => 'Value / Amount (£)'] as $field => $label): ?>
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
