<?php
// functions.php - Add this function at the top

function generatePONumber($region, $country, $prefix = 'PO') {
    $region_codes = [
        'AMER' => 'AMER', 'DACH' => 'DACH', 'UKI' => 'UKI', 'APAC' => 'APAC',
        'ANZ' => 'ANZ', 'NORD' => 'NORD', 'BNL' => 'BNL', 'FRANCE' => 'FR'
    ];
    
    $country_codes = [
        'America' => 'US', 'Canada' => 'CA', 'Germany' => 'DE', 'Austria' => 'AT',
        'Switzerland' => 'CH', 'UK' => 'UK', 'Ireland' => 'IE', 'Hong Kong' => 'HK',
        'Singapore' => 'SG', 'China' => 'CN', 'Japan' => 'JP', 'India' => 'IN',
        'Australia' => 'AU', 'New Zealand' => 'NZ', 'Denmark' => 'DK', 'Sweden' => 'SE',
        'Norway' => 'NO', 'Finland' => 'FI', 'Iceland' => 'IS', 'Belgium' => 'BE',
        'Netherlands' => 'NL', 'Luxembourg' => 'LU', 'France' => 'FR'
    ];
    
    $region_code = $region_codes[$region] ?? 'GL';
    $country_code = $country_codes[$country] ?? 'XX';
    $random_id = substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyz', 5)), 0, 5);
    
    return $region_code . '-' . $country_code . '-' . $prefix . '-' . $random_id;
}

function getCountryFlag($countryCode) {
    $flagMap = [
        'UK' => '🇬🇧',
        'US' => '🇺🇸',
        'CA' => '🇨🇦',
        'DE' => '🇩🇪',
        'FR' => '🇫🇷',
        'IT' => '🇮🇹',
        'ES' => '🇪🇸',
        'AU' => '🇦🇺',
        'JP' => '🇯🇵',
        'CN' => '🇨🇳',
        'IN' => '🇮🇳',
        'BR' => '🇧🇷',
        'MX' => '🇲🇽',
        'NL' => '🇳🇱',
        'SE' => '🇸🇪',
        'NO' => '🇳🇴',
        'DK' => '🇩🇰',
        'FI' => '🇫🇮',
        'IE' => '🇮🇪',
        'BE' => '🇧🇪',
        'CH' => '🇨🇭',
        'AT' => '🇦🇹',
        'PT' => '🇵🇹',
        'GR' => '🇬🇷',
        'PL' => '🇵🇱',
        'CZ' => '🇨🇿',
        'HU' => '🇭🇺',
        'RO' => '🇷🇴',
        'RU' => '🇷🇺',
        'ZA' => '🇿🇦',
        'AE' => '🇦🇪',
        'SA' => '🇸🇦',
        'KR' => '🇰🇷',
        'SG' => '🇸🇬',
        'NZ' => '🇳🇿',
    ];
    
    return $flagMap[$countryCode] ?? '🏴';
}

function formatCurrency($amount, $currency = 'EUR') {
    global $CURRENCY_SYMBOLS;
    $symbol = $CURRENCY_SYMBOLS[$currency] ?? '€';
    return $symbol . number_format($amount, 2);
}

function getRegionalBudgetLimit($region, $year = null) {
    $pdo = getDBConnection();
    $budget_info = getRegionalBudgetFromDB($pdo, $region, $year);
    return $budget_info['budget_limit'];
}

function getRegionalColor($region) {
    global $REGIONAL_SETTINGS;
    return $REGIONAL_SETTINGS[$region]['color'] ?? '#666666';
}

function getRegionCountries($region) {
    global $REGIONAL_SETTINGS;
    return $REGIONAL_SETTINGS[$region]['countries'] ?? [];
}

function getCountryRegionGroup($country) {
    $regionGroups = [
        'AMER' => ['US', 'CA'], // America, Canada
        'DACH' => ['DE', 'AT', 'CH'], // Germany, Austria, Switzerland
        'UKI' => ['UK', 'IE'], // UK, Ireland
        'APAC' => ['HK', 'SG', 'CN', 'JP', 'IN'], // Hong Kong, Singapore, China, Japan, India
        'ANZ' => ['AU', 'NZ'], // Australia, New Zealand
        'NORD' => ['DK', 'SE', 'NO'], // Denmark, Sweden, Norway
        'BNL' => ['BE', 'NL'], // Belgium, Netherlands
        'FRANCE' => ['FR'], // France
    ];
    
    foreach ($regionGroups as $regionGroup => $countries) {
        if (in_array($country, $countries)) {
            return $regionGroup;
        }
    }
    
    return $country; // Return the country code if no group found
}

function getCountryRegion($country) {
    $regionGroup = getCountryRegionGroup($country);
    
    // Map the region groups to the main regions
    $groupToRegion = [
        'AMER' => 'NA',
        'DACH' => 'EMEA', 
        'UKI' => 'EMEA',
        'APAC' => 'APAC',
        'ANZ' => 'APAC',
        'NORD' => 'EMEA',
        'BNL' => 'EMEA',
        'FRANCE' => 'EMEA',
    ];
    
    return $groupToRegion[$regionGroup] ?? 'Global';
}

function getStatusBadge($status) {
    $badges = [
        'Planned' => 'secondary',
        'Invoiced' => 'success',
        'Executed' => 'primary',
        'Cancelled' => 'danger',
        'Allocated' => 'warning'
    ];
    
    $color = $badges[$status] ?? 'secondary';
    return "<span class='glass-badge bg-$color'>$status</span>";
}

function getStatusColor($status) {
    $colors = [
        'Planned' => 'info',
        'Invoiced' => 'warning',
        'Executed' => 'success',
        'Cancelled' => 'secondary',
        'Allocated' => 'primary'
    ];
    return $colors[$status] ?? 'light';
}

function getStatusColorForCss($status) {
    $hex = [
        'Planned' => '#3498db',
        'Invoiced' => '#f39c12',
        'Executed' => '#2ecc71',
        'Cancelled' => '#e74c3c',
        'Allocated' => '#9b59b6'
    ];
    return $hex[$status] ?? '#95a5a6';
}

function getRegionalBadge($region, $country = null) {
    $color = getRegionalColor($region);
    $badge = "<span class='region-badge' style='background-color: $color;'>$region</span>";
    if ($country) {
        $badge .= " <small class='text-muted'>($country)</small>";
    }
    return $badge;
}

/**
 * SQL fragment (use with AND ...) — whether a row counts toward calendar year $year for budget reporting.
 * - budget_accrual_approved = 1 (default): legacy — start_date / end_date / entry_creation year.
 * - budget_accrual_approved = 0: use invoiced_date year when invoiced_date is set; else legacy.
 *
 * @param string $alias Table alias with trailing dot, e.g. 'bi.' or '' for unqualified columns
 */
function budgetYearMatchSql($alias = '') {
    $t = $alias;
    return "(
        (COALESCE({$t}budget_accrual_approved, 1) = 1 AND (
            YEAR({$t}start_date) = ? OR YEAR({$t}end_date) = ? OR ({$t}start_date IS NULL AND YEAR({$t}entry_creation_date) = ?)
        ))
        OR
        (COALESCE({$t}budget_accrual_approved, 1) = 0 AND (
            ({$t}invoiced_date IS NOT NULL AND YEAR({$t}invoiced_date) = ?)
            OR
            ({$t}invoiced_date IS NULL AND (
                YEAR({$t}start_date) = ? OR YEAR({$t}end_date) = ? OR ({$t}start_date IS NULL AND YEAR({$t}entry_creation_date) = ?)
            ))
        ))
    )";
}

/** Positional parameters for budgetYearMatchSql (same year repeated 7 times). */
function budgetYearMatchParams($year) {
    return [$year, $year, $year, $year, $year, $year, $year];
}

/**
 * JOIN to conversion_rates with normalized currency code (fixes INR vs stray whitespace/case).
 */
function conversionRatesJoinSql($alias = 'bi') {
    $norm = "UPPER(TRIM(COALESCE(NULLIF(TRIM({$alias}.currency), ''), 'EUR')))";
    return "LEFT JOIN conversion_rates cr ON cr.target_currency = $norm";
}

/**
 * SQL divisor: amount in local currency / this value = EUR (same semantics as convertToEUR in config.php).
 * Uses DB rate when join matches; otherwise falls back to $DEFAULT_CONVERSION_RATES.
 *
 * INDIA region: amounts are often stored in ₹ but currency left as EUR/blank (defaults to EUR). Those rows must
 * not divide by 1 — treat as INR using the configured INR rate.
 */
function eurConversionDivisorSqlExpr($alias = 'bi') {
    global $DEFAULT_CONVERSION_RATES;
    $c = "UPPER(TRIM(COALESCE(NULLIF(TRIM({$alias}.currency), ''), 'EUR')))";
    $regionCol = "{$alias}.region";
    $inr = (float) ($DEFAULT_CONVERSION_RATES['INR'] ?? 90);
    $whens = [];
    foreach ($DEFAULT_CONVERSION_RATES as $ccy => $rate) {
        $ccyEsc = str_replace("'", "''", $ccy);
        $whens[] = "WHEN $c = '$ccyEsc' THEN " . (float) $rate;
    }
    $case = 'CASE ' . implode(' ', $whens) . ' ELSE 1 END';
    $fallback = "COALESCE(NULLIF(cr.exchange_rate, 0), ($case))";
    return "NULLIF(CASE WHEN $regionCol = 'INDIA' AND $c = 'EUR' THEN $inr ELSE $fallback END, 0)";
}

// ADD THESE FUNCTIONS TO YOUR EXISTING functions.php

/**
 * Get total spent for a region, optionally filtered by year
 * Now includes ALL statuses by default, not just 'Invoiced'
 */
function getRegionalSpent($pdo, $region, $year = null, $status_filter = null) {
    $sql = "SELECT SUM(IF(IFNULL(status, '') = 'Cancelled', 0, amount_requested)) as total FROM budget_items WHERE region = ?";
    $params = [$region];
    
    // Add year filter if specified - FIXED: Check for 'all'
    if ($year && $year !== 'all') {
        $sql .= " AND " . budgetYearMatchSql();
        $params = array_merge($params, budgetYearMatchParams($year));
    }
    
    // Add status filter if specified
    if ($status_filter) {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'] ?? 0;
}

/**
 * Get total spent for a region with optional year, status and advanced filters
 * Overload: getRegionalSpentWithFilters($pdo, $whereClause, $params) for raw WHERE
 * Standard: getRegionalSpentWithFilters($pdo, $region, $year, $status_filter, $additional_filters)
 */
function getRegionalSpentWithFilters($pdo, $region_or_where, $year_or_params = null, $status_filter = null, $additional_filters = []) {
    // Overload: ($pdo, $whereClause, $params) when 2nd is string and 3rd is array
    if (is_string($region_or_where) && is_array($year_or_params)) {
        $sql = "SELECT SUM(IF(IFNULL(status, '') = 'Cancelled', 0, amount_requested)) as total FROM budget_items WHERE " . $region_or_where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($year_or_params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($result['total'] ?? 0);
    }

    $region = $region_or_where;
    $year = $year_or_params;
    $sql = "SELECT SUM(IF(IFNULL(status, '') = 'Cancelled', 0, amount_requested)) as total FROM budget_items WHERE region = ?";
    $params = [$region];

    if ($year && $year !== 'all') {
        $sql .= " AND " . budgetYearMatchSql();
        $params = array_merge($params, budgetYearMatchParams($year));
    }

    if ($status_filter) {
        $sql .= " AND status = ?";
        $params[] = $status_filter;
    }

    // Mirror getRegionalItems advanced filter logic
    if (!empty($additional_filters)) {
        if (isset($additional_filters['amount_min']) && $additional_filters['amount_min'] !== '') {
            $sql .= " AND amount_requested >= ?";
            $params[] = floatval($additional_filters['amount_min']);
        }
        if (isset($additional_filters['amount_max']) && $additional_filters['amount_max'] !== '') {
            $sql .= " AND amount_requested <= ?";
            $params[] = floatval($additional_filters['amount_max']);
        }
        if (!empty($additional_filters['account'])) {
            $sql .= " AND account = ?";
            $params[] = $additional_filters['account'];
        }
        if (!empty($additional_filters['sub_account'])) {
            $sql .= " AND sub_account = ?";
            $params[] = $additional_filters['sub_account'];
        }
        if (!empty($additional_filters['start_date_from'])) {
            $sql .= " AND start_date >= ?";
            $params[] = $additional_filters['start_date_from'];
        }
        if (!empty($additional_filters['start_date_to'])) {
            $sql .= " AND start_date <= ?";
            $params[] = $additional_filters['start_date_to'];
        }
        if (!empty($additional_filters['end_date_from'])) {
            $sql .= " AND end_date >= ?";
            $params[] = $additional_filters['end_date_from'];
        }
        if (!empty($additional_filters['end_date_to'])) {
            $sql .= " AND end_date <= ?";
            $params[] = $additional_filters['end_date_to'];
        }
        if (!empty($additional_filters['invoiced_date_from'])) {
            $sql .= " AND invoiced_date >= ?";
            $params[] = $additional_filters['invoiced_date_from'];
        }
        if (!empty($additional_filters['invoiced_date_to'])) {
            $sql .= " AND invoiced_date <= ?";
            $params[] = $additional_filters['invoiced_date_to'];
        }
        if (!empty($additional_filters['associated_epos_staff'])) {
            $sql .= " AND associated_epos_staff = ?";
            $params[] = $additional_filters['associated_epos_staff'];
        }
        if (!empty($additional_filters['country'])) {
            $sql .= " AND country = ?";
            $params[] = $additional_filters['country'];
        }
        if (!empty($additional_filters['po_number'])) {
            $sql .= " AND po_number LIKE ?";
            $params[] = '%' . $additional_filters['po_number'] . '%';
        }
        if (!empty($additional_filters['vendor'])) {
            $sql .= " AND vendor LIKE ?";
            $params[] = '%' . $additional_filters['vendor'] . '%';
        }
        if (!empty($additional_filters['external_vendor'])) {
            $sql .= " AND external_vendor LIKE ?";
            $params[] = '%' . $additional_filters['external_vendor'] . '%';
        }
        if (!empty($additional_filters['activity_title'])) {
            $sql .= " AND activity_title LIKE ?";
            $params[] = '%' . $additional_filters['activity_title'] . '%';
        }
        if (!empty($additional_filters['frequency'])) {
            $sql .= " AND frequency_of_spend = ?";
            $params[] = $additional_filters['frequency'];
        }
        if (!empty($additional_filters['budget_category'])) {
            $sql .= " AND budget_category = ?";
            $params[] = $additional_filters['budget_category'];
        }
        if (!empty($additional_filters['item_type'])) {
            $sql .= " AND item_type = ?";
            $params[] = $additional_filters['item_type'];
        }
        if (isset($additional_filters['sf_match'])) {
            if ($additional_filters['sf_match'] === 'matched') {
                $sql .= " AND project_code IS NOT NULL AND project_code != ''";
            } elseif ($additional_filters['sf_match'] === 'unmatched') {
                $sql .= " AND (project_code IS NULL OR project_code = '')";
            }
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'] ?? 0;
}

/**
 * Get regional items with optional year and status filtering
 * Now includes advanced filtering capabilities
 */
function getRegionalItems($pdo, $region, $year = null, $status_filter = null, $additional_filters = []) {
    $sql = "SELECT bi.*, 
            COALESCE(bi.project_code, v.salesforce_id) as salesforce_id,
            v.id as vendor_id,
            v.account_type,
            v.AMPLIFY_Level__c,
            v.Account_Status__c
            FROM budget_items bi
            LEFT JOIN vendors v ON TRIM(LOWER(bi.vendor)) = TRIM(LOWER(v.vendor_name))
            WHERE bi.region = ?";
    $params = [$region];
    
    // Add year filter if specified - FIXED: Check for 'all'
    if ($year && $year !== 'all') {
        $sql .= " AND " . budgetYearMatchSql('bi.');
        $params = array_merge($params, budgetYearMatchParams($year));
    }
    
    // Add status filter if specified
    if ($status_filter) {
        $sql .= " AND bi.status = ?";
        $params[] = $status_filter;
    }
    
    // Add advanced filters
    if (!empty($additional_filters)) {
        // Amount range
        if (isset($additional_filters['amount_min']) && $additional_filters['amount_min'] !== '') {
            $sql .= " AND bi.amount_requested >= ?";
            $params[] = floatval($additional_filters['amount_min']);
        }
        
        if (isset($additional_filters['amount_max']) && $additional_filters['amount_max'] !== '') {
            $sql .= " AND bi.amount_requested <= ?";
            $params[] = floatval($additional_filters['amount_max']);
        }
        
        // Account filters
        if (isset($additional_filters['account']) && $additional_filters['account'] !== '') {
            $sql .= " AND bi.account = ?";
            $params[] = $additional_filters['account'];
        }
        
        if (isset($additional_filters['sub_account']) && $additional_filters['sub_account'] !== '') {
            $sql .= " AND bi.sub_account = ?";
            $params[] = $additional_filters['sub_account'];
        }
        
        // Date filters
        if (isset($additional_filters['start_date_from']) && $additional_filters['start_date_from'] !== '') {
            $sql .= " AND bi.start_date >= ?";
            $params[] = $additional_filters['start_date_from'];
        }
        
        if (isset($additional_filters['start_date_to']) && $additional_filters['start_date_to'] !== '') {
            $sql .= " AND bi.start_date <= ?";
            $params[] = $additional_filters['start_date_to'];
        }
        
        if (isset($additional_filters['end_date_from']) && $additional_filters['end_date_from'] !== '') {
            $sql .= " AND bi.end_date >= ?";
            $params[] = $additional_filters['end_date_from'];
        }
        
        if (isset($additional_filters['end_date_to']) && $additional_filters['end_date_to'] !== '') {
            $sql .= " AND bi.end_date <= ?";
            $params[] = $additional_filters['end_date_to'];
        }
        
        if (isset($additional_filters['invoiced_date_from']) && $additional_filters['invoiced_date_from'] !== '') {
            $sql .= " AND bi.invoiced_date >= ?";
            $params[] = $additional_filters['invoiced_date_from'];
        }
        
        if (isset($additional_filters['invoiced_date_to']) && $additional_filters['invoiced_date_to'] !== '') {
            $sql .= " AND bi.invoiced_date <= ?";
            $params[] = $additional_filters['invoiced_date_to'];
        }
        
        // Staff and country
        if (isset($additional_filters['associated_epos_staff']) && $additional_filters['associated_epos_staff'] !== '') {
            $sql .= " AND bi.associated_epos_staff = ?";
            $params[] = $additional_filters['associated_epos_staff'];
        }
        
        if (isset($additional_filters['country']) && $additional_filters['country'] !== '') {
            $sql .= " AND bi.country = ?";
            $params[] = $additional_filters['country'];
        }
        
        // Search filters (partial matches)
        if (isset($additional_filters['po_number']) && $additional_filters['po_number'] !== '') {
            $sql .= " AND bi.po_number LIKE ?";
            $params[] = '%' . $additional_filters['po_number'] . '%';
        }
        
        if (isset($additional_filters['vendor']) && $additional_filters['vendor'] !== '') {
            $sql .= " AND bi.vendor LIKE ?";
            $params[] = '%' . $additional_filters['vendor'] . '%';
        }
        
        if (isset($additional_filters['external_vendor']) && $additional_filters['external_vendor'] !== '') {
            $sql .= " AND bi.external_vendor LIKE ?";
            $params[] = '%' . $additional_filters['external_vendor'] . '%';
        }
        
        if (isset($additional_filters['activity_title']) && $additional_filters['activity_title'] !== '') {
            $sql .= " AND bi.activity_title LIKE ?";
            $params[] = '%' . $additional_filters['activity_title'] . '%';
        }
        
        // Dropdown filters
        if (isset($additional_filters['frequency']) && $additional_filters['frequency'] !== '') {
            $sql .= " AND bi.frequency_of_spend = ?";
            $params[] = $additional_filters['frequency'];
        }
        
        if (isset($additional_filters['budget_category']) && $additional_filters['budget_category'] !== '') {
            $sql .= " AND bi.budget_category = ?";
            $params[] = $additional_filters['budget_category'];
        }
        
        if (isset($additional_filters['item_type']) && $additional_filters['item_type'] !== '') {
            $sql .= " AND bi.item_type = ?";
            $params[] = $additional_filters['item_type'];
        }
        
        // Salesforce match
        if (isset($additional_filters['sf_match'])) {
            if ($additional_filters['sf_match'] === 'matched') {
                $sql .= " AND (bi.project_code IS NOT NULL AND bi.project_code != '' OR v.salesforce_id IS NOT NULL)";
            } elseif ($additional_filters['sf_match'] === 'unmatched') {
                $sql .= " AND (bi.project_code IS NULL OR bi.project_code = '') AND (v.salesforce_id IS NULL OR v.salesforce_id = '')";
            }
        }
    }
    
    $sql .= " ORDER BY entry_creation_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get status distribution for a region/year
 */
function getRegionalStatusDistribution($pdo, $region, $year = null) {
    $sql = "SELECT status, COUNT(*) as count, SUM(amount_requested) as total 
            FROM budget_items 
            WHERE region = ?";
    $params = [$region];
    
    // FIXED: Check for 'all'
    if ($year && $year !== 'all') {
        $sql .= " AND " . budgetYearMatchSql();
        $params = array_merge($params, budgetYearMatchParams($year));
    }
    
    $sql .= " GROUP BY status ORDER BY total DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get available years for a region from the data
 */
function getAvailableYearsForRegion($pdo, $region) {
    $sql = "SELECT DISTINCT YEAR(start_date) as year 
            FROM budget_items 
            WHERE region = ? AND start_date IS NOT NULL 
            UNION 
            SELECT DISTINCT YEAR(end_date) as year 
            FROM budget_items 
            WHERE region = ? AND end_date IS NOT NULL 
            UNION
            SELECT DISTINCT YEAR(entry_creation_date) as year
            FROM budget_items
            WHERE region = ? AND entry_creation_date IS NOT NULL
            UNION
            SELECT DISTINCT YEAR(invoiced_date) as year
            FROM budget_items
            WHERE region = ? AND invoiced_date IS NOT NULL AND COALESCE(budget_accrual_approved, 1) = 0
            ORDER BY year DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$region, $region, $region, $region]);
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // If no years found, add current and next year as defaults
    if (empty($years)) {
        $current_year = date('Y');
        $years = [$current_year, $current_year + 1];
    }
    
    return $years;
}

/**
 * Get regional summary with year filtering
 */
function getRegionalSummary($pdo, $region, $year = null) {
    $sql = "SELECT 
                COUNT(*) as total_items,
                SUM(IF(IFNULL(status, '') = 'Cancelled', 0, amount_requested)) as total_amount,
                SUM(CASE WHEN status = 'Planned' THEN amount_requested ELSE 0 END) as planned_amount,
                SUM(CASE WHEN status = 'Invoiced' THEN amount_requested ELSE 0 END) as invoiced_amount,
                SUM(CASE WHEN status = 'Executed' THEN amount_requested ELSE 0 END) as executed_amount,
                SUM(CASE WHEN status = 'Cancelled' THEN amount_requested ELSE 0 END) as cancelled_amount,
                SUM(CASE WHEN status = 'Allocated' THEN amount_requested ELSE 0 END) as allocated_amount,
                AVG(CASE WHEN IFNULL(status, '') != 'Cancelled' THEN amount_requested END) as average_amount,
                MIN(start_date) as earliest_date,
                MAX(start_date) as latest_date
            FROM budget_items 
            WHERE region = ?";
    $params = [$region];
    
    // FIXED: Check for 'all'
    if ($year && $year !== 'all') {
        $sql .= " AND " . budgetYearMatchSql();
        $params = array_merge($params, budgetYearMatchParams($year));
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // CHANGED: Use getRegionalBudgetFromDB instead of getRegionalBudgetLimit
    $budget_info = getRegionalBudgetFromDB($pdo, $region, $year);
    $budget_limit = $budget_info['budget_limit'];
    $total_spent = $result['total_amount'] ?? 0;
    
    return [
        'total_items' => $result['total_items'] ?? 0,
        'total_amount' => $total_spent,
        'budget_limit' => $budget_limit,
        'remaining_budget' => $budget_limit - $total_spent,
        'utilization_percentage' => $budget_limit > 0 ? min(100, ($total_spent / $budget_limit) * 100) : 0,
        'average_amount' => $result['average_amount'] ?? 0,
        'planned_amount' => $result['planned_amount'] ?? 0,
        'invoiced_amount' => $result['invoiced_amount'] ?? 0,
        'executed_amount' => $result['executed_amount'] ?? 0,
        'cancelled_amount' => $result['cancelled_amount'] ?? 0,
        'allocated_amount' => $result['allocated_amount'] ?? 0,
        'earliest_date' => $result['earliest_date'] ?? null,
        'latest_date' => $result['latest_date'] ?? null
    ];
}

/**
 * Convert currency using conversion_rates table
 * Renamed to avoid conflict with config.php
 */
function convertCurrencyAmount($amount, $from_currency, $to_currency, $pdo) {
    if ($from_currency === $to_currency) {
        return $amount;
    }
    
    // Special case: if converting TO EUR
    if ($to_currency === 'EUR') {
        $stmt = $pdo->prepare("
            SELECT exchange_rate 
            FROM conversion_rates 
            WHERE target_currency = ?
        ");
        $stmt->execute([$from_currency]);
        $rate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rate) {
            // Convert to EUR: amount / rate (since rate is EUR to currency)
            return $amount / $rate['exchange_rate'];
        }
        return $amount; // Fallback
    }
    
    // Special case: if converting FROM EUR
    if ($from_currency === 'EUR') {
        $stmt = $pdo->prepare("
            SELECT exchange_rate 
            FROM conversion_rates 
            WHERE target_currency = ?
        ");
        $stmt->execute([$to_currency]);
        $rate = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rate) {
            // Convert from EUR: amount * rate
            return $amount * $rate['exchange_rate'];
        }
        return $amount; // Fallback
    }
    
    // Convert between two non-EUR currencies
    // First get rate for from_currency
    $stmt = $pdo->prepare("
        SELECT exchange_rate 
        FROM conversion_rates 
        WHERE target_currency = ?
    ");
    $stmt->execute([$from_currency]);
    $from_rate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Then get rate for to_currency
    $stmt->execute([$to_currency]);
    $to_rate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($from_rate && $to_rate) {
        // Convert to EUR first, then to target currency
        $amount_in_eur = $amount / $from_rate['exchange_rate'];
        return $amount_in_eur * $to_rate['exchange_rate'];
    }
    
    return $amount; // Fallback
}

// Then ADD this wrapper function that calls convertCurrencyAmount()
function convertCurrency($amount, $from_currency, $to_currency, $pdo) {
    // Use the new function but keep the same name for compatibility
    return convertCurrencyAmount($amount, $from_currency, $to_currency, $pdo);
}

/**
 * Convert EUR amount to local currency
 * Renamed to avoid conflict with config.php
 */
function convertEURtoLocalCurrency($amount_eur, $local_currency, $pdo) {
    if ($local_currency === 'EUR') {
        return $amount_eur;
    }
    
    $stmt = $pdo->prepare("
        SELECT exchange_rate 
        FROM conversion_rates 
        WHERE target_currency = ?
    ");
    $stmt->execute([$local_currency]);
    $rate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($rate) {
        return $amount_eur * $rate['exchange_rate'];
    }
    
    return $amount_eur; // Fallback
}

/**
 * Convert local currency to EUR
 * Renamed to avoid conflict with config.php
 */
function convertLocalCurrencytoEUR($amount_local, $local_currency, $pdo) {
    if ($local_currency === 'EUR') {
        return $amount_local;
    }
    
    $stmt = $pdo->prepare("
        SELECT exchange_rate 
        FROM conversion_rates 
        WHERE target_currency = ?
    ");
    $stmt->execute([$local_currency]);
    $rate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($rate) {
        return $amount_local / $rate['exchange_rate'];
    }
    
    return $amount_local; // Fallback
}

/**
 * Convert an amount already normalized to EUR into display currency (conversion_rates, then $DEFAULT_CONVERSION_RATES).
 */
function convertEURToDisplayCurrency(float $amountEur, string $displayCurrency, PDO $pdo): float {
    if ($displayCurrency === 'EUR') {
        return $amountEur;
    }
    global $DEFAULT_CONVERSION_RATES;
    try {
        $stmt = $pdo->prepare('SELECT exchange_rate FROM conversion_rates WHERE target_currency = ? LIMIT 1');
        $stmt->execute([$displayCurrency]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $rate = ($row && (float) $row['exchange_rate'] > 0) ? (float) $row['exchange_rate'] : null;
        if ($rate === null && isset($DEFAULT_CONVERSION_RATES[$displayCurrency])) {
            $rate = (float) $DEFAULT_CONVERSION_RATES[$displayCurrency];
        }
        if ($rate !== null && $rate > 0) {
            return $amountEur * $rate;
        }
    } catch (Throwable $e) {
        /* ignore */
    }
    return $amountEur;
}

/** Scale array values from EUR to display currency (reports chart series). */
function convertReportSeriesFromEUR(array $series, string $displayCurrency, PDO $pdo): array {
    if ($displayCurrency === 'EUR') {
        return $series;
    }
    foreach ($series as $k => $v) {
        $series[$k] = convertEURToDisplayCurrency((float) $v, $displayCurrency, $pdo);
    }
    return $series;
}

/**
 * Format currency with conversion if needed
 */
function formatCurrencyWithConversion($amount, $currency = 'EUR', $region = null, $pdo = null) {
    global $CURRENCY_SYMBOLS, $REGIONAL_SETTINGS;
    
    // If no PDO provided, try to get it
    if (!$pdo) {
        $pdo = getDBConnection();
    }
    
    $symbol = $CURRENCY_SYMBOLS[$currency] ?? '€';
    
    // If amount is in EUR but we want to display in local currency
    if ($currency == 'EUR' && $region && isset($REGIONAL_SETTINGS[$region])) {
        $local_currency = $REGIONAL_SETTINGS[$region]['currency'] ?? 'EUR';
        
        if ($local_currency !== 'EUR') {
            $local_amount = convertEURtoLocalCurrency($amount, $local_currency, $pdo);
            $local_symbol = $CURRENCY_SYMBOLS[$local_currency] ?? '€';
            return $local_symbol . number_format($local_amount, 2);
        }
    }
    
    return $symbol . number_format($amount, 2);
}

// Add these functions to your existing functions.php file

/**
 * Get global summary for a specific year
 */
function getGlobalSummary($pdo, $year = null) {
    $year = $year ?? date('Y');
    
    $query = "
        SELECT 
            region,
            SUM(amount_requested) as total_amount,
            COUNT(*) as total_items
        FROM budget_items 
        WHERE " . budgetYearMatchSql() . "
        GROUP BY region
        ORDER BY region
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(budgetYearMatchParams($year));
    $regional_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    global $REGIONAL_SETTINGS;
    $summary = [];
    
    foreach ($REGIONAL_SETTINGS as $region => $settings) {
        $budget_info = getRegionalBudgetFromDB($pdo, $region, $year);
        $budget_limit = $budget_info['budget_limit'] ?? $settings['budget_limit'] ?? 0;
        $budget_currency = $budget_info['currency'] ?? $settings['currency'] ?? 'EUR';
        
        // Find this region's data
        $region_data = array_filter($regional_data, function($item) use ($region) {
            return $item['region'] == $region;
        });
        $region_data = reset($region_data);
        
        $summary[$region] = [
            'budget_limit' => $budget_limit,
            'budget_currency' => $budget_currency,
            'total_amount' => $region_data['total_amount'] ?? 0,
            'total_items' => $region_data['total_items'] ?? 0,
            'remaining_budget' => $budget_limit - ($region_data['total_amount'] ?? 0),
            'utilization_percentage' => $budget_limit > 0 ? 
                (($region_data['total_amount'] ?? 0) / $budget_limit) * 100 : 0
        ];
    }
    
    return $summary;
}

/**
 * Get summary for all years
 */
function getAllYearsSummary($pdo) {
    $query = "
        SELECT 
            YEAR(start_date) as year,
            SUM(amount_requested) as spent,
            COUNT(*) as items
        FROM budget_items 
        WHERE start_date IS NOT NULL
        GROUP BY YEAR(start_date)
        
        UNION
        
        SELECT 
            YEAR(entry_creation_date) as year,
            SUM(amount_requested) as spent,
            COUNT(*) as items
        FROM budget_items 
        WHERE start_date IS NULL
        GROUP BY YEAR(entry_creation_date)
        
        ORDER BY year DESC
    ";
    
    $stmt = $pdo->query($query);
    $yearly_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Consolidate data by year
    $consolidated = [];
    foreach ($yearly_data as $data) {
        $year = $data['year'];
        if (!isset($consolidated[$year])) {
            $consolidated[$year] = [
                'year' => $year,
                'spent' => 0,
                'items' => 0
            ];
        }
        $consolidated[$year]['spent'] += $data['spent'];
        $consolidated[$year]['items'] += $data['items'];
    }
    
    // Add budget data (aggregated in EUR)
    global $REGIONAL_SETTINGS;
    foreach ($consolidated as &$year_data) {
        $total_budget_eur = 0;
        foreach ($REGIONAL_SETTINGS as $region => $settings) {
            $budget_info = getRegionalBudgetFromDB($pdo, $region, $year_data['year']);
            $bl = $budget_info['budget_limit'] ?? $settings['budget_limit'] ?? 0;
            $ccy = $budget_info['currency'] ?? $settings['currency'] ?? 'EUR';
            $total_budget_eur += ($ccy === 'EUR') ? $bl : convertToEUR($bl, $ccy, $pdo);
        }
        $year_data['budget'] = $total_budget_eur;
    }
    
    return array_values($consolidated);
}

/**
 * Get available years from database
 */
function getAvailableYears($pdo) {
    $query = "
        SELECT DISTINCT YEAR(start_date) as year
        FROM budget_items 
        WHERE start_date IS NOT NULL
        UNION
        SELECT DISTINCT YEAR(entry_creation_date) as year
        FROM budget_items 
        WHERE start_date IS NULL
        UNION
        SELECT DISTINCT YEAR(invoiced_date) as year
        FROM budget_items
        WHERE invoiced_date IS NOT NULL AND COALESCE(budget_accrual_approved, 1) = 0
        ORDER BY year DESC
    ";
    
    $stmt = $pdo->query($query);
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Add current year if not present
    $current_year = date('Y');
    if (!in_array($current_year, $years)) {
        $years[] = $current_year;
    }
    
    // Sort descending
    rsort($years);
    
    return $years;
}

function getFormFieldOptions($field_type, $region_group = null) {
    global $pdo;
    
    $sql = "SELECT field_value, field_label 
            FROM form_field_options 
            WHERE field_type = ? AND is_active = TRUE";
    
    $params = [$field_type];
    
    if ($region_group) {
        $sql .= " AND (region_group IS NULL OR region_group = ?)";
        $params[] = $region_group;
    } else {
        $sql .= " AND region_group IS NULL";
    }
    
    $sql .= " ORDER BY sort_order, field_label";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function importVendorsFromFile($file, $field_type, $region_group = null) {
    $pdo = getDBConnection();
    $imported_count = 0;
    $sort_order = 0;
    
    $filename = $file['tmp_name'];
    
    if (($handle = fopen($filename, 'r')) !== FALSE) {
        // Skip header row
        $header = fgetcsv($handle, 1000, ',');
        
        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
            // Skip empty rows
            if (empty($data[0]) || empty($data[1])) {
                continue;
            }
            
            $vendor_id = trim($data[0]);
            $vendor_name = trim($data[1]);
            $country_code = isset($data[2]) ? trim($data[2]) : '';
            
            // Skip if it looks like a header row
            if (stripos($vendor_id, 'vendor') !== false) {
                continue;
            }
            
            // Extract country from code (e.g., "FR15" -> "FR")
            $country = substr(strtoupper($country_code), 0, 2);
            
            // Auto-detect region group (but don't fail if we can't determine it)
            $final_region_group = $region_group;
            if (!$final_region_group && $country) {
                $final_region_group = getRegionGroupFromCountry($country);
            }
            
            // If we still don't have a region group, use global
            if (!$final_region_group) {
                $final_region_group = null; // This will make it global
            }
            
            $result = saveVendor($pdo, $field_type, $vendor_id, $vendor_name, $final_region_group, $country, $sort_order++);
            $imported_count += $result;
        }
        fclose($handle);
    }
    
    return $imported_count;
}

function getRegionGroupFromCountry($country) {
    // Handle both full country names and 2-letter codes
    $country = strtoupper($country);
    
    $country_to_region_group = [
        // AMER - United States and Canada
        'US' => 'AMER', 'USA' => 'AMER', 'UNITED STATES' => 'AMER', 'AMERICA' => 'AMER',
        'CA' => 'AMER', 'CANADA' => 'AMER',
        
        // DACH - Germany, Austria, Switzerland
        'DE' => 'DACH', 'GERMANY' => 'DACH',
        'AT' => 'DACH', 'AUSTRIA' => 'DACH', 
        'CH' => 'DACH', 'SWITZERLAND' => 'DACH',
        
        // UKI - United Kingdom and Ireland
        'UK' => 'UKI', 'GB' => 'UKI', 'UNITED KINGDOM' => 'UKI', 'GREAT BRITAIN' => 'UKI',
        'IE' => 'UKI', 'IRELAND' => 'UKI',
        
        // APAC - Asia Pacific
        'HK' => 'APAC', 'HONG KONG' => 'APAC',
        'SG' => 'APAC', 'SINGAPORE' => 'APAC',
        'CN' => 'APAC', 'CHINA' => 'APAC',
        'JP' => 'APAC', 'JAPAN' => 'APAC',
        'IN' => 'APAC', 'INDIA' => 'APAC',
        
        // ANZ - Australia and New Zealand
        'AU' => 'ANZ', 'AUSTRALIA' => 'ANZ',
        'NZ' => 'ANZ', 'NEW ZEALAND' => 'ANZ',
        
        // NORD - Nordic countries
        'DK' => 'NORD', 'DENMARK' => 'NORD',
        'SE' => 'NORD', 'SWEDEN' => 'NORD',
        'NO' => 'NORD', 'NORWAY' => 'NORD',
        
        // BNL - Belgium and Netherlands
        'BE' => 'BNL', 'BELGIUM' => 'BNL',
        'NL' => 'BNL', 'NETHERLANDS' => 'BNL',
        
        // FRANCE
        'FR' => 'FRANCE', 'FRANCE' => 'FRANCE'
    ];
    
    return $country_to_region_group[$country] ?? null;
}

function saveVendor($pdo, $field_type, $vendor_id, $vendor_name, $region_group, $country, $sort_order) {
    try {
        // Check if vendor already exists by Vendor ID
        $check_stmt = $pdo->prepare("
            SELECT id FROM form_field_options 
            WHERE field_type = ? AND field_value = ?
        ");
        $check_stmt->execute([$field_type, $vendor_id]);
        
        if (!$check_stmt->fetch()) {
            // Insert new vendor
            $stmt = $pdo->prepare("
                INSERT INTO form_field_options (field_type, field_value, field_label, region_group, country, sort_order, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $field_type,
                $vendor_id,
                $vendor_name,
                $region_group, // This can be null for global vendors
                $country,
                $sort_order
            ]);
            return 1;
        }
        return 0;
    } catch (Exception $e) {
        error_log("Error saving vendor: " . $e->getMessage());
        return 0;
    }
}

// Add to your existing functions.php

function getRegionalBudgetInLocal($region) {
    global $REGIONAL_SETTINGS;
    $pdo = getDBConnection();
    
    $budgetEUR = $REGIONAL_SETTINGS[$region]['budget_limit'] ?? 0;
    $localCurrency = $REGIONAL_SETTINGS[$region]['currency'] ?? 'EUR';
    
    return convertToLocal($budgetEUR, $localCurrency, $pdo);
}

function formatCurrencyLocal($amountEUR, $currency) {
    $localAmount = convertToLocal($amountEUR, $currency);
    return formatCurrency($localAmount, $currency);
}

function getBudgetDisplay($region) {
    global $REGIONAL_SETTINGS;
    $pdo = getDBConnection();
    
    $budgetEUR = $REGIONAL_SETTINGS[$region]['budget_limit'] ?? 0;
    $localCurrency = $REGIONAL_SETTINGS[$region]['currency'] ?? 'EUR';
    
    $localAmount = convertToLocal($budgetEUR, $localCurrency, $pdo);
    
    return [
        'eur' => formatCurrency($budgetEUR, 'EUR'),
        'local' => formatCurrency($localAmount, $localCurrency),
        'local_raw' => $localAmount,
        'currency' => $localCurrency
    ];
}

// Update the global summary to show both EUR and local currencies
function getEnhancedGlobalSummary($pdo) {
    $summary = [];
    global $REGIONAL_SETTINGS;
    
    foreach ($REGIONAL_SETTINGS as $region => $settings) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN status = 'Invoiced' THEN amount_requested ELSE 0 END) as spent,
                SUM(CASE WHEN status = 'Planned' THEN amount_requested ELSE 0 END) as planned
            FROM budget_items 
            WHERE region = ?
        ");
        $stmt->execute([$region]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $spentEUR = $data['spent'] ?? 0;
        $plannedEUR = $data['planned'] ?? 0;
        $budgetEUR = $settings['budget_limit'];
        
        $localCurrency = $settings['currency'];
        $spentLocal = convertToLocal($spentEUR, $localCurrency, $pdo);
        $plannedLocal = convertToLocal($plannedEUR, $localCurrency, $pdo);
        $budgetLocal = convertToLocal($budgetEUR, $localCurrency, $pdo);
        
        $summary[$region] = [
            // EUR amounts
            'budget_eur' => $budgetEUR,
            'spent_eur' => $spentEUR,
            'planned_eur' => $plannedEUR,
            'remaining_eur' => $budgetEUR - $spentEUR,
            
            // Local currency amounts
            'budget_local' => $budgetLocal,
            'spent_local' => $spentLocal,
            'planned_local' => $plannedLocal,
            'remaining_local' => $budgetLocal - $spentLocal,
            
            // Currency info
            'local_currency' => $localCurrency,
            'currency_symbol' => $GLOBALS['CURRENCY_SYMBOLS'][$localCurrency] ?? '€',
            
            // Additional data
            'total_items' => $data['total_items'] ?? 0,
            'countries' => $settings['countries'],
            'color' => $settings['color']
        ];
    }
    
    return $summary;
}

function processImportRow($pdo, $row, $column_mapping, $import_mode = 'insert_new', $duplicate_action = 'overwrite') {
    global $REGIONAL_SETTINGS;
    
    echo "<!-- DEBUG: Starting processImportRow -->\n";
    echo "<!-- DEBUG: Row data: " . htmlspecialchars(json_encode($row)) . " -->\n";
    echo "<!-- DEBUG: Column mapping: " . htmlspecialchars(json_encode($column_mapping)) . " -->\n";
    
    // 1. Find PO Number
    $po_number = '';
    foreach ($row as $key => $value) {
        $normalized_key = strtolower(str_replace([' ', '-', '_'], '', $key));
        if (in_array($normalized_key, ['ponumber', 'po', 'pono'])) {
            $po_number = trim($value);
            break;
        }
    }
    
    if (empty($po_number)) {
        echo "<!-- DEBUG: No PO number found -->\n";
        return [
            'status' => 'skipped',
            'po_number' => 'N/A',
            'message' => 'Missing PO Number'
        ];
    }
    
    echo "<!-- DEBUG: PO Number found: $po_number -->\n";
    
    // 2. Build data array using column mapping
    $data_to_insert = [];
    
    foreach ($column_mapping as $csv_col => $db_col) {
        if ($db_col && isset($row[$csv_col]) && $row[$csv_col] !== '') {
            $value = trim($row[$csv_col]);
            
            // Special handling for specific fields
            switch ($db_col) {
                case 'amount_requested':
                    // Clean amount
                    $clean = preg_replace('/[^0-9\.]/', '', $value);
                    // Handle European decimal format
                    if (strpos($clean, ',') !== false && strpos($clean, '.') === false) {
                        $clean = str_replace(',', '.', $clean);
                    }
                    if (is_numeric($clean) && floatval($clean) > 0) {
                        $data_to_insert[$db_col] = floatval($clean);
                    }
                    break;
                    
                case 'start_date':
                case 'end_date':
                case 'invoiced_date':
                    $data_to_insert[$db_col] = formatDateForDB($value);
                    break;
                    
                default:
                    $data_to_insert[$db_col] = $value;
            }
        }
    }
    
    echo "<!-- DEBUG: Extracted data: " . htmlspecialchars(json_encode($data_to_insert)) . " -->\n";
    
    // 3. Set defaults for required fields
    if (!isset($data_to_insert['region'])) {
        // Extract from PO number
        if (strpos($po_number, '-') !== false) {
            $parts = explode('-', $po_number);
            $data_to_insert['region'] = strtoupper($parts[0]);
        }
    }
    
    if (!isset($data_to_insert['region']) || !isset($REGIONAL_SETTINGS[$data_to_insert['region']])) {
        $data_to_insert['region'] = 'AMER';
    }
    
    if (!isset($data_to_insert['currency']) || empty($data_to_insert['currency'])) {
        $data_to_insert['currency'] = $REGIONAL_SETTINGS[$data_to_insert['region']]['currency'] ?? 'USD';
    }
    
    // CRITICAL: Check if amount exists
    if (!isset($data_to_insert['amount_requested'])) {
        echo "<!-- DEBUG: Amount not found. Available data: " . htmlspecialchars(json_encode($data_to_insert)) . " -->\n";
        return [
            'status' => 'skipped',
            'po_number' => $po_number,
            'message' => 'Invalid amount - field not found'
        ];
    }
    
    if ($data_to_insert['amount_requested'] <= 0) {
        echo "<!-- DEBUG: Amount is 0 or negative: " . $data_to_insert['amount_requested'] . " -->\n";
        return [
            'status' => 'skipped',
            'po_number' => $po_number,
            'message' => 'Invalid amount - must be greater than 0'
        ];
    }
    
    // Set other defaults
    if (!isset($data_to_insert['activity_title']) || empty($data_to_insert['activity_title'])) {
        $data_to_insert['activity_title'] = "Imported Item - $po_number";
    }
    
    if (!isset($data_to_insert['status']) || empty($data_to_insert['status'])) {
        $data_to_insert['status'] = 'Planned';
    }
    
    if (!isset($data_to_insert['associated_epos_staff']) || empty($data_to_insert['associated_epos_staff'])) {
        $data_to_insert['associated_epos_staff'] = 'notspecified@epos.com';
    }
    
    // 4. Check if exists and insert/update
    $stmt = $pdo->prepare("SELECT id FROM budget_items WHERE po_number = ?");
    $stmt->execute([$po_number]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $db_columns = getBudgetTableColumns($pdo);
    $filtered_data = [];
    
    foreach ($data_to_insert as $field => $value) {
        if (in_array($field, $db_columns)) {
            $filtered_data[$field] = $value;
        }
    }
    
    echo "<!-- DEBUG: Filtered data for DB: " . htmlspecialchars(json_encode($filtered_data)) . " -->\n";
    
    if ($existing) {
        // Update
        $fields = [];
        $params = [];
        
        foreach ($filtered_data as $field => $value) {
            if ($field != 'po_number') {
                $fields[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        $params[] = $po_number;
        
        $sql = "UPDATE budget_items SET " . implode(', ', $fields) . ", entry_updated_date = NOW() WHERE po_number = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $action = 'updated';
        $item_id = $existing['id'];
    } else {
        // Insert
        $fields = array_keys($filtered_data);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_values($filtered_data);
        
        $sql = "INSERT INTO budget_items (" . implode(', ', $fields) . ", entry_creation_date, entry_updated_date) 
                VALUES (" . implode(', ', $placeholders) . ", NOW(), NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        
        $action = 'imported';
        $item_id = $pdo->lastInsertId();
    }
    
    echo "<!-- DEBUG: $action successfully -->\n";
    
    return [
        'status' => $action,
        'po_number' => $po_number,
        'id' => $item_id,
        'message' => ucfirst($action) . ' successfully'
    ];
}

function readCSVFileSimple($file_path) {
    $data = [];
    
    if (($handle = fopen($file_path, 'r')) !== FALSE) {
        echo "<!-- DEBUG: Opened CSV file successfully -->\n";
        
        // Get the first row as headers
        $headers = fgetcsv($handle);
        
        // DEBUG: Show headers
        echo "<!-- DEBUG: Raw Headers: " . print_r($headers, true) . " -->\n";
        
        if ($headers === FALSE || empty($headers[0])) {
            fclose($handle);
            echo "<!-- DEBUG: No headers found -->\n";
            return $data;
        }
        
        // Clean and normalize headers
        $normalized_headers = [];
        foreach ($headers as $index => $header) {
            // Remove BOM if present (common in UTF-8 files)
            if ($index === 0) {
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            }
            
            // Trim and lowercase
            $normalized = trim($header);
            echo "<!-- DEBUG: Header $index before: '$header', after: '$normalized' -->\n";
            
            // Convert to lowercase and replace spaces/weird characters
            $normalized = strtolower($normalized);
            $normalized = preg_replace('/[^a-z0-9]/', '_', $normalized);
            $normalized = preg_replace('/_+/', '_', $normalized); // Remove multiple underscores
            $normalized = trim($normalized, '_');
            
            $normalized_headers[$index] = $normalized;
        }
        
        echo "<!-- DEBUG: Normalized Headers: " . print_r($normalized_headers, true) . " -->\n";
        
        // Read data rows
        $row_num = 0;
        while (($row = fgetcsv($handle)) !== FALSE) {
            $row_num++;
            echo "<!-- DEBUG: Raw Row $row_num: " . print_r($row, true) . " -->\n";
            
            if (count($row) > 0 && !empty(implode('', $row))) { // Skip empty rows
                $row_data = [];
                
                // Map each column to its normalized header
                for ($i = 0; $i < count($normalized_headers); $i++) {
                    $column_name = $normalized_headers[$i];
                    $value = isset($row[$i]) ? trim($row[$i]) : '';
                    
                    // Special handling for PO Number - try to extract from various patterns
                    if (($column_name == 'po_number' || $column_name == 'po' || $column_name == 'ponumber') && !empty($value)) {
                        // Clean up PO number - remove quotes, trim whitespace
                        $value = str_replace(['"', "'", 'PO:', 'PO-', 'PO '], '', $value);
                        $value = trim($value);
                    }
                    
                    $row_data[$column_name] = $value;
                }
                
                // DEBUG: Show processed row
                echo "<!-- DEBUG: Processed Row $row_num: " . print_r($row_data, true) . " -->\n";
                
                $data[] = $row_data;
            }
        }
        
        fclose($handle);
        echo "<!-- DEBUG: Found " . count($data) . " data rows -->\n";
    } else {
        echo "<!-- DEBUG: Failed to open CSV file -->\n";
    }
    
    return $data;
}

function processImportFile($pdo, $file_path, $file_ext, $import_mode = 'insert_new', $duplicate_action = 'overwrite', $column_mapping = null) {
    $results = [
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
        'details' => []
    ];
    
    // Only accept CSV for now
    if ($file_ext != 'csv') {
        $results['errors'][] = "Only CSV files are supported. Please convert Excel files to CSV first.";
        return $results;
    }
    
    $data = readCSVFileSimple($file_path);
    
    if (empty($data)) {
        $results['errors'][] = "No data found in file or file is empty.";
        return $results;
    }
    
    // Get CSV headers from first row
    $csv_headers = array_keys($data[0]);
    
    // Get database columns
    $db_columns = getBudgetTableColumns($pdo);
    
    // If no custom mapping provided, auto-map
    if ($column_mapping === null) {
        $column_mapping = mapCSVColumnsToDatabase($csv_headers, $db_columns);
    }
    
    // Log the mapping for debugging
    error_log("Auto-mapped columns: " . json_encode($column_mapping));
    
    // Process each row
    foreach ($data as $row) {
        $result = processImportRow($pdo, $row, $column_mapping, $import_mode, $duplicate_action);
        
        if ($result['status'] == 'imported') {
            $results['imported']++;
        } elseif ($result['status'] == 'updated') {
            $results['updated']++;
        } else {
            $results['skipped']++;
        }
        
        $results['details'][] = $result;
    }
    
    return $results;
}

// Add this function to your functions.php
function getBudgetTableColumns($pdo) {
    $stmt = $pdo->query("DESCRIBE budget_items");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $columns;
}

// Add this function to handle column mapping
function mapCSVColumnsToDatabase($csv_headers, $db_columns) {
    $mapping = [];
    
    // Manual mapping for common CSV headers
    $manual_mapping = [
        'PO Number' => 'po_number',
        'Amount Requested' => 'amount_requested', 
        'Activity Title' => 'activity_title',
        'Associated EPOS Staff' => 'associated_epos_staff',
        'Budget Category' => 'budget_category',
        'Start Date' => 'start_date',
        'End Date' => 'end_date',
        'Invoiced Date' => 'invoiced_date',
        'Item Type' => 'item_type',
        'Frequency of Spend' => 'frequency_of_spend',
        'Activity Description' => 'activity_description',
        'PO Prefix' => 'po_prefix',
        'External Vendor' => 'external_vendor',
        'Vendor Contact' => 'vendor_contact',
        'Sub Account' => 'sub_account'
    ];
    
    foreach ($csv_headers as $csv_header) {
        $csv_lower = strtolower($csv_header);
        $csv_clean = str_replace([' ', '-', '_'], '', $csv_lower);
        
        // First check manual mapping
        if (isset($manual_mapping[$csv_header])) {
            $mapping[$csv_header] = $manual_mapping[$csv_header];
            continue;
        }
        
        // Try to find matching database column
        $found = false;
        foreach ($db_columns as $db_col) {
            $db_lower = strtolower($db_col);
            $db_clean = str_replace([' ', '-', '_'], '', $db_lower);
            
            // Check for exact match or partial match
            if ($csv_clean === $db_clean || 
                strpos($csv_clean, $db_clean) !== false || 
                strpos($db_clean, $csv_clean) !== false) {
                $mapping[$csv_header] = $db_col;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $mapping[$csv_header] = null;
        }
    }
    
    return $mapping;
}

function formatDateForDB($date) {
    if (empty($date)) {
        return null;
    }
    
    $date = trim($date);
    
    // If already in YYYY-MM-DD format
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }
    
    // Handle DD/MM/YYYY format (common in European CSVs)
    if (preg_match('/(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})/', $date, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        
        // Check if date is valid
        if (checkdate($month, $day, $year)) {
            return "$year-$month-$day";
        }
    }
    
    // Try to parse other formats
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return null;
    }
    
    return date('Y-m-d', $timestamp);
}

// ============================================
// ADD THIS AT THE VERY END OF functions.php
// ============================================

/**
 * Get budget from DATABASE for a specific region and year
 */
function getRegionalBudgetFromDB($pdo, $region, $year = null) {
    // FIXED: Handle 'all' year differently
    if ($year === 'all') {
        // For "All Years", sum up all budgets or return a default
        $stmt = $pdo->prepare("
            SELECT SUM(budget_amount) as budget_limit, currency 
            FROM region_budgets 
            WHERE region = ?
            GROUP BY currency
        ");
        $stmt->execute([$region]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($budget) {
            return $budget;
        }
        
        // Fallback: if no budgets in database, use config
        global $REGIONAL_SETTINGS;
        if (isset($REGIONAL_SETTINGS[$region])) {
            return [
                'budget_limit' => $REGIONAL_SETTINGS[$region]['budget_limit'] ?? 0,
                'currency' => $REGIONAL_SETTINGS[$region]['currency'] ?? 'EUR'
            ];
        }
    } else {
        // Specific year requested
        if ($year === null) {
            $year = date('Y');
        }
        
        // Try to get from database first
        $stmt = $pdo->prepare("
            SELECT budget_amount as budget_limit, currency 
            FROM region_budgets 
            WHERE region = ? AND year = ?
        ");
        $stmt->execute([$region, $year]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($budget) {
            return $budget;
        }
        
        // Fallback to default from config
        global $REGIONAL_SETTINGS;
        if (isset($REGIONAL_SETTINGS[$region])) {
            return [
                'budget_limit' => $REGIONAL_SETTINGS[$region]['budget_limit'] ?? 0,
                'currency' => $REGIONAL_SETTINGS[$region]['currency'] ?? 'EUR'
            ];
        }
    }
    
    // Final fallback
    return [
        'budget_limit' => 0,
        'currency' => 'EUR'
    ];
}

/**
 * Regional budget limit for a year, expressed in EUR (cross-region reports).
 */
function getRegionalBudgetLimitInEUR($pdo, $region, $year) {
    $info = getRegionalBudgetFromDB($pdo, $region, $year);
    $limit = (float) ($info['budget_limit'] ?? 0);
    $ccy = $info['currency'] ?? 'EUR';
    if ($limit <= 0) {
        return 0.0;
    }
    return convertToEUR($limit, $ccy, $pdo);
}

/**
 * MBR report: per-region status buckets in EUR (excludes Cancelled).
 * Committed = Allocated + Executed.
 */
function getMbrRegionMetricsInEUR($pdo, $year) {
    if ($year === null || $year === '' || $year === 'all') {
        return [];
    }
    $div = eurConversionDivisorSqlExpr('bi');
    $ym = budgetYearMatchSql('bi.');
    $params = budgetYearMatchParams($year);
    $sql = "
        SELECT bi.region,
            SUM(CASE WHEN bi.status = 'Planned' THEN bi.amount_requested / $div ELSE 0 END) AS planned_eur,
            SUM(CASE WHEN bi.status IN ('Allocated','Executed') THEN bi.amount_requested / $div ELSE 0 END) AS committed_eur,
            SUM(CASE WHEN bi.status = 'Invoiced' THEN bi.amount_requested / $div ELSE 0 END) AS invoiced_eur,
            SUM(CASE WHEN IFNULL(bi.status,'') <> 'Cancelled' THEN bi.amount_requested / $div ELSE 0 END) AS total_used_eur
        FROM budget_items bi
        " . conversionRatesJoinSql('bi') . "
        WHERE IFNULL(bi.status,'') <> 'Cancelled' AND $ym
        GROUP BY bi.region
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['region']] = [
            'planned_eur' => (float) $row['planned_eur'],
            'committed_eur' => (float) $row['committed_eur'],
            'invoiced_eur' => (float) $row['invoiced_eur'],
            'total_used_eur' => (float) $row['total_used_eur'],
        ];
    }
    return $out;
}

/**
 * MBR: top partner spend in EUR for a region (vendor, else external vendor).
 */
function getMbrTopPartnersInEUR($pdo, $region, $year, $limit = 3) {
    if ($year === null || $year === '' || $year === 'all') {
        return [];
    }
    $div = eurConversionDivisorSqlExpr('bi');
    $ym = budgetYearMatchSql('bi.');
    $params = array_merge([$region], budgetYearMatchParams($year));
    $lim = max(1, (int) $limit);
    $partnerExpr = "COALESCE(NULLIF(TRIM(bi.vendor), ''), NULLIF(TRIM(bi.external_vendor), ''), '(Unnamed partner)')";
    $sql = "
        SELECT $partnerExpr AS partner,
            SUM(bi.amount_requested / $div) AS total_eur
        FROM budget_items bi
        " . conversionRatesJoinSql('bi') . "
        WHERE bi.region = ? AND IFNULL(bi.status,'') <> 'Cancelled' AND $ym
        GROUP BY $partnerExpr
        ORDER BY total_eur DESC
        LIMIT $lim
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * MBR: spend in EUR by item_type (labelled "Spend Type" in UI), excl. Cancelled.
 *
 * @return list<array{spend_type:string,total_eur:float,pct:float}>
 */
function getMbrSpendTypeBreakdownInEUR($pdo, $region, $year) {
    if ($year === null || $year === '' || $year === 'all') {
        return [];
    }
    $div = eurConversionDivisorSqlExpr('bi');
    $ym = budgetYearMatchSql('bi.');
    $params = array_merge([$region], budgetYearMatchParams($year));
    $typeExpr = "COALESCE(NULLIF(TRIM(bi.item_type), ''), '(Unspecified)')";
    $sql = "
        SELECT $typeExpr AS spend_type,
            SUM(bi.amount_requested / $div) AS total_eur
        FROM budget_items bi
        " . conversionRatesJoinSql('bi') . "
        WHERE bi.region = ? AND IFNULL(bi.status,'') <> 'Cancelled' AND $ym
        GROUP BY $typeExpr
        ORDER BY total_eur DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    $sum = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sum += (float) $row['total_eur'];
        $rows[] = [
            'spend_type' => (string) $row['spend_type'],
            'total_eur' => (float) $row['total_eur'],
        ];
    }
    $out = [];
    foreach ($rows as $r) {
        $pct = $sum > 0 ? round(100.0 * $r['total_eur'] / $sum, 1) : 0.0;
        $out[] = [
            'spend_type' => $r['spend_type'],
            'total_eur' => $r['total_eur'],
            'pct' => $pct,
        ];
    }
    return $out;
}

/**
 * MBR report: region cards (metrics, budget, top partners) — same data as mbr_report.php.
 *
 * @return list<array<string,mixed>>
 */
function getMbrReportRegionCards($pdo, $selected_year) {
    global $REGIONAL_SETTINGS;
    if ($selected_year === 'all' || $selected_year === '' || $selected_year === null) {
        $selected_year = date('Y');
    }
    $regionDisplayNames = [
        'AMER' => 'Americas',
        'DACH' => 'DACH',
        'UKI' => 'UK & Ireland',
        'APAC' => 'APAC',
        'ANZ' => 'Australia & NZ',
        'NORD' => 'Nordics',
        'BNL' => 'Benelux',
        'FRANCE' => 'France',
        'EMEA_PARTNERS' => 'EMEA Partners',
        'INDIA' => 'India',
    ];
    $regionFlags = [
        'AMER' => "\u{1F1FA}\u{1F1F8}", 'DACH' => "\u{1F1E9}\u{1F1EA}", 'UKI' => "\u{1F1EC}\u{1F1E7}",
        'APAC' => "\u{1F1ED}\u{1F1F0}", 'ANZ' => "\u{1F1E6}\u{1F1FA}", 'NORD' => "\u{1F1F8}\u{1F1EA}",
        'BNL' => "\u{1F1F3}\u{1F1F1}", 'FRANCE' => "\u{1F1EB}\u{1F1F7}", 'EMEA_PARTNERS' => "\u{1F310}",
        'INDIA' => "\u{1F1EE}\u{1F1F3}",
    ];
    $metricsByRegion = getMbrRegionMetricsInEUR($pdo, $selected_year);
    $regionCards = [];
    foreach (array_keys($REGIONAL_SETTINGS) as $reg) {
        $m = $metricsByRegion[$reg] ?? [
            'planned_eur' => 0.0,
            'committed_eur' => 0.0,
            'invoiced_eur' => 0.0,
            'total_used_eur' => 0.0,
        ];
        $budgetEur = getRegionalBudgetLimitInEUR($pdo, $reg, $selected_year);
        $remainingEur = $budgetEur - $m['total_used_eur'];
        $partners = getMbrTopPartnersInEUR($pdo, $reg, $selected_year, 3);
        $spendByType = getMbrSpendTypeBreakdownInEUR($pdo, $reg, $selected_year);
        $plannedPctBudget = $budgetEur > 0 ? round(100.0 * $m['planned_eur'] / $budgetEur, 1) : null;
        $committedPctBudget = $budgetEur > 0 ? round(100.0 * $m['committed_eur'] / $budgetEur, 1) : null;
        $invoicedPctBudget = $budgetEur > 0 ? round(100.0 * $m['invoiced_eur'] / $budgetEur, 1) : null;
        $totalUsedEur = $m['total_used_eur'];
        $totalSpendPctBudget = $budgetEur > 0 ? round(100.0 * $totalUsedEur / $budgetEur, 1) : null;
        $remainingPctBudget = $budgetEur > 0 ? round(100.0 * $remainingEur / $budgetEur, 1) : null;

        // Spend vs time (Executive Summary style — current year vs % of year elapsed)
        $yv = (int) $selected_year;
        $currY = (int) date('Y');
        $isFutureYearSel = ($yv > $currY);
        $isCurrentYearMbr = ($yv === $currY);
        $daysInYear = (($yv % 4 === 0 && $yv % 100 !== 0) || ($yv % 400 === 0)) ? 366 : 365;
        if ($isCurrentYearMbr) {
            $daysElapsed = max(0, (int) floor((time() - strtotime($yv . '-01-01')) / 86400));
        } else {
            $daysElapsed = ($yv < $currY) ? $daysInYear : 0;
        }
        $timeElapsedPct = ($daysInYear > 0 && !$isFutureYearSel)
            ? min(100.0, $daysElapsed / $daysInYear * 100)
            : 0.0;
        $expectedToDateEur = ($budgetEur > 0 && !$isFutureYearSel && $daysInYear > 0)
            ? ($budgetEur * ($daysElapsed / $daysInYear))
            : 0.0;
        $pctAheadSchedule = null;
        if ($isFutureYearSel || $budgetEur <= 0) {
            $pctAheadSchedule = null;
        } elseif ($expectedToDateEur > 0.01) {
            $pctAheadSchedule = round(100.0 * ($totalUsedEur / $expectedToDateEur) - 100.0, 1);
        } elseif ($totalUsedEur > 0 && $isCurrentYearMbr) {
            $pctAheadSchedule = round(100.0 * ($totalUsedEur / $budgetEur) - 100.0, 1);
        } else {
            $pctAheadSchedule = 0.0;
        }
        $usagePctBar = $budgetEur > 0 ? min(100.0, ($totalUsedEur / $budgetEur) * 100.0) : 0.0;
        $refLinePct = ($isCurrentYearMbr && !$isFutureYearSel) ? min(99.0, max(0.0, $timeElapsedPct)) : 100.0;

        $color = $REGIONAL_SETTINGS[$reg]['color'] ?? '#00a399';
        $regionCards[] = [
            'region' => $reg,
            'title' => $regionDisplayNames[$reg] ?? $reg,
            'flag' => $regionFlags[$reg] ?? '',
            'color' => $color,
            'planned_eur' => $m['planned_eur'],
            'committed_eur' => $m['committed_eur'],
            'invoiced_eur' => $m['invoiced_eur'],
            'total_used_eur' => $totalUsedEur,
            'budget_eur' => $budgetEur,
            'remaining_eur' => $remainingEur,
            'planned_pct_budget' => $plannedPctBudget,
            'committed_pct_budget' => $committedPctBudget,
            'invoiced_pct_budget' => $invoicedPctBudget,
            'total_spend_pct_budget' => $totalSpendPctBudget,
            'remaining_pct_budget' => $remainingPctBudget,
            'spend_by_type' => $spendByType,
            'partners' => $partners,
            'mbr_is_current_year' => $isCurrentYearMbr,
            'mbr_is_future_year' => $isFutureYearSel,
            'mbr_time_elapsed_pct' => round($timeElapsedPct, 1),
            'mbr_expected_to_date_eur' => round($expectedToDateEur, 2),
            'mbr_pct_ahead_schedule' => $pctAheadSchedule,
            'mbr_usage_pct_bar' => round($usagePctBar, 1),
            'mbr_ref_line_pct' => round($refLinePct, 1),
        ];
    }
    return $regionCards;
}

// ========== Reports dashboard helper functions ==========

/** Scale numeric columns on each row from EUR to display currency (reports tables/lists). */
function convertReportRowsMoneyFromEUR(array $rows, array $amountKeys, string $displayCurrency, PDO $pdo): array {
    if ($displayCurrency === 'EUR') {
        return $rows;
    }
    foreach ($rows as &$row) {
        foreach ($amountKeys as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = convertEURToDisplayCurrency((float) $row[$k], $displayCurrency, $pdo);
            }
        }
    }
    unset($row);
    return $rows;
}

/**
 * Overload only: same filters as getRegionalSpentWithFilters($pdo, $whereClause, $params); amounts normalized to EUR.
 */
function getRegionalSpentWithFiltersInEUR($pdo, $whereClause, $params) {
    $wc = prefixBudgetItemsWhereClause($whereClause);
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT COALESCE(SUM(IF(IFNULL(bi.status, '') = 'Cancelled', 0, bi.amount_requested / $div)), 0) as total
        FROM budget_items bi
        " . conversionRatesJoinSql('bi') . "
        WHERE $wc";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($r['total'] ?? 0);
    } catch (PDOException $e) {
        return 0.0;
    }
}

/** Get total spend converted to EUR (global/all-regions view). */
function getSpendInEUR($pdo, $whereClause, $params) {
    $whereClause = prefixBudgetItemsWhereClause($whereClause);
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT COALESCE(SUM(bi.amount_requested / $div), 0) as total 
            FROM budget_items bi 
            " . conversionRatesJoinSql('bi') . "
            WHERE $whereClause";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float)($r['total'] ?? 0);
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Get spend for a date range, converted to EUR (for global period comparison).
 */
function getPeriodSpendInEUR($pdo, $periodWhere, $periodParamsBase, $dateFrom, $dateTo) {
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT COALESCE(SUM(bi.amount_requested / $div), 0) as total 
            FROM budget_items bi 
            " . conversionRatesJoinSql('bi') . "
            WHERE $periodWhere 
            AND (bi.start_date BETWEEN ? AND ? OR (bi.start_date IS NULL AND bi.entry_creation_date BETWEEN ? AND ?))";
    $params = array_merge($periodParamsBase, [$dateFrom, $dateTo, $dateFrom, $dateTo]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)($stmt->fetchColumn() ?: 0);
}

/**
 * Get spend by region, converted to EUR (for global view region split).
 */
function getRegionSplitInEUR($pdo, $yearCond, $yearParams) {
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT region, COUNT(*) as item_count, 
            SUM(bi.amount_requested / $div) as total 
            FROM budget_items bi 
            " . conversionRatesJoinSql('bi') . "
            WHERE 1=1 $yearCond 
            GROUP BY region ORDER BY total DESC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($yearParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getFilterOptions($pdo, $column, $region) {
    $allowed = ['country', 'vendor', 'external_vendor', 'account', 'sub_account', 'associated_epos_staff', 'status'];
    if (!in_array($column, $allowed)) return [];
    $stmt = $pdo->prepare("SELECT DISTINCT `$column` FROM budget_items WHERE region = ? AND `$column` != '' AND `$column` IS NOT NULL ORDER BY `$column`");
    $stmt->execute([$region]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

function getFilterOptionsAllRegions($pdo, $column) {
    $allowed = ['country', 'vendor', 'external_vendor', 'account', 'sub_account', 'associated_epos_staff', 'status'];
    if (!in_array($column, $allowed)) return [];
    $stmt = $pdo->query("SELECT DISTINCT `$column` FROM budget_items WHERE `$column` != '' AND `$column` IS NOT NULL ORDER BY `$column`");
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

function getFilteredItemsCount($pdo, $whereClause, $params) {
    $sql = "SELECT COUNT(*) as cnt FROM budget_items WHERE $whereClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int) ($r['cnt'] ?? 0);
}

function getStatusColorHex($status) {
    $hex = [
        'Planned' => '#3498db',
        'Invoiced' => '#f39c12',
        'Executed' => '#2ecc71',
        'Cancelled' => '#e74c3c',
        'Allocated' => '#9b59b6'
    ];
    return $hex[$status] ?? '#95a5a6';
}

function getCountryFlagClass($countryOrRegion) {
    $map = [
        'AMER' => 'ti ti-world', 'America' => 'ti ti-flag', 'Canada' => 'ti ti-flag',
        'DACH' => 'ti ti-flag', 'Germany' => 'ti ti-flag', 'Austria' => 'ti ti-flag', 'Switzerland' => 'ti ti-flag',
        'UKI' => 'ti ti-flag', 'UK' => 'ti ti-flag', 'Ireland' => 'ti ti-flag',
        'APAC' => 'ti ti-world', 'ANZ' => 'ti ti-flag', 'NORD' => 'ti ti-flag',
        'BNL' => 'ti ti-flag', 'FRANCE' => 'ti ti-flag', 'France' => 'ti ti-flag',
        'EMEA_PARTNERS' => 'ti ti-flag', 'INDIA' => 'ti ti-flag'
    ];
    return $map[$countryOrRegion] ?? 'ti ti-flag';
}

/**
 * Get vendor match stats with amounts converted to EUR (for global view).
 */
function getVendorMatchStatsInEUR($pdo, $whereClause, $params) {
    $whereClause = prefixBudgetItemsWhereClause($whereClause);
    $div = eurConversionDivisorSqlExpr('bi');
    $conv = "bi.amount_requested / $div";
    $sql = "SELECT 
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') 
             OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as matched_count,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') 
             AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as unmatched_count,
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') 
             OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN $conv ELSE 0 END) as matched_spend,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') 
             AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN $conv ELSE 0 END) as unmatched_spend,
        SUM($conv) as total_budget
        FROM budget_items bi 
        " . conversionRatesJoinSql('bi') . "
        WHERE $whereClause";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return ['matched_count' => 0, 'unmatched_count' => 0, 'matched_spend' => 0, 'unmatched_spend' => 0, 'total_budget' => 0];
    }
}

function getVendorMatchStats($pdo, $whereClause, $params) {
    $sql = "SELECT 
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') 
             OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as matched_count,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') 
             AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as unmatched_count,
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') 
             OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN bi.amount_requested ELSE 0 END) as matched_spend,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') 
             AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN bi.amount_requested ELSE 0 END) as unmatched_spend,
        SUM(bi.amount_requested) as total_budget
        FROM budget_items bi WHERE $whereClause";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return ['matched_count' => 0, 'unmatched_count' => 0, 'matched_spend' => 0, 'unmatched_spend' => 0, 'total_budget' => 0];
    }
}

/** Prefix budget_items columns in WHERE clause for use in joined queries (avoids ambiguous column errors). */
function prefixBudgetItemsWhereClause($whereClause) {
    $cols = ['region', 'country', 'vendor', 'external_vendor', 'account', 'sub_account', 'associated_epos_staff', 'status', 'start_date', 'end_date', 'entry_creation_date', 'invoiced_date', 'budget_accrual_approved'];
    foreach ($cols as $col) {
        $whereClause = preg_replace('/\b' . preg_quote($col, '/') . '\b(?!\s*\.)/', 'bi.' . $col, $whereClause);
    }
    return $whereClause;
}

function getSpendByAccountType($pdo, $region, $whereClause, $params) {
    $wc = prefixBudgetItemsWhereClause($whereClause);
    $sql = "SELECT COALESCE(NULLIF(TRIM(v.account_type), ''), 'Other') as account_type, SUM(bi.amount_requested) as total_spent
        FROM budget_items bi
        LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
        WHERE $wc
        GROUP BY COALESCE(NULLIF(TRIM(v.account_type), ''), 'Other') ORDER BY total_spent DESC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getSpendByAccountTypeInEUR($pdo, $region, $whereClause, $params) {
    $wc = prefixBudgetItemsWhereClause($whereClause);
    $div = eurConversionDivisorSqlExpr('bi');
    $conv = "bi.amount_requested / $div";
    $sql = "SELECT COALESCE(NULLIF(TRIM(v.account_type), ''), 'Other') as account_type, SUM($conv) as total_spent
        FROM budget_items bi
        LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
        " . conversionRatesJoinSql('bi') . "
        WHERE $wc
        GROUP BY COALESCE(NULLIF(TRIM(v.account_type), ''), 'Other') ORDER BY total_spent DESC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getSpendByAmplifyLevel($pdo, $region, $whereClause, $params) {
    try {
        // Check AMPLIFY_Level__c column exists (may be missing in older schemas)
        $hasCol = (bool) $pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch();
        if (!$hasCol) {
            return [];
        }
        $wc = prefixBudgetItemsWhereClause($whereClause);
        $sql = "SELECT COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level, SUM(bi.amount_requested) as total_spent
            FROM budget_items bi
            LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
            WHERE $wc
            GROUP BY COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') ORDER BY total_spent DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getSpendByAmplifyLevelInEUR($pdo, $region, $whereClause, $params) {
    try {
        $hasCol = (bool) $pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch();
        if (!$hasCol) {
            return [];
        }
        $wc = prefixBudgetItemsWhereClause($whereClause);
        $div = eurConversionDivisorSqlExpr('bi');
        $conv = "bi.amount_requested / $div";
        $sql = "SELECT COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level, SUM($conv) as total_spent
            FROM budget_items bi
            LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
            " . conversionRatesJoinSql('bi') . "
            WHERE $wc
            GROUP BY COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') ORDER BY total_spent DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getTopSpendingVendors($pdo, $region, $limit, $whereClause, $params) {
    $limit = (int) $limit;
    $p = array_merge($params, [$limit]);
    try {
        $wc = prefixBudgetItemsWhereClause($whereClause);
        $sql = "SELECT bi.vendor as vendor_name, v.salesforce_id, v.account_type, v.AMPLIFY_Level__c, v.Account_Status__c,
            COUNT(*) as item_count, SUM(bi.amount_requested) as total_spent, AVG(bi.amount_requested) as avg_spend_per_item
            FROM budget_items bi
            LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
            WHERE $wc
            GROUP BY bi.vendor, v.salesforce_id, v.account_type, v.AMPLIFY_Level__c, v.Account_Status__c
            ORDER BY total_spent DESC LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getTopSpendingVendorsInEUR($pdo, $region, $limit, $whereClause, $params) {
    $limit = (int) $limit;
    $p = array_merge($params, [$limit]);
    try {
        $wc = prefixBudgetItemsWhereClause($whereClause);
        $div = eurConversionDivisorSqlExpr('bi');
        $conv = "bi.amount_requested / $div";
        $sql = "SELECT bi.vendor as vendor_name, v.salesforce_id, v.account_type, v.AMPLIFY_Level__c, v.Account_Status__c,
            COUNT(*) as item_count, SUM($conv) as total_spent, AVG($conv) as avg_spend_per_item
            FROM budget_items bi
            LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
            " . conversionRatesJoinSql('bi') . "
            WHERE $wc
            GROUP BY bi.vendor, v.salesforce_id, v.account_type, v.AMPLIFY_Level__c, v.Account_Status__c
            ORDER BY total_spent DESC LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getVendorActivityTimeline($pdo, $region, $whereClause, $params) {
    $wc = prefixBudgetItemsWhereClause($whereClause);
    $sql = "SELECT DATE_FORMAT(bi.start_date, '%Y-%m') as month, COALESCE(v.account_type, 'Other') as account_type,
        SUM(bi.amount_requested) as monthly_spend
        FROM budget_items bi
        LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
        WHERE $wc AND bi.start_date IS NOT NULL
        GROUP BY month, account_type ORDER BY month";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getVendorActivityTimelineInEUR($pdo, $region, $whereClause, $params) {
    $wc = prefixBudgetItemsWhereClause($whereClause);
    $div = eurConversionDivisorSqlExpr('bi');
    $conv = "bi.amount_requested / $div";
    $sql = "SELECT DATE_FORMAT(bi.start_date, '%Y-%m') as month, COALESCE(v.account_type, 'Other') as account_type,
        SUM($conv) as monthly_spend
        FROM budget_items bi
        LEFT JOIN vendors v ON TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))
        " . conversionRatesJoinSql('bi') . "
        WHERE $wc AND bi.start_date IS NOT NULL
        GROUP BY month, account_type ORDER BY month";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getMonthlyTrendData($pdo, $year, $whereClause, $params) {
    $yearCond = " AND " . budgetYearMatchSql();
    $p = array_merge($params, budgetYearMatchParams($year));
    $sql = "SELECT MONTH(COALESCE(start_date, entry_creation_date)) as m, SUM(amount_requested) as total
        FROM budget_items WHERE $whereClause $yearCond
        GROUP BY m";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = array_fill(1, 12, 0);
    foreach ($rows as $r) {
        $data[(int)$r['m']] = (float)($r['total'] ?? 0);
    }
    return $data;
}

function getQuarterlyData($pdo, $year, $whereClause, $params) {
    $yearCond = " AND " . budgetYearMatchSql();
    $p = array_merge($params, budgetYearMatchParams($year));
    $sql = "SELECT QUARTER(COALESCE(start_date, entry_creation_date)) as q, SUM(amount_requested) as total
        FROM budget_items WHERE $whereClause $yearCond
        GROUP BY q ORDER BY q";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = [0, 0, 0, 0];
    foreach ($rows as $r) {
        $q = (int)$r['q'];
        if ($q >= 1 && $q <= 4) $data[$q - 1] = (float)($r['total'] ?? 0);
    }
    return $data;
}

function getQuarterlyDataInEUR($pdo, $year, $whereClause, $params) {
    $wc = prefixBudgetItemsWhereClause($whereClause);
    $yearCond = " AND " . budgetYearMatchSql('bi.');
    $p = array_merge($params, budgetYearMatchParams($year));
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT QUARTER(COALESCE(bi.start_date, bi.entry_creation_date)) as q, SUM(bi.amount_requested / $div) as total
        FROM budget_items bi
        " . conversionRatesJoinSql('bi') . "
        WHERE $wc $yearCond
        GROUP BY q ORDER BY q";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = [0, 0, 0, 0];
    foreach ($rows as $r) {
        $q = (int)$r['q'];
        if ($q >= 1 && $q <= 4) $data[$q - 1] = (float)($r['total'] ?? 0);
    }
    return $data;
}

function getTopVendors($pdo, $region, $whereClause, $params) {
    $sql = "SELECT vendor, COUNT(*) as item_count, SUM(amount_requested) as total_spent
        FROM budget_items WHERE $whereClause
        GROUP BY vendor ORDER BY total_spent DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTopVendorsInEUR($pdo, $whereClause, $params) {
    $whereClause = prefixBudgetItemsWhereClause($whereClause);
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT bi.vendor as vendor, COUNT(*) as item_count, 
            SUM(bi.amount_requested / $div) as total_spent
        FROM budget_items bi 
        " . conversionRatesJoinSql('bi') . "
        WHERE $whereClause
        GROUP BY bi.vendor ORDER BY total_spent DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStatusDistributionDataInEUR($pdo, $year, $whereClause, $params) {
    $whereClause = prefixBudgetItemsWhereClause($whereClause);
    $yearCond = ($year && $year !== 'all') ? " AND " . budgetYearMatchSql('bi.') : "";
    $p = ($year && $year !== 'all') ? array_merge($params, budgetYearMatchParams($year)) : $params;
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT bi.status as status, COUNT(*) as count, 
            SUM(bi.amount_requested / $div) as total 
            FROM budget_items bi 
            " . conversionRatesJoinSql('bi') . "
            WHERE $whereClause $yearCond GROUP BY bi.status";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMonthlyTrendDataInEUR($pdo, $year, $whereClause, $params) {
    $whereClause = prefixBudgetItemsWhereClause($whereClause);
    $yearCond = " AND " . budgetYearMatchSql('bi.');
    $p = array_merge($params, budgetYearMatchParams($year));
    $div = eurConversionDivisorSqlExpr('bi');
    $sql = "SELECT MONTH(COALESCE(bi.start_date, bi.entry_creation_date)) as m, 
            SUM(bi.amount_requested / $div) as total
        FROM budget_items bi 
        " . conversionRatesJoinSql('bi') . "
        WHERE $whereClause $yearCond
        GROUP BY m";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = array_fill(1, 12, 0);
    foreach ($rows as $r) {
        $data[(int)$r['m']] = (float)($r['total'] ?? 0);
    }
    return $data;
}

function getRecentItems($pdo, $whereClause, $params) {
    $sql = "SELECT * FROM budget_items WHERE $whereClause ORDER BY entry_creation_date DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStatusDistributionData($pdo, $year, $whereClause, $params) {
    $yearCond = ($year && $year !== 'all') ? " AND " . budgetYearMatchSql() : "";
    $p = ($year && $year !== 'all') ? array_merge($params, budgetYearMatchParams($year)) : $params;
    $sql = "SELECT status, COUNT(*) as count, SUM(amount_requested) as total FROM budget_items WHERE $whereClause $yearCond GROUP BY status";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchedUnmatchedData($pdo, $year, $whereClause, $params) {
    $yearCond = ($year && $year !== 'all') ? " AND " . budgetYearMatchSql('bi.') : "";
    $yearParams = ($year && $year !== 'all') ? budgetYearMatchParams($year) : [];
    $p = array_merge($params, $yearParams);
    $sql = "SELECT 
        'Channel Partners' as category, 
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN bi.amount_requested ELSE 0 END) as total,
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as count
        FROM budget_items bi WHERE $whereClause $yearCond
        UNION ALL
        SELECT 
        'Other/Direct' as category,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN bi.amount_requested ELSE 0 END) as total,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as count
        FROM budget_items bi WHERE $whereClause $yearCond";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $yearParams, $params, $yearParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [['category' => 'Channel Partners', 'total' => 0, 'count' => 0], ['category' => 'Other/Direct', 'total' => 0, 'count' => 0]];
    } catch (PDOException $e) {
        return [['category' => 'Channel Partners', 'total' => 0, 'count' => 0], ['category' => 'Other/Direct', 'total' => 0, 'count' => 0]];
    }
}

function getMatchedUnmatchedDataInEUR($pdo, $year, $whereClause, $params) {
    $wc = prefixBudgetItemsWhereClause($whereClause);
    $yearCond = ($year && $year !== 'all') ? " AND " . budgetYearMatchSql('bi.') : "";
    $yearParams = ($year && $year !== 'all') ? budgetYearMatchParams($year) : [];
    $div = eurConversionDivisorSqlExpr('bi');
    $conv = "bi.amount_requested / $div";
    $join = conversionRatesJoinSql('bi');
    $sql = "SELECT 
        'Channel Partners' as category, 
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN $conv ELSE 0 END) as total,
        SUM(CASE WHEN (bi.project_code IS NOT NULL AND bi.project_code != '') OR EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as count
        FROM budget_items bi $join WHERE $wc $yearCond
        UNION ALL
        SELECT 
        'Other/Direct' as category,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN $conv ELSE 0 END) as total,
        SUM(CASE WHEN (bi.project_code IS NULL OR bi.project_code = '') AND NOT EXISTS (SELECT 1 FROM vendors v WHERE TRIM(LOWER(v.vendor_name)) = TRIM(LOWER(bi.vendor))) THEN 1 ELSE 0 END) as count
        FROM budget_items bi $join WHERE $wc $yearCond";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, $yearParams, $params, $yearParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [['category' => 'Channel Partners', 'total' => 0, 'count' => 0], ['category' => 'Other/Direct', 'total' => 0, 'count' => 0]];
    } catch (PDOException $e) {
        return [['category' => 'Channel Partners', 'total' => 0, 'count' => 0], ['category' => 'Other/Direct', 'total' => 0, 'count' => 0]];
    }
}

// Budget item file attachments
function ensureBudgetAttachmentsTableExists($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS budget_item_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        budget_item_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(64) NOT NULL,
        file_size INT DEFAULT 0,
        mime_type VARCHAR(100) DEFAULT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_budget_item (budget_item_id)
    )");
}

function getBudgetItemAttachments($pdo, $budget_item_id) {
    ensureBudgetAttachmentsTableExists($pdo);
    $stmt = $pdo->prepare("SELECT * FROM budget_item_attachments WHERE budget_item_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$budget_item_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveBudgetItemAttachment($pdo, $budget_item_id, $file, $upload_dir = null) {
    if ($file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) return false;
    
    ensureBudgetAttachmentsTableExists($pdo);
    
    $upload_dir = $upload_dir ?? (__DIR__ . '/uploads/budget_attachments/');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'bin';
    $stored_name = bin2hex(random_bytes(16)) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
    $target = $upload_dir . $stored_name;
    
    if (!move_uploaded_file($file['tmp_name'], $target)) return false;
    
    $stmt = $pdo->prepare("INSERT INTO budget_item_attachments (budget_item_id, file_name, stored_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $budget_item_id,
        basename($file['name']),
        $stored_name,
        $file['size'],
        $file['type'] ?? mime_content_type($target)
    ]);
    return true;
}

function deleteBudgetItemAttachment($pdo, $attachment_id, $upload_dir = null) {
    $stmt = $pdo->prepare("SELECT * FROM budget_item_attachments WHERE id = ?");
    $stmt->execute([$attachment_id]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$att) return false;
    
    $upload_dir = $upload_dir ?? (__DIR__ . '/uploads/budget_attachments/');
    $path = $upload_dir . $att['stored_name'];
    if (file_exists($path)) unlink($path);
    
    $pdo->prepare("DELETE FROM budget_item_attachments WHERE id = ?")->execute([$attachment_id]);
    return true;
}

function getBudgetAttachmentPath($attachment, $upload_dir = null) {
    $upload_dir = $upload_dir ?? (__DIR__ . '/uploads/budget_attachments/');
    return $upload_dir . $attachment['stored_name'];
}

/** Item types used by Budget Planner (must match add_item / edit_item selects). */
function budgetPlannerItemTypes(): array {
    return ['Distributor', 'Reseller', 'End User', 'Other'];
}

function ensureBudgetTypeAllocationsTableExists(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS budget_type_allocations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        region VARCHAR(32) NOT NULL,
        year SMALLINT NOT NULL,
        item_type VARCHAR(64) NOT NULL,
        pct DECIMAL(8,3) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_region_year_type (region, year, item_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Saved % split by item type for a region/year (defaults to 25% each if unset).
 */
function getBudgetTypeAllocationsMap(PDO $pdo, string $region, $year): array {
    ensureBudgetTypeAllocationsTableExists($pdo);
    $defaults = [
        'Distributor' => 25.0,
        'Reseller' => 25.0,
        'End User' => 25.0,
        'Other' => 25.0,
    ];
    if ($year === null || $year === '' || $year === 'all') {
        $year = (int) date('Y');
    }
    $year = (int) $year;
    $stmt = $pdo->prepare("SELECT item_type, pct FROM budget_type_allocations WHERE region = ? AND year = ?");
    $stmt->execute([$region, $year]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $t = trim((string) ($row['item_type'] ?? ''));
        if ($t !== '' && array_key_exists($t, $defaults)) {
            $defaults[$t] = (float) $row['pct'];
        }
    }
    return $defaults;
}

/**
 * Persist planner percentages; each key must be one of budgetPlannerItemTypes() and values must sum to ~100.
 */
function saveBudgetTypeAllocations(PDO $pdo, string $region, int $year, array $pctByType): void {
    ensureBudgetTypeAllocationsTableExists($pdo);
    $types = budgetPlannerItemTypes();
    $sum = 0.0;
    foreach ($types as $t) {
        $sum += (float) ($pctByType[$t] ?? 0);
    }
    if (abs($sum - 100.0) > 0.02) {
        throw new InvalidArgumentException('Percentages must total 100%.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM budget_type_allocations WHERE region = ? AND year = ?")->execute([$region, $year]);
        $ins = $pdo->prepare("INSERT INTO budget_type_allocations (region, year, item_type, pct) VALUES (?,?,?,?)");
        foreach ($types as $t) {
            $ins->execute([$region, $year, $t, round((float) ($pctByType[$t] ?? 0), 3)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Sum of amount_requested by item_type for a region/year (excludes Cancelled).
 * Keys: Distributor, Reseller, End User, Other, plus '(Unspecified)' for blank type.
 */
function getRegionalSpendByItemType(PDO $pdo, string $region, $year): array {
    if ($year === null || $year === '' || $year === 'all') {
        return [];
    }
    $year = (int) $year;
    $ym = budgetYearMatchSql();
    $params = array_merge([$region], budgetYearMatchParams($year));
    // Single-quoted SQL: in double quotes, `GROUP BY t` would interpolate undefined $t and break the query.
    $sql = '
        SELECT
            CASE
                WHEN NULLIF(TRIM(item_type), \'\') IS NULL THEN \'(Unspecified)\'
                ELSE TRIM(item_type)
            END AS t,
            SUM(amount_requested) AS total
        FROM budget_items
        WHERE region = ? AND IFNULL(status, \'\') <> \'Cancelled\'
        AND (' . $ym . ')
        GROUP BY t
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['t']] = (float) $row['total'];
    }
    return $out;
}

/**
 * Full planner snapshot: budget cap per type, spent, remaining (regional currency).
 */
function getBudgetPlannerSnapshot(PDO $pdo, string $region, $year): array {
    if ($year === null || $year === '' || $year === 'all') {
        $year = (int) date('Y');
    }
    $year = (int) $year;
    $budget_info = getRegionalBudgetFromDB($pdo, $region, $year);
    $budget_limit = (float) ($budget_info['budget_limit'] ?? 0);
    $currency = $budget_info['currency'] ?? 'EUR';
    $alloc = getBudgetTypeAllocationsMap($pdo, $region, $year);
    $spentByType = getRegionalSpendByItemType($pdo, $region, $year);
    $unspecified = (float) ($spentByType['(Unspecified)'] ?? 0);
    $types = [];
    foreach (budgetPlannerItemTypes() as $t) {
        $pct = (float) ($alloc[$t] ?? 25);
        $cap = $budget_limit * ($pct / 100.0);
        $spent = (float) ($spentByType[$t] ?? 0);
        $types[] = [
            'item_type' => $t,
            'pct' => $pct,
            'cap' => $cap,
            'spent' => $spent,
            'remaining' => $cap - $spent,
        ];
    }
    return [
        'year' => $year,
        'budget_limit' => $budget_limit,
        'currency' => $currency,
        'types' => $types,
        'unspecified_spent' => $unspecified,
    ];
}

/**
 * Recent spend with the same channel vendor and/or external vendor name (case-insensitive trim match).
 */
function getPartnerSpendHistoryForRegion(
    PDO $pdo,
    string $region,
    string $vendor = '',
    string $external = '',
    int $excludeItemId = 0,
    int $limit = 50
): array {
    $vendor = trim($vendor);
    $external = trim($external);
    if ($vendor === '' && $external === '') {
        return [];
    }
    $params = [$region];
    $or = [];
    if ($vendor !== '') {
        $or[] = 'LOWER(TRIM(bi.vendor)) = LOWER(?)';
        $params[] = $vendor;
        $or[] = 'LOWER(TRIM(bi.external_vendor)) = LOWER(?)';
        $params[] = $vendor;
    }
    if ($external !== '') {
        $or[] = 'LOWER(TRIM(bi.vendor)) = LOWER(?)';
        $params[] = $external;
        $or[] = 'LOWER(TRIM(bi.external_vendor)) = LOWER(?)';
        $params[] = $external;
    }
    $whereOr = implode(' OR ', $or);
    $conditions = [
        'bi.region = ?',
        "IFNULL(bi.status,'') <> 'Cancelled'",
        '(' . $whereOr . ')',
    ];
    if ($excludeItemId > 0) {
        $conditions[] = 'bi.id <> ?';
        $params[] = $excludeItemId;
    }
    $where = implode(' AND ', $conditions);
    $limit = max(1, min(100, $limit));
    $sql = "
        SELECT bi.id, bi.po_number, bi.activity_title, bi.amount_requested, bi.currency, bi.status,
               bi.item_type, bi.start_date, bi.vendor, bi.external_vendor
        FROM budget_items bi
        WHERE $where
        ORDER BY bi.start_date DESC, bi.entry_creation_date DESC
        LIMIT $limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
