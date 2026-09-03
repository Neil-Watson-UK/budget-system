<?php
// export_region.php - Export region data with ALL columns
session_start();
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

// Get parameters
$selected_region = $_GET['region'] ?? '';
$selected_year = $_GET['year'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Validate region
if (empty($selected_region)) {
    die("Error: No region specified.");
}

// Get regional data
$regional_items = getRegionalItems($pdo, $selected_region, $selected_year, $status_filter);
$regional_summary = getRegionalSummary($pdo, $selected_region, $selected_year);

// Get currency info
global $REGIONAL_SETTINGS;
$currency = $REGIONAL_SETTINGS[$selected_region]['currency'] ?? 'EUR';
$currency_symbol = $CURRENCY_SYMBOLS[$currency] ?? '€';

// Set headers for CSV download
$filename = "budget_export_{$selected_region}_" . ($selected_year ? "{$selected_year}_" : "") . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fwrite($output, "\xEF\xBB\xBF");

// ALL COLUMNS HEADER (based on your schema)
$headers = [
    'ID',
    'PO Number',
    'PO Prefix',
    'Region',
    'Country',
    'Start Date',
    'End Date',
    'Invoiced Date',
    'Amount Requested',
    'Currency',
    'Activity Title',
    'Status',
    'Frequency of Spend',
    'Vendor',
    'External Vendor',
    'Vendor Contact',
    'Account',
    'Sub Account',
    'Budget Category',
    'Activity Description',
    'Comments',
    'Associated Epos Staff',
    'Department',
    'Project Code',
    'Item Type',
    'Path',
    'Is Global',
    'Local PO Reference',
    'Entry Creation Date',
    'Entry Updated Date'
];

fputcsv($output, $headers);

// Data rows with ALL columns
foreach ($regional_items as $item) {
    $row = [
        $item['id'] ?? '',
        $item['po_number'] ?? '',
        $item['po_prefix'] ?? '',
        $item['region'] ?? '',
        $item['country'] ?? '',
        $item['start_date'] ? date('Y-m-d', strtotime($item['start_date'])) : '',
        $item['end_date'] ? date('Y-m-d', strtotime($item['end_date'])) : '',
        $item['invoiced_date'] ? date('Y-m-d', strtotime($item['invoiced_date'])) : '',
        number_format(floatval($item['amount_requested'] ?? 0), 2, '.', ''),
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
        $item['activity_description'] ?? '',
        $item['comments'] ?? '',
        $item['associated_epos_staff'] ?? '',
        $item['department'] ?? '',
        $item['project_code'] ?? '',
        $item['item_type'] ?? '',
        $item['path'] ?? '',
        $item['is_global'] ?? 0,
        $item['local_po_reference'] ?? '',
        $item['entry_creation_date'] ? date('Y-m-d H:i:s', strtotime($item['entry_creation_date'])) : '',
        $item['entry_updated_date'] ? date('Y-m-d H:i:s', strtotime($item['entry_updated_date'])) : ''
    ];
    
    fputcsv($output, $row);
}

// Add summary section
fputcsv($output, []); // Empty row
fputcsv($output, ['SUMMARY INFORMATION']);
fputcsv($output, ['Region:', $selected_region]);
fputcsv($output, ['Year:', $selected_year ?: 'All']);
fputcsv($output, ['Status Filter:', $status_filter ?: 'All']);
fputcsv($output, ['Export Date:', date('Y-m-d H:i:s')]);
fputcsv($output, ['Total Records Exported:', count($regional_items)]);
fputcsv($output, []); // Empty row
fputcsv($output, ['BUDGET SUMMARY']);
fputcsv($output, ['Budget Limit:', formatCurrencyForCSV($regional_summary['budget_limit'], $currency)]);
fputcsv($output, ['Amount Spent:', formatCurrencyForCSV($regional_summary['total_amount'], $currency)]);
fputcsv($output, ['Remaining Budget:', formatCurrencyForCSV($regional_summary['remaining_budget'], $currency)]);
fputcsv($output, ['Utilization Percentage:', number_format($regional_summary['utilization_percentage'], 2) . '%']);
fputcsv($output, ['Total Items:', $regional_summary['total_items']]);

// Add filter information
fputcsv($output, []); // Empty row
fputcsv($output, ['FILTER CRITERIA']);
fputcsv($output, ['Region Filter Applied:', $selected_region]);
fputcsv($output, ['Year Filter Applied:', $selected_year ?: 'None']);
fputcsv($output, ['Status Filter Applied:', $status_filter ?: 'None']);
fputcsv($output, ['Query Generated:', date('Y-m-d H:i:s T')]);

fclose($output);

// Helper function to format currency for CSV (without symbols)
function formatCurrencyForCSV($amount, $currency) {
    $amount = floatval($amount);
    return number_format($amount, 2, '.', '');
}