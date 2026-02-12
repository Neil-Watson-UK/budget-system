<?php
// salesout/export.php - Export to Excel/CSV (CSV used when ZipStream not available for Xlsx)
ob_start();
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php?redirect=' . urlencode('salesout/export.php'));
    exit;
}

$pdo = getDBConnection();

// Filters from GET
$dateFrom = $_GET['date_from'] ?? date('Y-01-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$distributor = $_GET['distributor'] ?? '';
$format = $_GET['format'] ?? 'xlsx';

if (isset($_GET['download']) && $_GET['download'] === '1') {
    try {
        // Ensure no output before headers
        if (ob_get_level()) ob_end_clean();

        $sql = "
            SELECT s.*, v.vendor_name as matched_vendor_name,
                p.product_name as product_master_name,
                COALESCE(p.product_category, p.product_type, p.product_line) as product_category,
                p.msrp, p.trade_price, p.currency as product_currency,
                (s.quantity * COALESCE(p.msrp, 0)) as value_at_msrp,
                (s.quantity * COALESCE(p.trade_price, 0)) as value_at_trade
            FROM sales_out_raw s
            LEFT JOIN vendors v ON s.matched_vendor_id = v.id
            LEFT JOIN sales_out_products p ON TRIM(REPLACE(s.sku, ' ', '')) = TRIM(REPLACE(p.sku, ' ', ''))
            WHERE s.report_date BETWEEN ? AND ?
        ";
        $params = [$dateFrom, $dateTo];
        if (!empty($distributor)) {
            $sql .= " AND s.distributor_name = ?";
            $params[] = $distributor;
        }
        $sql .= " ORDER BY s.report_date DESC, s.distributor_name, s.total_value DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales Out');

        $headers = ['Date', 'Distributor', 'Reseller', 'Matched Vendor', 'SKU', 'Product', 'Product Category', 'Qty',
            'Dist Reported', 'At MSRP', 'At Trade', 'Currency'];
        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . '1', $h);
            $col++;
        }
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);

        $rowNum = 2;
        foreach ($rows as $r) {
            $sheet->setCellValue('A' . $rowNum, $r['report_date']);
            $sheet->setCellValue('B' . $rowNum, $r['distributor_name']);
            $sheet->setCellValue('C' . $rowNum, $r['reseller_name']);
            $sheet->setCellValue('D' . $rowNum, $r['matched_vendor_name'] ?? '');
            $sheet->setCellValue('E' . $rowNum, $r['sku']);
            $sheet->setCellValue('F' . $rowNum, $r['product_master_name'] ?: $r['product_name']);
            $sheet->setCellValue('G' . $rowNum, $r['product_category'] ?? '');
            $sheet->setCellValue('H' . $rowNum, $r['quantity']);
            $sheet->setCellValue('I' . $rowNum, $r['total_value']);
            $sheet->setCellValue('J' . $rowNum, $r['value_at_msrp'] ?? '');
            $sheet->setCellValue('K' . $rowNum, $r['value_at_trade'] ?? '');
            $sheet->setCellValue('L' . $rowNum, $r['currency'] ?? $r['product_currency'] ?? '');
            $rowNum++;
        }

        $useXlsx = ($format === 'xlsx' && class_exists(\ZipStream\ZipStream::class));

        if ($useXlsx) {
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $filename = 'sales_out_' . date('Y-m-d') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } else {
            // CSV: works without ZipStream, opens in Excel
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Csv');
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setUseBOM(true);
            $filename = 'sales_out_' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
        }

        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $writer->save('php://output');
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$distributors = $pdo->query("SELECT DISTINCT distributor_name FROM sales_out_raw ORDER BY distributor_name")->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/header.php';
?>
<div class="container-xl py-4">
    <h1>Export to Excel</h1>
    <p class="text-muted">Download standardised sales data with vendor and product enrichment. Uses CSV format (opens in Excel) when XLSX dependencies are not available on server.</p>
    
    <?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <div class="card mt-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="download" value="1">
                <div class="col-md-3">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
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
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Download Excel</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div></div>
</body>
</html>
