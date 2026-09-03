<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/headset_finder_data_lib.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('headset_finder_data_manager.php'));
    exit;
}

$message = '';
$error = '';
$products = [];
$columns = [];
$thumbs = [];

try {
    $products = hf_load_products();
    $columns = hf_product_column_order($products);
    $thumbs = hf_load_epos_thumbs();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    try {
        if (isset($_POST['save_products'])) {
            $rowsPost = $_POST['rows'] ?? [];
            if (!is_array($rowsPost)) {
                throw new RuntimeException('Invalid form data');
            }
            $parsed = hf_parse_products_from_post(array_values($rowsPost), $columns);
            $validated = hf_validate_and_cast_products($parsed);
            hf_save_products($validated);
            $products = $validated;
            $message = 'Product matrix saved (../lenovocalc/headset-finder-products.json).';
        } elseif (isset($_POST['import_products'])) {
            if (!isset($_FILES['products_csv']) || $_FILES['products_csv']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please choose a CSV file to import.');
            }
            $tmp = $_FILES['products_csv']['tmp_name'];
            $imported = hf_import_products_csv($tmp);
            hf_save_products($imported);
            $products = $imported;
            $columns = hf_product_column_order($products);
            $message = 'Imported ' . count($imported) . ' rows from CSV and saved.';
        } elseif (isset($_POST['save_thumbs'])) {
            $labels = $_POST['thumb_label'] ?? [];
            $urls = $_POST['thumb_url'] ?? [];
            if (!is_array($labels) || !is_array($urls)) {
                throw new RuntimeException('Invalid thumbs form');
            }
            $map = [];
            foreach ($labels as $i => $label) {
                $label = trim((string) $label);
                $url = trim((string) ($urls[$i] ?? ''));
                if ($label === '') {
                    continue;
                }
                if ($url !== '' && strpos($url, 'http') !== 0) {
                    throw new RuntimeException('URLs must start with http(s): ' . htmlspecialchars($label));
                }
                $map[$label] = $url;
            }
            hf_save_epos_thumbs($map);
            $thumbs = $map;
            $message = 'EPOS image map saved (../lenovocalc/epos-finder-product-thumbs.json).';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$productChoices = [];
foreach ($products as $r) {
    $pc = trim((string) ($r['Product Choice'] ?? ''));
    if ($pc !== '') {
        $productChoices[] = $pc;
    }
}
$thumbKeys = array_unique(array_merge($productChoices, array_keys($thumbs)));
sort($thumbKeys, SORT_NATURAL);
$thumbRows = [];
foreach ($thumbKeys as $k) {
    $thumbRows[] = ['label' => $k, 'url' => $thumbs[$k] ?? ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Headset finder data — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
    <style>
        .hf-table-wrap { overflow-x: auto; max-width: 100%; }
        .hf-table-wrap table { font-size: 0.8rem; }
        .hf-table-wrap th { white-space: nowrap; position: sticky; top: 0; background: var(--tblr-bg-surface); z-index: 1; }
        .hf-table-wrap input.form-control, .hf-table-wrap select.form-select { min-width: 7rem; font-size: 0.8rem; }
        .hf-narrow { max-width: 5rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>
<div class="container-xl py-4">
    <div class="page-header mb-4">
        <h2 class="page-title"><i class="ti ti-headphones me-2"></i>Headset finder — manage data</h2>
        <div class="text-muted mt-2">
            Edit JSON consumed by <code>/lenovocalc/index.html</code> (repo: sibling folder <code>lenovocalc/</code>). Export to Excel (CSV or XLSX) for offline edits, then import CSV for the product matrix.
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success"><i class="ti ti-check me-2"></i><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-products">Product matrix</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-thumbs">EPOS images</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-export">Download</a></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-products">
            <div class="card mb-3">
                <div class="card-body row g-2 align-items-end">
                    <div class="col-auto">
                        <a class="btn btn-outline-primary" href="headset_finder_data_export.php?dataset=products&amp;format=csv"><i class="ti ti-download me-1"></i>CSV</a>
                        <a class="btn btn-outline-primary" href="headset_finder_data_export.php?dataset=products&amp;format=xlsx"><i class="ti ti-file-spreadsheet me-1"></i>Excel</a>
                    </div>
                    <div class="col-md-6">
                        <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
                            <input type="hidden" name="import_products" value="1" />
                            <input type="file" name="products_csv" accept=".csv,text/csv" class="form-control form-control-sm" style="max-width:280px" required />
                            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-upload me-1"></i>Replace from CSV</button>
                        </form>
                    </div>
                    <div class="col-12 text-muted small">
                        CSV must use the same column headers as the export (UTF-8). Import replaces the entire matrix — download a backup first.
                    </div>
                </div>
            </div>
            <form method="post" class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Edit rows</span>
                    <button type="submit" name="save_products" value="1" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy me-1"></i>Save matrix</button>
                </div>
                <div class="card-body p-0 hf-table-wrap">
                    <table class="table table-vcenter table-nowrap mb-0">
                        <thead>
                        <tr>
                            <th class="w-1">#</th>
                            <?php foreach ($columns as $col): ?>
                                <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $ri => $row): ?>
                            <tr>
                                <td class="text-muted"><?= $ri + 1 ?></td>
                                <?php foreach ($columns as $col): ?>
                                    <td>
                                        <?php
                                        $v = $row[$col] ?? '';
                                        $name = 'rows[' . $ri . '][' . htmlspecialchars($col, ENT_NOQUOTES, 'UTF-8') . ']';
                                        if ($col === 'recommendedFlag') {
                                            $checked = !empty($v);
                                            ?>
                                            <input type="hidden" name="rows[<?= $ri ?>][<?= htmlspecialchars($col) ?>]" value="0" />
                                            <label class="form-check m-0">
                                                <input class="form-check-input" type="checkbox" name="rows[<?= $ri ?>][<?= htmlspecialchars($col) ?>]" value="1" <?= $checked ? 'checked' : '' ?> />
                                            </label>
                                            <?php
                                        } elseif ($col === 'Local LPN') {
                                            $lpn = $v === null ? '' : (string) $v;
                                            ?>
                                            <input class="form-control form-control-sm hf-narrow" type="number" name="rows[<?= $ri ?>][<?= htmlspecialchars($col) ?>]" value="<?= htmlspecialchars($lpn) ?>" />
                                            <?php
                                        } else {
                                            $s = $v === null ? '' : (string) $v;
                                            ?>
                                            <input class="form-control form-control-sm" type="text" name="rows[<?= $ri ?>][<?= htmlspecialchars($col) ?>]" value="<?= htmlspecialchars($s) ?>" />
                                            <?php
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <p class="text-muted small mt-3">
                After saving, regenerate the static page: from repo root run <code>python lenovocalc/build_headset_finder_html.py</code> (writes <code>lenovocalc/index.html</code>).
            </p>
        </div>

        <div class="tab-pane fade" id="tab-thumbs">
            <p class="text-muted">Map each <strong>Product Choice</strong> label to an EPOS CDN image URL (<code>B2BProductImage</code> or full-size JPEG). Keys must match the matrix exactly.</p>
            <form method="post" class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>EPOS finder thumbnails</span>
                    <button type="submit" name="save_thumbs" value="1" class="btn btn-primary btn-sm"><i class="ti ti-device-floppy me-1"></i>Save URLs</button>
                </div>
                <div class="card-body p-0 hf-table-wrap">
                    <table class="table mb-0">
                        <thead><tr><th>Product Choice</th><th>Image URL</th></tr></thead>
                        <tbody>
                        <?php foreach ($thumbRows as $ti => $tr): ?>
                            <tr>
                                <td style="min-width:12rem">
                                    <input type="text" class="form-control form-control-sm" name="thumb_label[]" value="<?= htmlspecialchars($tr['label']) ?>" />
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm" name="thumb_url[]" value="<?= htmlspecialchars($tr['url']) ?>" placeholder="https://…" spellcheck="false" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <p class="text-muted small mt-2">Regenerate embedded thumbs by running <code>python lenovocalc/build_headset_finder_html.py</code> from the repo root after editing JSON.</p>
        </div>

        <div class="tab-pane fade" id="tab-export">
            <div class="card">
                <div class="card-body">
                    <h4 class="card-title">Download</h4>
                    <p class="text-muted">Use CSV for Excel; UTF-8 BOM is included. XLSX requires PhpSpreadsheet (same as salesout exports).</p>
                    <ul class="list-unstyled space-y-2">
                        <li><a class="btn btn-outline-primary me-2 mb-2" href="headset_finder_data_export.php?dataset=products&amp;format=csv">Products — CSV</a></li>
                        <li><a class="btn btn-outline-primary me-2 mb-2" href="headset_finder_data_export.php?dataset=products&amp;format=xlsx">Products — Excel</a></li>
                        <li><a class="btn btn-outline-primary me-2 mb-2" href="headset_finder_data_export.php?dataset=thumbs&amp;format=csv">EPOS image URLs — CSV</a></li>
                        <li><a class="btn btn-outline-primary me-2 mb-2" href="headset_finder_data_export.php?dataset=thumbs&amp;format=xlsx">EPOS image URLs — Excel</a></li>
                        <li><a class="btn btn-outline-primary me-2 mb-2" href="headset_finder_data_export.php?dataset=all&amp;format=zip">Everything — ZIP (two CSV files)</a></li>
                        <li><a class="btn btn-outline-primary me-2 mb-2" href="headset_finder_data_export.php?dataset=all&amp;format=xlsx">Everything — Excel (two sheets)</a></li>
                    </ul>
                    <hr class="my-4" />
                    <h5 class="mb-2">Full EPOS catalog (review)</h5>
                    <p class="text-muted small mb-2">Streaming export of <code>../lenovocalc/products/fullepos.csv</code> — all variant rows and CDN asset columns (~1M+ lines). CSV only; ensure that file is deployed with the standalone <code>/lenovocalc/</code> tree.</p>
                    <a class="btn btn-outline-secondary me-2 mb-2" href="headset_finder_data_export.php?dataset=fullepos&amp;format=csv"><i class="ti ti-database me-1"></i>Full fullepos catalog — CSV</a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
