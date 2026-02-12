<?php
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

// Define the exact columns needed for import
$export_columns = [
    'PO Number',
    'Region',
    'Country',
    'Amount Requested',
    'Currency',
    'Activity Title',
    'Status',
    'Vendor',
    'External Vendor',
    'Vendor Contact',
    'Account',
    'Sub Account',
    'Budget Category',
    'Start Date',
    'End Date',
    'Invoiced Date',
    'Associated EPOS Staff',  // CHANGED from "Associated Staff"
    'Item Type',
    'Path',
    'Frequency of Spend',
    'Activity Description',
    'Comments',
    'PO Prefix'
];

// Get all data or filtered by region
$region = $_GET['region'] ?? '';
if ($region && array_key_exists($region, $REGIONAL_SETTINGS)) {
    $stmt = $pdo->prepare("SELECT * FROM budget_items WHERE region = ? ORDER BY region, po_number");
    $stmt->execute([$region]);
    $filename = "budget_import_template_{$region}_" . date('Y-m-d') . ".csv";
} else {
    $stmt = $pdo->query("SELECT * FROM budget_items ORDER BY region, po_number");
    $filename = "budget_import_template_global_" . date('Y-m-d') . ".csv";
}

$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Open output stream
$output = fopen('php://output', 'w');

// Write BOM for UTF-8 support in Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write headers
fputcsv($output, $export_columns);

// Write data rows
foreach ($items as $item) {
    $row = [
        'PO Number' => $item['po_number'],
        'Region' => $item['region'],
        'Country' => $item['country'],
        'Amount Requested' => number_format($item['amount_requested'], 2, '.', ''), // Clean format
        'Currency' => $item['currency'],
        'Activity Title' => $item['activity_title'],
        'Status' => $item['status'],
        'Vendor' => $item['vendor'],
        'External Vendor' => $item['external_vendor'],
        'Vendor Contact' => $item['vendor_contact'],
        'Account' => $item['account'],
        'Sub Account' => $item['sub_account'],
        'Budget Category' => $item['budget_category'],
        'Start Date' => $item['start_date'],
        'End Date' => $item['end_date'],
        'Invoiced Date' => $item['invoiced_date'],
        'Associated EPOS Staff' => $item['associated_epos_staff'],
        'Item Type' => $item['item_type'],
        'Path' => $item['path'],
        'Frequency of Spend' => $item['frequency_of_spend'],
        'Activity Description' => $item['activity_description'],
        'Comments' => $item['comments'],
        'PO Prefix' => $item['po_prefix']
    ];
    
    fputcsv($output, $row);
}

fclose($output);
exit;