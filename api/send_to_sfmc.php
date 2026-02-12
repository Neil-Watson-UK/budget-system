<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
// Set up error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure the response is always JSON
header('Content-Type: application/json');

// --- Salesforce Marketing Cloud API Credentials ---
// IMPORTANT: Replace these placeholders with your actual credentials from SFMC Installed Package.
//
// Client ID:     rxifx1q03zrdcmbhw6lp82si
// Client Secret: seudptgESVtWJpL15iRZ43oF
// Auth Base URI: https://mc8l4b6v89xlnpz8ydnvmmc76pg4.auth.marketingcloudapis.com
// REST Base URI: https://mc8l4b6v89xlnpz8ydnvmmc76pg4.rest.marketingcloudapis.com
//
// Note: Do NOT add a trailing slash to the base URIs.
define('SFMC_CLIENT_ID', 'rxifx1q03zrdcmbhw6lp82si');
define('SFMC_CLIENT_SECRET', 'seudptgESVtWJpL15iRZ43oF');
define('SFMC_AUTH_BASE_URI', 'https://mc8l4b6v89xlnpz8ydnvmmc76pg4.auth.marketingcloudapis.com');
define('SFMC_REST_BASE_URI', 'https://mc8l4b6v89xlnpz8ydnvmmc76pg4.rest.marketingcloudapis.com');

/**
 * Obtains an access token from Salesforce Marketing Cloud using Client Credentials grant type.
 *
 * @return array An associative array containing 'success' (boolean) and either 'access_token' or 'message' and 'details'.
 */
function getSfmcAccessToken() {
    $auth_url = SFMC_AUTH_BASE_URI . '/v2/token'; // Full authentication endpoint
    error_log("Attempting to get access token from: " . $auth_url);

    $payload = [
        'grant_type' => 'client_credentials',
        'client_id' => SFMC_CLIENT_ID,
        'client_secret' => SFMC_CLIENT_SECRET
    ];

    $ch = curl_init($auth_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
    curl_setopt($ch, CURLOPT_POST, true);           // Set as POST request
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); // Encode payload as JSON
    curl_setopt($ch, CURLOPT_HTTPHEADER, [         // Set request headers
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Get HTTP status code
    $curl_error = curl_error($ch);                        // Get cURL error message
    curl_close($ch);                                      // Close cURL session

    if ($curl_error) {
        // Log or handle cURL errors
        error_log("SFMC Access Token cURL Error: " . $curl_error);
        return ['success' => false, 'message' => 'Network error during authentication: ' . $curl_error];
    }

    $data = json_decode($response, true); // Decode JSON response

    // Check for HTTP 200 OK and presence of access_token
    if ($http_status !== 200 || !isset($data['access_token'])) {
        error_log("SFMC Access Token API Error: HTTP Status " . $http_status . " - Response: " . print_r($data, true));
        return [
            'success' => false,
            'message' => 'Failed to get access token from SFMC.',
            'details' => $data // Include API response details for debugging
        ];
    }

    return ['success' => true, 'access_token' => $data['access_token']];
}

/**
 * Gets a Content Builder category (folder) by name and parent ID.
 *
 * @param string $accessToken The SFMC OAuth access token.
 * @param string $categoryName The name of the category to find.
 * @param int $parentId The ID of the parent category (0 for root).
 * @return array An associative array containing 'success' (boolean) and either 'category_id' or 'message' and 'details'.
 */
function getSfmcCategoryByNameAndParent($accessToken, $categoryName, $parentId) {
    $rest_url = SFMC_REST_BASE_URI . '/asset/v1/content/categories';

    // Construct the filter expression string first
    // The name should be enclosed in single quotes for OData, and then the entire filter URL-encoded.
    // urlencode will handle the single quotes and spaces correctly.
    $filter_expression = "name eq '" . $categoryName . "'";
    if ($parentId !== null) { // ParentId can be 0 or other valid IDs
        // Ensure parentId is treated as an integer in the filter string
        $filter_expression .= " AND parentId eq " . (int)$parentId;
    }

    // Now, URL-encode the *entire* filter expression and prepend '$filter='
    $url_with_params = $rest_url . '?$filter=' . urlencode($filter_expression);
    error_log("Attempting to get category: '$categoryName' with parentId: '$parentId' from URL: " . $url_with_params);


    $ch = curl_init($url_with_params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("SFMC Get Category cURL Error: " . $curl_error);
        return ['success' => false, 'message' => 'Network error during category lookup: ' . $curl_error, 'details' => ['curl_error' => $curl_error]];
    }

    $data = json_decode($response, true);

    if ($http_status !== 200) {
        error_log("SFMC Get Category API Error: HTTP Status " . $http_status . " - Response: " . print_r($data, true));
        return [
            'success' => false,
            'message' => 'Failed to retrieve SFMC category.',
            'details' => $data
        ];
    }

    if (isset($data['items']) && count($data['items']) > 0) {
        error_log("Category '$categoryName' (parentId: $parentId) found. ID: " . $data['items'][0]['id']);
        return ['success' => true, 'category_id' => $data['items'][0]['id']];
    }

    error_log("Category '$categoryName' (parentId: $parentId) not found.");
    return ['success' => false, 'message' => 'Category not found.'];
}

/**
 * Creates a new Content Builder category (folder).
 *
 * @param string $accessToken The SFMC OAuth access token.
 * @param string $categoryName The name of the new category.
 * @param int $parentId The ID of the parent category.
 * @return array An associative array containing 'success' (boolean) and either 'category_id' or 'message' and 'details'.
 */
function createSfmcCategory($accessToken, $categoryName, $parentId) {
    $rest_url = SFMC_REST_BASE_URI . '/asset/v1/content/categories';
    error_log("Attempting to create category: '$categoryName' under parentId: '$parentId'");

    $payload = [
        'name' => $categoryName,
        'parentId' => $parentId,
        'description' => 'Created by EmailPOS application.'
    ];

    $ch = curl_init($rest_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("SFMC Create Category cURL Error: " . $curl_error);
        return ['success' => false, 'message' => 'Network error during category creation: ' . $curl_error, 'details' => ['curl_error' => $curl_error]];
    }

    $data = json_decode($response, true);

    // SFMC API returns 201 Created on successful category creation
    if ($http_status !== 201) {
        error_log("SFMC Create Category API Error: HTTP Status " . $http_status . " - Response: " . print_r($data, true));
        return [
            'success' => false,
            'message' => 'Failed to create SFMC category.',
            'details' => $data
        ];
    }

    error_log("Category '$categoryName' created successfully. ID: " . $data['id']);
    return ['success' => true, 'category_id' => $data['id'], 'message' => 'Category created successfully.'];
}

/**
 * Ensures a full category path exists in SFMC Content Builder and returns the final category ID.
 * Creates any missing folders along the path.
 *
 * @param string $accessToken The SFMC OAuth access token.
 * @param array $pathSegments An array of folder names in order (e.g., ['00 - EmailPOS', '2025', '06 - June', 'AMER', 'My Subject']).
 * @param int $rootCategoryId The starting parent category ID (e.g., 0 for "My Content" root).
 * @return array An associative array containing 'success' (boolean) and either 'final_category_id' or 'message' and 'details'.
 */
function ensureSfmcCategoryPath($accessToken, $pathSegments, $rootCategoryId = 0) {
    $currentParentId = $rootCategoryId;
    error_log("Starting path assurance with rootCategoryId: " . $rootCategoryId);

    // Explicitly handle the "Content Builder" root category as it's a special system folder.
    // It should *always* exist. We should not attempt to create it.
    $contentBuilderSegment = array_shift($pathSegments);
    if (empty(trim($contentBuilderSegment)) || strtolower(trim($contentBuilderSegment)) !== 'content builder') {
        error_log("First path segment is empty or not 'Content Builder'.");
        return ['success' => false, 'message' => 'Invalid initial path segment. Expected "Content Builder".'];
    }

    // Try to find the Content Builder root category
    $getContentBuilderRoot = getSfmcCategoryByNameAndParent($accessToken, trim($contentBuilderSegment), $rootCategoryId);
    if ($getContentBuilderRoot['success']) {
        $currentParentId = $getContentBuilderRoot['category_id'];
        error_log("Found Content Builder root with ID: " . $currentParentId);
    } else {
        // If Content Builder root isn't found, this is a critical error.
        // It implies a permissions issue or an unexpected SFMC configuration.
        // We cannot proceed by trying to CREATE "Content Builder" as it's a system root.
        error_log("CRITICAL ERROR: Content Builder root category not found under parentId " . $rootCategoryId . ". Details: " . ($getContentBuilderRoot['message'] ?? 'Unknown error.') . " " . print_r($getContentBuilderRoot['details'], true));
        return ['success' => false, 'message' => 'Failed to find the root "Content Builder" category. Please ensure the API user has read permissions for Content Builder root folders.', 'details' => $getContentBuilderRoot['details'] ?? ''];
    }


    foreach ($pathSegments as $segmentName) {
        if (empty(trim($segmentName))) {
            continue; // Skip empty segments
        }
        $categoryName = trim($segmentName);

        $get_category_result = getSfmcCategoryByNameAndParent($accessToken, $categoryName, $currentParentId);

        if ($get_category_result['success']) {
            $currentParentId = $get_category_result['category_id'];
        } else {
            // Category not found, try to create it
            $create_category_result = createSfmcCategory($accessToken, $categoryName, $currentParentId);
            if ($create_category_result['success']) {
                $currentParentId = $create_category_result['category_id'];
            } else {
                return ['success' => false, 'message' => 'Failed to create path segment "' . $categoryName . '".', 'details' => $create_category_result['details'] ?? ''];
            }
        }
    }

    error_log("Successfully resolved full path. Final category ID: " . $currentParentId);
    return ['success' => true, 'final_category_id' => $currentParentId];
}

/**
 * Creates an Email Asset in Salesforce Marketing Cloud's Content Builder.
 *
 * @param string $accessToken The SFMC OAuth access token.
 * @param string $emailName The desired name for the email asset in Content Builder.
 * @param string $subjectLine The subject line of the email.
 * @param string $preheaderText The preheader text of the email.
 * @param string $htmlContent The full HTML content of the email.
 * @param int $categoryId The ID of the Content Builder category (folder) where the email asset will be saved.
 * @return array An associative array indicating success/failure and relevant data (e.g., asset ID).
 */
function createSfmcEmailAsset($accessToken, $emailName, $subjectLine, $preheaderText, $htmlContent, $categoryId) {
    $rest_url = SFMC_REST_BASE_URI . '/asset/v1/content/assets'; // Content Builder Assets endpoint
    error_log("Attempting to create email asset: '$emailName' in category ID: '$categoryId'");

    // Payload for creating an HTML Email asset
    $payload = [
        'name' => $emailName,
        'assetType' => [
            'id' => 207, // Asset Type ID for HTML Email Message in Content Builder
            'name' => 'htmlemail'
        ],
        'category' => [
            'id' => $categoryId // Use the dynamically determined category ID
        ],
        'views' => [
            'html' => [
                'content' => $htmlContent // The main HTML content
            ],
            'text' => [
                'content' => strip_tags($htmlContent) // Basic plain text version (consider a more robust HTML-to-text conversion for production)
            ],
            'preheader' => [
                'content' => $preheaderText
            ]
        ],
        'channels' => [
            'email' => true, // Indicate that this asset is for email channels
            'web' => false
        ],
        'data' => [
            'email' => [
                'subject' => $subjectLine,
                'preheader' => $preheaderText
            ]
        ]
    ];

    $ch = curl_init($rest_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken // Authorization header with bearer token
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("SFMC Create Email Asset cURL Error: " . $curl_error);
        return ['success' => false, 'message' => 'Network error during asset creation: ' . $curl_error, 'details' => ['curl_error' => $curl_error]];
    }

    $data = json_decode($response, true);

    // SFMC API returns 201 Created on successful asset creation
    if ($http_status !== 201) {
        error_log("SFMC Create Email Asset API Error: HTTP Status " . $http_status . " - Response: " . print_r($data, true));
        return [
            'success' => false,
            'message' => 'Failed to create email asset in SFMC.',
            'details' => $data // Include API response details for debugging
        ];
    }

    error_log("Email asset created successfully in SFMC. ID: " . $data['id']);
    return ['success' => true, 'asset_id' => $data['id'], 'message' => 'Email asset created successfully in SFMC.'];
}

// --- Main Logic ---
// This script expects a POST request with JSON data containing email details.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $request_data = json_decode($input, true);

    // Validate JSON input
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input received.']);
        exit;
    }

    // Extract necessary data from the request
    $emailName = $request_data['subjectLine'] ?? 'Untitled Email Asset'; // Use subject as name
    $subjectLine = $request_data['subjectLine'] ?? '';
    $preheaderText = $request_data['preheaderText'] ?? '';
    $htmlContent = $request_data['htmlContent'] ?? '';
    $region = $request_data['region'] ?? 'Unknown'; // Get region from front-end data

    // Basic validation of incoming data
    if (empty($subjectLine) || empty($htmlContent)) {
        echo json_encode(['success' => false, 'message' => 'Missing required email data (subject line or HTML content).']);
        exit;
    }

    // Step 1: Get Access Token from SFMC
    $auth_result = getSfmcAccessToken();
    if (!$auth_result['success']) {
        echo json_encode($auth_result); // Return the authentication error to the client
        exit;
    }
    $accessToken = $auth_result['access_token'];

    // Step 2: Determine and create the category (folder) path
    $currentYear = date('Y');
    $currentMonthNum = date('n'); // Numeric month (1-12)
    $currentMonthName = date('F'); // Full month name (e.g., January)
    $monthFolderName = sprintf('%02d - %s', $currentMonthNum, $currentMonthName); // e.g., "06 - June"

    // Sanitize subject line for use as a folder name
    $subjectFolderName = preg_replace('/[^a-zA-Z0-9\s-]/', '', $subjectLine); // Remove special chars
    $subjectFolderName = trim(substr($subjectFolderName, 0, 50)); // Trim and limit length
    if (empty($subjectFolderName)) {
        $subjectFolderName = 'Untitled Campaign';
    }

    // UPDATED: The FULL path to the target folder, based on API output and SFMC structure.
    // This is the array of segments that `ensureSfmcCategoryPath` will process.
    // Assumes 'Test' is a direct child of 'Content Builder'.
    $path_segments_to_create = [
        'Content Builder', // This is the fixed root
        'Test',            // Your specific subfolder within Content Builder
        $currentYear,
        $monthFolderName,
        $region,
        $subjectFolderName
    ];

    // Root category ID for Content Builder is often 0.
    // The ensureSfmcCategoryPath function is now more robust for the first segment.
    $rootContentBuilderCategoryId = 0;

    $ensure_path_result = ensureSfmcCategoryPath($accessToken, $path_segments_to_create, $rootContentBuilderCategoryId);

    if (!$ensure_path_result['success']) {
        echo json_encode($ensure_path_result); // Return path creation error
        exit;
    }
    $targetCategoryId = $ensure_path_result['final_category_id'];

    // Step 3: Create Email Asset in SFMC Content Builder in the determined category
    $create_asset_result = createSfmcEmailAsset($accessToken, $emailName, $subjectLine, $preheaderText, $htmlContent, $targetCategoryId);

    // Return the result of the asset creation to the client
    echo json_encode($create_asset_result);

} else {
    // Handle non-POST requests
    echo json_encode(['success' => false, 'message' => 'Invalid request method. Only POST requests are allowed.']);
}
?>