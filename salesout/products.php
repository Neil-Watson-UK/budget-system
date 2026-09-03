<?php
// salesout/products.php - Product master (SKU lookup)
// Supports EPOS_GBP_Products.csv format and simple SKU,Name,Category,Brand
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/products.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';

// EPOS CSV column mapping (header -> db column)
$eposColumns = [
    'product sku' => 'sku',
    'product name' => 'product_name',
    'msrp' => 'msrp',
    'currency' => 'currency',
    'trade price' => 'trade_price',
    'product status description' => 'product_status',
    'product series' => 'product_series',
    'product line' => 'product_line',
    'product type' => 'product_type',
    'product sub-type' => 'product_sub_type',
    'ean code' => 'ean_code',
    'upc code' => 'upc_code',
    'country of origin' => 'country_of_origin',
];

function normaliseEposHeader(string $h): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', $h)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product'])) {
        try {
            $msrp = trim($_POST['msrp'] ?? '') !== '' ? parseDecimalValue($_POST['msrp']) : null;
            $tradePrice = trim($_POST['trade_price'] ?? '') !== '' ? parseDecimalValue($_POST['trade_price']) : null;
            $currency = trim($_POST['currency'] ?? '');
            $imageUrl = trim($_POST['image_url'] ?? '') ?: null;
            $hasImageCol = (bool) $pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_url'")->fetch();
            $cols = ['sku', 'product_name', 'product_category', 'brand', 'msrp', 'trade_price', 'currency'];
            $vals = [trim($_POST['sku']), trim($_POST['product_name'] ?? ''), trim($_POST['product_category'] ?? ''), trim($_POST['brand'] ?? ''), $msrp, $tradePrice, $currency ?: null];
            $updates = ['product_name=VALUES(product_name)', 'product_category=VALUES(product_category)', 'brand=VALUES(brand)', 'msrp=VALUES(msrp)', 'trade_price=VALUES(trade_price)', 'currency=VALUES(currency)'];
            if ($hasImageCol) {
                $cols[] = 'image_url';
                $vals[] = $imageUrl;
                $updates[] = 'image_url=VALUES(image_url)';
            }
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $stmt = $pdo->prepare("
                INSERT INTO sales_out_products (" . implode(', ', $cols) . ")
                VALUES ($placeholders)
                ON DUPLICATE KEY UPDATE " . implode(', ', $updates) . "
            ");
            $stmt->execute($vals);
            $message = 'Product saved.';
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['import_csv'])) {
        if (!empty($_FILES['products_csv']['tmp_name'])) {
            $handle = fopen($_FILES['products_csv']['tmp_name'], 'r');
            if (!$handle) {
                $error = 'Could not open file.';
            } else {
                $headerRow = fgetcsv($handle);
                if (!$headerRow) {
                    $error = 'File is empty.';
                    fclose($handle);
                } else {
                    // Remove BOM from first cell if present
                    if (!empty($headerRow[0])) {
                        $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]);
                    }
                    $normHeaders = array_map('normaliseEposHeader', $headerRow);
                    $colMap = [];
                    foreach ($normHeaders as $i => $h) {
                        if (empty($h)) continue;
                        foreach ($eposColumns as $eposKey => $dbCol) {
                            if ($h === $eposKey || $h === str_replace('-', ' ', $eposKey)) {
                                $colMap[$dbCol] = $i;
                                break;
                            }
                        }
                    }
                    $isEpos = isset($colMap['product_type']) || isset($colMap['product_line']) || isset($colMap['msrp']);
                    // For EPOS: also populate product_category from Product Type (for export when migration not run)
                    if ($isEpos && isset($colMap['product_type']) && !isset($colMap['product_category'])) {
                        $colMap['product_category'] = $colMap['product_type'];
                    }
                    if (!$isEpos && count($headerRow) >= 4) {
                        $colMap = ['sku' => 0, 'product_name' => 1, 'product_category' => 2, 'brand' => 3];
                    } elseif (!$isEpos && count($headerRow) >= 2) {
                        $colMap = ['sku' => 0, 'product_name' => 1];
                    }

                    $cols = array_keys($colMap);
                    if (empty($cols) || !isset($colMap['sku'])) {
                        $error = 'Could not detect SKU column. Use EPOS format or CSV with headers: Product SKU / SKU in first column.';
                    } else {
                        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                        $setClause = [];
                        foreach ($cols as $c) {
                            if ($c !== 'sku') $setClause[] = "`$c`=VALUES(`$c`)";
                        }
                        $setClause[] = 'updated_at=CURRENT_TIMESTAMP';
                        $setClause = implode(', ', $setClause);

                        $stmt = $pdo->prepare("
                            INSERT INTO sales_out_products (" . implode(', ', array_map(function($c) { return "`$c`"; }, $cols)) . ")
                            VALUES ($placeholders)
                            ON DUPLICATE KEY UPDATE $setClause
                        ");

                        $count = 0;
                        while (($row = fgetcsv($handle)) !== false) {
                            if (empty(trim($row[$colMap['sku']] ?? ''))) continue;
                            $vals = [];
                            foreach ($cols as $c) {
                                $idx = $colMap[$c];
                                $v = trim($row[$idx] ?? '');
                                if (in_array($c, ['msrp', 'trade_price']) && $v !== '') {
                                    $v = parseDecimalValue($v);
                                }
                                $vals[] = $v ?: null;
                            }
                            try {
                                $stmt->execute($vals);
                                $count++;
                            } catch (PDOException $e) {
                                if (strpos($e->getMessage(), 'Unknown column') !== false) {
                                    $error = 'Database needs migration. Run salesout/install/migrate_epos_products.sql in phpMyAdmin.';
                                    break;
                                }
                            }
                        }
                        fclose($handle);
                        if (empty($error)) {
                            $format = $isEpos ? 'EPOS' : 'simple';
                            $message = "Imported $count products ($format format).";
                        }
                    }
                }
            }
        } else {
            $error = 'Please select a CSV file.';
        }
    } elseif (isset($_POST['import_old_skus'])) {
        if (!empty($_FILES['old_skus_file']['tmp_name']) && $_FILES['old_skus_file']['error'] === UPLOAD_ERR_OK) {
            try {
                require_once __DIR__ . '/bootstrap.php';
                $file = $_FILES['old_skus_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
                    throw new Exception('Upload Excel (.xlsx, .xls) or CSV.');
                }
                $rows = [];
                if (in_array($ext, ['xlsx', 'xls'])) {
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
                    $reader->setReadDataOnly(true);
                    $sheet = $reader->load($file['tmp_name'])->getActiveSheet();
                    $rows = $sheet->toArray();
                } else {
                    $h = fopen($file['tmp_name'], 'r');
                    while (($r = fgetcsv($h)) !== false) $rows[] = $r;
                    fclose($h);
                }
                if (empty($rows)) throw new Exception('File is empty.');
                $headers = array_map('trim', array_map('strtolower', $rows[0]));
                $dataRows = array_slice($rows, 1);
                $col = function($n) { return array_search(strtolower($n), $headers); };
                $idx = [
                    'sku' => $col('code') !== false ? $col('code') : $col('sku'),
                    'name' => $col('name') !== false ? $col('name') : $col('product name'),
                    'group' => $col('group'),
                    'category' => $col('category'),
                    'msrp' => $col('msrp'),
                    'trade' => $col('trade') !== false ? $col('trade') : $col('trade price'),
                    'ean' => $col('ean') !== false ? $col('ean') : $col('ean code'),
                    'upc' => $col('upc') !== false ? $col('upc') : $col('upc code'),
                ];
                if ($idx['sku'] === false) throw new Exception('File must have a Code or SKU column.');
                $stmt = $pdo->prepare("
                    INSERT INTO sales_out_products (sku, product_name, product_category, product_line, msrp, trade_price, currency, ean_code, upc_code)
                    VALUES (?, ?, ?, ?, ?, ?, 'GBP', ?, ?)
                    ON DUPLICATE KEY UPDATE
                        product_name=COALESCE(VALUES(product_name), product_name),
                        product_category=COALESCE(VALUES(product_category), product_category),
                        product_line=COALESCE(VALUES(product_line), product_line),
                        msrp=COALESCE(VALUES(msrp), msrp),
                        trade_price=COALESCE(VALUES(trade_price), trade_price),
                        currency=COALESCE(VALUES(currency), currency),
                        ean_code=COALESCE(VALUES(ean_code), ean_code),
                        upc_code=COALESCE(VALUES(upc_code), upc_code),
                        updated_at=CURRENT_TIMESTAMP
                ");
                $count = 0;
                foreach ($dataRows as $row) {
                    $sku = trim((string)($row[$idx['sku']] ?? ''));
                    if ($sku === '' || $sku === 'nan') continue;
                    $sku = preg_replace('/\.0+$/', '', $sku);
                    $name = $idx['name'] !== false ? trim($row[$idx['name']] ?? '') : '';
                    $cat = $idx['category'] !== false ? trim($row[$idx['category']] ?? '') : null;
                    $group = $idx['group'] !== false ? trim($row[$idx['group']] ?? '') : null;
                    $msrp = $idx['msrp'] !== false && trim((string)($row[$idx['msrp']] ?? '')) !== '' ? parseDecimalValue((string)$row[$idx['msrp']]) : null;
                    $trade = $idx['trade'] !== false && trim((string)($row[$idx['trade']] ?? '')) !== '' ? parseDecimalValue((string)$row[$idx['trade']]) : null;
                    $ean = $idx['ean'] !== false ? trim(preg_replace('/\.0+$/', '', (string)($row[$idx['ean']] ?? ''))) : null;
                    $upc = $idx['upc'] !== false ? trim(preg_replace('/\.0+$/', '', (string)($row[$idx['upc']] ?? ''))) : null;
                    if ($ean === '' || $ean === 'nan') $ean = null;
                    if ($upc === '' || $upc === 'nan') $upc = null;
                    $stmt->execute([$sku, $name ?: null, $cat ?: null, $group ?: null, $msrp, $trade, $ean, $upc]);
                    $count++;
                }
                $message = "Imported $count old/legacy SKUs into product master. Unmatched sales rows using these SKUs will now resolve.";
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = 'Please select a file.';
        }
    } elseif (isset($_POST['update_product'])) {
        $id = (int) ($_POST['update_id'] ?? 0);
        if ($id > 0) {
            try {
                $msrp = trim($_POST['msrp'] ?? '') !== '' ? parseDecimalValue($_POST['msrp']) : null;
                $tradePrice = trim($_POST['trade_price'] ?? '') !== '' ? parseDecimalValue($_POST['trade_price']) : null;
                $currency = trim($_POST['currency'] ?? '');
                $imageUrl = trim($_POST['image_url'] ?? '') ?: null;
                $hasImageCol = (bool) $pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_url'")->fetch();
                $stmt = $pdo->prepare("
                    UPDATE sales_out_products SET
                        product_name = ?, product_category = ?, brand = ?,
                        msrp = ?, trade_price = ?, currency = ?
                        " . ($hasImageCol ? ", image_url = ?" : "") . "
                    WHERE id = ?
                ");
                $params = [
                    trim($_POST['product_name'] ?? '') ?: null,
                    trim($_POST['product_category'] ?? '') ?: null,
                    trim($_POST['brand'] ?? '') ?: null,
                    $msrp,
                    $tradePrice,
                    $currency ?: null
                ];
                if ($hasImageCol) {
                    $params[] = $imageUrl;
                }
                $params[] = $id;
                $stmt->execute($params);
                $message = 'Product updated.';
            } catch (PDOException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif (isset($_POST['bulk_update'])) {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_filter(array_map('intval', $_POST['ids'])) : [];
        $stayFiltered = !empty($_POST['filter_query']);
        $redirectQuery = trim($_POST['filter_query'] ?? '');
        if (empty($ids)) {
            $error = 'Select one or more products to update.';
        } elseif (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $bulkDone = false;
            try {
                if (isset($_POST['bulk_set_currency']) && trim($_POST['bulk_currency_value'] ?? '') !== '') {
                    $curr = strtoupper(substr(trim($_POST['bulk_currency_value']), 0, 3));
                    $stmt = $pdo->prepare("UPDATE sales_out_products SET currency = ? WHERE id IN ($placeholders)");
                    $stmt->execute(array_merge([$curr], $ids));
                    $message = count($ids) . ' product(s) updated: currency set to ' . htmlspecialchars($curr) . '.';
                    $bulkDone = true;
                } elseif (isset($_POST['bulk_set_trade_from_msrp'])) {
                    $stmt = $pdo->prepare("UPDATE sales_out_products SET trade_price = ROUND(COALESCE(msrp, 0) * 0.55, 2) WHERE id IN ($placeholders) AND (msrp IS NOT NULL AND msrp > 0)");
                    $stmt->execute($ids);
                    $message = $stmt->rowCount() . ' product(s) updated: trade price set from MSRP (55%).';
                    $bulkDone = true;
                } elseif (isset($_POST['bulk_set_msrp']) && trim($_POST['bulk_msrp_value'] ?? '') !== '') {
                    $val = parseDecimalValue(trim($_POST['bulk_msrp_value']));
                    if ($val !== null) {
                        $stmt = $pdo->prepare("UPDATE sales_out_products SET msrp = ? WHERE id IN ($placeholders)");
                        $stmt->execute(array_merge([$val], $ids));
                        $message = count($ids) . ' product(s) updated: MSRP set to ' . htmlspecialchars($val) . '.';
                        $bulkDone = true;
                    }
                } elseif (isset($_POST['bulk_set_trade']) && trim($_POST['bulk_trade_value'] ?? '') !== '') {
                    $val = parseDecimalValue(trim($_POST['bulk_trade_value']));
                    if ($val !== null) {
                        $stmt = $pdo->prepare("UPDATE sales_out_products SET trade_price = ? WHERE id IN ($placeholders)");
                        $stmt->execute(array_merge([$val], $ids));
                        $message = count($ids) . ' product(s) updated: trade price set to ' . htmlspecialchars($val) . '.';
                        $bulkDone = true;
                    }
                } elseif (isset($_POST['bulk_set_series']) && trim($_POST['bulk_series_value'] ?? '') !== '') {
                    $val = trim($_POST['bulk_series_value']);
                    $stmt = $pdo->prepare("UPDATE sales_out_products SET product_series = ? WHERE id IN ($placeholders)");
                    $stmt->execute(array_merge([$val], $ids));
                    $message = count($ids) . ' product(s) updated: Series set to ' . htmlspecialchars($val) . '.';
                    $bulkDone = true;
                } elseif (isset($_POST['bulk_set_line']) && trim($_POST['bulk_line_value'] ?? '') !== '') {
                    $val = trim($_POST['bulk_line_value']);
                    $stmt = $pdo->prepare("UPDATE sales_out_products SET product_line = ? WHERE id IN ($placeholders)");
                    $stmt->execute(array_merge([$val], $ids));
                    $message = count($ids) . ' product(s) updated: Line set to ' . htmlspecialchars($val) . '.';
                    $bulkDone = true;
                } elseif (isset($_POST['bulk_set_type']) && trim($_POST['bulk_type_value'] ?? '') !== '') {
                    $val = trim($_POST['bulk_type_value']);
                    $stmt = $pdo->prepare("UPDATE sales_out_products SET product_type = ? WHERE id IN ($placeholders)");
                    $stmt->execute(array_merge([$val], $ids));
                    $message = count($ids) . ' product(s) updated: Type set to ' . htmlspecialchars($val) . '.';
                    $bulkDone = true;
                }
                if ($bulkDone && $stayFiltered && $redirectQuery !== '') {
                    header('Location: products.php?' . $redirectQuery . '&updated=1');
                    exit;
                }
            } catch (PDOException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

if (isset($_GET['updated'])) {
    $message = 'Selected products updated.';
}
$filterMissing = (isset($_GET['filter_missing']) && $_GET['filter_missing'] !== '') || !empty($_POST['filter_missing']);
$filterSeries = trim($_GET['filter_series'] ?? $_POST['filter_series'] ?? '');
$filterLine   = trim($_GET['filter_line'] ?? $_POST['filter_line'] ?? '');
$filterType  = trim($_GET['filter_type'] ?? $_POST['filter_type'] ?? '');
define('PRODUCTS_FILTER_EMPTY', '__empty__');

// Distinct values for header filter dropdowns
$distinctSeries = $pdo->query("SELECT DISTINCT product_series FROM sales_out_products WHERE product_series IS NOT NULL AND TRIM(product_series) != '' ORDER BY product_series")->fetchAll(PDO::FETCH_COLUMN);
$distinctLine   = $pdo->query("SELECT DISTINCT product_line FROM sales_out_products WHERE product_line IS NOT NULL AND TRIM(product_line) != '' ORDER BY product_line")->fetchAll(PDO::FETCH_COLUMN);
$distinctType   = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(TRIM(product_type),''), NULLIF(TRIM(product_category),''), 'Other') as t FROM sales_out_products HAVING t IS NOT NULL AND t != '' ORDER BY t")->fetchAll(PDO::FETCH_COLUMN);

$sql = "SELECT * FROM sales_out_products";
$params = [];
$where = [];
if ($filterMissing) {
    $where[] = "(
        (msrp IS NULL OR msrp = 0)
        OR (trade_price IS NULL OR trade_price = 0)
        OR (currency IS NULL OR TRIM(COALESCE(currency,'')) = '')
        OR (product_name IS NULL OR TRIM(COALESCE(product_name,'')) = '')
    )";
}
if ($filterSeries !== '') {
    if ($filterSeries === PRODUCTS_FILTER_EMPTY) {
        $where[] = "(product_series IS NULL OR TRIM(COALESCE(product_series,'')) = '')";
    } else {
        $where[] = "product_series = ?";
        $params[] = $filterSeries;
    }
}
if ($filterLine !== '') {
    if ($filterLine === PRODUCTS_FILTER_EMPTY) {
        $where[] = "(product_line IS NULL OR TRIM(COALESCE(product_line,'')) = '')";
    } else {
        $where[] = "product_line = ?";
        $params[] = $filterLine;
    }
}
if ($filterType !== '') {
    if ($filterType === PRODUCTS_FILTER_EMPTY || $filterType === 'Other') {
        $where[] = "((product_type IS NULL OR TRIM(COALESCE(product_type,'')) = '') AND (product_category IS NULL OR TRIM(COALESCE(product_category,'')) = ''))";
    } else {
        $where[] = "(COALESCE(NULLIF(TRIM(product_type),''), NULLIF(TRIM(product_category),'')) = ?)";
        $params[] = $filterType;
    }
}
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY sku LIMIT 2000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasActiveFilter = $filterMissing || $filterSeries !== '' || $filterLine !== '' || $filterType !== '';
$filterQuery = http_build_query(array_filter([
    'filter_missing' => $filterMissing ? '1' : null,
    'filter_series'  => $filterSeries !== '' ? $filterSeries : null,
    'filter_line'    => $filterLine !== '' ? $filterLine : null,
    'filter_type'    => $filterType !== '' ? $filterType : null,
]));

require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4">
    <h1>Product Master (SKU Lookup)</h1>
    <p class="text-muted">Import EPOS product lists (EPOS_GBP_Products.csv) or simple CSV (SKU, Name, Category, Brand)</p>
    
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    
    <?php
    $hasImageCol = (bool) $pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_url'")->fetch();
    ?>
    <div class="card mt-4">
        <div class="card-header">Add Product</div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">SKU *</label>
                    <input type="text" name="sku" class="form-control" required placeholder="Manufacturer part no">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Product Name</label>
                    <input type="text" name="product_name" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Category</label>
                    <input type="text" name="product_category" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Brand</label>
                    <input type="text" name="brand" class="form-control">
                </div>
                <div class="col-12"></div>
                <div class="col-md-2">
                    <label class="form-label">MSRP</label>
                    <input type="text" name="msrp" class="form-control" placeholder="0.00">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Trade Price</label>
                    <input type="text" name="trade_price" class="form-control" placeholder="0.00">
                </div>
                <div class="col-md-1">
                    <label class="form-label">Currency</label>
                    <input type="text" name="currency" class="form-control" placeholder="GBP" maxlength="3">
                </div>
                <?php if ($hasImageCol): ?>
                <div class="col-md-5">
                    <label class="form-label">Image URL</label>
                    <input type="url" name="image_url" class="form-control" placeholder="https://...">
                </div>
                <?php else: ?>
                <div class="col-md-5 d-flex align-items-end">
                    <small class="text-muted">Run <code>install/icecat_images.sql</code> to add Image URL field.</small>
                </div>
                <?php endif; ?>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="add_product" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>
    
    <div class="card mt-4">
        <div class="card-header">Import from CSV</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <p class="text-muted small">
                    <strong>EPOS format</strong> (e.g. EPOS_GBP_Products.csv): Product SKU, Product Name, MSRP, Currency, Trade Price, Product Series, Product Line, Product Type, etc.<br>
                    <strong>Simple format</strong>: SKU, Product Name, Category, Brand
                </p>
                <input type="file" name="products_csv" class="form-control" accept=".csv">
                <button type="submit" name="import_csv" class="btn btn-secondary mt-2">Import</button>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Import Old / Legacy SKUs</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <p class="text-muted small">
                    Add outdated or legacy SKUs to the product master so historical sales data can match. Expects columns: <strong>Code</strong> (SKU), <strong>Name</strong>, <strong>Group</strong>, <strong>Category</strong>, <strong>MSRP</strong>, <strong>Trade</strong>, <strong>EAN</strong>, <strong>UPC</strong> (e.g. old_skus.xlsx).
                </p>
                <input type="file" name="old_skus_file" class="form-control" accept=".xlsx,.xls,.csv">
                <button type="submit" name="import_old_skus" class="btn btn-secondary mt-2">Import Old SKUs</button>
            </form>
        </div>
    </div>
    
    <div class="card mt-4">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span>Products (<?= count($products) ?>)</span>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if ($hasActiveFilter): ?>
                <a href="products.php" class="btn btn-sm btn-outline-secondary">Show all</a>
                <?php if ($filterMissing): ?><span class="badge bg-warning text-dark">Missing cells</span><?php endif; ?>
                <?php else: ?>
                <span class="text-muted small">Filter by headers below to select and bulk-edit rows.</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body border-bottom bg-light">
            <form method="GET" class="row g-2 align-items-end flex-wrap" id="filterForm">
                <div class="col-auto">
                    <label class="form-label small mb-0">Series</label>
                    <select name="filter_series" class="form-select form-select-sm" style="width:auto;min-width:8em" onchange="this.form.submit()">
                        <option value="">(any)</option>
                        <option value="<?= htmlspecialchars(PRODUCTS_FILTER_EMPTY) ?>" <?= $filterSeries === PRODUCTS_FILTER_EMPTY ? 'selected' : '' ?>>— empty —</option>
                        <?php foreach ($distinctSeries as $v): ?>
                        <option value="<?= htmlspecialchars($v) ?>" <?= $filterSeries === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Line</label>
                    <select name="filter_line" class="form-select form-select-sm" style="width:auto;min-width:8em" onchange="this.form.submit()">
                        <option value="">(any)</option>
                        <option value="<?= htmlspecialchars(PRODUCTS_FILTER_EMPTY) ?>" <?= $filterLine === PRODUCTS_FILTER_EMPTY ? 'selected' : '' ?>>— empty —</option>
                        <?php foreach ($distinctLine as $v): ?>
                        <option value="<?= htmlspecialchars($v) ?>" <?= $filterLine === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Type</label>
                    <select name="filter_type" class="form-select form-select-sm" style="width:auto;min-width:8em" onchange="this.form.submit()">
                        <option value="">(any)</option>
                        <option value="<?= htmlspecialchars(PRODUCTS_FILTER_EMPTY) ?>" <?= $filterType === PRODUCTS_FILTER_EMPTY ? 'selected' : '' ?>>— empty —</option>
                        <?php foreach ($distinctType as $v): ?>
                        <option value="<?= htmlspecialchars($v) ?>" <?= $filterType === $v ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">&nbsp;</label>
                    <label class="d-flex align-items-center gap-1 small">
                        <input type="checkbox" name="filter_missing" value="1" <?= $filterMissing ? 'checked' : '' ?> onchange="this.form.submit()">
                        Missing cells only
                    </label>
                </div>
            </form>
        </div>
        <?php if ($hasActiveFilter && !empty($products)): ?>
        <div class="card-body border-bottom bg-light">
            <form method="POST" id="bulkForm" action="products.php<?= $filterQuery !== '' ? '?' . htmlspecialchars($filterQuery) : '' ?>" class="row g-2 align-items-end flex-wrap">
                <input type="hidden" name="bulk_update" value="1">
                <input type="hidden" name="filter_query" value="<?= htmlspecialchars($filterQuery) ?>">
                <?php if ($filterSeries !== ''): ?><input type="hidden" name="filter_series" value="<?= htmlspecialchars($filterSeries) ?>"><?php endif; ?>
                <?php if ($filterLine !== ''): ?><input type="hidden" name="filter_line" value="<?= htmlspecialchars($filterLine) ?>"><?php endif; ?>
                <?php if ($filterType !== ''): ?><input type="hidden" name="filter_type" value="<?= htmlspecialchars($filterType) ?>"><?php endif; ?>
                <?php if ($filterMissing): ?><input type="hidden" name="filter_missing" value="1"><?php endif; ?>
                <div class="col-auto">
                    <label class="form-label small mb-0">Bulk update selected:</label>
                </div>
                <div class="col-auto">
                    <input type="text" name="bulk_series_value" class="form-control form-control-sm" placeholder="Series" style="width:6em">
                    <button type="submit" name="bulk_set_series" class="btn btn-sm btn-outline-secondary mt-1">Set Series</button>
                </div>
                <div class="col-auto">
                    <input type="text" name="bulk_line_value" class="form-control form-control-sm" placeholder="Line" style="width:6em">
                    <button type="submit" name="bulk_set_line" class="btn btn-sm btn-outline-secondary mt-1">Set Line</button>
                </div>
                <div class="col-auto">
                    <input type="text" name="bulk_type_value" class="form-control form-control-sm" placeholder="Type" style="width:6em">
                    <button type="submit" name="bulk_set_type" class="btn btn-sm btn-outline-secondary mt-1">Set Type</button>
                </div>
                <div class="col-auto">
                    <input type="text" name="bulk_currency_value" class="form-control form-control-sm" placeholder="GBP" maxlength="3" style="width:4em">
                    <button type="submit" name="bulk_set_currency" class="btn btn-sm btn-outline-secondary mt-1">Set currency</button>
                </div>
                <div class="col-auto">
                    <input type="text" name="bulk_msrp_value" class="form-control form-control-sm" placeholder="MSRP" style="width:5em">
                    <button type="submit" name="bulk_set_msrp" class="btn btn-sm btn-outline-secondary mt-1">Set MSRP</button>
                </div>
                <div class="col-auto">
                    <input type="text" name="bulk_trade_value" class="form-control form-control-sm" placeholder="Trade" style="width:5em">
                    <button type="submit" name="bulk_set_trade" class="btn btn-sm btn-outline-secondary mt-1">Set trade</button>
                </div>
                <div class="col-auto">
                    <button type="submit" name="bulk_set_trade_from_msrp" class="btn btn-sm btn-outline-secondary" title="Set trade = MSRP × 55% where MSRP present">Trade from MSRP (55%)</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead class="table-light"><tr>
                    <?php if ($hasActiveFilter): ?>
                    <th><input type="checkbox" id="selectAll" class="form-check-input" title="Select all"></th>
                    <?php endif; ?>
                    <th>SKU</th><th>Product Name</th><th>Series</th><th>Line</th><th>Type</th>
                    <th>MSRP</th><th>Trade</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <?php if ($hasActiveFilter): ?>
                        <td><input type="checkbox" name="ids[]" form="bulkForm" value="<?= (int)$p['id'] ?>" class="form-check-input row-cb"></td>
                        <?php endif; ?>
                        <td><code><?= htmlspecialchars($p['sku']) ?></code></td>
                        <td><?= htmlspecialchars($p['product_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['product_series'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['product_line'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['product_type'] ?? $p['product_category'] ?? '') ?></td>
                        <td><?= !empty($p['msrp']) ? htmlspecialchars($p['msrp']) . ' ' . ($p['currency'] ?? '') : '<span class="text-muted">-</span>' ?></td>
                        <td><?= !empty($p['trade_price']) ? htmlspecialchars($p['trade_price']) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= htmlspecialchars($p['product_status'] ?? '') ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="editProduct(<?= htmlspecialchars(json_encode($p)) ?>)">
                                <i class="ti ti-edit"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr><td colspan="<?= $hasActiveFilter ? 10 : 9 ?>" class="text-muted"><?= $hasActiveFilter ? 'No products match the current filters.' : 'No products yet. Import EPOS_GBP_Products.csv or add manually.' ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="update_product" value="1">
                <input type="hidden" name="update_id" id="edit_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">SKU</label>
                            <input type="text" class="form-control" id="edit_sku" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Product Name</label>
                            <input type="text" name="product_name" class="form-control" id="edit_product_name">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Brand</label>
                            <input type="text" name="brand" class="form-control" id="edit_brand">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <input type="text" name="product_category" class="form-control" id="edit_product_category">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">MSRP</label>
                            <input type="text" name="msrp" class="form-control" id="edit_msrp" placeholder="0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Trade Price</label>
                            <input type="text" name="trade_price" class="form-control" id="edit_trade_price" placeholder="0.00">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Currency</label>
                            <input type="text" name="currency" class="form-control" id="edit_currency" maxlength="3">
                        </div>
                        <?php if ($hasImageCol): ?>
                        <div class="col-12">
                            <label class="form-label">Image URL</label>
                            <input type="url" name="image_url" class="form-control" id="edit_image_url" placeholder="https://...">
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editProduct(p) {
    document.getElementById('edit_id').value = p.id || '';
    document.getElementById('edit_sku').value = p.sku || '';
    document.getElementById('edit_product_name').value = p.product_name || '';
    document.getElementById('edit_brand').value = p.brand || '';
    document.getElementById('edit_product_category').value = p.product_category || '';
    document.getElementById('edit_msrp').value = p.msrp || '';
    document.getElementById('edit_trade_price').value = p.trade_price || '';
    document.getElementById('edit_currency').value = p.currency || '';
    var img = document.getElementById('edit_image_url');
    if (img) img.value = p.image_url || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
(function() {
    var selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.row-cb').forEach(function(cb) { cb.checked = selectAll.checked; });
        });
    }
})();
</script>
</div></div>
</body>
</html>
