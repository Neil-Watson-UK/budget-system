<?php
// sync_vendors_from_api.php - CLI script to sync vendors from emailpos /vendors API into the local vendors table.
// Usage: php sync_vendors_from_api.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/salesout/config.php';

if (php_sapi_name() !== 'cli') {
    echo "This script is intended to be run from the command line.\n";
    exit(1);
}

if (empty(VENDOR_API_URL)) {
    fwrite(STDERR, "VENDOR_API_URL is not configured. Set it in salesout/config.php.\n");
    exit(1);
}

echo "Syncing vendors from API: " . VENDOR_API_URL . "\n";

try {
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Discover which optional columns exist on vendors.
$cols = [];
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM vendors");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[$row['Field']] = true;
    }
} catch (Exception $e) {
    fwrite(STDERR, "Failed to inspect vendors table: " . $e->getMessage() . "\n");
    exit(1);
}

$hasAmplify = isset($cols['AMPLIFY_Level__c']);
$hasStatus  = isset($cols['Account_Status__c']);
$hasOwner   = isset($cols['Owner_Full_Name__c']);
$hasTypeVal = isset($cols['Type_value__c']);

// Fetch JSON from emailpos /vendors endpoint.
$contextOpts = [
    'http' => [
        'method' => 'GET',
        'header' => [
            'Accept: application/json',
        ],
        'timeout' => 20,
    ],
];

if (!empty(VENDOR_API_KEY)) {
    $contextOpts['http']['header'][] = 'Authorization: Bearer ' . VENDOR_API_KEY;
}

$ctx = stream_context_create($contextOpts);
$json = @file_get_contents(VENDOR_API_URL, false, $ctx);

if ($json === false) {
    $error = error_get_last();
    fwrite(STDERR, "Failed to fetch vendors from API: " . ($error['message'] ?? 'unknown error') . "\n");
    exit(1);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    fwrite(STDERR, "API returned invalid JSON or non-array payload.\n");
    exit(1);
}

$inserted = 0;
$updated  = 0;
$skipped  = 0;

// Prepare basic insert/update statements (built dynamically based on available columns).
foreach ($data as $row) {
    // Expected minimal fields from API:
    //  - salesforce_id (string, key)
    //  - vendor_name  (string)
    //  - region       (budget region code, e.g. UKI)
    // Optional fields:
    //  - account_type
    //  - amplify_level
    //  - account_status
    //  - owner_full_name
    //  - type_value

    $sfId   = trim($row['salesforce_id'] ?? '');
    $name   = trim($row['vendor_name'] ?? '');
    $region = trim($row['region'] ?? '');

    if ($name === '' && $sfId === '') {
        $skipped++;
        continue;
    }

    $accountType   = $row['account_type']   ?? null;
    $amplifyLevel  = $row['amplify_level']  ?? null;
    $accountStatus = $row['account_status'] ?? null;
    $ownerName     = $row['owner_full_name'] ?? null;
    $typeValue     = $row['type_value'] ?? null;

    // Find existing vendor by Salesforce ID first, then by name.
    $vendorId = null;
    if ($sfId !== '') {
        $stmt = $pdo->prepare("SELECT id FROM vendors WHERE salesforce_id = ? LIMIT 1");
        $stmt->execute([$sfId]);
        $vendorId = $stmt->fetchColumn() ?: null;
    }

    if ($vendorId === null && $name !== '') {
        $stmt = $pdo->prepare("SELECT id FROM vendors WHERE LOWER(TRIM(vendor_name)) = LOWER(TRIM(?)) LIMIT 1");
        $stmt->execute([$name]);
        $vendorId = $stmt->fetchColumn() ?: null;
    }

    if ($vendorId) {
        // Update path.
        $fields = ['vendor_name = :name', 'region = :region'];
        $params = [
            ':id'     => $vendorId,
            ':name'   => $name,
            ':region' => $region,
        ];

        if ($sfId !== '') {
            $fields[]          = 'salesforce_id = :sfid';
            $params[':sfid']   = $sfId;
        }
        if ($accountType !== null) {
            $fields[]            = 'account_type = :atype';
            $params[':atype']    = $accountType;
        }
        if ($hasAmplify && $amplifyLevel !== null) {
            $fields[]             = 'AMPLIFY_Level__c = :amplify';
            $params[':amplify']   = $amplifyLevel;
        }
        if ($hasStatus && $accountStatus !== null) {
            $fields[]              = 'Account_Status__c = :accstatus';
            $params[':accstatus']  = $accountStatus;
        }
        if ($hasOwner && $ownerName !== null) {
            $fields[]             = 'Owner_Full_Name__c = :owner';
            $params[':owner']     = $ownerName;
        }
        if ($hasTypeVal && $typeValue !== null) {
            $fields[]              = 'Type_value__c = :typeval';
            $params[':typeval']    = $typeValue;
        }

        $sql = "UPDATE vendors SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $updated++;
    } else {
        // Insert path.
        $cols = ['vendor_name', 'salesforce_id', 'account_type', 'region'];
        $placeholders = [':name', ':sfid', ':atype', ':region'];
        $params = [
            ':name'   => $name,
            ':sfid'   => $sfId !== '' ? $sfId : null,
            ':atype'  => $accountType,
            ':region' => $region,
        ];

        if ($hasAmplify) {
            $cols[]         = 'AMPLIFY_Level__c';
            $placeholders[] = ':amplify';
            $params[':amplify'] = $amplifyLevel;
        }
        if ($hasStatus) {
            $cols[]         = 'Account_Status__c';
            $placeholders[] = ':accstatus';
            $params[':accstatus'] = $accountStatus;
        }
        if ($hasOwner) {
            $cols[]         = 'Owner_Full_Name__c';
            $placeholders[] = ':owner';
            $params[':owner'] = $ownerName;
        }
        if ($hasTypeVal) {
            $cols[]         = 'Type_value__c';
            $placeholders[] = ':typeval';
            $params[':typeval'] = $typeValue;
        }

        $sql = "INSERT INTO vendors (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inserted++;
    }
}

echo "Sync complete.\n";
echo "Inserted: $inserted\n";
echo "Updated:  $updated\n";
echo "Skipped:  $skipped\n";

