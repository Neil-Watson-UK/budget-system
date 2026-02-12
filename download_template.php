<?php
$type = $_GET['type'] ?? 'vendor';

if ($type === 'vendor_with_id') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="vendor_import_with_ids.csv"');

    $output = fopen('php://output', 'w');

    // Add headers
    fputcsv($output, ['Vendor ID', 'Vendor Name', 'Country Code', 'Description (Optional)']);

    // Add example rows with Salesforce-style IDs
    fputcsv($output, ['0011100000ScvKO', 'Media & Communications Ltd', 'UK', 'Primary UK media vendor']);
    fputcsv($output, ['0011100000ScvKP', 'Nimans Ltd', 'UK', 'UK technology distributor']);
    fputcsv($output, ['0011100000ScvKQ', 'Misco Technologies Limited', 'US', 'US operations']);
    fputcsv($output, ['0011100000ScvKR', 'PC Print', 'DE', 'German printing services']);
    fputcsv($output, ['0011100000ScvKS', 'Apex Graphics', 'FR', 'French design agency']);
    fputcsv($output, ['0011100000ScvKT', 'Global Vendor Inc', '', 'Vendor for all countries']);

    fclose($output);
    exit;
}
?>