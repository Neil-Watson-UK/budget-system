<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

$region = $_GET['region'] ?? '';

if (empty($region)) {
    echo json_encode([]);
    exit;
}

$pdo = getDBConnection();

// Get staff for the selected region
$sql = "SELECT field_value, field_label 
        FROM form_field_options 
        WHERE field_type = 'staff' 
        AND (region_group = ? OR region_group IS NULL)
        AND is_active = TRUE 
        ORDER BY sort_order, field_label";

$stmt = $pdo->prepare($sql);
$stmt->execute([$region]);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($staff);
?>