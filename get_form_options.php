<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

$region_group = $_GET['region_group'] ?? '';

// Function to get options (same as in add_item.php)
// In get_form_options.php, update the getFormOptions function:
function getFormOptions($field_type, $region_group = null, $country = null) {
    $pdo = getDBConnection();
    
    $sql = "SELECT field_value, field_label, country 
            FROM form_field_options 
            WHERE field_type = ? AND is_active = TRUE";
    
    $params = [$field_type];
    
    if ($region_group) {
        $sql .= " AND (region_group IS NULL OR region_group = ?)";
        $params[] = $region_group;
    }
    
    if ($country) {
        $sql .= " AND (country IS NULL OR country = ?)";
        $params[] = $country;
    }
    
    $sql .= " ORDER BY country IS NULL, country, sort_order, field_label";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Then update the options array:
$country = $_GET['country'] ?? '';

$options = [
    'staff' => getFormOptions('staff', $region_group, $country),
    'accounting_codes' => getFormOptions('accounting_code', $region_group, $country),
    'sub_accounting_codes' => getFormOptions('sub_accounting_code', $region_group, $country),
    'channel_vendors' => getFormOptions('channel_vendor', $region_group, $country),
    'external_vendors' => getFormOptions('external_vendor', $region_group, $country),
    'budget_activities' => getFormOptions('budget_activity', $region_group, $country),
    'mdf_activities' => getFormOptions('mdf_activity', $region_group, $country),
    'primary_campaigns' => getFormOptions('primary_campaign', $region_group, $country),
];

echo json_encode($options);
?>