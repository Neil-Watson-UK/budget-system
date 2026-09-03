<?php
// salesout/mapping.php - Reseller to Vendor mapping
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/mapping.php'));
    exit;
}

$pdo = getDBConnection();
$message = '';
$error = '';
$showMapSimilar = [];
$mapSimilarVendorId = null;
$mapSimilarBaseReseller = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_mapping'])) {
        try {
            $reseller = trim($_POST['reseller_name']);
            $vendorId = (int) $_POST['vendor_id'];
            $stmt = $pdo->prepare("
                INSERT INTO sales_out_reseller_mapping (reseller_name_raw, vendor_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id)
            ");
            $stmt->execute([$reseller, $vendorId]);
            $message = 'Mapping saved.';
            $showMapSimilar = findSimilarUnmatchedResellers($pdo, $reseller);
            if (!empty($showMapSimilar)) {
                $mapSimilarVendorId = $vendorId;
                $mapSimilarBaseReseller = $reseller;
            }
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['approve_mapping'])) {
        try {
            $reseller = trim($_POST['reseller_name'] ?? '');
            $vendorId = (int) ($_POST['vendor_id'] ?? 0);
            if ($reseller && $vendorId) {
                $stmt = $pdo->prepare("
                    INSERT INTO sales_out_reseller_mapping (reseller_name_raw, vendor_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id)
                ");
                $stmt->execute([$reseller, $vendorId]);
                $message = 'Proposed mapping approved: ' . htmlspecialchars($reseller) . ' → vendor.';
            }
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['approve_mappings_bulk'])) {
        try {
            $pairs = $_POST['bulk_pairs'] ?? [];
            $count = 0;
            $stmt = $pdo->prepare("
                INSERT INTO sales_out_reseller_mapping (reseller_name_raw, vendor_id)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id)
            ");
            foreach ($pairs as $p) {
                $parts = explode('|', $p, 2);
                if (count($parts) === 2 && trim($parts[0]) && (int)$parts[1]) {
                    $stmt->execute([trim($parts[0]), (int)$parts[1]]);
                    $count++;
                }
            }
            if ($count) $message = "Approved $count proposed mapping(s).";
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['map_similar'])) {
        try {
            $baseReseller = trim($_POST['base_reseller'] ?? '');
            $vendorId = (int) ($_POST['vendor_id'] ?? 0);
            $similar = $_POST['similar_resellers'] ?? [];
            if ($baseReseller && $vendorId && is_array($similar)) {
                $stmt = $pdo->prepare("
                    INSERT INTO sales_out_reseller_mapping (reseller_name_raw, vendor_id)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id)
                ");
                $stmt->execute([$baseReseller, $vendorId]);
                $count = 1;
                foreach ($similar as $r) {
                    $r = trim($r);
                    if ($r && $r !== $baseReseller) {
                        $stmt->execute([$r, $vendorId]);
                        $count++;
                    }
                }
                $message = "Mapped $count reseller(s) to vendor.";
            }
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    } elseif (isset($_POST['delete_mapping'])) {
        $pdo->prepare("DELETE FROM sales_out_reseller_mapping WHERE id = ?")->execute([(int) $_POST['id']]);
        $message = 'Mapping removed.';
    } elseif (isset($_POST['reapply_mappings'])) {
        try {
            require_once __DIR__ . '/functions.php';
            $pdo->exec("
                UPDATE sales_out_raw r
                INNER JOIN sales_out_reseller_mapping m ON LOWER(TRIM(m.reseller_name_raw)) = LOWER(TRIM(r.reseller_name))
                SET r.matched_vendor_id = m.vendor_id
                WHERE r.reseller_name != ''
            ");
            $unmatched = $pdo->query("SELECT DISTINCT reseller_name FROM sales_out_raw WHERE reseller_name != '' AND matched_vendor_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($unmatched as $r) {
                $vid = matchResellerToVendor($pdo, $r);
                if ($vid) {
                    $pdo->prepare("UPDATE sales_out_raw SET matched_vendor_id = ? WHERE reseller_name = ? AND matched_vendor_id IS NULL")->execute([$vid, $r]);
                }
            }
            // Sync Salesforce IDs from vendors into sales_out_raw for SF lookups
            $pdo->exec("
                UPDATE sales_out_raw s
                INNER JOIN vendors v ON s.matched_vendor_id = v.id
                SET s.salesforce_id = v.salesforce_id
                WHERE s.matched_vendor_id IS NOT NULL
            ");
            $message = 'Mappings reapplied. Existing data now reflects current reseller→vendor mappings and Salesforce IDs.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$mappings = $pdo->query("
    SELECT m.*, v.vendor_name, v.salesforce_id 
    FROM sales_out_reseller_mapping m 
    JOIN vendors v ON m.vendor_id = v.id 
    ORDER BY m.reseller_name_raw
")->fetchAll(PDO::FETCH_ASSOC);

$vendors = $pdo->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name")->fetchAll(PDO::FETCH_ASSOC);

// Proposed auto-mappings (similarity-based)
$proposed = proposeMappingsForUnmatched($pdo, 30, 65);

// Unmatched resellers from raw data
$unmatched = $pdo->query("
    SELECT reseller_name, COUNT(*) as cnt, SUM(total_value) as total
    FROM sales_out_raw 
    WHERE reseller_name != '' AND matched_vendor_id IS NULL
    GROUP BY reseller_name ORDER BY total DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/header.php';
?>
<style>
.mapping-page .form-control, .mapping-page .form-select, .mapping-page #resellerDropdown, .mapping-page #vendorDropdown { background-color: #fff !important; }
</style>
<div class="container-xl py-4 mapping-page">
    <h1>Reseller Mapping</h1>
    <p class="text-muted">Map distributor reseller names to known vendors for aggregation. Different reseller spellings (e.g. "Acme Ltd" vs "ACME UK") can map to the same vendor — sales are then aggregated across distributors.</p>
    
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    
    <div class="card mt-4">
        <div class="card-header">Add Mapping</div>
        <div class="card-body">
            <p class="text-muted small mb-3">Type a reseller name from your reports — suggestions appear from all distributors. Pick one, then map it to a vendor. If that reseller is already mapped, the vendor will pre-fill. Different reseller names (e.g. "Acme Ltd" vs "ACME UK") can all map to the same vendor for aggregation.</p>
            <form method="POST" class="row g-3" id="mappingForm">
                <div class="col-md-4 position-relative">
                    <label class="form-label">Reseller Name (as in report)</label>
                    <input type="text" name="reseller_name" id="resellerInput" class="form-control" required autocomplete="off" placeholder="e.g. Acme Ltd">
                    <div id="resellerDropdown" class="list-group position-absolute start-0 end-0 mt-1 shadow" style="z-index:1050;max-height:240px;overflow-y:auto;display:none"></div>
                </div>
                <div class="col-md-4 position-relative">
                    <label class="form-label">Map to Vendor</label>
                    <input type="text" id="vendorDisplay" class="form-control" autocomplete="off" placeholder="Type to search vendors...">
                    <input type="hidden" name="vendor_id" id="vendorId">
                    <div id="vendorDropdown" class="list-group position-absolute start-0 end-0 mt-1 shadow" style="z-index:1050;max-height:240px;overflow-y:auto;display:none"></div>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" name="add_mapping" class="btn btn-primary">Add</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!empty($showMapSimilar)): ?>
    <div class="card mt-4 border-success">
        <div class="card-header bg-success bg-opacity-10 text-success"><i class="ti ti-bulb me-2"></i>Map similar resellers</div>
        <div class="card-body">
            <p class="mb-3">These share a key word with "<strong><?= htmlspecialchars($mapSimilarBaseReseller) ?></strong>". Select which ones to map:</p>
            <form method="POST">
                <input type="hidden" name="map_similar" value="1">
                <input type="hidden" name="base_reseller" value="<?= htmlspecialchars($mapSimilarBaseReseller) ?>">
                <input type="hidden" name="vendor_id" value="<?= (int)$mapSimilarVendorId ?>">
                <div class="mb-3">
                    <?php foreach ($showMapSimilar as $s): ?>
                    <div class="form-check form-check-inline mb-2">
                        <input class="form-check-input" type="checkbox" name="similar_resellers[]" value="<?= htmlspecialchars($s['reseller_name']) ?>" id="similar_<?= htmlspecialchars(md5($s['reseller_name'])) ?>" checked>
                        <label class="form-check-label" for="similar_<?= htmlspecialchars(md5($s['reseller_name'])) ?>">
                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($s['reseller_name']) ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <button type="submit" class="btn btn-success">Map selected</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-2" onclick="document.querySelectorAll('input[name=\'similar_resellers[]\']').forEach(c=>c.checked=true)">Select all</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-1" onclick="document.querySelectorAll('input[name=\'similar_resellers[]\']').forEach(c=>c.checked=false)">Deselect all</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($proposed)): ?>
    <div class="card mt-4">
        <div class="card-header bg-primary bg-opacity-10"><i class="ti ti-sparkles me-2"></i>Proposed Auto-Mappings</div>
        <div class="card-body">
            <form method="POST">
                <table class="table table-sm table-hover">
                    <tr class="table-light"><th>Reseller</th><th>→ Vendor</th><th>Conf.</th><th>Approve</th></tr>
                    <?php foreach ($proposed as $p): ?>
                    <tr>
                        <td><?= htmlspecialchars($p['reseller_name']) ?></td>
                        <td><?= htmlspecialchars($p['vendor_name']) ?></td>
                        <td><span class="badge <?= $p['confidence'] >= 85 ? 'bg-success' : 'bg-primary' ?>"><?= $p['confidence'] ?>%</span></td>
                        <td>
                            <input class="form-check-input" type="checkbox" name="bulk_pairs[]" value="<?= htmlspecialchars($p['reseller_name']) ?>|<?= $p['vendor_id'] ?>">
                            <form method="POST" class="d-inline ms-1">
                                <input type="hidden" name="approve_mapping" value="1">
                                <input type="hidden" name="reseller_name" value="<?= htmlspecialchars($p['reseller_name']) ?>">
                                <input type="hidden" name="vendor_id" value="<?= $p['vendor_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Approve this one">+</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <button type="submit" name="approve_mappings_bulk" class="btn btn-primary">Approve selected</button>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-2" onclick="document.querySelectorAll('input[name=\'bulk_pairs[]\']').forEach(c=>c.checked=true)">Select all</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-body">
            <form method="POST" class="d-inline" onsubmit="return confirm('Reapply all mappings to existing sales data? This updates matched_vendor_id on all rows.');">
                <button type="submit" name="reapply_mappings" class="btn btn-outline-secondary">
                    <i class="ti ti-refresh me-1"></i> Reapply mappings to existing data
                </button>
            </form>
            <small class="text-muted ms-2">Use after adding new mappings to update already-imported rows.</small>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Current Mappings</div>
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered">
                        <thead class="table-light"><tr><th>Reseller (raw)</th><th>→ Vendor</th><th>Salesforce ID</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($mappings as $m): ?>
                            <tr>
                                <td><?= htmlspecialchars($m['reseller_name_raw']) ?></td>
                                <td><?= htmlspecialchars($m['vendor_name']) ?></td>
                                <td><?php if (!empty($m['salesforce_id'])): ?><code class="small"><?= htmlspecialchars($m['salesforce_id']) ?></code><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                <td>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove?')">
                                        <input type="hidden" name="id" value="<?= $m['id'] ?>">
                                        <button type="submit" name="delete_mapping" class="btn btn-sm btn-outline-danger">×</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($mappings)): ?>
                            <tr><td colspan="4" class="text-muted">No mappings yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Unmatched Resellers (top by value)</div>
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered">
                        <thead class="table-light"><tr><th>Reseller</th><th>Rows</th><th>Value</th></tr></thead>
                        <tbody>
                            <?php foreach ($unmatched as $u): ?>
                            <tr>
                                <td><?= htmlspecialchars($u['reseller_name']) ?></td>
                                <td><?= $u['cnt'] ?></td>
                                <td>£<?= number_format($u['total'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($unmatched)): ?>
                            <tr><td colspan="3" class="text-muted">All resellers matched.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const api = 'mapping_api.php';
    let resellerDebounce, vendorDebounce;

    const resellerInput = document.getElementById('resellerInput');
    const resellerDropdown = document.getElementById('resellerDropdown');
    const vendorDisplay = document.getElementById('vendorDisplay');
    const vendorId = document.getElementById('vendorId');
    const vendorDropdown = document.getElementById('vendorDropdown');
    const form = document.getElementById('mappingForm');

    function hideAll() {
        resellerDropdown.style.display = 'none';
        vendorDropdown.style.display = 'none';
    }

    function showResellerResults(items) {
        if (!items.length) { hideAll(); return; }
        resellerDropdown.innerHTML = items.map(r => {
            let badge = '';
            if (r.mapped_vendor_name) badge = '<span class="badge bg-success ms-2">→ ' + escapeHtml(r.mapped_vendor_name) + '</span>';
            return '<a href="#" class="list-group-item list-group-item-action" data-name="' + escapeHtml(r.reseller_name) + '" data-vendor-id="' + (r.mapped_vendor_id || '') + '" data-vendor-name="' + escapeHtml(r.mapped_vendor_name || '') + '">' + escapeHtml(r.reseller_name) + badge + '</a>';
        }).join('');
        resellerDropdown.style.display = 'block';
        vendorDropdown.style.display = 'none';
        resellerDropdown.querySelectorAll('a').forEach(a => {
            a.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                resellerInput.value = a.dataset.name;
                vendorId.value = a.dataset.vendorId || '';
                vendorDisplay.value = a.dataset.vendorName || '';
                hideAll();
                if (!a.dataset.vendorId && a.dataset.name) fetchSuggestVendors(a.dataset.name);
            });
        });
    }

    function showVendorResults(items) {
        if (!items.length) { vendorDropdown.style.display = 'none'; return; }
        vendorDropdown.innerHTML = items.map(v => 
            '<a href="#" class="list-group-item list-group-item-action" data-id="' + v.id + '" data-name="' + escapeHtml(v.vendor_name) + '">' + escapeHtml(v.vendor_name) + '</a>'
        ).join('');
        vendorDropdown.style.display = 'block';
        resellerDropdown.style.display = 'none';
        vendorDropdown.querySelectorAll('a').forEach(a => {
            a.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                vendorDisplay.value = a.dataset.name;
                vendorId.value = a.dataset.id;
                hideAll();
            });
        });
    }

    function escapeHtml(s) {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function fetchResellers(q) {
        if (q.length < 2) { hideAll(); return; }
        fetch(api + '?type=reseller&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(d => showResellerResults(d.results || []));
    }

    function fetchVendors(q) {
        if (q.length < 1) { vendorDropdown.style.display = 'none'; return; }
        fetch(api + '?type=vendor&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(d => showVendorResults(d.results || []));
    }

    function fetchSuggestVendors(reseller) {
        fetch(api + '?type=suggest_vendors&reseller=' + encodeURIComponent(reseller))
            .then(r => r.json())
            .then(d => showVendorResults(d.results || []));
    }

    resellerInput.addEventListener('input', () => {
        clearTimeout(resellerDebounce);
        resellerDebounce = setTimeout(() => fetchResellers(resellerInput.value.trim()), 200);
    });
    resellerInput.addEventListener('focus', () => { if (resellerInput.value.trim().length >= 2) fetchResellers(resellerInput.value.trim()); });
    resellerInput.addEventListener('blur', () => setTimeout(hideAll, 200));

    vendorDisplay.addEventListener('input', () => {
        clearTimeout(vendorDebounce);
        vendorId.value = '';
        vendorDebounce = setTimeout(() => fetchVendors(vendorDisplay.value.trim()), 200);
    });
    vendorDisplay.addEventListener('focus', () => { if (vendorDisplay.value.trim()) fetchVendors(vendorDisplay.value.trim()); });
    vendorDisplay.addEventListener('blur', () => setTimeout(hideAll, 200));

    document.addEventListener('click', (e) => {
        if (!resellerDropdown.contains(e.target) && !vendorDropdown.contains(e.target) && 
            e.target !== resellerInput && e.target !== vendorDisplay) {
            hideAll();
        }
    });
    resellerDropdown.addEventListener('mousedown', e => e.preventDefault());
    vendorDropdown.addEventListener('mousedown', e => e.preventDefault());

    form.addEventListener('submit', function(e) {
        if (!vendorId.value) {
            e.preventDefault();
            alert('Please select a vendor from the suggestions.');
            vendorDisplay.focus();
        }
    });
})();
</script>
</div></div>
</body>
</html>
