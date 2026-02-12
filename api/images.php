<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';
// images.php - Image Library and Uploader Interface

// Get target input ID and image type from URL parameters, if present
$targetInputId = isset($_GET['targetInputId']) ? htmlspecialchars($_GET['targetInputId']) : '';
$imageType = isset($_GET['imageType']) ? htmlspecialchars($_GET['imageType']) : '';

// Directory to store uploaded images
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true); // Create directory if it doesn't exist
}

// Define the base URL for the uploaded images.
// IMPORTANT: Adjust this to your actual domain and path if it's different from the example.
// This URL will be used to construct absolute paths for images in the HTML and when selected.
define('BASE_URL', 'https://eposaudioevents.com/emailtemplates/');
$metadataFile = __DIR__ . '/image_metadata.json'; // Path to the metadata file


// Function to send messages back to the parent (emailpos.php)
function sendMessageToParent($type, $text, $closeModal = false, $imageUrl = '', $targetInputId = '') {
    echo '<script type="text/javascript">';
    echo 'if (window.parent) {';
    echo '  window.parent.postMessage({';
    echo '    type: "' . $type . '",';
    echo '    text: "' . addslashes($text) . '",'; // Escape quotes for JavaScript string
    echo '    closeModal: ' . ($closeModal ? 'true' : 'false') . ',';
    echo '    imageUrl: "' . addslashes($imageUrl) . '",';
    echo '    targetInputId: "' . addslashes($targetInputId) . '"';
    echo '  }, "*");'; // Use "*" for targetOrigin if you don't know it or need cross-origin
    echo '}';
    echo '</script>';
}

// Handle file upload (this will be called by form submission within this page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    require_once 'ajax_image_upload.php'; // Include the robust upload logic

    // Pass the image type and meta description from the form to the upload handler
    $uploadedImageType = $_POST['image_type'] ?? 'main'; // Default to 'main' if not set
    $metaDescription = $_POST['meta_description'] ?? ''; // Get meta description from form

    $uploadResult = handleImageUpload($_FILES['image'], $uploadDir, $uploadedImageType, $metaDescription);

    if ($uploadResult['success']) {
        // Send success message and the absolute image URL back to the parent
        sendMessageToParent('imageSelected', 'Image uploaded successfully!', true, $uploadResult['imageUrl'], $targetInputId);
    } else {
        sendMessageToParent('message', 'Upload failed: ' . $uploadResult['message']);
    }
}

// Get list of uploaded images from metadata file
$images = []; // This will now store full metadata objects
if (file_exists($metadataFile)) {
    $currentMetadata = file_get_contents($metadataFile);
    $images = json_decode($currentMetadata, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("image_metadata.json is malformed during read. Error: " . json_last_error_msg());
        $images = []; // Reset if malformed
    }
}

// Filter images based on search query (client-side filtering will handle this, but for PHP reload, pre-filter)
// This PHP-side filtering is useful if the search term is part of the URL query
$searchTerm = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
if (!empty($searchTerm)) {
    $filteredImages = [];
    foreach ($images as $img) {
        $fileNameLower = strtolower(basename($img['fileName']));
        $metaDescLower = strtolower($img['metaDescription'] ?? '');
        if (strpos($fileNameLower, $searchTerm) !== false || strpos($metaDescLower, $searchTerm) !== false) {
            $filteredImages[] = $img;
        }
    }
    $images = $filteredImages;
}


// Sort images by modification time, newest first (based on uploadTime in metadata)
usort($images, function($a, $b) {
    $timeA = strtotime($a['uploadTime'] ?? '1970-01-01 00:00:00'); // Default if missing
    $timeB = strtotime($b['uploadTime'] ?? '1970-01-01 00:00:00'); // Default if missing
    return $timeB - $timeA;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Library</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f0f2f5;
            color: #333;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h2 {
            color: #00242a;
            margin-bottom: 20px;
            text-align: center;
        }
        .upload-section {
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }
        .upload-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        .upload-form input[type="file"],
        .upload-form input[type="text"] { /* Added text input for meta description */
            margin-bottom: 15px;
            border: 1px solid #ccc;
            padding: 8px;
            border-radius: 4px;
            background-color: #f8f8f8;
            width: calc(100% - 16px);
            box-sizing: border-box; /* Include padding in width */
        }
        .upload-form button {
            background-color: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s ease;
        }
        .upload-form button:hover {
            background-color: #0056b3;
        }
        .image-type-selector {
            margin-bottom: 15px;
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        .image-type-selector label {
            font-weight: normal;
            display: flex;
            align-items: center;
            cursor: pointer;
        }
        .image-type-selector input[type="radio"] {
            margin-right: 5px;
            cursor: pointer;
        }
        .search-section {
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            text-align: center;
        }
        .search-section input[type="text"] {
            width: calc(100% - 16px);
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            margin-bottom: 10px;
        }
        .search-section button {
            background-color: #00242a;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s ease;
        }
        .search-section button:hover {
            background-color: #00353d;
        }
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            max-height: 500px; /* Limit height for scrollability */
            overflow-y: auto; /* Enable scrolling */
            padding-right: 10px; /* Space for scrollbar */
        }
        .image-item {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background-color: #f9f9f9;
            box-shadow: 0 1px 5px rgba(0,0,0,0.08);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between; /* Space out image and button */
            padding-bottom: 10px; /* Padding below button */
        }
        .image-item img {
            max-width: 100%;
            height: 120px; /* Fixed height for image preview */
            object-fit: contain; /* Contain image within bounds */
            display: block;
            margin: 0 auto 10px auto;
            background-color: #eee; /* Placeholder background */
        }
        .image-item .image-info {
            font-size: 0.85em;
            color: #555;
            word-break: break-all;
            padding: 0 10px;
            margin-bottom: 5px; /* Reduced margin */
        }
        .image-item .meta-description {
            font-size: 0.75em;
            color: #888;
            word-break: break-all;
            padding: 0 10px;
            margin-bottom: 10px;
            min-height: 1.5em; /* Ensure some space even if empty */
        }
        .image-item .select-btn {
            background-color: #00a399;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s ease;
            white-space: nowrap; /* Prevent button text wrapping */
            margin-top: auto; /* Push button to bottom */
        }
        .image-item .select-btn:hover {
            background-color: #007a73;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="upload-section">
            <h2>Upload New Image</h2>
            <form action="images.php?targetInputId=<?php echo $targetInputId; ?>&imageType=<?php echo $imageType; ?>" method="post" enctype="multipart/form-data" class="upload-form">
                <label for="fileToUpload">Select image to upload:</label>
                <input type="file" name="image" id="fileToUpload" required>

                <label for="metaDescription">Meta Description (optional):</label>
                <input type="text" name="meta_description" id="metaDescription" placeholder="e.g., product shot, background, logo">

                <div class="image-type-selector">
                    <label>
                        <input type="radio" name="image_type" value="main" <?php echo ($imageType == 'main' || empty($imageType)) ? 'checked' : ''; ?>> Main Image (resizes to 600px width, auto height)
                    </label>
                    <label>
                        <input type="radio" name="image_type" value="small" <?php echo ($imageType == 'small') ? 'checked' : ''; ?>> Small Image (resizes to 250px width, auto height)
                    </label>
                    <label>
                        <input type="radio" name="image_type" value="background" <?php echo ($imageType == 'background') ? 'checked' : ''; ?>> Background Image (resizes to 1920x1080px)
                    </label>
                </div>

                <button type="submit" name="submit">Upload Image</button>
            </form>
        </div>

        <div class="search-section">
            <h2>Search Image Library</h2>
            <input type="text" id="imageSearchInput" placeholder="Search by filename or description...">
            <button type="button" onclick="filterImages()">Search</button>
        </div>

        <h2>Your Image Library</h2>
        <div class="image-gallery" id="imageGallery">
            <?php if (empty($images)): ?>
                <p style="grid-column: 1 / -1; text-align: center; color: #777;">No images uploaded yet.</p>
            <?php else: ?>
                <?php foreach ($images as $img): ?>
                    <div class="image-item" data-filename="<?php echo htmlspecialchars(basename($img['fileName'])); ?>" data-metadesc="<?php echo htmlspecialchars($img['metaDescription'] ?? ''); ?>">
                        <img src="<?php echo htmlspecialchars($img['imageUrl']); ?>" alt="<?php echo htmlspecialchars($img['metaDescription'] ?? 'Uploaded Image'); ?>">
                        <div class="image-info">
                            <?php echo basename($img['fileName']); ?><br>
                        </div>
                        <div class="meta-description">
                            <?php echo htmlspecialchars($img['metaDescription'] ?? 'No description'); ?>
                        </div>
                        <button type="button" class="select-btn" onclick="selectImage('<?php echo htmlspecialchars($img['imageUrl']); ?>', '<?php echo $targetInputId; ?>')">Select</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script type="text/javascript">
        // Function to send the selected image URL back to the parent window
        function selectImage(imageUrl, targetInputId) {
            if (window.parent) {
                window.parent.postMessage({
                    type: 'imageSelected',
                    imageUrl: imageUrl,
                    targetInputId: targetInputId
                }, "*"); // Use "*" for targetOrigin if you don't know it or need cross-origin
            }
        }

        // Function to filter images based on search input
        function filterImages() {
            const searchTerm = document.getElementById('imageSearchInput').value.toLowerCase();
            const imageItems = document.querySelectorAll('.image-item');

            imageItems.forEach(item => {
                const fileName = item.dataset.filename.toLowerCase();
                const metaDesc = item.dataset.metadesc.toLowerCase();

                if (fileName.includes(searchTerm) || metaDesc.includes(searchTerm)) {
                    item.style.display = 'flex'; // Show the item
                } else {
                    item.style.display = 'none'; // Hide the item
                }
            });
        }

        // Add event listener for real-time filtering as user types
        document.getElementById('imageSearchInput').addEventListener('keyup', filterImages);

        // Initial filter on page load (in case there's a pre-filled search term from URL)
        window.onload = function() {
            filterImages();
        };
    </script>
</body>
</html>
