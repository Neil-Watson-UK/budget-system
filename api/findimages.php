<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'images' => []];
$metadataFile = 'image_data.json'; // New: Use the metadata file

if (!file_exists($metadataFile)) {
    $response['message'] = 'No image metadata available.';
    echo json_encode($response);
    exit;
}

$imageData = json_decode(file_get_contents($metadataFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $response['message'] = 'Error reading image metadata.';
    echo json_encode($response);
    exit;
}

$searchQuery = isset($_GET['q']) ? strtolower(trim($_GET['q'])) : '';

$filteredImages = [];
foreach ($imageData as $image) {
    $match = true;
    if ($searchQuery) {
        $customNameLower = strtolower($image['customName'] ?? '');
        $fileNameLower = strtolower($image['fileName'] ?? ''); // Fallback to original generated filename

        // Check if search query is found in custom name or filename
        if (strpos($customNameLower, $searchQuery) === false && strpos($fileNameLower, $searchQuery) === false) {
            $match = false;
        }
    }

    if ($match) {
        $filteredImages[] = [
            'url' => $image['url'],
            'name' => $image['customName'] ?? pathinfo($image['originalFileName'], PATHINFO_FILENAME) // Prioritize custom name, fallback to original filename without extension
        ];
    }
}

// Sort images by upload time, newest first
usort($filteredImages, function($a, $b) use ($imageData) {
    // Find the full image data to get the uploadTime
    $a_id = array_search($a['url'], array_column($imageData, 'url'));
    $b_id = array_search($b['url'], array_column($imageData, 'url'));

    $a_time = $imageData[$a_id]['uploadTime'] ?? 0;
    $b_time = $imageData[$b_id]['uploadTime'] ?? 0;

    return $b_time <=> $a_time; // Descending order (newest first)
});


if (!empty($filteredImages)) {
    $response['success'] = true;
    $response['message'] = 'Images found.';
    $response['images'] = $filteredImages;
} else {
    $response['message'] = 'No images found matching your criteria.';
}

echo json_encode($response);
?>