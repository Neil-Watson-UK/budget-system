<?php
// excel_reports.php - Excel/CSV export for 6 report types: Exec Summary, Distributor, Reseller (all), Reseller (single), Product, Inventory
ob_start();
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/excel_reports.php'));
    exit;
}

$pdo = getDBConnection();

$reportType = $_GET['report_type'] ?? '';
$format = $_GET['format'] ?? 'xlsx';
$download = isset($_GET['download']) && $_GET['download'] === '1';

// Filters used by multiple reports
$year = $_GET['year'] ?? date('Y');
$dateFrom = $_GET['date_from'] ?? date('Y-m-01', strtotime('-12 months'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$distributor = trim($_GET['distributor'] ?? '');
$resellerId = (int)($_GET['reseller'] ?? 0);
$vendorId = trim($_GET['vendor_id'] ?? '');
$yearsBack = (int)($_GET['years_back'] ?? 5);
if ($yearsBack < 1 || $yearsBack > 10) $yearsBack = 5;

$error = null;

// XLSX requires ZipStream for valid output; without it Excel reports "invalid format"
$useXlsx = ($format === 'xlsx' && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) && class_exists(\ZipStream\ZipStream::class));

/** Excel sheet title: max 31 chars, no \ / * ? : [ ] */
function sanitizeSheetTitle(string $title): string {
    $title = preg_replace('/[\\\\\\/*?:\\[\\]]/', '', $title);
    return mb_substr($title, 0, 31);
}

/**
 * Write a sheet (array of rows, first row = headers) to PhpSpreadsheet. Returns next row index.
 */
function addSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, string $title, array $headers, array $rows): void {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle(sanitizeSheetTitle($title));
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '1', $h);
        $col++;
    }
    $sheet->getStyle('A1:' . $col . '1')->getFont()->setBold(true);
    $rowNum = 2;
    foreach ($rows as $row) {
        $col = 'A';
        foreach ($row as $val) {
            $sheet->setCellValue($col . $rowNum, $val);
            $col++;
        }
        $rowNum++;
    }
}

/**
 * Build rows for CSV (array of arrays). First element is headers.
 */
function buildCsvRows(array $headers, array $rows): array {
    $out = [ $headers ];
    foreach ($rows as $row) $out[] = $row;
    return $out;
}

/**
 * Apply formatting to Inventory report sheets: header style, column widths, number formats, freeze pane.
 * Uses sheet index (0=Summary, 1=Detail) to avoid getSheetByName which may not exist in all PhpSpreadsheet versions.
 */
function applyInventoryFormatting(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): void {
    try {
        $headerFill = [
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E8E8E8'],
            ],
        ];
        if ($spreadsheet->getSheetCount() < 2) return;
        $sheetSummary = $spreadsheet->getSheet(0);
        $sheetDetail = $spreadsheet->getSheet(1);
        $sheetSummary->getStyle('A1:B1')->getFont()->setBold(true);
        $sheetSummary->getStyle('A1:B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheetSummary->getStyle('A1:B1')->applyFromArray($headerFill);
        $sheetSummary->getStyle('B2:B9')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheetSummary->getColumnDimension('A')->setWidth(22);
        $sheetSummary->getColumnDimension('B')->setWidth(16);
        $sheetSummary->freezePane('A2');
        $lastCol = 'L';
        $sheetDetail->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true);
        $sheetDetail->getStyle('A1:' . $lastCol . '1')->applyFromArray($headerFill);
        $sheetDetail->getStyle('G2:G10000')->getNumberFormat()->setFormatCode('#,##0');
        $sheetDetail->getStyle('H2:I10000')->getNumberFormat()->setFormatCode('£#,##0.00');
        $sheetDetail->getStyle('J2:K10000')->getNumberFormat()->setFormatCode('#,##0');
        $sheetDetail->getStyle('L2:L10000')->getNumberFormat()->setFormatCode('0.0');
        $sheetDetail->getColumnDimension('A')->setWidth(14);
        $sheetDetail->getColumnDimension('B')->setWidth(14);
        $sheetDetail->getColumnDimension('C')->setWidth(36);
        $sheetDetail->getColumnDimension('D')->setWidth(14);
        $sheetDetail->getColumnDimension('E')->setWidth(12);
        $sheetDetail->getColumnDimension('F')->setWidth(14);
        $sheetDetail->getColumnDimension('G')->setWidth(10);
        $sheetDetail->getColumnDimension('H')->setWidth(12);
        $sheetDetail->getColumnDimension('I')->setWidth(14);
        $sheetDetail->getColumnDimension('J')->setWidth(12);
        $sheetDetail->getColumnDimension('K')->setWidth(12);
        $sheetDetail->getColumnDimension('L')->setWidth(14);
        $sheetDetail->freezePane('A2');
    } catch (Throwable $e) {
        // If formatting fails (e.g. PhpSpreadsheet version), report still downloads without formatting
    }
}

if ($download && $reportType !== '') {
    $error = null;
    try {
        if (ob_get_level()) ob_end_clean();

        $reportFrom = $dateFrom;
        $reportTo = $dateTo;
        if (in_array($reportType, ['exec_summary', 'distributor', 'reseller', 'reseller_single'], true)) {
            $reportFrom = date('Y-m-01', strtotime("-$yearsBack years"));
        }

        $where = ['1=1'];
        $params = [];
        if ($year !== '' && preg_match('/^\d{4}$/', $year) && $reportType === 'exec_summary') {
            $where[] = 'YEAR(s.report_date) = ?';
            $params[] = $year;
        }
        if (in_array($reportType, ['exec_summary', 'distributor', 'product', 'inventory'], true) && $distributor !== '') {
            $where[] = 's.distributor_name = ?';
            $params[] = $distributor;
        }
        if ($reportType === 'exec_summary' && $resellerId > 0) {
            $where[] = 's.matched_vendor_id = ?';
            $params[] = $resellerId;
        }
        $whereClause = implode(' AND ', $where);
        $dateWhere = "s.report_date BETWEEN ? AND ?";
        $dateParams = [$reportFrom, $reportTo];

        $sheets = [];
        $csvRows = [];

        switch ($reportType) {
            case 'exec_summary': {
                $summarySql = "
                    SELECT COUNT(*) as total_rows, COUNT(DISTINCT s.distributor_name) as distributors,
                        COUNT(DISTINCT s.reseller_name) as resellers, COUNT(DISTINCT s.sku) as skus,
                        COALESCE(SUM(s.total_value), 0) as total_value,
                        SUM(CASE WHEN s.matched_vendor_id IS NOT NULL THEN 1 ELSE 0 END) as matched_to_vendor
                    FROM sales_out_raw s WHERE $whereClause
                ";
                $summaryStmt = $pdo->prepare($summarySql);
                $summaryStmt->execute($params);
                $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
                $totalRows = (int)($summary['total_rows'] ?? 0);
                $matchRate = $totalRows > 0 ? round(100 * (int)($summary['matched_to_vendor'] ?? 0) / $totalRows, 1) : 0;

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
                    GROUP BY distributor_name ORDER BY total DESC LIMIT 10
                ";
                $topDistStmt = $pdo->prepare($topDistSql);
                $topDistStmt->execute($params);
                $topDistributors = $topDistStmt->fetchAll(PDO::FETCH_ASSOC);

                $topResSql = "
                    SELECT v.vendor_name, COUNT(*) as row_count, COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    WHERE $whereClause GROUP BY s.matched_vendor_id, v.vendor_name ORDER BY total DESC LIMIT 10
                ";
                $topResStmt = $pdo->prepare($topResSql);
                $topResStmt->execute($params);
                $topResellers = $topResStmt->fetchAll(PDO::FETCH_ASSOC);

                $topCatSql = "
                    SELECT COALESCE(p.product_category, p.product_line, 'Uncategorised') as category,
                        COALESCE(SUM(s.total_value), 0) as dist_reported
                    FROM sales_out_raw s
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $whereClause GROUP BY category ORDER BY dist_reported DESC LIMIT 10
                ";
                $topCatStmt = $pdo->prepare($topCatSql);
                $topCatStmt->execute($params);
                $topCategories = $topCatStmt->fetchAll(PDO::FETCH_ASSOC);

                // Period compare: last 12 vs previous 12 months (like online report)
                $last12To = date('Y-m-d');
                $last12From = date('Y-m-d', strtotime('-12 months'));
                $prev12To = date('Y-m-d', strtotime('-12 months') - 86400);
                $prev12From = date('Y-m-d', strtotime('-24 months'));
                $periodSql = "SELECT COALESCE(SUM(s.total_value), 0) as total FROM sales_out_raw s WHERE s.report_date BETWEEN ? AND ?" .
                    ($distributor !== '' ? " AND s.distributor_name = ?" : "") . ($resellerId > 0 ? " AND s.matched_vendor_id = ?" : "");
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
                    'last_label' => date('M Y', strtotime($last12From)) . ' – ' . date('M Y', strtotime($last12To)),
                    'prev_label' => date('M Y', strtotime($prev12From)) . ' – ' . date('M Y', strtotime($prev12To)),
                ];

                // By month when year filter (like online)
                $byMonth = [];
                if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
                    $monthStmt = $pdo->prepare("SELECT MONTH(s.report_date) as mo, COALESCE(SUM(s.total_value), 0) as total FROM sales_out_raw s WHERE $whereClause GROUP BY mo ORDER BY mo");
                    $monthStmt->execute($params);
                    foreach ($monthStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $byMonth[(int)$r['mo']] = (float)$r['total'];
                    }
                }

                $sheets[] = ['Summary', ['Metric', 'Value'], [
                    ['Total rows', $summary['total_rows'] ?? 0],
                    ['Total value (£)', $valueCompare['dist_reported'] ?? 0],
                    ['At MSRP (£)', $valueCompare['at_msrp'] ?? 0],
                    ['At Trade (£)', $valueCompare['at_trade'] ?? 0],
                    ['Distributors', $summary['distributors'] ?? 0],
                    ['Resellers', $summary['resellers'] ?? 0],
                    ['SKUs', $summary['skus'] ?? 0],
                    ['Match rate %', $matchRate],
                ]];
                $topDistRows = array_map(function ($r) { return [$r['distributor_name'], $r['row_count'], $r['total']]; }, $topDistributors);
                $sheets[] = ['Top Distributors', ['Distributor', 'Row count', 'Total (£)'], $topDistRows];
                $topResRows = array_map(function ($r) { return [$r['vendor_name'], $r['row_count'], $r['total']]; }, $topResellers);
                $sheets[] = ['Top Resellers', ['Reseller', 'Row count', 'Total (£)'], $topResRows];
                $topCatRows = array_map(function ($r) { return [$r['category'], $r['dist_reported']]; }, $topCategories);
                $sheets[] = ['Top Categories', ['Category', 'Dist reported (£)'], $topCatRows];
                $periodRows = [
                    [$periodCompare['last_label'], $periodCompare['last12'], 'Last 12 months'],
                    [$periodCompare['prev_label'], $periodCompare['prev12'], 'Previous 12 months'],
                    ['Change %', $periodCompare['change_pct'] . '%', ''],
                ];
                $sheets[] = ['Period compare', ['Period', 'Total (£)', 'Note'], $periodRows];
                if (!empty($byMonth)) {
                    $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    $byMonthRows = [];
                    foreach (range(1, 12) as $mo) {
                        $byMonthRows[] = [$monthLabels[$mo - 1], $byMonth[$mo] ?? 0];
                    }
                    $sheets[] = ['By month ' . $year, ['Month', 'Total (£)'], $byMonthRows];
                }
                $csvRows = buildCsvRows(['Metric', 'Value'], [
                    ['Total value (£)', $valueCompare['dist_reported'] ?? 0],
                    ['Distributors', $summary['distributors'] ?? 0],
                    ['Resellers', $summary['resellers'] ?? 0],
                    ['Match rate %', $matchRate],
                ]);
                break;
            }

            case 'distributor': {
                $whereDist = $dateWhere . ($distributor !== '' ? " AND s.distributor_name = ?" : "");
                $paramsDist = array_merge($dateParams, $distributor !== '' ? [$distributor] : []);

                $distributorsStmt = $pdo->prepare("
                    SELECT DISTINCT distributor_name, SUM(total_value) as total
                    FROM sales_out_raw WHERE report_date >= ?
                    GROUP BY distributor_name ORDER BY total DESC
                ");
                $distributorsStmt->execute([$reportFrom]);
                $distList = $distributorsStmt->fetchAll(PDO::FETCH_ASSOC);

                $yearStmt = $pdo->prepare("
                    SELECT YEAR(s.report_date) as yr, COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s WHERE $whereDist GROUP BY yr ORDER BY yr
                ");
                $yearStmt->execute($paramsDist);
                $yearTotals = $yearStmt->fetchAll(PDO::FETCH_ASSOC);

                // Monthly by year (pivot: Year, Jan..Dec) like online
                $monthlyStmt = $pdo->prepare("
                    SELECT YEAR(s.report_date) as yr, MONTH(s.report_date) as mo, COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s WHERE $whereDist GROUP BY yr, mo ORDER BY yr, mo
                ");
                $monthlyStmt->execute($paramsDist);
                $rawMonthly = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
                $yearsList = array_unique(array_column($rawMonthly, 'yr'));
                sort($yearsList);
                $monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                $monthlyByYear = [];
                foreach ($yearsList as $yr) {
                    $row = [(string)$yr];
                    foreach (range(1, 12) as $mo) {
                        $val = 0;
                        foreach ($rawMonthly as $r) {
                            if ((int)$r['yr'] === (int)$yr && (int)$r['mo'] === $mo) { $val = (float)$r['total']; break; }
                        }
                        $row[] = $val;
                    }
                    $monthlyByYear[] = $row;
                }
                $monthlyHeaders = array_merge(['Year'], $monthLabels);

                // Seasonality: avg per month
                $seasonStmt = $pdo->prepare("
                    SELECT mo, AVG(month_val) as avg_val FROM (
                        SELECT YEAR(report_date) as yr, MONTH(report_date) as mo, SUM(total_value) as month_val
                        FROM sales_out_raw s WHERE $whereDist GROUP BY yr, mo
                    ) sub GROUP BY mo ORDER BY mo
                ");
                $seasonStmt->execute($paramsDist);
                $seasonRaw = $seasonStmt->fetchAll(PDO::FETCH_ASSOC);
                $seasonalityRows = [];
                foreach ($seasonRaw as $r) {
                    $seasonalityRows[] = [$monthLabels[(int)$r['mo'] - 1], (float)($r['avg_val'] ?? 0)];
                }

                // Category by year
                $catYearStmt = $pdo->prepare("
                    SELECT YEAR(s.report_date) as yr, COALESCE(p.product_type, p.product_line, p.product_category, 'Uncategorised') as category, COALESCE(SUM(s.total_value), 0) as dist_reported
                    FROM sales_out_raw s
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $whereDist GROUP BY yr, category ORDER BY yr, dist_reported DESC
                ");
                $catYearStmt->execute($paramsDist);
                $categoryByYear = $catYearStmt->fetchAll(PDO::FETCH_ASSOC);

                $topResellers = [];
                $topProducts = [];
                $amplifyByLevel = [];
                if ($distributor !== '') {
                    $topResStmt = $pdo->prepare("
                        SELECT v.vendor_name, COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level,
                            SUM(s.total_value) as total_value, SUM(s.quantity) as total_qty, COUNT(*) as deal_count
                        FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id
                        WHERE s.distributor_name = ?
                        GROUP BY v.id, v.vendor_name, amplify_level ORDER BY total_value DESC LIMIT 50
                    ");
                    $topResStmt->execute([$distributor]);
                    $topResellers = $topResStmt->fetchAll(PDO::FETCH_ASSOC);

                    $topProdStmt = $pdo->prepare("
                        SELECT COALESCE(p.sku, s.sku) as sku, COALESCE(p.product_name, s.product_name) as product_name,
                            p.product_category, p.product_line, SUM(s.total_value) as total_value, SUM(s.quantity) as total_qty
                        FROM sales_out_raw s
                        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                        WHERE s.distributor_name = ? AND s.sku IS NOT NULL AND s.sku != ''
                        GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name), p.product_category, p.product_line
                        ORDER BY total_value DESC LIMIT 50
                    ");
                    $topProdStmt->execute([$distributor]);
                    $topProducts = $topProdStmt->fetchAll(PDO::FETCH_ASSOC);

                    try {
                        if ($pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch()) {
                            $ampStmt = $pdo->prepare("
                                SELECT COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level, COALESCE(SUM(s.total_value), 0) as total
                                FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id
                                WHERE s.distributor_name = ? GROUP BY amplify_level ORDER BY total DESC
                            ");
                            $ampStmt->execute([$distributor]);
                            $amplifyByLevel = $ampStmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    } catch (PDOException $e) { /* ignore */ }
                }

                $sheets[] = ['Distributors', ['Distributor', 'Total (£)'], array_map(fn($r) => [$r['distributor_name'], $r['total']], $distList)];
                $sheets[] = ['Year Totals', ['Year', 'Total (£)'], array_map(fn($r) => [$r['yr'], $r['total']], $yearTotals)];
                if (!empty($monthlyByYear)) {
                    $sheets[] = ['Monthly by year', $monthlyHeaders, $monthlyByYear];
                }
                if (!empty($seasonalityRows)) {
                    $sheets[] = ['Seasonality', ['Month', 'Avg (£)'], $seasonalityRows];
                }
                if (!empty($categoryByYear)) {
                    $sheets[] = ['Category by year', ['Year', 'Category', 'Total (£)'], array_map(fn($r) => [$r['yr'], $r['category'], $r['dist_reported']], $categoryByYear)];
                }
                if ($distributor !== '') {
                    $distributorTargetInfo = null;
                    try {
                        $currentYear = (int)date('Y');
                        $tstmt = $pdo->prepare("SELECT annual_target FROM sales_out_targets WHERE target_type = 'distributor' AND entity_key = ? AND year = ?");
                        $tstmt->execute([$distributor, $currentYear]);
                        $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
                        if ($trow && (float)($trow['annual_target'] ?? 0) > 0) {
                            $targetSeasonality = getSeasonalityPercentages($pdo);
                            $timeElapsed = getTimeElapsedForYear($currentYear);
                            $targetToDate = getTargetToDate((float)$trow['annual_target'], $targetSeasonality, $currentYear);
                            $actualYtd = (float)0;
                            foreach ($yearTotals as $r) { if ((int)$r['yr'] === $currentYear) { $actualYtd = (float)$r['total']; break; } }
                            $distributorTargetInfo = [
                                'annual_target' => (float)$trow['annual_target'],
                                'actual' => $actualYtd,
                                'target_to_date' => $targetToDate,
                                'pct' => round(100 * $actualYtd / (float)$trow['annual_target'], 1),
                                'vs_time' => $targetToDate > 0 ? round(100 * $actualYtd / $targetToDate, 1) : 0,
                            ];
                        }
                    } catch (PDOException $e) { /* ignore */ }
                    if ($distributorTargetInfo !== null) {
                        $sheets[] = ['Target', ['Metric', 'Value'], [
                            ['Annual target (£)', $distributorTargetInfo['annual_target']],
                            ['Actual YTD (£)', $distributorTargetInfo['actual']],
                            ['Target to date (£)', $distributorTargetInfo['target_to_date']],
                            ['% of annual', $distributorTargetInfo['pct'] . '%'],
                            ['% vs time elapsed', $distributorTargetInfo['vs_time'] . '%'],
                        ]];
                    }
                }
                if (!empty($topResellers)) {
                    $sheets[] = ['Top Resellers', ['Reseller', 'Amplify Level', 'Total (£)', 'Qty', 'Deals'], array_map(fn($r) => [$r['vendor_name'], $r['amplify_level'] ?? '', $r['total_value'], $r['total_qty'], $r['deal_count']], $topResellers)];
                    $sheets[] = ['Top Products', ['SKU', 'Product', 'Category', 'Line', 'Total (£)', 'Qty'], array_map(fn($r) => [$r['sku'], $r['product_name'], $r['product_category'] ?? '', $r['product_line'] ?? '', $r['total_value'], $r['total_qty']], $topProducts)];
                }
                if (!empty($amplifyByLevel)) {
                    $sheets[] = ['By Amplify Level', ['Amplify Level', 'Total (£)'], array_map(fn($r) => [$r['amplify_level'], $r['total']], $amplifyByLevel)];
                }
                $csvRows = buildCsvRows(['Distributor', 'Total (£)'], array_map(fn($r) => [$r['distributor_name'], $r['total']], $distList));
                break;
            }

            case 'reseller': {
                $whereRes = "s.report_date BETWEEN ? AND ? AND s.matched_vendor_id IS NOT NULL";
                $paramsRes = [$reportFrom, $reportTo];
                if ($distributor !== '') { $whereRes .= " AND s.distributor_name = ?"; $paramsRes[] = $distributor; }

                $hasAmplifyCol = false;
                try {
                    $hasAmplifyCol = (bool)$pdo->query("SHOW COLUMNS FROM vendors LIKE 'AMPLIFY_Level__c'")->fetch();
                } catch (PDOException $e) { /* ignore */ }
                if ($hasAmplifyCol) {
                    $vendorsStmt = $pdo->prepare("
                        SELECT v.id, v.vendor_name, v.salesforce_id,
                            COALESCE(NULLIF(TRIM(v.AMPLIFY_Level__c), ''), 'Other') as amplify_level,
                            SUM(s.total_value) as total
                        FROM vendors v
                        INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
                        WHERE s.report_date >= ?
                        " . ($distributor !== '' ? " AND s.distributor_name = ?" : "") . "
                        GROUP BY v.id, v.vendor_name, v.salesforce_id, amplify_level
                        ORDER BY total DESC
                    ");
                } else {
                    $vendorsStmt = $pdo->prepare("
                        SELECT v.id, v.vendor_name, v.salesforce_id, SUM(s.total_value) as total
                        FROM vendors v
                        INNER JOIN sales_out_raw s ON s.matched_vendor_id = v.id
                        WHERE s.report_date >= ?
                        " . ($distributor !== '' ? " AND s.distributor_name = ?" : "") . "
                        GROUP BY v.id, v.vendor_name, v.salesforce_id
                        ORDER BY total DESC
                    ");
                }
                $vendorsStmt->execute(array_merge([$reportFrom], $distributor !== '' ? [$distributor] : []));
                $vendors = $vendorsStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!isset($vendors[0]['amplify_level'])) {
                    foreach ($vendors as &$v) { $v['amplify_level'] = ''; }
                    unset($v);
                }
                $hasAmplify = $hasAmplifyCol && !empty($vendors);

                $midDate = date('Y-m-d', (int)((strtotime($reportFrom) + strtotime($reportTo)) / 2));
                $growersStmt = $pdo->prepare("
                    SELECT * FROM (
                        SELECT v.vendor_name, v.id as vendor_id,
                            SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                            SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val
                        FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id
                        WHERE s.report_date BETWEEN ? AND ? " . ($distributor !== '' ? " AND s.distributor_name = ?" : "") . "
                        GROUP BY s.matched_vendor_id, v.vendor_name, v.id
                    ) sub
                    WHERE prior_val >= 500 AND recent_val > prior_val
                    ORDER BY (recent_val - prior_val) / prior_val DESC, recent_val DESC LIMIT 30
                ");
                $growersBind = [$midDate, $midDate, $reportFrom, $reportTo];
                if ($distributor !== '') $growersBind[] = $distributor;
                $growersStmt->execute($growersBind);
                $growers = $growersStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($growers as &$g) {
                    $g['growth_pct'] = $g['prior_val'] > 0 ? round(100 * ($g['recent_val'] - $g['prior_val']) / $g['prior_val'], 1) : 0;
                }
                unset($g);

                $declinersStmt = $pdo->prepare("
                    SELECT * FROM (
                        SELECT v.vendor_name, v.id as vendor_id,
                            SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                            SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val
                        FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id
                        WHERE s.report_date BETWEEN ? AND ? " . ($distributor !== '' ? " AND s.distributor_name = ?" : "") . "
                        GROUP BY s.matched_vendor_id, v.vendor_name, v.id
                    ) sub
                    WHERE prior_val >= 500 AND recent_val < prior_val AND recent_val > 0
                    ORDER BY (recent_val - prior_val) / prior_val ASC, prior_val DESC LIMIT 30
                ");
                $declinersStmt->execute($growersBind);
                $decliners = $declinersStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($decliners as &$d) {
                    $d['decline_pct'] = $d['prior_val'] > 0 ? round(100 * ($d['recent_val'] - $d['prior_val']) / $d['prior_val'], 1) : 0;
                }
                unset($d);

                $resellerHeaders = $hasAmplify ? ['Reseller', 'Amplify Level', 'Total (£)', 'Salesforce ID'] : ['Reseller', 'Total (£)', 'Salesforce ID'];
                $resellerRows = array_map(function($r) use ($hasAmplify) {
                    $row = [$r['vendor_name'], $r['total'], $r['salesforce_id'] ?? ''];
                    if ($hasAmplify) array_splice($row, 1, 0, [$r['amplify_level'] ?? '']);
                    return $row;
                }, $vendors);
                $sheets[] = ['Resellers', $resellerHeaders, $resellerRows];

                // Year totals per reseller (like online)
                $resellerYearStmt = $pdo->prepare("
                    SELECT v.vendor_name, YEAR(s.report_date) as yr, COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id
                    WHERE s.report_date BETWEEN ? AND ? AND s.matched_vendor_id IS NOT NULL
                    " . ($distributor !== '' ? " AND s.distributor_name = ?" : "") . "
                    GROUP BY v.id, v.vendor_name, yr ORDER BY v.vendor_name, yr
                ");
                $resellerYearStmt->execute($paramsRes);
                $resellerYearTotals = $resellerYearStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($resellerYearTotals)) {
                    $sheets[] = ['Reseller year totals', ['Reseller', 'Year', 'Total (£)'], array_map(fn($r) => [$r['vendor_name'], $r['yr'], $r['total']], $resellerYearTotals)];
                }

                $sheets[] = ['Fastest Growers', ['Reseller', 'Prior period (£)', 'Recent period (£)', 'Growth %'], array_map(fn($r) => [$r['vendor_name'], $r['prior_val'], $r['recent_val'], $r['growth_pct']], $growers)];
                $sheets[] = ['Decliners', ['Reseller', 'Prior period (£)', 'Recent period (£)', 'Decline %'], array_map(fn($r) => [$r['vendor_name'], $r['prior_val'], $r['recent_val'], $r['decline_pct']], $decliners)];
                $csvRows = buildCsvRows($resellerHeaders, $resellerRows);
                break;
            }

            case 'reseller_single': {
                $vid = $resellerId > 0 ? $resellerId : (int)$vendorId;
                if ($vid <= 0) { $error = 'Select a reseller (vendor).'; break; }
                $singleWhere = "s.report_date BETWEEN ? AND ? AND s.matched_vendor_id = ?";
                $singleParams = [$reportFrom, $reportTo, $vid];

                $vendorName = $pdo->prepare("SELECT vendor_name FROM vendors WHERE id = ?");
                $vendorName->execute([$vid]);
                $vendorName = $vendorName->fetchColumn() ?: 'Vendor #' . $vid;

                $yearStmt = $pdo->prepare("SELECT YEAR(s.report_date) as yr, COALESCE(SUM(s.total_value), 0) as total FROM sales_out_raw s WHERE $singleWhere GROUP BY yr ORDER BY yr");
                $yearStmt->execute($singleParams);
                $yearTotals = $yearStmt->fetchAll(PDO::FETCH_ASSOC);

                $monthlySingleStmt = $pdo->prepare("
                    SELECT YEAR(s.report_date) as yr, MONTH(s.report_date) as mo, COALESCE(SUM(s.total_value), 0) as total
                    FROM sales_out_raw s WHERE $singleWhere GROUP BY yr, mo ORDER BY yr, mo
                ");
                $monthlySingleStmt->execute($singleParams);
                $rawMonthlySingle = $monthlySingleStmt->fetchAll(PDO::FETCH_ASSOC);
                $yearsSingle = array_unique(array_column($rawMonthlySingle, 'yr'));
                sort($yearsSingle);
                $monthLabelsSingle = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                $monthlyByYearSingle = [];
                foreach ($yearsSingle as $yr) {
                    $row = [(string)$yr];
                    for ($mo = 1; $mo <= 12; $mo++) {
                        $val = 0;
                        foreach ($rawMonthlySingle as $r) {
                            if ((int)$r['yr'] === (int)$yr && (int)$r['mo'] === $mo) { $val = (float)$r['total']; break; }
                        }
                        $row[] = $val;
                    }
                    $monthlyByYearSingle[] = $row;
                }
                $monthlyHeadersSingle = array_merge(['Year'], $monthLabelsSingle);

                $topProdStmt = $pdo->prepare("
                    SELECT COALESCE(p.sku, s.sku) as sku, COALESCE(p.product_name, s.product_name) as product_name,
                        p.product_category, p.product_line, SUM(s.total_value) as total_value, SUM(s.quantity) as total_qty, COUNT(*) as deal_count
                    FROM sales_out_raw s
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE s.matched_vendor_id = ? AND s.sku IS NOT NULL AND s.sku != ''
                    GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name), p.product_category, p.product_line
                    ORDER BY total_value DESC LIMIT 50
                ");
                $topProdStmt->execute([$vid]);
                $topProducts = $topProdStmt->fetchAll(PDO::FETCH_ASSOC);

                $dealsStmt = $pdo->prepare("
                    SELECT s.report_date, s.distributor_name, s.sku, COALESCE(p.product_name, s.product_name) as product_name,
                        s.quantity, s.unit_price, s.total_value, s.currency
                    FROM sales_out_raw s
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE s.matched_vendor_id = ? ORDER BY s.total_value DESC LIMIT 100
                ");
                $dealsStmt->execute([$vid]);
                $deals = $dealsStmt->fetchAll(PDO::FETCH_ASSOC);

                $totalVal = array_sum(array_column($yearTotals, 'total'));
                $sheets[] = ['Summary', ['Metric', 'Value'], [
                    ['Reseller', $vendorName],
                    ['Total value (£)', $totalVal],
                    ['Years', count($yearTotals)],
                ]];
                $sheets[] = ['Year Totals', ['Year', 'Total (£)'], array_map(fn($r) => [$r['yr'], $r['total']], $yearTotals)];
                if (!empty($monthlyByYearSingle)) {
                    $sheets[] = ['Monthly by year', $monthlyHeadersSingle, $monthlyByYearSingle];
                }
                try {
                    $currentYear = (int)date('Y');
                    $tstmt = $pdo->prepare("SELECT annual_target FROM sales_out_targets WHERE target_type = 'reseller' AND entity_key = ? AND year = ?");
                    $tstmt->execute([$vid, $currentYear]);
                    $trow = $tstmt->fetch(PDO::FETCH_ASSOC);
                    if ($trow && (float)($trow['annual_target'] ?? 0) > 0) {
                        $targetSeasonality = getSeasonalityPercentages($pdo);
                        $timeElapsed = getTimeElapsedForYear($currentYear);
                        $targetToDate = getTargetToDate((float)$trow['annual_target'], $targetSeasonality, $currentYear);
                        $actualYtd = (float)0;
                        foreach ($yearTotals as $r) { if ((int)$r['yr'] === $currentYear) { $actualYtd = (float)$r['total']; break; } }
                        $sheets[] = ['Target', ['Metric', 'Value'], [
                            ['Annual target (£)', (float)$trow['annual_target']],
                            ['Actual YTD (£)', $actualYtd],
                            ['Target to date (£)', $targetToDate],
                            ['% of annual', round(100 * $actualYtd / (float)$trow['annual_target'], 1) . '%'],
                            ['% vs time elapsed', $targetToDate > 0 ? round(100 * $actualYtd / $targetToDate, 1) . '%' : ''],
                        ]];
                    }
                } catch (PDOException $e) { /* ignore */ }
                $sheets[] = ['Top Products', ['SKU', 'Product', 'Category', 'Line', 'Total (£)', 'Qty', 'Deals'], array_map(fn($r) => [$r['sku'], $r['product_name'], $r['product_category'] ?? '', $r['product_line'] ?? '', $r['total_value'], $r['total_qty'], $r['deal_count']], $topProducts)];
                $sheets[] = ['Top Deals', ['Date', 'Distributor', 'SKU', 'Product', 'Qty', 'Unit price', 'Total (£)', 'Currency'], array_map(fn($r) => [$r['report_date'], $r['distributor_name'], $r['sku'], $r['product_name'], $r['quantity'], $r['unit_price'], $r['total_value'], $r['currency'] ?? ''], $deals)];
                $csvRows = buildCsvRows(['Year', 'Total (£)'], array_map(fn($r) => [$r['yr'], $r['total']], $yearTotals));
                break;
            }

            case 'product': {
                $whereProd = $dateWhere . ($distributor !== '' ? " AND s.distributor_name = ?" : "");
                $paramsProd = array_merge($dateParams, $distributor !== '' ? [$distributor] : []);

                $byCatStmt = $pdo->prepare("
                    SELECT COALESCE(p.product_category, p.product_line, 'Uncategorised') as category,
                        COUNT(*) as row_count, COALESCE(SUM(s.total_value), 0) as dist_reported,
                        COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
                    FROM sales_out_raw s
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $whereProd GROUP BY category ORDER BY dist_reported DESC
                ");
                $byCatStmt->execute($paramsProd);
                $byCategory = $byCatStmt->fetchAll(PDO::FETCH_ASSOC);

                $monthlyTrendStmt = $pdo->prepare("
                    SELECT DATE_FORMAT(s.report_date, '%Y-%m') as month, COUNT(*) as row_count,
                        COALESCE(SUM(s.total_value), 0) as dist_reported, COALESCE(SUM(s.quantity * p.trade_price), 0) as at_trade
                    FROM sales_out_raw s
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $whereProd GROUP BY month ORDER BY month
                ");
                $monthlyTrendStmt->execute($paramsProd);
                $monthlyTrend = $monthlyTrendStmt->fetchAll(PDO::FETCH_ASSOC);

                $topSkuStmt = $pdo->prepare("
                    SELECT COALESCE(p.product_category, p.product_line, 'Uncategorised') as category,
                        COALESCE(p.sku, s.sku) as sku, COALESCE(p.product_name, s.product_name) as product_name,
                        SUM(s.quantity) as total_qty, COALESCE(SUM(s.total_value), 0) as dist_reported
                    FROM sales_out_raw s
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    WHERE $whereProd AND s.sku IS NOT NULL AND s.sku != ''
                    GROUP BY category, COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name)
                    ORDER BY dist_reported DESC LIMIT 200
                ");
                $topSkuStmt->execute($paramsProd);
                $topSkus = $topSkuStmt->fetchAll(PDO::FETCH_ASSOC);

                $midDate = date('Y-m-d', (int)((strtotime($dateFrom) + strtotime($dateTo)) / 2));
                $growingStmt = $pdo->prepare("
                    SELECT * FROM (
                        SELECT COALESCE(p.sku, s.sku) as sku, COALESCE(p.product_name, s.product_name) as product_name,
                            SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                            SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val
                        FROM sales_out_raw s
                        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                        WHERE s.report_date BETWEEN ? AND ? AND s.sku IS NOT NULL AND s.sku != ''
                        " . ($distributor !== '' ? " AND s.distributor_name = ?" : "") . "
                        GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name)
                    ) sub
                    WHERE prior_val >= 100 AND recent_val > prior_val
                    ORDER BY (recent_val - prior_val) / prior_val DESC, recent_val DESC LIMIT 30
                ");
                $growBind = [$midDate, $midDate, $dateFrom, $dateTo];
                if ($distributor !== '') $growBind[] = $distributor;
                $growingStmt->execute($growBind);
                $growing = $growingStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($growing as &$g) {
                    $g['growth_pct'] = $g['prior_val'] > 0 ? round(100 * ($g['recent_val'] - $g['prior_val']) / $g['prior_val'], 1) : 0;
                }
                unset($g);

                $shrinkingStmt = $pdo->prepare("
                    SELECT * FROM (
                        SELECT COALESCE(p.sku, s.sku) as sku, COALESCE(p.product_name, s.product_name) as product_name,
                            SUM(CASE WHEN s.report_date < ? THEN s.total_value ELSE 0 END) as prior_val,
                            SUM(CASE WHEN s.report_date >= ? THEN s.total_value ELSE 0 END) as recent_val
                        FROM sales_out_raw s
                        LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                        WHERE s.report_date BETWEEN ? AND ? AND s.sku IS NOT NULL AND s.sku != ''
                        " . ($distributor !== '' ? " AND s.distributor_name = ?" : "") . "
                        GROUP BY COALESCE(p.sku, s.sku), COALESCE(p.product_name, s.product_name)
                    ) sub
                    WHERE prior_val >= 100 AND recent_val < prior_val AND recent_val > 0
                    ORDER BY (recent_val - prior_val) / prior_val ASC, prior_val DESC LIMIT 30
                ");
                $shrinkingStmt->execute($growBind);
                $shrinking = $shrinkingStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($shrinking as &$s) {
                    $s['decline_pct'] = $s['prior_val'] > 0 ? round(100 * ($s['recent_val'] - $s['prior_val']) / $s['prior_val'], 1) : 0;
                }
                unset($s);

                $sheets[] = ['By Category', ['Category', 'Row count', 'Dist reported (£)', 'At trade (£)'], array_map(fn($r) => [$r['category'], $r['row_count'], $r['dist_reported'], $r['at_trade']], $byCategory)];
                if (!empty($monthlyTrend)) {
                    $sheets[] = ['Monthly trend', ['Month', 'Row count', 'Dist reported (£)', 'At trade (£)'], array_map(fn($r) => [$r['month'], $r['row_count'], $r['dist_reported'], $r['at_trade']], $monthlyTrend)];
                }
                $sheets[] = ['Top SKUs', ['Category', 'SKU', 'Product', 'Qty', 'Dist reported (£)'], array_map(fn($r) => [$r['category'], $r['sku'], $r['product_name'], $r['total_qty'], $r['dist_reported']], $topSkus)];
                $sheets[] = ['Fastest Sellers (growth)', ['SKU', 'Product', 'Prior (£)', 'Recent (£)', 'Growth %'], array_map(fn($r) => [$r['sku'], $r['product_name'], $r['prior_val'], $r['recent_val'], $r['growth_pct']], $growing)];
                $sheets[] = ['Declining Products', ['SKU', 'Product', 'Prior (£)', 'Recent (£)', 'Decline %'], array_map(fn($r) => [$r['sku'], $r['product_name'], $r['prior_val'], $r['recent_val'], $r['decline_pct']], $shrinking)];
                $csvRows = buildCsvRows(['Category', 'Row count', 'Dist reported (£)', 'At trade (£)'], array_map(fn($r) => [$r['category'], $r['row_count'], $r['dist_reported'], $r['at_trade']], $byCategory));
                break;
            }

            case 'inventory': {
                $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'sales_out_inventory'")->fetch();
                if (!$tableExists) {
                    $error = 'Inventory table (sales_out_inventory) not found. Run install/inventory_schema.sql.';
                    break;
                }
                $weeksBack = 8;
                $latestDate = $pdo->query("SELECT MAX(snapshot_date) FROM sales_out_inventory")->fetchColumn();
                if (!$latestDate) {
                    $error = 'No inventory data.';
                    break;
                }
                $salesFrom = date('Y-m-d', strtotime("-$weeksBack weeks", strtotime($latestDate)));
                $aliases = getInventoryDistributorAliases();
                $distNames = $distributor ? ($aliases[$distributor] ?? [$distributor]) : [];
                $whereDist = $distNames ? " AND LOWER(TRIM(i.distributor_name)) IN (" . implode(',', array_fill(0, count($distNames), 'LOWER(?)')) . ")" : "";
                $whereSales = $distNames ? " AND LOWER(TRIM(s.distributor_name)) IN (" . implode(',', array_fill(0, count($distNames), 'LOWER(?)')) . ")" : "";
                $canonJoin = "CASE WHEN LOWER(TRIM(i.distributor_name)) IN ('westcoast','wc') THEN 'westcoast' ELSE LOWER(TRIM(i.distributor_name)) END = CASE WHEN LOWER(TRIM(sa.distributor_name)) IN ('westcoast','wc') THEN 'westcoast' ELSE LOWER(TRIM(sa.distributor_name)) END";
                $paramsInv = [];
                if ($distNames) $paramsInv = array_merge($paramsInv, $distNames);
                $paramsInv[] = $salesFrom; $paramsInv[] = $latestDate;
                if ($distNames) $paramsInv = array_merge($paramsInv, $distNames);

                $invSql = "
                    SELECT i.distributor_name, i.sku, COALESCE(p.product_name, i.sku_description) as sku_description,
                        p.product_category, p.product_line, i.snapshot_date, i.on_hand_qty, i.unit_cost, i.inventory_value,
                        COALESCE(sa.units_sold, 0) as units_sold, COALESCE(sa.weeks_count, 0) as weeks_count,
                        CASE WHEN COALESCE(sa.weeks_count, 0) > 0 AND sa.units_sold > 0 THEN i.on_hand_qty / (sa.units_sold / sa.weeks_count) ELSE NULL END as weeks_of_stock
                    FROM sales_out_inventory i
                    LEFT JOIN sales_out_products p ON TRIM(REPLACE(i.sku,' ','')) = TRIM(REPLACE(p.sku,' ',''))
                    INNER JOIN (SELECT distributor_name, TRIM(REPLACE(sku,' ','')) as sku_norm, MAX(snapshot_date) as max_date FROM sales_out_inventory WHERE 1=1 $whereDist GROUP BY distributor_name, sku_norm) latest
                        ON i.distributor_name = latest.distributor_name AND TRIM(REPLACE(i.sku,' ','')) = latest.sku_norm AND i.snapshot_date = latest.max_date
                    LEFT JOIN (SELECT s.distributor_name, TRIM(REPLACE(s.sku,' ','')) as sku_norm, SUM(s.quantity) as units_sold, COUNT(DISTINCT YEARWEEK(s.report_date)) as weeks_count
                        FROM sales_out_raw s WHERE s.report_date >= ? AND s.report_date <= ? AND s.sku IS NOT NULL AND s.sku != '' $whereSales GROUP BY s.distributor_name, sku_norm) sa
                        ON $canonJoin AND TRIM(REPLACE(i.sku,' ','')) = sa.sku_norm
                    WHERE 1=1
                    ORDER BY i.inventory_value DESC LIMIT 2000
                ";
                $stmtInv = $pdo->prepare($invSql);
                $stmtInv->execute($paramsInv);
                $inventory = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

                $summary = ['total_value' => 0, 'total_units' => 0, 'skus' => count($inventory), 'distributors' => 0];
                foreach ($inventory as $r) {
                    $summary['total_value'] += (float)($r['inventory_value'] ?? 0);
                    $summary['total_units'] += (int)($r['on_hand_qty'] ?? 0);
                }
                $summary['distributors'] = count(array_unique(array_column($inventory, 'distributor_name')));

                $sheets[] = ['Summary', ['Metric', 'Value'], [
                    ['Total value (£)', $summary['total_value']],
                    ['Total units', $summary['total_units']],
                    ['SKUs', $summary['skus']],
                    ['Distributors', $summary['distributors']],
                ]];
                $sheets[] = ['Detail', ['Distributor', 'SKU', 'Product', 'Category', 'Line', 'Snapshot date', 'On hand', 'Unit cost', 'Value (£)', 'Units sold', 'Weeks count', 'Weeks of stock'],
                    array_map(fn($r) => [$r['distributor_name'], $r['sku'], $r['sku_description'] ?? '', $r['product_category'] ?? '', $r['product_line'] ?? '', $r['snapshot_date'], $r['on_hand_qty'], $r['unit_cost'] ?? '', $r['inventory_value'] ?? '', $r['units_sold'] ?? 0, $r['weeks_count'] ?? 0, $r['weeks_of_stock'] ?? ''], $inventory)];
                $csvRows = buildCsvRows(['Distributor', 'SKU', 'Product', 'Category', 'On hand', 'Value (£)', 'Weeks of stock'], array_map(fn($r) => [$r['distributor_name'], $r['sku'], $r['sku_description'] ?? '', $r['product_category'] ?? '', $r['on_hand_qty'], $r['inventory_value'] ?? '', $r['weeks_of_stock'] ?? ''], $inventory));
                break;
            }

            default:
                $error = 'Invalid report type.';
        }

        if ($error !== null) {
            if (ob_get_level()) ob_end_clean();
            session_start();
            $_GET['excel_error'] = $error;
        } else {
            $filename = 'salesout_' . $reportType . '_' . date('Y-m-d') . ($useXlsx ? '.xlsx' : '.csv');

            if (!$useXlsx) {
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: max-age=0');
                $out = fopen('php://output', 'w');
                fprintf($out, "\xEF\xBB\xBF");
                foreach ($csvRows as $row) fputcsv($out, $row);
                fclose($out);
                exit;
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $spreadsheet->getActiveSheet()->setTitle('Summary');
            $first = true;
            foreach ($sheets as $sh) {
                if ($first) {
                    $first = false;
                    $sheet = $spreadsheet->getActiveSheet();
                    $sheet->setTitle(sanitizeSheetTitle($sh[0]));
                } else {
                    $sheet = $spreadsheet->createSheet();
                    $sheet->setTitle(sanitizeSheetTitle($sh[0]));
                }
                $headers = $sh[1];
                $rows = $sh[2];
                $col = 'A';
                foreach ($headers as $h) {
                    $sheet->setCellValue($col . '1', $h);
                    $col++;
                }
                $sheet->getStyle('A1:' . $col . '1')->getFont()->setBold(true);
                $rowNum = 2;
                foreach ($rows as $row) {
                    $c = 'A';
                    foreach ($row as $val) {
                        $sheet->setCellValue($c . $rowNum, $val);
                        $c++;
                    }
                    $rowNum++;
                }
            }

            if ($reportType === 'inventory') {
                applyInventoryFormatting($spreadsheet);
            }

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $tmpFile = tempnam(sys_get_temp_dir(), 'salesout_');
            $writer->save($tmpFile);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            header('Content-Length: ' . filesize($tmpFile));
            readfile($tmpFile);
            @unlink($tmpFile);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$excelError = $_GET['excel_error'] ?? $error ?? '';
$years = [];
$distributors = [];
$resellers = [];
try {
    $years = $pdo->query("SELECT DISTINCT YEAR(report_date) as y FROM sales_out_raw WHERE report_date IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    $distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);
    $resellers = $pdo->query("SELECT v.id, v.vendor_name FROM sales_out_raw s INNER JOIN vendors v ON s.matched_vendor_id = v.id GROUP BY v.id, v.vendor_name ORDER BY v.vendor_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $excelError = $excelError ?: 'Database error loading filters: ' . $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4">
    <h1><i class="ti ti-file-spreadsheet me-2"></i>Excel Reports</h1>
    <p class="text-muted">Generate Excel (or CSV) reports for management, distributors, resellers, products, and inventory. Choose a report type and optional filters, then download.</p>

    <?php if ($excelError !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($excelError) ?></div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-header">Report type &amp; filters</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="download" value="1">
                <div class="col-md-4">
                    <label class="form-label">Report type</label>
                    <select name="report_type" class="form-select" required>
                        <option value="">— Select —</option>
                        <option value="exec_summary" <?= $reportType === 'exec_summary' ? 'selected' : '' ?>>Exec Summary — management summary data</option>
                        <option value="distributor" <?= $reportType === 'distributor' ? 'selected' : '' ?>>Distributor — performance &amp; stock position</option>
                        <option value="reseller" <?= $reportType === 'reseller' ? 'selected' : '' ?>>Reseller — all resellers, Amplify level, growers &amp; decliners</option>
                        <option value="reseller_single" <?= $reportType === 'reseller_single' ? 'selected' : '' ?>>Reseller (single) — summary for one reseller</option>
                        <option value="product" <?= $reportType === 'product' ? 'selected' : '' ?>>Product — top by category, fastest sellers &amp; inventory</option>
                        <option value="inventory" <?= $reportType === 'inventory' ? 'selected' : '' ?>>Inventory — summary &amp; detail</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Format</label>
                    <select name="format" class="form-select">
                        <option value="xlsx" <?= $format === 'xlsx' ? 'selected' : '' ?>>XLSX</option>
                        <option value="csv" <?= $format === 'csv' ? 'selected' : '' ?>>CSV</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Years of data</label>
                    <select name="years_back" class="form-select">
                        <?php for ($i = 2; $i <= 10; $i++): ?>
                        <option value="<?= $i ?>" <?= $yearsBack === $i ? 'selected' : '' ?>><?= $i ?> years</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Year (Exec Summary)</label>
                    <select name="year" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $year === $y ? 'selected' : '' ?>><?= (int)$y ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Distributor (optional)</label>
                    <select name="distributor" class="form-select">
                        <option value="">All</option>
                        <?php foreach ($distributors as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $distributor === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reseller (for single report)</label>
                    <select name="reseller" class="form-select">
                        <option value="">— Select for single reseller —</option>
                        <?php foreach ($resellers as $r): ?>
                        <option value="<?= (int)$r['id'] ?>" <?= $resellerId === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['vendor_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">From date (Product)</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To date (Product)</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="ti ti-download me-1"></i>Download report</button>
                    <a href="excel_reports.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">Report contents</div>
        <div class="card-body small">
            <ul class="mb-0">
                <li><strong>Exec Summary</strong> — Summary KPIs; top distributors, resellers, categories; <em>period compare</em> (last 12 vs previous 12 months); <em>by month</em> when year filter set.</li>
                <li><strong>Distributor</strong> — Distributors &amp; year totals; <em>monthly by year</em> (Jan–Dec pivot); <em>seasonality</em>; <em>category by year</em>; when a distributor selected: <em>target</em> (annual, YTD, %), top resellers (Amplify), top products, by Amplify level.</li>
                <li><strong>Reseller</strong> — All resellers (Amplify level if available); <em>reseller year totals</em>; fastest growers; decliners. Optional distributor filter.</li>
                <li><strong>Reseller (single)</strong> — Summary, year totals, <em>monthly by year</em>, <em>target</em> (if set), top products, top deals. Select reseller from dropdown.</li>
                <li><strong>Product</strong> — By category; <em>monthly trend</em>; top SKUs; fastest-growing and declining products. Optional date range and distributor.</li>
                <li><strong>Inventory</strong> — Formatted: summary + detail (distributor, SKU, product, on hand, value, weeks of stock). Header styling, column widths, number formats, freeze pane. Optional distributor filter.</li>
            </ul>
        </div>
    </div>
</div>
</div></div>
</body>
</html>
