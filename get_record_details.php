<?php
// get_record_details.php
require_once 'db_config.php';

$id = $_GET['id'] ?? 0;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $stmt = $pdo->prepare("SELECT * FROM budget_items WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        echo '<div class="row">';
        echo '<div class="col-md-6">';
        echo '<h6>Basic Information</h6>';
        echo '<table class="table table-sm">';
        echo '<tr><th>PO Number:</th><td>' . htmlspecialchars($row['po_number']) . '</td></tr>';
        echo '<tr><th>Region:</th><td>' . htmlspecialchars($row['region']) . '</td></tr>';
        echo '<tr><th>Country:</th><td>' . htmlspecialchars($row['country']) . '</td></tr>';
        echo '<tr><th>Status:</th><td>' . htmlspecialchars($row['status']) . '</td></tr>';
        echo '<tr><th>Vendor:</th><td>' . htmlspecialchars($row['vendor']) . '</td></tr>';
        echo '</table></div>';
        
        echo '<div class="col-md-6">';
        echo '<h6>Financial Information</h6>';
        echo '<table class="table table-sm">';
        echo '<tr><th>Amount:</th><td>' . number_format($row['amount_requested'], 2) . ' ' . htmlspecialchars($row['currency']) . '</td></tr>';
        echo '<tr><th>Start Date:</th><td>' . (!empty($row['start_date']) ? date('d/m/Y', strtotime($row['start_date'])) : '') . '</td></tr>';
        echo '<tr><th>End Date:</th><td>' . (!empty($row['end_date']) ? date('d/m/Y', strtotime($row['end_date'])) : '') . '</td></tr>';
        echo '<tr><th>Invoiced Date:</th><td>' . (!empty($row['invoiced_date']) ? date('d/m/Y', strtotime($row['invoiced_date'])) : '') . '</td></tr>';
        echo '</table></div>';
        
        echo '</div>';
        
        if (!empty($row['activity_description'])) {
            echo '<div class="mt-3"><h6>Description</h6><p>' . nl2br(htmlspecialchars($row['activity_description'])) . '</p></div>';
        }
        
        if (!empty($row['comments'])) {
            echo '<div class="mt-3"><h6>Comments</h6><p>' . nl2br(htmlspecialchars($row['comments'])) . '</p></div>';
        }
    }
} catch (PDOException $e) {
    echo 'Error loading details.';
}
?>