<?php
// salesout/functions.php - Helper functions

/** Value mode for totals: disti = distributor reported, trade = at trade price, msrp = at MSRP */
const SALESOUT_VALUE_MODES = ['disti', 'trade', 'msrp'];

/** Human labels for value mode (for tooltips and switcher). */
function getSalesOutValueModeLabels(): array {
    return [
        'disti' => 'Distributor reported',
        'trade'  => 'Trade',
        'msrp'   => 'MSRP',
    ];
}

/** Current value mode from session (default: trade). */
function getSalesOutValueMode(): string {
    if (session_status() === PHP_SESSION_NONE) return 'trade';
    $mode = $_SESSION['salesout_value_mode'] ?? 'trade';
    return in_array($mode, SALESOUT_VALUE_MODES, true) ? $mode : 'trade';
}

/** Tooltip text for the current value mode, e.g. "Totals by Trade" or "By MSRP". */
function getSalesOutValueModeTooltip(?string $mode = null): string {
    $mode = $mode ?? getSalesOutValueMode();
    $labels = getSalesOutValueModeLabels();
    $label = $labels[$mode] ?? $mode;
    return 'By ' . $label;
}

/** Map value mode to valueCompare keys (dist_reported, at_trade, at_msrp). */
function getSalesOutValueCompareKey(string $mode): string {
    $map = ['disti' => 'dist_reported', 'trade' => 'at_trade', 'msrp' => 'at_msrp'];
    return $map[$mode] ?? 'at_trade';
}

/**
 * Normalize region for display and filtering: "United Kingdom" and "UKI" both become "UKI".
 */
function normalizeRegionForUKI(string $region): string {
    $r = trim($region);
    if ($r === '') return $region;
    if (strtoupper($r) === 'UNITED KINGDOM' || $r === 'UKI') return 'UKI';
    return $r;
}

/** Distributor name aliases: sales name -> inventory name(s). E.g. sales uses "Westcoast", inventory files use "WC". */
function getInventoryDistributorAliases(): array {
    return [
        'Westcoast' => ['Westcoast', 'WC'],
        'WC' => ['Westcoast', 'WC'],
        'Hypertec' => ['Hypertec', 'HYPERTEC'],
        'HYPERTEC' => ['Hypertec', 'HYPERTEC'],
    ];
}

/** Canonical distributor key for joining sales + inventory when names differ (e.g. Westcoast vs WC). */
function canonicalDistributorForJoin(string $name): string {
    $n = strtolower(trim($name));
    if (in_array($n, ['westcoast', 'wc'], true)) return 'westcoast';
    if (in_array($n, ['hypertec'], true)) return 'hypertec';
    return $n;
}

/**
 * Match reseller name to known vendor (from budget vendors table)
 */
function matchResellerToVendor($pdo, string $resellerName): ?int {
    $resellerName = trim($resellerName);
    if (empty($resellerName)) return null;
    
    // 1. Check mapping table first
    $stmt = $pdo->prepare("
        SELECT vendor_id FROM sales_out_reseller_mapping 
        WHERE LOWER(TRIM(reseller_name_raw)) = LOWER(?)
    ");
    $stmt->execute([$resellerName]);
    $mapped = $stmt->fetchColumn();
    if ($mapped) return (int) $mapped;
    
    // 2. Fuzzy match against vendors
    $stmt = $pdo->prepare("
        SELECT id FROM vendors 
        WHERE LOWER(vendor_name) = LOWER(?) 
        OR vendor_name LIKE ?
        LIMIT 1
    ");
    $stmt->execute([$resellerName, '%' . $resellerName . '%']);
    $vendorId = $stmt->fetchColumn();
    
    return $vendorId ? (int) $vendorId : null;
}

/**
 * Get inventory summary: total value (£), total units, avg weeks of stock.
 * Returns null if sales_out_inventory table does not exist.
 * @param string $distributor Optional distributor filter
 * @param int $weeksBack Weeks of sales for run-rate (default 8)
 */
function getInventorySummary($pdo, string $distributor = '', int $weeksBack = 8): ?array {
    try {
        $ok = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
        if (!$ok) return null;
    } catch (PDOException $e) { return null; }

    $latestDate = $pdo->query("SELECT MAX(snapshot_date) FROM sales_out_inventory")->fetchColumn();
    if (!$latestDate) return ['total_value' => 0, 'total_units' => 0, 'avg_weeks' => null];

    $salesFrom = date('Y-m-d', strtotime("-$weeksBack weeks", strtotime($latestDate)));
    $aliases = getInventoryDistributorAliases();
    $distNorm = $distributor ? trim($distributor) : '';
    $distNames = $distNorm ? ($aliases[$distNorm] ?? [$distNorm]) : [];
    $distPlaceholders = $distNames ? implode(',', array_fill(0, count($distNames), 'LOWER(?)')) : '';
    $whereDist = $distPlaceholders ? " AND LOWER(TRIM(i.distributor_name)) IN ($distPlaceholders)" : "";
    $whereLatest = $distPlaceholders ? " AND LOWER(TRIM(distributor_name)) IN ($distPlaceholders)" : "";
    $whereSales = $distPlaceholders ? " AND LOWER(TRIM(s.distributor_name)) IN ($distPlaceholders)" : "";
    $canonJoin = "CASE WHEN LOWER(TRIM(i.distributor_name)) IN ('westcoast','wc') THEN 'westcoast' ELSE LOWER(TRIM(i.distributor_name)) END = CASE WHEN LOWER(TRIM(sa.distributor_name)) IN ('westcoast','wc') THEN 'westcoast' ELSE LOWER(TRIM(sa.distributor_name)) END";

    $params = [];
    if ($distNames) { $params = array_merge($params, $distNames); }
    $params[] = $salesFrom;
    $params[] = $latestDate;
    if ($distNames) { $params = array_merge($params, $distNames); }
    if ($distNames) { $params = array_merge($params, $distNames); }

    $sql = "
        SELECT i.distributor_name, i.sku, i.on_hand_qty, i.inventory_value,
            COALESCE(sa.units_sold, 0) as units_sold, COALESCE(sa.weeks_count, 0) as weeks_count,
            CASE WHEN COALESCE(sa.weeks_count, 0) > 0 AND sa.units_sold > 0
                THEN i.on_hand_qty / (sa.units_sold / sa.weeks_count) ELSE NULL END as weeks_of_stock
        FROM sales_out_inventory i
        INNER JOIN (
            SELECT distributor_name, TRIM(REPLACE(sku,' ','')) as sku_norm, MAX(snapshot_date) as max_date
            FROM sales_out_inventory
            WHERE 1=1 $whereLatest
            GROUP BY distributor_name, sku_norm
        ) latest ON i.distributor_name = latest.distributor_name
            AND TRIM(REPLACE(i.sku,' ','')) = latest.sku_norm
            AND i.snapshot_date = latest.max_date
        LEFT JOIN (
            SELECT s.distributor_name, TRIM(REPLACE(s.sku,' ','')) as sku_norm,
                SUM(s.quantity) as units_sold, COUNT(DISTINCT YEARWEEK(s.report_date)) as weeks_count
            FROM sales_out_raw s
            WHERE s.report_date >= ? AND s.report_date <= ? AND s.sku IS NOT NULL AND s.sku != ''
            $whereSales
            GROUP BY s.distributor_name, sku_norm
        ) sa ON $canonJoin AND TRIM(REPLACE(i.sku,' ','')) = sa.sku_norm
        WHERE 1=1 $whereDist
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalValue = 0;
    $totalUnits = 0;
    $weeksSum = 0;
    $weeksCount = 0;
    foreach ($rows as $r) {
        $totalValue += (float)($r['inventory_value'] ?? 0);
        $totalUnits += (int)($r['on_hand_qty'] ?? 0);
        $w = $r['weeks_of_stock'];
        if ($w !== null && (float)$w >= 0) {
            $weeksSum += (float)$w;
            $weeksCount++;
        }
    }

    return [
        'total_value' => $totalValue,
        'total_units' => $totalUnits,
        'avg_weeks' => $weeksCount > 0 ? round($weeksSum / $weeksCount, 1) : null,
    ];
}

/**
 * Get top inventory SKUs by value for a distributor (latest snapshot).
 * Returns [] if table missing or no data.
 */
function getInventoryTopSkus($pdo, string $distributor, int $limit = 10): array {
    try {
        $ok = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
        if (!$ok) return [];
    } catch (PDOException $e) { return []; }

    $dist = trim($distributor);
    if ($dist === '') return [];

    $aliases = getInventoryDistributorAliases();
    $distNames = $aliases[$dist] ?? [$dist];
    $placeholders = implode(',', array_fill(0, count($distNames), 'LOWER(?)'));

    $stmt = $pdo->prepare("
        SELECT i.sku, i.sku_description, i.on_hand_qty, i.inventory_value, i.snapshot_date
        FROM sales_out_inventory i
        INNER JOIN (
            SELECT distributor_name, TRIM(REPLACE(sku,' ','')) as sku_norm, MAX(snapshot_date) as max_date
            FROM sales_out_inventory WHERE LOWER(TRIM(distributor_name)) IN ($placeholders)
            GROUP BY distributor_name, sku_norm
        ) latest ON i.distributor_name = latest.distributor_name AND TRIM(REPLACE(i.sku,' ','')) = latest.sku_norm AND i.snapshot_date = latest.max_date
        WHERE LOWER(TRIM(i.distributor_name)) IN ($placeholders) AND i.on_hand_qty > 0
        ORDER BY i.inventory_value DESC
        LIMIT " . (int)$limit . "
    ");
    $stmt->execute(array_merge($distNames, $distNames));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get inventory trend (value per snapshot date) for a distributor.
 */
function getInventoryTrend($pdo, string $distributor, int $limitSnapshots = 12): array {
    try {
        $ok = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
        if (!$ok) return [];
    } catch (PDOException $e) { return []; }

    $dist = trim($distributor);
    if ($dist === '') return [];

    $aliases = getInventoryDistributorAliases();
    $distNames = $aliases[$dist] ?? [$dist];
    $placeholders = implode(',', array_fill(0, count($distNames), 'LOWER(?)'));

    $stmt = $pdo->prepare("
        SELECT snapshot_date, SUM(inventory_value) as val, SUM(on_hand_qty) as qty
        FROM sales_out_inventory
        WHERE LOWER(TRIM(distributor_name)) IN ($placeholders)
        GROUP BY snapshot_date
        ORDER BY snapshot_date DESC
        LIMIT " . (int)$limitSnapshots . "
    ");
    $stmt->execute($distNames);
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Get product info by SKU
 */
function getProductBySku($pdo, string $sku): ?array {
    $sku = trim($sku);
    if (empty($sku)) return null;
    
    $stmt = $pdo->prepare("
        SELECT * FROM sales_out_products 
        WHERE sku = ? OR sku = ? OR REPLACE(sku, ' ', '') = REPLACE(?, ' ', '')
        LIMIT 1
    ");
    $stmt->execute([$sku, trim($sku), $sku]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $row ?: null;
}

/**
 * Generate product detail link
 */
function productLink(?string $sku, ?string $productName = null, string $display = null): string {
    if (empty($sku) && empty($productName)) return $display ?? '—';
    $url = 'product_detail.php?' . (!empty($sku) ? 'sku=' . urlencode($sku) : '');
    $text = $display ?? $productName ?? $sku ?? 'Product';
    return '<a href="' . htmlspecialchars($url) . '" class="text-decoration-none">' . htmlspecialchars($text) . '</a>';
}

/**
 * Extract first significant word from a company name (for similarity matching)
 */
function getResellerKeyWord(string $name): string {
    $words = preg_split('/[\s\-\.,&]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $skip = ['ltd', 'limited', 'plc', 'uk', 'the', 'and', 'or', 'group', 'holdings', 'solutions', 'services'];
    foreach ($words as $w) {
        $w = strtolower($w);
        if (strlen($w) >= 2 && !in_array($w, $skip) && !preg_match('/^\d+$/', $w)) {
            return $w;
        }
    }
    return $words[0] ?? '';
}

/**
 * Propose vendor matches for unmatched resellers using similarity.
 * Returns [reseller_name, vendor_id, vendor_name, confidence] — confidence 0–100.
 */
function proposeMappingsForUnmatched($pdo, int $limit = 50, float $minConfidence = 60): array {
    $unmatched = $pdo->query("
        SELECT reseller_name, SUM(total_value) as total
        FROM sales_out_raw
        WHERE reseller_name != '' AND matched_vendor_id IS NULL
        GROUP BY reseller_name
        ORDER BY total DESC
        LIMIT " . (int)$limit
    )->fetchAll(PDO::FETCH_ASSOC);
    $vendors = $pdo->query("SELECT id, vendor_name FROM vendors ORDER BY vendor_name")->fetchAll(PDO::FETCH_ASSOC);
    $proposed = [];
    foreach ($unmatched as $u) {
        $reseller = $u['reseller_name'];
        $best = null;
        $bestScore = 0;
        $resNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $reseller));
        $resWord = getResellerKeyWord($reseller);
        foreach ($vendors as $v) {
            $score = 0;
            $venNorm = strtolower($v['vendor_name']);
            if ($resNorm === preg_replace('/[^a-z0-9]/', '', $venNorm)) {
                $score = 95;
            } elseif (strpos($venNorm, $resWord) !== false || strpos($resNorm, strtolower(preg_replace('/[^a-z0-9]/', '', $v['vendor_name']))) !== false) {
                similar_text($resNorm, preg_replace('/[^a-z0-9]/', '', $venNorm), $pct);
                $score = max($score, $pct);
            } else {
                similar_text($resNorm, preg_replace('/[^a-z0-9]/', '', $venNorm), $pct);
                if ($pct >= 70) $score = $pct;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $v;
            }
        }
        if ($best && $bestScore >= $minConfidence) {
            $proposed[] = [
                'reseller_name' => $reseller,
                'vendor_id' => $best['id'],
                'vendor_name' => $best['vendor_name'],
                'confidence' => round($bestScore, 1),
            ];
        }
    }
    usort($proposed, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
    return $proposed;
}

/**
 * Find unmatched resellers similar to the given one (same key word).
 * For "map similar" bulk action.
 */
function findSimilarUnmatchedResellers($pdo, string $resellerName): array {
    $keyword = getResellerKeyWord($resellerName);
    if ($keyword === '') return [];
    $stmt = $pdo->prepare("
        SELECT reseller_name, SUM(total_value) as total
        FROM sales_out_raw
        WHERE reseller_name != '' AND matched_vendor_id IS NULL
        AND LOWER(reseller_name) LIKE ?
        AND LOWER(TRIM(reseller_name)) != LOWER(?)
        GROUP BY reseller_name
        ORDER BY total DESC
    ");
    $stmt->execute(['%' . $keyword . '%', trim($resellerName)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Normalise column header for flexible matching
 */
function normaliseHeader(string $header): string {
    return strtolower(preg_replace('/[^a-z0-9]/', '', trim($header)));
}

/**
 * Parse a date string from US (MM/DD/YYYY) or UK (DD/MM/YYYY) format to Y-m-d.
 * Tries UK format first, then US format. Returns null on failure.
 */
function parseFlexibleDate(string $val): ?string {
    $val = trim($val);
    if ($val === '') return null;

    $parts = preg_split('/[\/\-\.\s]+/', $val);
    if (count($parts) !== 3) return null;

    $year = null;
    $yearPart = null;
    foreach ($parts as $i => $p) {
        if (strlen($p) === 4 && is_numeric($p)) {
            $year = (int) $p;
            $yearPart = $p;
            break;
        }
    }
    if (!$year && count($parts) === 3) {
        $last = $parts[2];
        if (strlen($last) === 2 && is_numeric($last)) {
            $y = (int) $last;
            $year = ($y >= 0 && $y <= 99) ? 2000 + $y : null;
            $yearPart = $last;
        }
    }
    if (!$year || $yearPart === null) return null;

    $other = array_values(array_filter($parts, fn($p) => $p !== $yearPart));
    if (count($other) !== 2) return null;

    $a = (int) $other[0];
    $b = (int) $other[1];

    // If one value > 12, it must be day (works for both UK and US)
    if ($a > 12 && $b <= 12) {
        $day = $a;
        $month = $b;
    } elseif ($b > 12 && $a <= 12) {
        $day = $b;
        $month = $a;
    } else {
        // Ambiguous (e.g. 05/06/2024): prefer UK (DD/MM/YYYY)
        $day = $a;
        $month = $b;
    }

    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) return null;
    if (!checkdate($month, $day, $year)) return null;

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/**
 * Parse a decimal/currency value from various formats.
 * Handles: European (3,25 or 1.234,56), US (3.25 or 1,234.56), space thousands (1 841.40)
 */
function parseDecimalValue(string $val): float {
    $val = trim((string) $val);
    if ($val === '') return 0.0;
    // Remove currency symbols and nbsp
    $val = str_replace(["\xc2\xa0", "\xc2\xa3", '€', '$', '£', ' '], '', $val);
    $val = trim($val);
    $lastComma = strrpos($val, ',');
    $lastDot = strrpos($val, '.');
    if ($lastComma !== false && $lastDot !== false) {
        // Both present: last occurrence is decimal separator
        if ($lastComma > $lastDot) {
            $val = str_replace('.', '', $val);
            $val = str_replace(',', '.', $val);
        } else {
            $val = str_replace(',', '', $val);
        }
    } elseif ($lastComma !== false) {
        $after = substr($val, $lastComma + 1);
        // 2 digits after comma = decimal (3,25). 3+ digits = thousands (1,234)
        if (preg_match('/^\d{2}$/', $after)) {
            $val = str_replace(',', '.', $val);
        } else {
            $val = str_replace(',', '', $val);
        }
    }
    $val = preg_replace('/[^\d.\-]/', '', $val);
    return $val === '' ? 0.0 : (float) $val;
}

/**
 * Get seasonality percentages by month (1–12). Uses 'default' profile.
 * Returns [1=>5, 2=>5, ..., 12=>10] or fallback if table missing.
 */
function getSeasonalityPercentages($pdo): array {
    try {
        $stmt = $pdo->query("SELECT month_num, pct FROM sales_out_seasonality WHERE name = 'default' ORDER BY month_num");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        $rows = [];
    }
    $pcts = array_fill(1, 12, 0);
    foreach ($rows as $r) {
        $pcts[(int)$r['month_num']] = (float)$r['pct'];
    }
    if (array_sum($pcts) == 0) {
        // Fallback: team Excel defaults
        $pcts = [1=>5, 2=>5, 3=>5, 4=>9, 5=>9, 6=>10, 7=>9, 8=>9, 9=>10, 10=>9, 11=>10, 12=>10];
    }
    return $pcts;
}

/**
 * Get monthly targets from annual target using seasonality.
 * Returns array [1=>value, 2=>value, ... 12=>value] (keys are month numbers).
 */
function getMonthlyTargetsFromAnnual(float $annualTarget, array $seasonalityPcts): array {
    $monthly = [];
    foreach ($seasonalityPcts as $mo => $pct) {
        $monthly[(int)$mo] = round($annualTarget * ($pct / 100), 2);
    }
    return $monthly;
}

/**
 * Get time elapsed through the year (based on today).
 * Returns [pct, days_elapsed, days_total, is_current_year].
 */
function getTimeElapsedForYear(int $year): array {
    $today = new DateTime();
    $currentYear = (int) $today->format('Y');
    if ($year > $currentYear) {
        return ['pct' => 0, 'days_elapsed' => 0, 'days_total' => (int) date('z', strtotime($year . '-12-31')) + 1, 'is_current_year' => false];
    }
    if ($year < $currentYear) {
        $daysTotal = (int) date('z', strtotime($year . '-12-31')) + 1;
        return ['pct' => 100, 'days_elapsed' => $daysTotal, 'days_total' => $daysTotal, 'is_current_year' => false];
    }
    $daysElapsed = (int) $today->format('z') + 1;
    $daysTotal = (int) date('z', strtotime($year . '-12-31')) + 1;
    $pct = $daysTotal > 0 ? round(100 * $daysElapsed / $daysTotal, 1) : 0;
    return ['pct' => $pct, 'days_elapsed' => $daysElapsed, 'days_total' => $daysTotal, 'is_current_year' => true];
}

/**
 * Get target-to-date (seasonality-weighted) for a given year using today's date.
 * For current year: sum completed months + partial current month.
 * For past years: full annual target. For future: 0.
 */
function getTargetToDate(float $annualTarget, array $seasonalityPcts, int $year): float {
    $today = new DateTime();
    $currentYear = (int) $today->format('Y');
    $currentMonth = (int) $today->format('n');
    $dayOfMonth = (int) $today->format('j');
    $daysInMonth = (int) $today->format('t');

    if ($year > $currentYear) return 0;
    if ($year < $currentYear) return $annualTarget;

    $pctToDate = 0;
    for ($m = 1; $m < $currentMonth; $m++) {
        $pctToDate += $seasonalityPcts[$m] ?? 0;
    }
    $pctToDate += ($seasonalityPcts[$currentMonth] ?? 0) * ($dayOfMonth / $daysInMonth);
    return round($annualTarget * ($pctToDate / 100), 2);
}

// --- Opportunities (Google Sheet or Salesforce API) ---

/** Google Sheet CSV URL for opportunities (sheet must be published to web). */
function getOpportunitiesSheetUrl(): string {
    $id = defined('SALESOUT_OPPORTUNITIES_SHEET_ID') ? SALESOUT_OPPORTUNITIES_SHEET_ID : '';
    $gid = defined('SALESOUT_OPPORTUNITIES_SHEET_GID') ? SALESOUT_OPPORTUNITIES_SHEET_GID : '0';
    return "https://docs.google.com/spreadsheets/d/{$id}/export?format=csv&gid={$gid}";
}

/**
 * Fetch and parse opportunities from the live Google Sheet.
 * Returns ['rows' => array of associative rows, 'headers' => first row keys, 'error' => string or null].
 */
function fetchOpportunitiesFromSheet(): array {
    $url = getOpportunitiesSheetUrl();
    $ctx = stream_context_create([
        'http' => ['timeout' => 15, 'user_agent' => 'SalesOut-Opportunities/1.0'],
        'ssl' => ['verify_peer' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['rows' => [], 'headers' => [], 'error' => 'Could not fetch sheet. Ensure the sheet is published to web (File → Share → Publish to web).'];
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    if (empty($lines)) {
        return ['rows' => [], 'headers' => [], 'error' => 'Sheet is empty.'];
    }
    $headers = str_getcsv(array_shift($lines));
    $headers = array_map('trim', $headers);
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $cells = str_getcsv($line);
        $row = [];
        foreach ($headers as $i => $h) {
            $row[trim($h)] = $cells[$i] ?? '';
        }
        $rows[] = normalizeOpportunityRow($row, $headers);
    }
    return ['rows' => $rows, 'headers' => $headers, 'error' => null];
}

/**
 * Fetch opportunities directly from Salesforce using the SalesforceClient (password grant).
 * Returns the same shape as fetchOpportunitiesFromSheet().
 */
function fetchOpportunitiesFromSalesforce(): array {
    if (!defined('SALESOUT_SF_ENABLED') || SALESOUT_SF_ENABLED !== true) {
        return ['rows' => [], 'headers' => [], 'error' => 'Salesforce integration is not enabled.'];
    }
    if (!class_exists('SalesforceClient')) {
        require_once dirname(__DIR__) . '/salesforce_api.php';
    }
    $cfg = [
        'client_id' => SALESOUT_SF_CLIENT_ID,
        'client_secret' => SALESOUT_SF_CLIENT_SECRET,
        'username' => SALESOUT_SF_USERNAME,
        'password' => SALESOUT_SF_PASSWORD,
        'security_token' => SALESOUT_SF_SECURITY_TOKEN,
    ];
    if (empty($cfg['client_id']) || empty($cfg['client_secret']) || empty($cfg['username']) || empty($cfg['password']) || empty($cfg['security_token'])) {
        return ['rows' => [], 'headers' => [], 'error' => 'Salesforce credentials are not configured.'];
    }
    $client = new \SalesforceClient($cfg);
    if (!$client->authenticate()) {
        return ['rows' => [], 'headers' => [], 'error' => 'Could not authenticate with Salesforce. Check credentials and Connected App configuration.'];
    }

    // SOQL: pull recent and relevant opportunities (filter stages here similar to sheet logic).
    // Adjust WHERE clause as needed for your org.
    $soql = "
        SELECT Id, Name, StageName, Amount, Status__c,
               Opportunity_Referred_by__c,
               EPOS_Contact__c,
               EPOS_Email__c,
               Account_Owner__c,
               ProjectNo__c,
               Project_No__c,
               Opportunity_Age__c
        FROM Opportunity
        WHERE IsDeleted = false
          AND (StageName != 'Closed Lost')
          AND CloseDate >= LAST_N_DAYS:365
    ";
    try {
        $records = $client->query($soql);
    } catch (\Throwable $e) {
        return ['rows' => [], 'headers' => [], 'error' => 'Salesforce query failed: ' . $e->getMessage()];
    }
    if (empty($records)) {
        return ['rows' => [], 'headers' => [], 'error' => null];
    }
    // Normalise records to the same structure as sheet rows.
    $rows = [];
    $headers = getOpportunityCanonicalColumns();
    foreach ($records as $rec) {
        $row = [];
        foreach ($rec as $k => $v) {
            if ($k === 'attributes') continue;
            $row[$k] = is_array($v) ? json_encode($v) : $v;
        }
        $rows[] = normalizeOpportunityRow($row, array_keys($row));
    }
    return ['rows' => $rows, 'headers' => $headers, 'error' => null];
}

/**
 * Wrapper: fetch opportunities when feature is enabled.
 * If SALESOUT_OPPORTUNITIES_ENABLED is false, returns an empty result immediately.
 */
function fetchOpportunities(): array {
    if (!defined('SALESOUT_OPPORTUNITIES_ENABLED') || SALESOUT_OPPORTUNITIES_ENABLED !== true) {
        return ['rows' => [], 'headers' => [], 'error' => 'Opportunities are disabled in configuration.'];
    }
    if (defined('SALESOUT_SF_ENABLED') && SALESOUT_SF_ENABLED === true) {
        $res = fetchOpportunitiesFromSalesforce();
        if ($res['error'] === null && !empty($res['rows'])) {
            return $res;
        }
        // Fall back to sheet if Salesforce is empty or temporarily failing
    }
    return fetchOpportunitiesFromSheet();
}

/** Normalize value for matching (trim, lower). */
function oppNorm(string $v): string {
    return strtolower(trim($v));
}

/** Canonical opportunity column names we use in code. */
function getOpportunityCanonicalColumns(): array {
    return [
        'Id',
        'Opportunity_Referred_by__c',
        'EPOS_Contact__c',
        'EPOS_Email__c',
        'Name',
        'ProjectNo__c',
        'Project_No__c',
        'Amount',
        'Account_Owner__c',
        'Status',
        'Status__c',
        'StageName',
        'Opportunity_Age__c',
    ];
}

/**
 * Aliases for opportunity columns (sheet header variations -> canonical key).
 * Google Sheets / CSV exports may use different labels than the Salesforce API name.
 */
function getOpportunityColumnAliases(): array {
    return [
        'Opportunity_Age__c' => ['opportunity age', 'age (days)', 'age', 'opportunity age __c', 'age in days', 'days', 'opportunity_age__c'],
    ];
}

/**
 * Map sheet headers to canonical column names (case-insensitive, trim).
 * Returns associative row with canonical keys so code can use $row['Name'] etc.
 * Handles field name variations and aliases (e.g. "Opportunity Age" -> Opportunity_Age__c).
 */
function normalizeOpportunityRow(array $row, array $headers): array {
    $canon = getOpportunityCanonicalColumns();
    $aliases = getOpportunityColumnAliases();
    $out = $row;
    foreach ($headers as $i => $h) {
        $key = trim((string) $h);
        $keyLower = strtolower($key);
        // Exact match to canonical name
        foreach ($canon as $c) {
            if (strtolower($c) === $keyLower) {
                $out[$c] = $row[$key] ?? $row[$c] ?? '';
                break;
            }
        }
        // Alias match (e.g. "Opportunity Age" -> Opportunity_Age__c)
        foreach ($aliases as $canonKey => $variants) {
            foreach ($variants as $variant) {
                if ($variant === $keyLower) {
                    $out[$canonKey] = $row[$key] ?? $out[$canonKey] ?? '';
                    break 2;
                }
            }
        }
    }
    // Map Status__c to Status if Status doesn't exist
    if (isset($out['Status__c']) && !isset($out['Status'])) {
        $out['Status'] = $out['Status__c'];
    }
    // Map Project_No__c to ProjectNo__c if ProjectNo__c doesn't exist
    if (isset($out['Project_No__c']) && !isset($out['ProjectNo__c'])) {
        $out['ProjectNo__c'] = $out['Project_No__c'];
    }
    return $out;
}

/**
 * Sort opportunities: Closed Won deals first, then by Opportunity_Age__c (low to high), then by StageName, then by Amount (desc).
 * Only includes: Qualifying and Solution presentation, Testing and Evaluation, Quotation and Negotiation,
 * Product Development, Contract Drafting, Closed Won.
 */
function sortOpportunities(array $oppRows): array {
    usort($oppRows, function($a, $b) {
        $aFields = opportunityDisplayFields($a);
        $bFields = opportunityDisplayFields($b);
        $aStage = trim($aFields['stage_name'] ?? '');
        $bStage = trim($bFields['stage_name'] ?? '');
        $aStageLower = strtolower($aStage);
        $bStageLower = strtolower($bStage);
        $aAmount = $aFields['amount'] ?? 0;
        $bAmount = $bFields['amount'] ?? 0;
        
        // Parse Opportunity_Age__c (days as number)
        $aAge = isset($aFields['age_days']) && $aFields['age_days'] !== null ? (float) $aFields['age_days'] : 999999;
        $bAge = isset($bFields['age_days']) && $bFields['age_days'] !== null ? (float) $bFields['age_days'] : 999999;
        
        // Closed Won deals first
        $aIsWon = $aStageLower === 'closed won';
        $bIsWon = $bStageLower === 'closed won';
        if ($aIsWon !== $bIsWon) {
            return $aIsWon ? -1 : 1;
        }
        
        // Then by Opportunity_Age__c (low to high - ascending)
        if ($aAge !== $bAge) {
            return $aAge <=> $bAge; // Ascending (low to high)
        }
        
        // Then by StageName (A-Z)
        if ($aStage !== $bStage) {
            return strcmp($aStage, $bStage);
        }
        
        // Then by Amount (desc)
        return $bAmount <=> $aAmount;
    });
    return $oppRows;
}

/**
 * Filter opportunities for a vendor (reseller).
 * Matches Opportunity_Referred_by__c to vendor name or salesforce_id.
 * $vendorId is vendors.id; we load vendor_name and salesforce_id from DB.
 */
function getOpportunitiesForVendor(array $oppRows, $pdo, int $vendorId): array {
    $stmt = $pdo->prepare("SELECT id, vendor_name, salesforce_id FROM vendors WHERE id = ?");
    $stmt->execute([$vendorId]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) return [];
    $nameNorm = oppNorm($vendor['vendor_name'] ?? '');
    $sfIdNorm = oppNorm($vendor['salesforce_id'] ?? '');
    $out = [];
    foreach ($oppRows as $row) {
        $ref = oppNorm($row['Opportunity_Referred_by__c'] ?? $row['Opportunity_Referred_by__c'] ?? '');
        if ($ref === '' && isset($row['Opportunity_Referred_by__c'])) $ref = oppNorm($row['Opportunity_Referred_by__c']);
        if ($ref === '') continue;
        if ($ref !== $nameNorm && $ref !== $sfIdNorm) continue;
        $out[] = $row;
    }
    return sortOpportunities($out);
}

/**
 * Filter opportunities for an account manager.
 * Matches EPOS_Contact__c or EPOS_Email__c to owner name (e.g. Owner_Full_Name__c).
 * Uses exact match or contains so "John Smith" matches "John Smith" or "Smith, John".
 */
function getOpportunitiesForAccountManager(array $oppRows, string $ownerName): array {
    $ownerNorm = oppNorm($ownerName);
    if ($ownerNorm === '') return [];
    $out = [];
    foreach ($oppRows as $row) {
        $contact = oppNorm($row['EPOS_Contact__c'] ?? '');
        $email = oppNorm($row['EPOS_Email__c'] ?? '');
        $match = ($contact !== '' && ($contact === $ownerNorm || strpos($contact, $ownerNorm) !== false || strpos($ownerNorm, $contact) !== false))
            || ($email !== '' && ($email === $ownerNorm || strpos($email, $ownerNorm) !== false));
        if (!$match) continue;
        $out[] = $row;
    }
    return sortOpportunities($out);
}

/** Pick display fields from an opportunity row (Id, Vendor Name, EPOS contact, Name, ProjectNo__c/Project_No__c, Amount, Account_Owner__c, Status/Status__c, StageName, Opportunity_Age__c). */
function opportunityDisplayFields(array $row): array {
    // Handle both ProjectNo__c and Project_No__c
    $projectNo = trim($row['ProjectNo__c'] ?? $row['Project_No__c'] ?? '');
    // Handle both Status and Status__c
    $status = trim($row['Status__c'] ?? $row['Status'] ?? '');
    // Opportunity_Age__c (days)
    $ageDays = isset($row['Opportunity_Age__c']) && $row['Opportunity_Age__c'] !== '' ? (float) $row['Opportunity_Age__c'] : null;
    
    return [
        'id' => trim($row['Id'] ?? ''),
        'vendor_name' => trim($row['Opportunity_Referred_by__c'] ?? ''),
        'epos_contact' => trim($row['EPOS_Contact__c'] ?? ''),
        'epos_email' => trim($row['EPOS_Email__c'] ?? ''),
        'name' => trim($row['Name'] ?? ''),
        'project_no' => $projectNo,
        'amount' => isset($row['Amount']) ? (float) $row['Amount'] : null,
        'account_owner' => trim($row['Account_Owner__c'] ?? ''),
        'status' => $status,
        'stage_name' => trim($row['StageName'] ?? ''),
        'age_days' => $ageDays,
    ];
}

/**
 * Lookup vendor ID and name from Opportunity_Referred_by__c (vendor name or Salesforce ID).
 * Returns ['vendor_id' => int|null, 'vendor_name' => string] for each opportunity.
 */
function lookupVendorForOpportunity($pdo, string $referredBy): array {
    $refNorm = oppNorm($referredBy);
    if ($refNorm === '') return ['vendor_id' => null, 'vendor_name' => $referredBy];
    
    // Try by Salesforce ID first
    $stmt = $pdo->prepare("SELECT id, vendor_name FROM vendors WHERE LOWER(TRIM(salesforce_id)) = ? LIMIT 1");
    $stmt->execute([$refNorm]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($vendor) {
        return ['vendor_id' => (int)$vendor['id'], 'vendor_name' => $vendor['vendor_name']];
    }
    
    // Try by vendor name (exact match)
    $stmt = $pdo->prepare("SELECT id, vendor_name FROM vendors WHERE LOWER(TRIM(vendor_name)) = ? LIMIT 1");
    $stmt->execute([$refNorm]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($vendor) {
        return ['vendor_id' => (int)$vendor['id'], 'vendor_name' => $vendor['vendor_name']];
    }
    
    // Try by vendor name (contains)
    $stmt = $pdo->prepare("SELECT id, vendor_name FROM vendors WHERE LOWER(vendor_name) LIKE ? LIMIT 1");
    $stmt->execute(['%' . $refNorm . '%']);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($vendor) {
        return ['vendor_id' => (int)$vendor['id'], 'vendor_name' => $vendor['vendor_name']];
    }
    
    return ['vendor_id' => null, 'vendor_name' => $referredBy];
}

/**
 * Format "Vendor (Referred by)" for display: show vendor name from our table and the raw ID/value in parentheses when matched.
 * Returns ['display' => string, 'vendor_id' => int|null] for optional link to reseller report.
 */
function getVendorReferredByDisplay($pdo, string $rawReferredBy): array {
    $rawReferredBy = trim($rawReferredBy);
    if ($rawReferredBy === '') return ['display' => '—', 'vendor_id' => null];
    $info = lookupVendorForOpportunity($pdo, $rawReferredBy);
    if ($info['vendor_id'] !== null) {
        return [
            'display' => $info['vendor_name'] . ' (' . $rawReferredBy . ')',
            'vendor_id' => $info['vendor_id'],
        ];
    }
    return ['display' => $rawReferredBy, 'vendor_id' => null];
}

/**
 * Group opportunities by vendor (lookup vendor IDs and names).
 * Returns array keyed by vendor_id (or 'unmatched' for null), each with ['vendor_id' => int|null, 'vendor_name' => string, 'opportunities' => array].
 */
function groupOpportunitiesByVendor($pdo, array $oppRows): array {
    $grouped = [];
    foreach ($oppRows as $row) {
        $refBy = trim($row['Opportunity_Referred_by__c'] ?? '');
        $vendorInfo = lookupVendorForOpportunity($pdo, $refBy);
        $vendorId = $vendorInfo['vendor_id'];
        $vendorName = $vendorInfo['vendor_name'];
        $key = $vendorId !== null ? (string)$vendorId : 'unmatched';
        
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorName,
                'opportunities' => [],
            ];
        }
        $grouped[$key]['opportunities'][] = $row;
    }
    // Sort opportunities within each group
    foreach ($grouped as $key => $group) {
        $grouped[$key]['opportunities'] = sortOpportunities($group['opportunities']);
    }
    return $grouped;
}

/** Generate Salesforce opportunity URL from Id. */
function getSalesforceOpportunityUrl(string $id): string {
    if (empty(trim($id))) return '';
    return "https://senncom.lightning.force.com/lightning/r/Opportunity/" . urlencode(trim($id)) . "/view";
}
