<?php
// icecat_import.php - Import Icecat product data (images, etc.) and match to sales_out_products
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/icecat_import.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';

// Check for image columns
$hasImageCols = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_thumb'")->fetch();
    $hasImageCols = (bool)$cols;
} catch (PDOException $e) { /* ignore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['icecat_file']) && $_FILES['icecat_file']['error'] === UPLOAD_ERR_OK) {
    if (!$hasImageCols) {
        $error = 'Run install/icecat_images.sql first to add image_thumb and image_url columns.';
    } else {
        try {
            $file = $_FILES['icecat_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls'])) {
                throw new Exception('Upload an Excel file (.xlsx or .xls).');
            }

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            if (empty($rows)) throw new Exception('File is empty.');
            $headers = array_map('trim', $rows[0]);
            $dataRows = array_slice($rows, 1);

            $colIdx = fn($name) => array_search($name, $headers);
            $prodIdIdx = $colIdx('Prod_id');
            $gtinIdx = $colIdx('GTIN(EAN/UPC)');
            $thumbIdx = $colIdx('ThumbPic');
            $highIdx = $colIdx('HighPic');

            if ($thumbIdx === false && $highIdx === false) {
                throw new Exception('File must have ThumbPic or HighPic column. Ensure it is the Icecat export format.');
            }

            $updateStmt = $pdo->prepare("UPDATE sales_out_products SET image_thumb = ?, image_url = ? WHERE id = ?");
            $matched = 0;

            foreach ($dataRows as $row) {
                $thumb = trim($row[$thumbIdx] ?? $row[$highIdx ?? 0] ?? '');
                $high = trim($row[$highIdx] ?? $row[$thumbIdx ?? 0] ?? '');
                if ($thumb === '' || strtolower($thumb) === 'nan') $thumb = $high;
                if ($thumb === '' || strpos($thumb, 'http') !== 0) continue;

                $prodId = isset($row[$prodIdIdx]) ? trim(preg_replace('/\.0+$/', '', (string)$row[$prodIdIdx])) : '';
                $gtinRaw = isset($row[$gtinIdx]) ? trim((string)$row[$gtinIdx]) : '';
                $gtins = array_filter(array_map('trim', explode('|', $gtinRaw)));

                // Match by SKU (Prod_id) or EAN/UPC
                $productIds = [];
                if ($prodIdIdx !== false && $prodId !== '' && $prodId !== 'nan') {
                    $s = $pdo->prepare("SELECT id FROM sales_out_products WHERE TRIM(REPLACE(sku,' ','')) = ?");
                    $s->execute([str_replace(' ', '', $prodId)]);
                    while ($r = $s->fetch(PDO::FETCH_ASSOC)) $productIds[] = $r['id'];
                }
                if (empty($productIds) && $gtinIdx !== false && !empty($gtins)) {
                    $placeholders = implode(',', array_fill(0, count($gtins), '?'));
                    $s = $pdo->prepare("SELECT id FROM sales_out_products WHERE ean_code IN ($placeholders) OR upc_code IN ($placeholders)");
                    $s->execute(array_merge($gtins, $gtins));
                    while ($r = $s->fetch(PDO::FETCH_ASSOC)) $productIds[] = $r['id'];
                }

                $productIds = array_unique($productIds);
                foreach ($productIds as $pid) {
                    $updateStmt->execute([$thumb, $high ?: $thumb, $pid]);
                    if ($updateStmt->rowCount() > 0) $matched++;
                }
            }

            $message = "Imported " . count($dataRows) . " Icecat rows. Updated images for $matched product(s).";
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/header.php';
?>
<style>
.icecat-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 10px; }
.card-epos { border-radius: 10px; border: 1px solid #D7D2CB; box-shadow: 0 1px 3px rgba(0,0,0,0.05); background: white; }
.card-epos .card-header { background: rgb(238, 239, 241); border-bottom: 1px solid #D7D2CB; padding: 1rem 1.25rem; font-weight: 600; }
</style>

<div class="container-xl py-4">
    <div class="icecat-header">
        <h1 class="h2 mb-1"><i class="ti ti-photo me-2"></i>Icecat Product Images</h1>
        <p class="mb-0 opacity-75">Import icecat_data.xlsx to add product thumbnails. Matches by SKU (Prod_id) or EAN/UPC. Run <code>install/icecat_images.sql</code> first if needed.</p>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$hasImageCols): ?>
    <div class="alert alert-warning"><i class="ti ti-alert-triangle me-2"></i>Run <code>salesout/install/icecat_images.sql</code> in phpMyAdmin to add image_thumb and image_url columns to sales_out_products.</div>
    <?php endif; ?>

    <div class="card-epos">
        <div class="card-header">Upload Icecat Excel</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">icecat_data.xlsx</label>
                        <input type="file" name="icecat_file" class="form-control" accept=".xlsx,.xls" required>
                        <small class="text-muted">Uses Prod_id, GTIN(EAN/UPC), ThumbPic, HighPic columns.</small>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary" <?= !$hasImageCols ? 'disabled' : '' ?>>Import</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</div></div>
</body>
</html>
