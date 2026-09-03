<?php
// vendor_lookup.php - CSV-based vendor matching
function getVendorSalesforceId($vendorName) {
    static $vendorMap = null;
    
    if ($vendorMap === null) {
        // Load vendor mapping from CSV
        $vendorMap = [];
        if (file_exists('data/vendor_mapping.csv')) {
            $handle = fopen('data/vendor_mapping.csv', 'r');
            while (($row = fgetcsv($handle)) !== false) {
                $vendorMap[strtolower(trim($row[0]))] = $row[1]; // vendor_name => salesforce_id
            }
            fclose($handle);
        }
    }
    
    // Try exact match
    $key = strtolower(trim($vendorName));
    if (isset($vendorMap[$key])) {
        return $vendorMap[$key];
    }
    
    // Try partial match
    foreach ($vendorMap as $vendor => $id) {
        if (strpos($key, $vendor) !== false || strpos($vendor, $key) !== false) {
            return $id;
        }
    }
    
    return null; // No match found
}
?>