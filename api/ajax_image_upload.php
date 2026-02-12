<?php
// api/ajax_image_upload.php

// This file handles image uploads, resizing, and storing metadata.

// Include necessary initialization (session, security, database connection)
require_once __DIR__ . '/../includes/init.php';

// Ensure the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    send_json_response(false, 'Invalid request method.');
}

// Ensure user is logged in for security
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403); // Forbidden
    send_json_response(false, 'Authentication required.');
}

// Check for uploaded file
if (!isset($_FILES['imageFile']) || $_FILES['imageFile']['error'] !== UPLOAD_ERR_OK) {
    send_json_response(false, 'No file uploaded or an upload error occurred. Error code: ' . $_FILES['imageFile']['error']);
}

$uploadedFile = $_FILES['imageFile'];
$imageDescription = $_POST['imageDescription'] ?? '';
$imageType = $_POST['imageType'] ?? 'custom';
$customWidth = isset($_POST['customWidth']) ? (int)$_POST['customWidth'] : null;
$customHeight = isset($_POST['customHeight']) ? (int)$_POST['customHeight'] : null;

// Define upload directory relative to the script
$uploadDir = __DIR__ . '/uploads/'; // This will be /home/site/wwwroot/api/uploads/
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true); // Create directory if it doesn't exist, recursively
}

// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$fileMimeType = mime_content_type($uploadedFile['tmp_name']);
if (!in_array($fileMimeType, $allowedTypes)) {
    send_json_response(false, 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.');
}

// Generate a unique filename to prevent overwrites
$fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
$uniqueFilename = uniqid('img_', true) . '.' . $fileExtension;
$targetFilePath = $uploadDir . $uniqueFilename;
$publicUrl = BASE_APP_URL . 'api/uploads/' . $uniqueFilename; // Publicly accessible URL

// --- Image Processing (Resizing and Saving) ---
// This requires the GD library (usually enabled by default in PHP containers)
if (!extension_loaded('gd') && !extension_loaded('imagick')) {
    send_json_response(false, 'PHP GD or Imagick extension is not enabled. Image resizing is not possible.');
}

$sourceImage = null;
switch ($fileMimeType) {
    case 'image/jpeg':
        $sourceImage = imagecreatefromjpeg($uploadedFile['tmp_name']);
        break;
    case 'image/png':
        $sourceImage = imagecreatefrompng($uploadedFile['tmp_name']);
        break;
    case 'image/gif':
        $sourceImage = imagecreatefromgif($uploadedFile['tmp_name']);
        break;
    case 'image/webp':
        $sourceImage = imagecreatefromwebp($uploadedFile['tmp_name']);
        break;
}

if (!$sourceImage) {
    send_json_response(false, 'Failed to open image file for processing.');
}

$originalWidth = imagesx($sourceImage);
$originalHeight = imagesy($sourceImage);
$targetWidth = $originalWidth;
$targetHeight = $originalHeight;

// Determine target dimensions based on image type
switch ($imageType) {
    case 'main_article':
        $targetWidth = 600;
        $targetHeight = (int)($originalHeight * ($targetWidth / $originalWidth)); // Maintain aspect ratio
        break;
    case 'two_column_article':
        $targetWidth = 280;
        $targetHeight = (int)($originalHeight * ($targetWidth / $originalWidth)); // Maintain aspect ratio
        break;
    case 'background':
        $targetWidth = 1920;
        $targetHeight = 1080;
        // For background, we might crop or stretch. For simplicity, we'll just resize.
        // If aspect ratio is critical, more complex cropping/padding logic is needed.
        if ($originalWidth / $originalHeight > $targetWidth / $targetHeight) { // Wider than target
            $targetHeight = (int)($originalHeight * ($targetWidth / $originalWidth));
        } else { // Taller than target
            $targetWidth = (int)($originalWidth * ($targetHeight / $originalHeight));
        }
        break;
    case 'custom':
        if ($customWidth) {
            $targetWidth = $customWidth;
            if (!$customHeight) { // If only width specified, maintain aspect ratio
                $targetHeight = (int)($originalHeight * ($targetWidth / $originalWidth));
            } else {
                $targetHeight = $customHeight;
            }
        } else if ($customHeight) { // If only height specified, maintain aspect ratio
            $targetHeight = $customHeight;
            $targetWidth = (int)($originalWidth * ($targetHeight / $originalHeight));
        }
        // If neither custom width nor height, use original dimensions (which are already set)
        break;
}

// Create a new true-color image with the target dimensions
$resizedImage = imagecreatetruecolor($targetWidth, $targetHeight);

// For PNG/GIF, preserve transparency
if ($fileMimeType == 'image/png' || $fileMimeType == 'image/gif') {
    imagealphablending($resizedImage, false);
    imagesavealpha($resizedImage, true);
    $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
    imagefilledrectangle($resizedImage, 0, 0, $targetWidth, $targetHeight, $transparent);
}


// Resample and resize the image
imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $originalWidth, $originalHeight);

// Save the resized image
$imageSaved = false;
switch ($fileMimeType) {
    case 'image/jpeg':
        $imageSaved = imagejpeg($resizedImage, $targetFilePath, 90); // 90% quality
        break;
    case 'image/png':
        $imageSaved = imagepng($resizedImage, $targetFilePath, 9); // 0-9, 9 is best compression
        break;
    case 'image/gif':
        $imageSaved = imagegif($resizedImage, $targetFilePath);
        break;
    case 'image/webp':
        $imageSaved = imagewebp($resizedImage, $targetFilePath, 90); // 0-100, 90 is good quality
        break;
}

imagedestroy($sourceImage);
imagedestroy($resizedImage);

if (!$imageSaved) {
    send_json_response(false, 'Failed to save the resized image.');
}

// --- Store Image Metadata ---
$metadataFile = __DIR__ . '/image_metadata.json'; // Store image metadata here

$allImagesMetadata = [];
if (file_exists($metadataFile)) {
    $currentMetadata = json_decode(file_get_contents($metadataFile), true);
    if ($currentMetadata !== null) {
        $allImagesMetadata = $currentMetadata;
    }
}

$newImageEntry = [
    'id' => uniqid('img_meta_', true),
    'filename' => $uniqueFilename,
    'publicUrl' => $publicUrl,
    'description' => $imageDescription,
    'type' => $imageType,
    'originalWidth' => $originalWidth,
    'originalHeight' => $originalHeight,
    'resizedWidth' => $targetWidth,
    'resizedHeight' => $targetHeight,
    'uploadedBy' => $_SESSION['loggedInUserName'] ?? 'Unknown', // From session
    'uploadTime' => date('Y-m-d H:i:s')
];

$allImagesMetadata[] = $newImageEntry;

if (!file_put_contents($metadataFile, json_encode($allImagesMetadata, JSON_PRETTY_PRINT))) {
    // Log error but still return success for image upload if image itself saved
    error_log("Failed to save image metadata to {$metadataFile}");
}

send_json_response(true, 'Image uploaded and processed successfully.', ['imageUrl' => $publicUrl, 'imageMetadata' => $newImageEntry]);

?>
