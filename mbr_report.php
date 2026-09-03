<?php
/**
 * MBR Report - per-region cards: planned / committed / invoiced, budget & remaining (EUR), top 3 partners.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode('mbr_report.php'));
    exit;
}

$pdo = getDBConnection();
$selected_year = $_GET['year'] ?? date('Y');
if ($selected_year === 'all' || $selected_year === '') {
    $selected_year = date('Y');
}

$regionCards = getMbrReportRegionCards($pdo, $selected_year);

$fmtEur = function ($amt) {
    // HTML entity avoids mojibake if PHP/source file encoding is not UTF-8
    return '&#8364;' . number_format((float) $amt, 0, '.', ',');
};

$available_years = [];
for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--) {
    $available_years[] = $y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MBR Report - <?= defined('APP_NAME') ? APP_NAME : 'Budget' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.40.0/tabler-icons.min.css">
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<style>
.mbr-page { margin: 0 auto; font-size: 0.95rem; }
.mbr-hero { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 1.25rem 1.75rem; border-radius: 10px; margin-bottom: 1.25rem; }
.mbr-hero h1 { font-size: 1.4rem; margin: 0 0 0.35rem 0; font-weight: 700; }
.mbr-hero .meta { opacity: 0.92; font-size: 0.85rem; }
.mbr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.25rem; }
.mbr-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.mbr-card-head {
    padding: 1rem 1.15rem;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}
.mbr-card-head .region-code { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.06em; color: #64748b; text-transform: uppercase; margin-bottom: 0.25rem; }
.mbr-card-head .region-title { font-size: 1.15rem; font-weight: 700; color: #0f172a; line-height: 1.3; }
.mbr-card-head .flag { margin-right: 0.35rem; }
.mbr-card-body { padding: 1rem 1.15rem 1.15rem; }
.mbr-metric-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.65rem; margin-bottom: 0.85rem; }
.mbr-metric { background: #f8fafc; border-radius: 8px; padding: 0.55rem 0.65rem; text-align: center; }
.mbr-metric .val { font-weight: 700; font-size: 0.95rem; color: #0f172a; }
.mbr-metric .lbl { font-size: 0.65rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 0.2rem; }
.mbr-budget-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.65rem; margin-bottom: 0.85rem; }
.mbr-budget-row .mbr-metric { text-align: left; padding: 0.65rem 0.75rem; }
.mbr-budget-row .mbr-metric .val { font-size: 1.05rem; }
.mbr-budget-row .mbr-metric-warn {
    background: rgba(0, 163, 153, 0.1);
    outline: 1px solid rgba(0, 53, 61, 0.35);
}
.mbr-budget-row .mbr-metric-warn .val { color: #00353d; }
.mbr-budget-row .mbr-metric-warn .mbr-pct { color: #0f766e; }
.mbr-partners-title { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #00353d; margin-bottom: 0.45rem; }
.mbr-partners table { font-size: 0.82rem; margin-bottom: 0; }
.mbr-partners th { font-weight: 600; color: #475569; }
.mbr-pct { font-size: 0.72rem; font-weight: 600; color: #64748b; white-space: nowrap; }
.mbr-metric .val .mbr-pct { font-weight: 600; }
.mbr-total-visual {
    border: 1px solid #D7D2CB;
    border-radius: 10px;
    padding: 0.85rem 0.95rem;
    margin-bottom: 0.95rem;
    background: white;
}
.mbr-total-visual .mbr-tv-main { display: flex; gap: 0.95rem; align-items: flex-start; flex-wrap: wrap; }
.mbr-total-visual .mbr-tv-left { flex: 1; min-width: 0; }
.mbr-total-visual .mbr-tv-big { font-size: 1.25rem; font-weight: 700; line-height: 1.2; margin-bottom: 0.2rem; color: #1e293b; }
.mbr-total-visual .mbr-tv-lbl { font-size: 0.78rem; color: #64748b; margin-bottom: 0.35rem; }
.mbr-total-visual .mbr-tv-bar-wrap { position: relative; height: 10px; background: #e5e7eb; border-radius: 5px; overflow: visible; margin-bottom: 0.3rem; }
.mbr-total-visual .mbr-tv-fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 5px; transition: width 0.3s ease; z-index: 1; max-width: 100%; background: #94a3b8; }
.mbr-total-visual .mbr-tv-ref { position: absolute; top: -2px; bottom: -2px; width: 2px; background: rgba(0,0,0,0.45); z-index: 2; }
.mbr-total-visual .mbr-tv-meta { font-size: 0.68rem; color: #64748b; }
.mbr-pct-budget-note { font-size: 0.72rem; color: #64748b; font-weight: 600; margin-top: 0.15rem; }
.mbr-spend-type-title { font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #00353d; margin-bottom: 0.45rem; }
.mbr-spend-type table { font-size: 0.82rem; margin-bottom: 0; }
.mbr-partners-acc { border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.85rem; }
.mbr-partners-acc > summary { list-style: none; padding: 0.55rem 0.65rem; cursor: pointer; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #00353d; background: #f8fafc; border-radius: 8px; }
.mbr-partners-acc > summary::-webkit-details-marker { display: none; }
.mbr-partners-acc > summary::after { content: '>'; float: right; opacity: 0.6; font-size: 0.9em; }
.mbr-partners-acc[open] > summary::after { content: '^'; }
.mbr-partners-acc .mbr-partners-inner { padding: 0.65rem 0.65rem 0.75rem; }
.filter-card { background: white; border: 1px solid #D7D2CB; border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
.note { font-size: 0.8rem; color: #64748b; margin-top: 1rem; line-height: 1.45; }
@media print {
    .navbar, .filter-card, .d-print-none { display: none !important; }
    .mbr-page { max-width: 100%; }
}
</style>

<div class="container-xl py-4">
    <div class="filter-card d-print-none">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label mb-0">Year</label>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($available_years as $y): ?>
                    <option value="<?= (int)$y ?>" <?= (string)$selected_year === (string)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter me-1"></i>Apply</button>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="ti ti-printer me-1"></i>Print</button>
            </div>
            <div class="col-auto">
                <a href="mbr_export_pptx.php?year=<?= urlencode((string) $selected_year) ?>" class="btn btn-outline-primary btn-sm"><i class="ti ti-download me-1"></i>Download PowerPoint</a>
            </div>
        </form>
    </div>

    <div class="mbr-page">
        <div class="mbr-hero">
            <h1>MBR Report</h1>
            <div class="meta">
                Management business review - all amounts in <strong>EUR</strong>
                - Year <strong><?= htmlspecialchars((string) $selected_year) ?></strong>
                - Generated <?= date('d M Y') ?>
            </div>
        </div>

        <p class="text-muted small mb-3">
            <strong>Planned</strong> = status Planned -
            <strong>Committed</strong> = Allocated + Executed -
            <strong>Invoiced</strong> = status Invoiced -
            Cancelled lines excluded. Budget remaining = regional budget (EUR) minus total spend (all non-cancelled statuses) in EUR.
            Percentages under Planned / Committed / Invoiced are <strong>% of regional budget</strong>.
            Total budget spend combines all non-cancelled lines (same basis as Budget remaining).
            Visual below each region: grey bar = share of annual budget used; vertical line = proportional spend vs year elapsed (<strong><?= htmlspecialchars((string) $selected_year) ?></strong>). <strong>Spend Type</strong> is budget line item type (e.g. Reseller, Distributor).
        </p>

        <div class="mbr-grid">
            <?php foreach ($regionCards as $c): ?>
            <div class="mbr-card">
                <div class="mbr-card-head" style="border-left: 4px solid <?= htmlspecialchars($c['color']) ?>;">
                    <div class="region-code"><?= htmlspecialchars($c['region']) ?></div>
                    <div class="region-title">
                        <?php if ($c['flag'] !== ''): ?><span class="flag" aria-hidden="true"><?= $c['flag'] ?></span><?php endif; ?>
                        <?= htmlspecialchars($c['title']) ?>
                    </div>
                </div>
                <div class="mbr-card-body">
                    <div class="mbr-metric-grid">
                        <div class="mbr-metric">
                            <div class="val"><?= $fmtEur($c['planned_eur']) ?><?php if ($c['planned_pct_budget'] !== null): ?> <span class="mbr-pct">(<?= htmlspecialchars((string) $c['planned_pct_budget']) ?>% of budget)</span><?php endif; ?></div>
                            <div class="lbl">Planned</div>
                        </div>
                        <div class="mbr-metric">
                            <div class="val"><?= $fmtEur($c['committed_eur']) ?><?php if ($c['committed_pct_budget'] !== null): ?> <span class="mbr-pct">(<?= htmlspecialchars((string) $c['committed_pct_budget']) ?>% of budget)</span><?php endif; ?></div>
                            <div class="lbl">Committed</div>
                        </div>
                        <div class="mbr-metric">
                            <div class="val"><?= $fmtEur($c['invoiced_eur']) ?><?php if ($c['invoiced_pct_budget'] !== null): ?> <span class="mbr-pct">(<?= htmlspecialchars((string) $c['invoiced_pct_budget']) ?>% of budget)</span><?php endif; ?></div>
                            <div class="lbl">Invoiced</div>
                        </div>
                    </div>
                    <?php $pctBar = isset($c['mbr_usage_pct_bar']) ? (float) $c['mbr_usage_pct_bar'] : 0; ?>
                    <div class="mbr-total-visual">
                        <div class="mbr-tv-main">
                            <div class="mbr-tv-left">
                                <div class="mbr-tv-big"><?= $fmtEur($c['total_used_eur']) ?></div>
                                <div class="mbr-tv-lbl">Total budget spend <?= htmlspecialchars((string) $selected_year) ?></div>
                                <div class="mbr-tv-bar-wrap">
                                    <div class="mbr-tv-fill" style="width: <?= htmlspecialchars((string) min(100, max(0, $pctBar))) ?>%;"></div>
                                    <?php if (!empty($c['mbr_is_current_year']) && empty($c['mbr_is_future_year']) && ($c['mbr_ref_line_pct'] ?? 0) > 0 && ($c['mbr_ref_line_pct'] ?? 100) < 100): ?>
                                    <div class="mbr-tv-ref" style="left: <?= htmlspecialchars((string) $c['mbr_ref_line_pct']) ?>%;" title="On-track pace"></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mbr-tv-meta"><span>Budget <?= $fmtEur($c['budget_eur']) ?></span><?php if (!empty($c['mbr_is_current_year']) && empty($c['mbr_is_future_year']) && ($c['mbr_expected_to_date_eur'] ?? 0) > 0): ?> &middot; On-track <?= $fmtEur($c['mbr_expected_to_date_eur'] ?? 0) ?><?php endif; ?></div>
                                <?php if ($c['total_spend_pct_budget'] !== null): ?>
                                <div class="mbr-pct-budget-note"><?= htmlspecialchars((string) $c['total_spend_pct_budget']) ?>% of annual budget used</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mbr-budget-row">
                        <div class="mbr-metric">
                            <div class="lbl">Budget total</div>
                            <div class="val"><?= $fmtEur($c['budget_eur']) ?></div>
                        </div>
                        <div class="mbr-metric<?= $c['remaining_eur'] < 0 ? ' mbr-metric-warn' : '' ?>">
                            <div class="lbl">Budget remaining</div>
                            <div class="val"><?= $fmtEur($c['remaining_eur']) ?><?php if ($c['remaining_pct_budget'] !== null): ?> <span class="mbr-pct">(<?= htmlspecialchars((string) $c['remaining_pct_budget']) ?>% of budget)</span><?php endif; ?></div>
                        </div>
                    </div>
                    <div class="mbr-spend-type mb-2">
                        <div class="mbr-spend-type-title">Spend Type</div>
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr><th>Type</th><th class="text-end">&#8364;</th><th class="text-end">%</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($c['spend_by_type'])): ?>
                                <tr><td colspan="3" class="text-muted">No data</td></tr>
                                <?php else: ?>
                                <?php foreach ($c['spend_by_type'] as $st): ?>
                                <tr>
                                    <td><?= htmlspecialchars($st['spend_type']) ?></td>
                                    <td class="text-end"><?= $fmtEur($st['total_eur']) ?></td>
                                    <td class="text-end"><?= htmlspecialchars((string) $st['pct']) ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <details class="mbr-partners-acc">
                        <summary>Top 3 partners (vendor / external vendor)</summary>
                        <div class="mbr-partners-inner">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr><th>#</th><th>Partner</th><th class="text-end">&#8364;</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($c['partners'])): ?>
                                    <tr><td colspan="3" class="text-muted">No data</td></tr>
                                    <?php else: ?>
                                    <?php $n = 0; foreach ($c['partners'] as $p): $n++; ?>
                                    <tr>
                                        <td><?= $n ?></td>
                                        <td><?= htmlspecialchars($p['partner']) ?></td>
                                        <td class="text-end"><?= $fmtEur($p['total_eur']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="note mb-0">
            <em>Partner totals group by primary vendor name, or external vendor when vendor is blank. Spend Type % is share of total regional spend (all non-cancelled lines) in EUR. Currency conversion uses the same EUR rules as the Executive Summary (global view).</em>
        </p>
    </div>
</div>

</body>
</html>
