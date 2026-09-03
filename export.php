<?php
// budget_export.php - Export to CSV
require_once __DIR__ . '/config.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=budget_export_' . date('Y-m-d') . '.csv');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SELECT * FROM budget_items ORDER BY entry_creation_date DESC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    $headers = [
        'PO Number', 'Region', 'Country', 'Amount Requested', 'Currency',
        'Activity Title', 'Status', 'Frequency of Spend', 'Vendor',
        'External Vendor', 'Vendor Contact', 'Account', 'Sub Account',
        'Budget Category', 'Start Date', 'End Date', 'Invoiced Date',
        'Associated EPOS Staff', 'Item Type', 'Path', 'Activity Description',
        'Comments', 'PO Prefix'
    ];
    
    fputcsv($output, $headers);
    
    foreach ($items as $item) {
        $row = [
            $item['po_number'] ?? '',
            $item['region'] ?? '',
            $item['country'] ?? '',
            number_format((float)($item['amount_requested'] ?? 0), 2, '.', ''),
            $item['currency'] ?? '',
            $item['activity_title'] ?? '',
            $item['status'] ?? '',
            $item['frequency_of_spend'] ?? '',
            $item['vendor'] ?? '',
            $item['external_vendor'] ?? '',
            $item['vendor_contact'] ?? '',
            $item['account'] ?? '',
            $item['sub_account'] ?? '',
            $item['budget_category'] ?? '',
            $item['start_date'] ? date('d/m/Y', strtotime($item['start_date'])) : '',
            $item['end_date'] ? date('d/m/Y', strtotime($item['end_date'])) : '',
            $item['invoiced_date'] ? date('d/m/Y', strtotime($item['invoiced_date'])) : '',
            $item['associated_epos_staff'] ?? '',
            $item['item_type'] ?? '',
            $item['path'] ?? '',
            $item['activity_description'] ?? '',
            $item['comments'] ?? '',
            $item['po_prefix'] ?? 'PO'
        ];
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
    
} catch (PDOException $e) {
    echo "Export failed: " . $e->getMessage();
}
?>