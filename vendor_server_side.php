<?php
// vendor_server_side.php - Server-side processing for DataTables
session_start();
require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", 
        DB_USER, 
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // DataTables parameters
    $start = $_GET['start'] ?? 0;
    $length = $_GET['length'] ?? 25;
    $search = $_GET['search']['value'] ?? '';
    $orderColumn = $_GET['order'][0]['column'] ?? 0;
    $orderDir = $_GET['order'][0]['dir'] ?? 'asc';
    
    // Column mapping
    $columns = [
        0 => 'id',
        1 => 'vendor_name',
        2 => 'salesforce_id',
        3 => 'account_type',
        4 => 'Type_value__c',
        5 => 'AMPLIFY_Level__c',
        6 => 'Owner_Full_Name__c',
        7 => 'region',
        8 => 'Account_Status__c'
    ];
    
    $orderBy = $columns[$orderColumn] . ' ' . strtoupper($orderDir);
    
    // Build search conditions
    $searchConditions = [];
    $searchParams = [];
    
    if (!empty($search)) {
        $searchConditions = [
            "vendor_name LIKE :search",
            "salesforce_id LIKE :search",
            "account_type LIKE :search",
            "Type_value__c LIKE :search",
            "AMPLIFY_Level__c LIKE :search",
            "Owner_Full_Name__c LIKE :search",
            "region LIKE :search",
            "Account_Status__c LIKE :search"
        ];
        $searchParams[':search'] = '%' . $search . '%';
    }
    
    $whereClause = '';
    if (!empty($searchConditions)) {
        $whereClause = 'WHERE ' . implode(' OR ', $searchConditions);
    }
    
    // Get total records
    $totalRecords = $pdo->query("SELECT COUNT(*) as count FROM vendors")->fetch()['count'];
    
    // Get filtered records
    $filteredQuery = "SELECT COUNT(*) as count FROM vendors $whereClause";
    $stmt = $pdo->prepare($filteredQuery);
    foreach ($searchParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $filteredRecords = $stmt->fetch()['count'];
    
    // Get paginated data
    $query = "SELECT 
                id,
                vendor_name,
                salesforce_id,
                account_type,
                Type_value__c,
                AMPLIFY_Level__c,
                Owner_Full_Name__c,
                region,
                Account_Status__c
              FROM vendors 
              $whereClause
              ORDER BY $orderBy
              LIMIT :start, :length";
    
    $stmt = $pdo->prepare($query);
    
    // Bind parameters
    foreach ($searchParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':start', (int)$start, PDO::PARAM_INT);
    $stmt->bindValue(':length', (int)$length, PDO::PARAM_INT);
    
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    // Format data for DataTables
    $formattedData = [];
    foreach ($data as $row) {
        // Determine AMPLIFY badge class
        $amplifyLevel = $row['AMPLIFY_Level__c'] ?? '';
        $amplifyClass = 'amplify-badge ';
        if (stripos($amplifyLevel, 'platinum') !== false) $amplifyClass .= 'amplify-platinum';
        elseif (stripos($amplifyLevel, 'gold') !== false) $amplifyClass .= 'amplify-gold';
        elseif (stripos($amplifyLevel, 'silver') !== false) $amplifyClass .= 'amplify-silver';
        elseif (stripos($amplifyLevel, 'bronze') !== false) $amplifyClass .= 'amplify-bronze';
        else $amplifyClass .= 'bg-light text-dark';
        
        // Determine status badge
        $status = $row['Account_Status__c'] ?? '';
        $statusClass = 'status-' . (stripos($status, 'active') !== false ? 'active' : 'inactive');
        
        $formattedData[] = [
            $row['id'],
            htmlspecialchars($row['vendor_name']),
            '<code class="sf-id">' . htmlspecialchars($row['salesforce_id'] ?: '—') . '</code>',
            $row['account_type'] ? '<span class="badge bg-info">' . htmlspecialchars($row['account_type']) . '</span>' : '—',
            $row['Type_value__c'] ? '<small>' . htmlspecialchars($row['Type_value__c']) . '</small>' : '—',
            $amplifyLevel ? '<span class="' . $amplifyClass . '">' . htmlspecialchars($amplifyLevel) . '</span>' : '—',
            $row['Owner_Full_Name__c'] ? '<span class="owner-badge">' . htmlspecialchars($row['Owner_Full_Name__c']) . '</span>' : '—',
            $row['region'] ? '<span class="badge bg-secondary">' . htmlspecialchars($row['region']) . '</span>' : '—',
            $status ? '<span class="' . $statusClass . '">' . htmlspecialchars($status) . '</span>' : '—',
            '<button class="btn btn-sm btn-outline-info view-details" data-id="' . $row['id'] . '" data-vendor="' . htmlspecialchars($row['vendor_name']) . '">
                <i class="fas fa-eye"></i> Details
            </button>
            ' . ($row['salesforce_id'] ? '<button class="btn btn-sm btn-outline-primary copy-sfid mt-1" data-sfid="' . htmlspecialchars($row['salesforce_id']) . '" title="Copy Salesforce ID">
                <i class="fas fa-copy"></i>
            </button>' : '')
        ];
    }
    
    // Return JSON response
    $response = [
        "draw" => isset($_GET['draw']) ? intval($_GET['draw']) : 1,
        "recordsTotal" => intval($totalRecords),
        "recordsFiltered" => intval($filteredRecords),
        "data" => $formattedData
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (PDOException $e) {
    // Return error response
    $response = [
        "draw" => isset($_GET['draw']) ? intval($_GET['draw']) : 1,
        "recordsTotal" => 0,
        "recordsFiltered" => 0,
        "data" => [],
        "error" => "Database Error: " . $e->getMessage()
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
}
?>