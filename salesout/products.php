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
            $stmt = $pdo->prepare("
                INSERT INTO sales_out_products (sku, product_name, product_category, brand)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE product_name=VALUES(product_name), product_category=VALUES(product_category), brand=VALUES(brand)
            ");
            $stmt->execute([
                trim($_POST['sku']),
                trim($_POST['product_name'] ?? ''),
                trim($_POST['product_category'] ?? ''),
                trim($_POST['brand'] ?? '')
            ]);
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
                            INSERT INTO sales_out_products (" . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ")
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
    }
}

$products = $pdo->query("SELECT * FROM sales_out_products ORDER BY sku LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4">
    <h1>Product Master (SKU Lookup)</h1>
    <p class="text-muted">Import EPOS product lists (EPOS_GBP_Products.csv) or simple CSV (SKU, Name, Category, Brand)</p>
    
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    
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
        <div class="card-header">Products (<?= count($products) ?>)</div>
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead class="table-light"><tr>
                    <th>SKU</th><th>Product Name</th><th>Series</th><th>Line</th><th>Type</th>
                    <th>MSRP</th><th>Trade</th><th>Status</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($p['sku']) ?></code></td>
                        <td><?= htmlspecialchars($p['product_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['product_series'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['product_line'] ?? '') ?></td>
                        <td><?= htmlspecialchars($p['product_type'] ?? $p['product_category'] ?? '') ?></td>
                        <td><?= !empty($p['msrp']) ? htmlspecialchars($p['msrp']) . ' ' . ($p['currency'] ?? '') : '-' ?></td>
                        <td><?= !empty($p['trade_price']) ? htmlspecialchars($p['trade_price']) : '-' ?></td>
                        <td><?= htmlspecialchars($p['product_status'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr><td colspan="8" class="text-muted">No products yet. Import EPOS_GBP_Products.csv or add manually.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div></div>
</body>
</html>
