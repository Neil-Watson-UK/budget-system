<?php
// executive_summary.php - Executive summary of spend for management (1–2 pager)
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/executive_summary.php'));
    exit;
}

$pdo = getDBConnection();

$year = $_GET['year'] ?? date('Y');
$distributor = $_GET['distributor'] ?? '';
$resellerId = (int)($_GET['reseller'] ?? 0);
$aiAvailable = defined('AI_SUMMARY_ENABLED') && AI_SUMMARY_ENABLED
    && ((defined('DEEPSEEK_API_KEY') && !empty(constant('DEEPSEEK_API_KEY')))
    || (defined('OPENAI_API_KEY') && !empty(constant('OPENAI_API_KEY')))
    || (defined('XAI_API_KEY') && !empty(constant('XAI_API_KEY')))
    || (defined('GEMINI_API_KEY') && !empty(constant('GEMINI_API_KEY'))));
$useAI = !empty($_GET['ai']) && $aiAvailable;

$where = ['1=1'];
$params = [];
if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
    $where[] = 'YEAR(s.report_date) = ?';
    $params[] = $year;
}
if ($distributor !== '') {
    $where[] = 's.distributor_name = ?';
    $params[] = $distributor;
}
if ($resellerId > 0) {
    $where[] = 's.matched_vendor_id = ?';
    $params[] = $resellerId;
}
$whereClause = implode(' AND ', $where);

$summary = ['total_rows' => 0, 'total_value' => 0, 'distributors' => 0, 'match_rate' => 0, 'matched_to_vendor' => 0, 'resellers' => 0, 'skus' => 0];
$valueCompare = ['dist_reported' => 0, 'at_msrp' => 0, 'at_trade' => 0];
$topDistributors = [];
$topResellers = [];
$topSkus = [];
$topCategories = [];
$byMonth = [];
$resellers = [];
$targetInfo = null;
$periodCompare = null;  // last 12 vs previous 12 months
$aiNarrative = null;
$aiError = null;
$dbError = null;
$inventorySummary = null;

try {
    $years = $pdo->query("SELECT DISTINCT YEAR(report_date) as y FROM sales_out_raw WHERE report_date IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);
    $resellers = $pdo->query("
        SELECT v.id, v.vendor_name FROM sales_out_raw s
        INNER JOIN vendors v ON s.matched_vendor_id = v.id
        GROUP BY v.id, v.vendor_name ORDER BY v.vendor_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $summarySql = "
        SELECT COUNT(*) as total_rows, COUNT(DISTINCT s.distributor_name) as distributors,
            COUNT(DISTINCT s.reseller_name) as resellers, COUNT(DISTINCT s.sku) as skus,
            COALESCE(SUM(s.total_value), 0) as total_value
        FROM sales_out_raw s WHERE $whereClause
    ";
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $valueSql = "
        SELECT COALESCE(SUM(s.total_value), 0) as dist_reported,
            COALESCE(SUM(s.quantity * p.msrp), 0) as at_msrp,
            COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $whereClause
    ";
    $valueStmt = $pdo->prepare($valueSql);
    $valueStmt->execute($params);
    $valueCompare = $valueStmt->fetch(PDO::FETCH_ASSOC);

    $topDistSql = "
        SELECT distributor_name, COUNT(*) as row_count, COALESCE(SUM(total_value), 0) as total
        FROM sales_out_raw s WHERE $whereClause
        GROUP BY distributor_name ORDER BY total DESC LIMIT 5
    ";
    $topDistStmt = $pdo->prepare($topDistSql);
    $topDistStmt->execute($params);
    $topDistributors = $topDistStmt->fetchAll(PDO::FETCH_ASSOC);

    $hasImageCol = false;
    try {
        $hasImageCol = (bool)$pdo->query("SHOW COLUMNS FROM sales_out_products LIKE 'image_thumb'")->fetch();
    } catch (PDOException $e) { /* ignore */ }

    if ($resellerId > 0) {
        $imgSel = $hasImageCol ? ", MAX(p.image_thumb) as image_thumb" : ", NULL as image_thumb";
        $topSkusSql = "
            SELECT COALESCE(p.sku, s.sku) as sku, COALESCE(p.product_name, s.product_name) as product_name,
                SUM(s.quantity) as qty, COALESCE(SUM(s.total_value), 0) as total $imgSel
            FROM sales_out_raw s
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
            WHERE $whereClause AND s.sku IS NOT NULL AND s.sku != ''
            GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name)
            ORDER BY total DESC LIMIT 5
        ";
        $topSkusStmt = $pdo->prepare($topSkusSql);
        $topSkusStmt->execute($params);
        $topSkus = $topSkusStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $topResSql = "
            SELECT v.vendor_name, COUNT(*) as row_count, COALESCE(SUM(s.total_value), 0) as total
            FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id
            WHERE $whereClause GROUP BY s.matched_vendor_id, v.vendor_name ORDER BY total DESC LIMIT 5
        ";
        $topResStmt = $pdo->prepare($topResSql);
        $topResStmt->execute($params);
        $topResellers = $topResStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $topCatSql = "
        SELECT COALESCE(p.product_category, p.product_line, 'Uncategorised') as category,
            COALESCE(SUM(s.total_value), 0) as dist_reported
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $whereClause GROUP BY category ORDER BY dist_reported DESC LIMIT 5
    ";
    $topCatStmt = $pdo->prepare($topCatSql);
    $topCatStmt->execute($params);
    $topCategories = $topCatStmt->fetchAll(PDO::FETCH_ASSOC);

    // Example product with thumbnail per category (for visual)
    $imgSelCat = $hasImageCol ? ", MAX(p.image_thumb) as image_thumb" : ", NULL as image_thumb";
    $exStmt = $pdo->prepare("
        SELECT COALESCE(p.sku, s.sku) as example_sku, MAX(COALESCE(p.product_name, s.product_name)) as example_name,
            COALESCE(SUM(s.total_value), 0) as example_val $imgSelCat
        FROM sales_out_raw s
        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
        WHERE $whereClause AND COALESCE(p.product_category, p.product_line, 'Uncategorised') = ? AND s.sku IS NOT NULL AND s.sku != ''
        GROUP BY COALESCE(p.sku, s.sku) ORDER BY example_val DESC LIMIT 1
    ");
    foreach ($topCategories as &$cat) {
        $exBind = array_merge($params, [$cat['category']]);
        $exStmt->execute($exBind);
        $cat['example'] = $exStmt->fetch(PDO::FETCH_ASSOC);
    }
    unset($cat);

    // Last 12 months vs previous 12 months (rolling, applies distributor/reseller filters)
    $last12To = date('Y-m-d');
    $last12From = date('Y-m-d', strtotime('-12 months'));
    $prev12To = date('Y-m-d', strtotime('-12 months') - 86400);
    $prev12From = date('Y-m-d', strtotime('-24 months'));
    $periodSql = "
        SELECT COALESCE(SUM(s.total_value), 0) as total
        FROM sales_out_raw s
        WHERE s.report_date BETWEEN ? AND ?" .
        ($distributor !== '' ? " AND s.distributor_name = ?" : "") .
        ($resellerId > 0 ? " AND s.matched_vendor_id = ?" : "");
    $last12Bind = array_merge([$last12From, $last12To], $distributor !== '' ? [$distributor] : [], $resellerId > 0 ? [$resellerId] : []);
    $prev12Bind = array_merge([$prev12From, $prev12To], $distributor !== '' ? [$distributor] : [], $resellerId > 0 ? [$resellerId] : []);
    $last12Stmt = $pdo->prepare($periodSql);
    $last12Stmt->execute($last12Bind);
    $last12Total = (float)$last12Stmt->fetchColumn();
    $prev12Stmt = $pdo->prepare($periodSql);
    $prev12Stmt->execute($prev12Bind);
    $prev12Total = (float)$prev12Stmt->fetchColumn();
    $periodCompare = [
        'last12' => $last12Total,
        'prev12' => $prev12Total,
        'change_pct' => $prev12Total > 0 ? round(100 * ($last12Total - $prev12Total) / $prev12Total, 1) : ($last12Total > 0 ? 100 : 0),
        'period_labels' => [
            'last' => date('M Y', strtotime($last12From)) . ' – ' . date('M Y', strtotime($last12To)),
            'prev' => date('M Y', strtotime($prev12From)) . ' – ' . date('M Y', strtotime($prev12To)),
        ],
    ];

    $inventorySummary = getInventorySummary($pdo, $distributor ?: '', 8);

    if ($year !== '' && preg_match('/^\d{4}$/', $year) && $resellerId <= 0) {
        $monthStmt = $pdo->prepare("
            SELECT MONTH(s.report_date) as mo, COALESCE(SUM(s.total_value), 0) as total
            FROM sales_out_raw s WHERE $whereClause GROUP BY mo ORDER BY mo
        ");
        $monthStmt->execute($params);
        foreach ($monthStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byMonth[(int)$r['mo']] = (float)$r['total'];
        }

        try {
            $targetStmt = null;
            if ($distributor !== '') {
                $targetStmt = $pdo->prepare("SELECT annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND entity_key = ? AND year = ?");
                $targetStmt->execute([$distributor, (int)$year]);
            } else {
                $targetStmt = $pdo->prepare("SELECT SUM(annual_target) as annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND year = ?");
                $targetStmt->execute([(int)$year]);
            }
            $row = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if ($row && (float)($row['annual_target'] ?? 0) > 0) {
                $seasonality = getSeasonalityPercentages($pdo);
                $timeElapsed = getTimeElapsedForYear((int)$year);
                $targetToDate = getTargetToDate((float)$row['annual_target'], $seasonality, (int)$year);
                $targetInfo = [
                    'annual_target' => (float)$row['annual_target'],
                    'actual' => (float)($valueCompare['dist_reported'] ?? 0),
                    'label' => $distributor ?: 'All distributors',
                    'target_to_date' => $targetToDate,
                    'time_elapsed' => $timeElapsed,
                ];
                $targetInfo['pct'] = round(100 * $targetInfo['actual'] / $targetInfo['annual_target'], 1);
                $targetInfo['vs_time'] = $targetToDate > 0 ? round(100 * $targetInfo['actual'] / $targetToDate, 1) : 0;
                $targetInfo['pct_ahead'] = $targetToDate > 0 ? round(100 * ($targetInfo['actual'] / $targetToDate - 1), 1) : 0;
                if ($timeElapsed['is_current_year'] && $timeElapsed['days_elapsed'] > 0) {
                    $targetInfo['year_end_forecast'] = round($targetInfo['actual'] * 365 / $timeElapsed['days_elapsed'], 0);
                }
            }
        } catch (PDOException $e) { /* targets table may not exist */ }
    }

    // Optional: Generate AI narrative via configured providers (DeepSeek, OpenAI, Grok, Gemini)
    if ($useAI && $aiAvailable) {
        $topForAi = $resellerId > 0 ? $topSkus : $topResellers;
        $result = generateAINarrative($summary, $valueCompare, $topDistributors, $topForAi, $topCategories, $targetInfo, $year, $distributor, $resellerId > 0);
        $aiNarrative = $result['text'] ?? null;
        $aiError = $result['error'] ?? null;
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

/**
 * Generate AI narrative from structured data. Tries providers in order:
 * DeepSeek (free tier) → OpenAI → Grok → Gemini.
 * Returns ['text' => string|null, 'error' => string|null].
 */
function generateAINarrative(array $summary, array $valueCompare, array $topDist, array $topResOrSkus, array $topCat, ?array $targetInfo, string $year, string $distributor, bool $isResellerView = false): array {
    $topItems = $isResellerView
        ? array_map(fn($r) => ['name' => ($r['product_name'] ?? $r['sku']) . ' (' . ($r['sku'] ?? '') . ')', 'total' => $r['total']], $topResOrSkus)
        : array_map(fn($r) => ['name' => $r['vendor_name'], 'total' => $r['total']], $topResOrSkus);
    $data = [
        'period' => $year,
        'scope' => $distributor ?: 'All distributors',
        'total_sales_rows' => $summary['total_rows'] ?? 0,
        'distributors_count' => $summary['distributors'] ?? 0,
        'dist_reported' => $valueCompare['dist_reported'] ?? 0,
        'at_msrp' => $valueCompare['at_msrp'] ?? 0,
        'at_trade' => $valueCompare['at_trade'] ?? 0,
        'top_distributors' => array_map(fn($r) => ['name' => $r['distributor_name'], 'total' => $r['total']], $topDist),
        'top_resellers_or_skus' => $topItems,
        'top_categories' => array_map(fn($r) => ['name' => $r['category'], 'value' => $r['dist_reported']], $topCat),
        'target' => $targetInfo ? [
            'actual' => $targetInfo['actual'],
            'annual_target' => $targetInfo['annual_target'],
            'pct' => $targetInfo['pct'],
            'vs_time' => $targetInfo['vs_time'] ?? null,
        ] : null,
    ];
    $prompt = sprintf(
        "Write a brief executive summary (2-3 short paragraphs, management style) for this sales/spend data. Be concise, use GBP (£). Do not invent data.\n\nData:\n%s",
        json_encode($data, JSON_PRETTY_PRINT)
    );

    $providers = [];

    // 1. DeepSeek (OpenAI-compatible, generous free tier)
    if (defined('DEEPSEEK_API_KEY') && !empty(constant('DEEPSEEK_API_KEY'))) {
        $key = constant('DEEPSEEK_API_KEY');
        $providers[] = ['name' => 'DeepSeek', 'call' => fn() => callOpenAICompatible('https://api.deepseek.com', $key, 'deepseek-chat', $prompt)];
    }
    // 2. OpenAI (ChatGPT)
    if (defined('OPENAI_API_KEY') && !empty(constant('OPENAI_API_KEY'))) {
        $key = constant('OPENAI_API_KEY');
        $providers[] = ['name' => 'OpenAI', 'call' => fn() => callOpenAICompatible('https://api.openai.com/v1', $key, 'gpt-4o-mini', $prompt)];
    }
    // 3. xAI Grok (OpenAI-compatible)
    if (defined('XAI_API_KEY') && !empty(constant('XAI_API_KEY'))) {
        $key = constant('XAI_API_KEY');
        $providers[] = ['name' => 'Grok', 'call' => fn() => callOpenAICompatible('https://api.x.ai/v1', $key, 'grok-2', $prompt)];
    }
    // 4. Gemini (try last — user may have quota issues)
    if (defined('GEMINI_API_KEY') && !empty(constant('GEMINI_API_KEY'))) {
        $key = constant('GEMINI_API_KEY');
        $providers[] = ['name' => 'Gemini', 'call' => fn() => callGemini($key, $prompt)];
    }

    if (empty($providers)) {
        return ['text' => null, 'error' => 'No AI API key configured. Add DEEPSEEK_API_KEY, OPENAI_API_KEY, XAI_API_KEY, or GEMINI_API_KEY in config.'];
    }

    $errors = [];
    foreach ($providers as $p) {
        $result = $p['call']();
        if (!empty($result['text'])) {
            return ['text' => $result['text'], 'error' => null];
        }
        $err = $result['error'] ?? ($p['name'] . ' failed');
        $errors[] = $p['name'] . ': ' . $err;
    }

    return ['text' => null, 'error' => implode('; ', $errors)];
}

/** OpenAI-compatible API (DeepSeek, OpenAI, Grok). */
function callOpenAICompatible(string $baseUrl, string $apiKey, string $model, string $prompt): array {
    $payload = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.3,
        'max_tokens' => 500,
    ]);
    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['text' => null, 'error' => 'cURL: ' . $curlErr];
    if ($code !== 200) {
        $err = $resp ? json_decode($resp, true) : [];
        return ['text' => null, 'error' => $err['error']['message'] ?? "HTTP $code"];
    }
    $json = json_decode($resp, true);
    $text = $json['choices'][0]['message']['content'] ?? null;
    return ['text' => $text ? trim($text) : null, 'error' => null];
}

/** Gemini API (Google). */
function callGemini(string $apiKey, string $prompt): array {
    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 500],
    ]);
    $models = ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-pro'];
    foreach ($models as $model) {
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) continue;
        $json = json_decode($resp, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text) return ['text' => trim($text), 'error' => null];
    }
    return ['text' => null, 'error' => 'Gemini quota or model unavailable'];
}

/**
 * Generate structured key takeaways from the data (no AI required).
 */
function generateKeyTakeaways(array $summary, array $valueCompare, array $topDist, ?array $targetInfo): array {
    $takeaways = [];
    $total = (float)($valueCompare['dist_reported'] ?? 0);

    if ($targetInfo) {
        if ($targetInfo['pct'] >= 100) {
            $takeaways[] = ['type' => 'positive', 'text' => sprintf('Annual target achieved: %s%% of target (£%s)', $targetInfo['pct'], number_format($targetInfo['actual'], 0))];
        } elseif (($targetInfo['vs_time'] ?? 0) >= 100) {
            $takeaways[] = ['type' => 'positive', 'text' => sprintf('Ahead of schedule: %s%% of time-elapsed target. On track for year-end.', $targetInfo['vs_time'])];
        } else {
            $takeaways[] = ['type' => 'attention', 'text' => sprintf('Behind target: %s%% of annual target. %s%% of time-elapsed target.', $targetInfo['pct'], $targetInfo['vs_time'] ?? 0)];
        }
        if (!empty($targetInfo['year_end_forecast'])) {
            $takeaways[] = ['type' => 'info', 'text' => sprintf('Year-end run-rate forecast: £%s', number_format($targetInfo['year_end_forecast'], 0))];
        }
    }

    if (count($topDist) > 0 && $total > 0) {
        $topShare = round(100 * ($topDist[0]['total'] ?? 0) / $total, 0);
        if ($topShare >= 40) {
            $takeaways[] = ['type' => 'info', 'text' => sprintf('%s accounts for %s%% of distributor-reported sales', $topDist[0]['distributor_name'], $topShare)];
        }
    }

    return $takeaways;
}

$takeaways = generateKeyTakeaways($summary, $valueCompare, $topDistributors, $targetInfo);
$monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$chartColors = ['#00a399', '#00353d', '#ff5549', '#666666', '#f59e0b', '#cccccc'];
$distChartLabels = json_encode(array_column($topDistributors, 'distributor_name'));
$distChartData = json_encode(array_map('floatval', array_column($topDistributors, 'total')));
$catChartLabels = json_encode(array_column($topCategories, 'category'));
$catChartData = json_encode(array_map('floatval', array_column($topCategories, 'dist_reported')));

require_once __DIR__ . '/header.php';
if (!empty($dbError)) {
    echo '<div class="container-xl py-4"><div class="alert alert-danger">' . htmlspecialchars($dbError) . '</div></div></div></div></body></html>';
    exit;
}
?>
<style>
.exec-summary { max-width: 900px; margin: 0 auto; font-size: 0.95rem; }
.exec-summary .es-header { background: linear-gradient(135deg, #00353d 0%, #00a399 100%); color: white; padding: 1.5rem 2rem; border-radius: 10px; margin-bottom: 1.5rem; }
.exec-summary .es-header h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
.exec-summary .es-header .es-meta { opacity: 0.9; font-size: 0.85rem; }
.exec-summary .es-section { margin-bottom: 1.25rem; }
.exec-summary .es-section-title { font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; color: #00353d; margin-bottom: 0.5rem; border-bottom: 2px solid #00a399; padding-bottom: 0.25rem; }
.exec-summary .es-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.exec-summary .es-kpi { background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; text-align: center; border: 1px solid #e2e8f0; }
.exec-summary .es-kpi-value { font-size: 1.25rem; font-weight: 700; color: #00353d; }
.exec-summary .es-kpi-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; }
.exec-summary table { width: 100%; font-size: 0.85rem; }
.exec-summary .takeaway-positive { color: #059669; }
.exec-summary .takeaway-attention { color: #dc2626; }
.exec-summary .takeaway-info { color: #0369a1; }
.exec-summary .ai-narrative { background: #f0fdfa; border-left: 4px solid #00a399; padding: 1rem 1.25rem; margin: 1rem 0; font-style: italic; }
.exec-summary .es-chart-wrap { display: flex; gap: 1rem; align-items: flex-start; flex-wrap: wrap; }
.exec-summary .es-chart-donut { flex: 0 0 180px; max-width: 180px; height: 180px; }
.exec-summary .es-chart-legend { flex: 1; min-width: 140px; }
.exec-summary .es-prod-thumb { width: 36px; height: 36px; object-fit: contain; border-radius: 6px; background: #f1f5f9; flex-shrink: 0; }
/* Print - reduce memory to avoid browser crash when saving as PDF */
@media print {
    .d-print-none, .navbar, .btn, .filter-card, nav { display: none !important; }
    .exec-summary { max-width: 100%; font-size: 10pt; }
    .exec-summary .es-header { padding: 1rem; }
    .page-body { padding: 0 !important; }
    body { background: white; }
    /* Hide external product images (Icecat) - they cause heavy memory use when embedding in PDF */
    img.es-prod-thumb, .es-prod-thumb { display: none !important; }
    /* Constrain chart size to reduce canvas rasterization memory */
    .es-chart-donut { width: 120px !important; height: 120px !important; max-width: 120px !important; max-height: 120px !important; }
}
</style>

<div class="container-xl py-4">
    <div class="filter-card d-print-none mb-4" style="background: white; border: 1px solid #D7D2CB; border-radius: 10px; padding: 1rem 1.5rem;">
        <form method="GET" class="row g-3 align-items-end">
            <?php if ($aiAvailable ?? false): ?>
            <div class="col-12 mb-2">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="ai" value="1" id="aiCheck" <?= !empty($_GET['ai']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="aiCheck"><i class="ti ti-sparkles me-1"></i> Include AI summary</label>
                </div>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">Year</label>
                <select name="year" class="form-select">
                    <?php
                    $yearOpts = !empty($years) ? $years : [date('Y')];
                    foreach ($yearOpts as $y):
                    ?><option value="<?= (int)$y ?>" <?= $year === (string)$y ? 'selected' : '' ?>><?= (int)$y ?></option><?php
                    endforeach;
                    ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Distributor</label>
                <select name="distributor" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($distributors ?? [] as $d): ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= $distributor === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Reseller</label>
                <select name="reseller" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($resellers ?? [] as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= $resellerId === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['vendor_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary"><i class="ti ti-filter me-1"></i> Apply</button>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Print / PDF</button>
            </div>
        </form>
    </div>

    <div class="exec-summary">
        <div class="es-header">
            <h1>Sales Out — Executive Summary</h1>
            <div class="es-meta">
                <?= $year ? htmlspecialchars($year) : 'All years' ?><?= $distributor ? ' · ' . htmlspecialchars($distributor) : '' ?><?php
                if ($resellerId > 0) {
                    $resellerName = '';
                    foreach ($resellers ?? [] as $r) { if ((int)$r['id'] === $resellerId) { $resellerName = $r['vendor_name']; break; } }
                    echo $resellerName ? ' · ' . htmlspecialchars($resellerName) : '';
                }
                ?><span class="ms-3">Generated <?= date('d M Y') ?></span>
            </div>
        </div>

        <?php if ($aiError): ?>
        <div class="es-section">
            <div class="alert alert-warning"><i class="ti ti-alert-triangle me-2"></i><strong>AI Summary error:</strong> <?= htmlspecialchars($aiError) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($aiNarrative): ?>
        <div class="es-section">
            <div class="es-section-title">AI Summary</div>
            <div class="ai-narrative"><?= nl2br(htmlspecialchars($aiNarrative)) ?></div>
        </div>
        <?php endif; ?>

        <div class="es-section">
            <div class="es-section-title">Key Metrics</div>
            <div class="es-kpis">
                <div class="es-kpi">
                    <div class="es-kpi-value">£<?= number_format($valueCompare['dist_reported'] ?? 0, 0) ?></div>
                    <div class="es-kpi-label">Dist. Reported</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value">£<?= number_format($valueCompare['at_msrp'] ?? 0, 0) ?></div>
                    <div class="es-kpi-label">At MSRP</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value">£<?= number_format($valueCompare['at_trade'] ?? 0, 0) ?></div>
                    <div class="es-kpi-label">At Trade</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value"><?= number_format($summary['total_rows']) ?></div>
                    <div class="es-kpi-label">Sales Rows</div>
                </div>
                <?php if ($targetInfo): ?>
                <div class="es-kpi" style="border-color: <?= $targetInfo['pct'] >= 100 ? '#00a399' : ($targetInfo['pct'] >= 80 ? '#f59e0b' : '#ff5549') ?>;">
                    <div class="es-kpi-value"><?= $targetInfo['pct'] ?>%</div>
                    <div class="es-kpi-label">Target</div>
                </div>
                <?php endif; ?>
                <?php if ($inventorySummary !== null): ?>
                <div class="es-kpi">
                    <div class="es-kpi-value" style="color:#00353d">£<?= number_format($inventorySummary['total_value'], 0) ?></div>
                    <div class="es-kpi-label">Inventory (trade)</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value" style="color:#00353d"><?= number_format($inventorySummary['total_units'], 0) ?></div>
                    <div class="es-kpi-label">Units on hand</div>
                </div>
                <div class="es-kpi">
                    <div class="es-kpi-value" style="color:#00353d"><?= $inventorySummary['avg_weeks'] !== null ? number_format($inventorySummary['avg_weeks'], 1) . 'w' : '—' ?></div>
                    <div class="es-kpi-label">Avg weeks of stock</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($inventorySummary !== null): ?>
        <div class="es-section">
            <div class="es-section-title">Inventory Summary</div>
            <p class="small text-muted mb-2">Latest snapshot<?= $distributor ? ' · ' . htmlspecialchars($distributor) : ' (all distributors)' ?>.</p>
            <div class="row g-3 mb-2">
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background: rgba(0,53,61,0.08);">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(0,53,61,0.15);"><i class="ti ti-currency-pound" style="color:#00353d"></i></div>
                        <div>
                            <div class="fw-bold" style="color:#00353d">£<?= number_format($inventorySummary['total_value'], 0) ?></div>
                            <div class="small text-muted">Total value (trade)</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background: rgba(0,53,61,0.08);">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(0,53,61,0.15);"><i class="ti ti-box" style="color:#00353d"></i></div>
                        <div>
                            <div class="fw-bold" style="color:#00353d"><?= number_format($inventorySummary['total_units'], 0) ?></div>
                            <div class="small text-muted">Units on hand</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background: rgba(0,53,61,0.08);">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:40px;height:40px;background:rgba(0,53,61,0.15);"><i class="ti ti-clock" style="color:#00353d"></i></div>
                        <div>
                            <div class="fw-bold" style="color:#00353d"><?= $inventorySummary['avg_weeks'] !== null ? number_format($inventorySummary['avg_weeks'], 1) . ' weeks' : '—' ?></div>
                            <div class="small text-muted">Avg weeks of stock</div>
                        </div>
                    </div>
                </div>
            </div>
            <a href="inventory_report.php<?= $distributor ? '?distributor=' . urlencode($distributor) : '' ?>" class="btn btn-sm btn-outline-secondary"><i class="ti ti-package me-1"></i>View full inventory report</a>
        </div>
        <?php endif; ?>

        <?php if (!empty($takeaways)): ?>
        <div class="es-section">
            <div class="es-section-title">Key Takeaways</div>
            <ul class="mb-0" style="padding-left: 1.25rem;">
                <?php foreach ($takeaways as $t): ?>
                <li class="takeaway-<?= $t['type'] ?> mb-1"><?= htmlspecialchars($t['text']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($periodCompare): ?>
        <div class="es-section">
            <div class="es-section-title">12-Month Performance vs Previous 12 Months</div>
            <table class="table table-bordered table-sm">
                <tr><th>Last 12 months</th><td>£<?= number_format($periodCompare['last12'], 0) ?></td><td class="text-muted small"><?= htmlspecialchars($periodCompare['period_labels']['last']) ?></td></tr>
                <tr><th>Previous 12 months</th><td>£<?= number_format($periodCompare['prev12'], 0) ?></td><td class="text-muted small"><?= htmlspecialchars($periodCompare['period_labels']['prev']) ?></td></tr>
                <tr><th>Change</th><td colspan="2"><strong style="color: <?= $periodCompare['change_pct'] >= 0 ? '#059669' : '#dc2626' ?>"><?= $periodCompare['change_pct'] >= 0 ? '+' : '' ?><?= $periodCompare['change_pct'] ?>%</strong> <?= $periodCompare['change_pct'] >= 0 ? 'growth' : 'decline' ?></td></tr>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($targetInfo): ?>
        <div class="es-section">
            <div class="es-section-title">Target Performance</div>
            <table class="table table-bordered table-sm">
                <tr><th>Actual (YTD)</th><td>£<?= number_format($targetInfo['actual'], 0) ?></td></tr>
                <tr><th>Annual Target</th><td>£<?= number_format($targetInfo['annual_target'], 0) ?></td></tr>
                <tr><th>Target to Date</th><td>£<?= number_format($targetInfo['target_to_date'], 0) ?></td></tr>
                <?php if (!empty($targetInfo['year_end_forecast'])): ?>
                <tr><th>Year-end Forecast</th><td>£<?= number_format($targetInfo['year_end_forecast'], 0) ?> (run-rate)</td></tr>
                <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="es-section">
                    <div class="es-section-title">Distributor Split</div>
                    <div class="es-chart-wrap">
                        <div class="es-chart-donut"><canvas id="distChart"></canvas></div>
                        <div class="es-chart-legend">
                            <?php $tot = array_sum(array_column($topDistributors, 'total')); foreach ($topDistributors as $i => $r): ?>
                            <div class="d-flex justify-content-between align-items-center small mb-1">
                                <span><span class="d-inline-block rounded" style="width:8px;height:8px;background:<?= $chartColors[$i % count($chartColors)] ?>"></span> <?= htmlspecialchars($r['distributor_name']) ?></span>
                                <span>£<?= number_format($r['total'], 0) ?><?= $tot > 0 ? ' (' . round(100 * $r['total'] / $tot, 0) . '%)' : '' ?></span>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($topDistributors)): ?><div class="text-muted">No data</div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="es-section">
                    <?php if ($resellerId > 0): ?>
                    <div class="es-section-title">Top 5 Product SKUs</div>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light"><tr><th>Product</th><th class="text-end">Value</th></tr></thead>
                        <tbody>
                            <?php foreach ($topSkus as $r): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($r['image_thumb'])): ?><img src="<?= htmlspecialchars($r['image_thumb']) ?>" alt="" class="es-prod-thumb" loading="lazy"><?php endif; ?>
                                        <div>
                                            <div class="small"><?= htmlspecialchars($r['product_name'] ?? $r['sku']) ?></div>
                                            <code class="small"><?= htmlspecialchars($r['sku']) ?></code>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">£<?= number_format($r['total'], 0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($topSkus)): ?><tr><td colspan="2" class="text-muted">No SKU data</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="es-section-title">Top 5 Resellers</div>
                    <table class="table table-sm table-bordered">
                        <thead class="table-light"><tr><th>Reseller</th><th class="text-end">Value</th></tr></thead>
                        <tbody>
                            <?php foreach ($topResellers as $r): ?>
                            <tr><td><?= htmlspecialchars($r['vendor_name']) ?></td><td class="text-end">£<?= number_format($r['total'], 0) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if (empty($topResellers)): ?><tr><td colspan="2" class="text-muted">No data</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="es-section">
            <div class="es-section-title">Category Split</div>
            <div class="es-chart-wrap mb-3">
                <div class="es-chart-donut"><canvas id="catChart"></canvas></div>
                <div class="es-chart-legend">
                    <?php $catTot = array_sum(array_column($topCategories, 'dist_reported')); foreach ($topCategories as $i => $r): ?>
                    <div class="d-flex justify-content-between align-items-center small mb-1">
                        <span><span class="d-inline-block rounded" style="width:8px;height:8px;background:<?= $chartColors[$i % count($chartColors)] ?>"></span> <?= htmlspecialchars($r['category']) ?></span>
                        <span>£<?= number_format($r['dist_reported'], 0) ?><?= $catTot > 0 ? ' (' . round(100 * $r['dist_reported'] / $catTot, 0) . '%)' : '' ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($topCategories)): ?><div class="text-muted">No category data</div><?php endif; ?>
                </div>
            </div>
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><th>Category</th><th>Best seller in category</th><th class="text-end">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($topCategories as $r):
                        $ex = $r['example'] ?? null;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($r['category']) ?></td>
                        <td>
                            <?php if ($ex): ?>
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!empty($ex['image_thumb'])): ?><img src="<?= htmlspecialchars($ex['image_thumb']) ?>" alt="" class="es-prod-thumb" loading="lazy"><?php endif; ?>
                                <div class="small text-truncate" style="max-width:120px" title="<?= htmlspecialchars($ex['example_name'] ?? '') ?>">
                                    <a href="product_detail.php?sku=<?= urlencode($ex['example_sku'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($ex['example_name'] ?? $ex['example_sku'] ?? '-') ?></a>
                                    <?php if (!empty($ex['example_sku'])): ?><br><code class="small"><?= htmlspecialchars($ex['example_sku']) ?></code><?php endif; ?>
                                </div>
                            </div>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-end">£<?= number_format($r['dist_reported'], 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($topCategories)): ?><tr><td colspan="3" class="text-muted">No category data</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($byMonth) && $year): ?>
        <div class="es-section">
            <div class="es-section-title">Monthly Trend (<?= htmlspecialchars($year) ?>)</div>
            <table class="table table-sm table-bordered">
                <thead class="table-light"><tr><?php foreach ($monthLabels as $m): ?><th class="text-center"><?= $m ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <tr>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <td class="text-end">£<?= number_format($byMonth[$i] ?? 0, 0) ?></td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <p class="text-muted small mt-3 mb-0"><em>Sales Out — Standardised distributor sales data. Values use product master (MSRP/Trade) when SKU matches.</em></p>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var fmt = function(v) { return '£' + parseFloat(v).toLocaleString('en-GB', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); };
    var colors = <?= json_encode($chartColors) ?>;

    var distEl = document.getElementById('distChart');
    if (distEl) {
        var distLabels = <?= $distChartLabels ?>;
        var distData = <?= $distChartData ?>;
        if (distLabels.length && distData.some(function(x) { return x > 0; })) {
            new Chart(distEl.getContext('2d'), {
                type: 'doughnut',
                data: { labels: distLabels, datasets: [{ data: distData, backgroundColor: colors.slice(0, distLabels.length), borderColor: '#fff', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: true, cutout: '55%', devicePixelRatio: 1, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return c.label + ': ' + fmt(c.raw); } } } } }
            });
        } else distEl.parentElement.innerHTML = '<div class="text-center text-muted small py-4">No data</div>';
    }

    var catEl = document.getElementById('catChart');
    if (catEl) {
        var catLabels = <?= $catChartLabels ?>;
        var catData = <?= $catChartData ?>;
        if (catLabels.length && catData.some(function(x) { return x > 0; })) {
            new Chart(catEl.getContext('2d'), {
                type: 'doughnut',
                data: { labels: catLabels, datasets: [{ data: catData, backgroundColor: colors.slice(0, catLabels.length), borderColor: '#fff', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: true, cutout: '55%', devicePixelRatio: 1, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return c.label + ': ' + fmt(c.raw); } } } } }
            });
        } else catEl.parentElement.innerHTML = '<div class="text-center text-muted small py-4">No data</div>';
    }

    // Resize charts before print to reduce memory (match @media print dimensions)
    window.addEventListener('beforeprint', function() {
        document.querySelectorAll('.es-chart-donut canvas').forEach(function(c) {
            var ch = Chart.getChart(c);
            if (ch) ch.resize(120, 120);
        });
    });
});
</script>
</div></div>
</body>
</html>
