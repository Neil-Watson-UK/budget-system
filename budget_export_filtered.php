<?php
// budget_export_filtered.php - Export with ALL columns
session_start();
require_once 'config.php';
require_once 'functions.php';

$pdo = getDBConnection();

$region_filter = $_GET['region'] ?? '';
$year_filter = $_GET['year'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query
$params = [];
$where_clauses = [];

if (!empty($region_filter)) {
    $where_clauses[] = "region = ?";
    $params[] = $region_filter;
}

if (!empty($year_filter)) {
    $where_clauses[] = "(YEAR(start_date) = ? OR YEAR(entry_creation_date) = ?)";
    $params[] = $year_filter;
    $params[] = $year_filter;
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = ?";
    $params[] = $status_filter;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Select ALL columns from schema
$sql = "SELECT 
            id, po_number, po_prefix, region, country, 
            start_date, end_date, invoiced_date, 
            amount_requested, currency, activity_title, status, 
            frequency_of_spend, vendor, external_vendor, vendor_contact, 
            account, sub_account, budget_category, activity_description, 
            comments, associated_epos_staff, department, project_code, 
            item_type, path, is_global, local_po_reference, 
            entry_creation_date, entry_updated_date
        FROM budget_items 
        $where_sql 
        ORDER BY entry_creation_date DESC";
        
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate filename based on filters
$filename_parts = ['budget_export_full'];
if ($region_filter) $filename_parts[] = $region_filter;
if ($year_filter) $filename_parts[] = $year_filter;
if ($status_filter) $filename_parts[] = $status_filter;
$filename_parts[] = date('Ymd_His');
$filename = implode('_', $filename_parts) . '.csv';

// Output CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF"); // BOM for UTF-8

// ALL COLUMNS HEADER
fputcsv($output, [
    'ID', 'PO Number', 'PO Prefix', 'Region', 'Country',
    'Start Date', 'End Date', 'Invoiced Date',
    'Amount Requested', 'Currency', 'Activity Title', 'Status',
    'Frequency of Spend', 'Vendor', 'External Vendor', 'Vendor Contact',
    'Account', 'Sub Account', 'Budget Category', 'Activity Description',
    'Comments', 'Associated Epos Staff', 'Department', 'Project Code',
    'Item Type', 'Path', 'Is Global', 'Local PO Reference',
    'Entry Creation Date', 'Entry Updated Date'
]);

// Data rows with ALL columns
foreach ($items as $item) {
    fputcsv($output, [
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
    ]);
}

// Add summary
fputcsv($output, []);
fputcsv($output, ['SUMMARY']);
fputcsv($output, ['Export Filters:', 
    'Region: ' . ($region_filter ?: 'All'),
    'Year: ' . ($year_filter ?: 'All'),
    'Status: ' . ($status_filter ?: 'All')
]);
fputcsv($output, ['Total Records:', count($items)]);
fputcsv($output, ['Export Date:', date('Y-m-d H:i:s')]);

fclose($output);