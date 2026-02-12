<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';

// Security check: Redirect if user is not logged in or not an admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || ($_SESSION['user_level'] ?? 'user') !== 'admin') {
    header('Location: index.php'); // Redirect to login page or a non-admin page
    exit;
}

$settingsFile = 'settings.json';
$settings = [];

// Define the directory for font uploads
$fontUploadDir = 'uploads/fonts/';
// Create the directory if it doesn't exist (and ensure it's writable!)
if (!is_dir($fontUploadDir)) {
    mkdir($fontUploadDir, 0755, true); // Create recursively with default permissions
}


// Load existing settings
if (file_exists($settingsFile)) {
    $settingsContent = file_get_contents($settingsFile);
    $settings = json_decode($settingsContent, true);
    if ($settings === null) {
        // Handle JSON decode error, e.g., corrupted file
        $settings = [];
        error_log("Error decoding settings.json. File might be corrupted.");
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newSettings = [
        'defaultLogoUrl' => $_POST['defaultLogoUrl'] ?? '',
        'primaryBrandColor' => $_POST['primaryBrandColor'] ?? '',
        'secondaryBrandColor' => $_POST['secondaryBrandColor'] ?? '',
        'defaultFontFamily' => $_POST['defaultFontFamily'] ?? '', // This might be overridden by customFontFamilyName
        'allowedTextColors' => $_POST['allowedTextColors'] ?? '',
        'allowedBackgroundColors' => $_POST['allowedBackgroundColors'] ?? '',
        'customFontUrl' => $settings['customFontUrl'] ?? '', // Preserve existing if not updated
        'customFontFamilyName' => $_POST['customFontFamilyName'] ?? '' // New field
    ];

    // Sanitize and validate inputs (basic example, enhance as needed)
    foreach ($newSettings as $key => $value) {
        // Simple string sanitization, for colors and URLs, more robust validation is recommended
        if (!in_array($key, ['customFontUrl'])) { // Do not htmlspecialchars customFontUrl until later
            $newSettings[$key] = htmlspecialchars(strip_tags(trim($value)));
        }
    }

    // Handle font file upload
    if (isset($_FILES['customFontFile']) && $_FILES['customFontFile']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['customFontFile']['tmp_name'];
        $fileName = basename($_FILES['customFontFile']['name']);
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['woff', 'woff2', 'ttf', 'otf']; // Common web font formats

        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension; // Unique filename
            $destPath = $fontUploadDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $newSettings['customFontUrl'] = $destPath;
                $message = "Font uploaded and settings saved successfully!";
            } else {
                $error = "Error uploading font file. Check permissions for the '{$fontUploadDir}' directory.";
            }
        } else {
            $error = "Invalid font file type. Allowed types: " . implode(', ', $allowedExtensions) . ".";
        }
    }

    // If customFontFamilyName is set and a custom font URL exists, make it the primary font
    if (!empty($newSettings['customFontFamilyName']) && !empty($newSettings['customFontUrl'])) {
        $newSettings['defaultFontFamily'] = $newSettings['customFontFamilyName'] . ', ' . $newSettings['defaultFontFamily'];
    }


    // Save settings to JSON file
    if (file_put_contents($settingsFile, json_encode($newSettings, JSON_PRETTY_PRINT))) {
        if (!isset($message)) { // Don't override font upload message
            $message = "Settings saved successfully!";
        }
        // Reload settings to ensure the form displays the latest saved data
        $settings = $newSettings;
    } else {
        $error = "Error saving settings. Check file permissions for settings.json.";
    }
}

$loggedInUserName = isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Settings - EmailPOS Editor</title>
    <link rel="stylesheet" href="../assets/css/emailpos.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Specific styles for color inputs if you want them to be squares */
        input[type="color"] {
            width: 50px;
            height: 30px;
            border: none;
            padding: 0;
            cursor: pointer;
            vertical-align: middle;
            margin-right: 10px;
        }
        .settings-form-group {
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
            padding: 15px;
            border-radius: 8px;
            background-color: #fdfdfd;
        }
        .settings-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #00242a;
        }
        .settings-form-group input[type="text"],
        .settings-form-group input[type="url"],
        .settings-form-group input[type="file"],
        .settings-form-group textarea {
            width: calc(100% - 22px); /* Adjust for padding and border */
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
            font-family: 'EPOS Basis', Arial, sans-serif;
            font-size: 1em;
            color: #333;
        }
        .settings-form-group input[type="file"] {
            padding: 5px; /* Adjust padding for file input */
        }
        .settings-form-group p.description {
            font-size: 0.85em;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <!-- New Navigation Bar -->
    <nav class="main-nav">
        <img src="../assets/images/emailpos_white.svg" alt="EmailPOS Logo" class="nav-logo">
        <span class="nav-title">EmailPOS Editor</span>
        <div class="nav-links">
            <a href="../emailpos.php">Email Editor</a>
            <a href="calendar.php">Calendar</a>
            <?php if (($_SESSION['user_level'] ?? 'user') === 'admin'): ?>
                <a href="admin_panel.php">Manage Users</a>
                <a href="create_user.php">Create New User</a>
                <a href="global_settings.php">Global Settings</a> <!-- Link to this page -->
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </nav>

    <div id="main-app-content">
        <div style="text-align: center; margin-top: 20px;">
            <a href="../emailpos.php">
                <img alt="EPOS" src="../assets/images/emailpos.svg" style="width:300px;height:auto; display: inline-block;">
            </a>
            <h1>Hi <?php echo $loggedInUserName; ?> - Global Settings</h1><br />
        </div>

        <div class="form-container">
            <h2>Brand Output Settings</h2>
            <?php if (isset($message)): ?>
                <p style="color: green; font-weight: bold;"><?php echo $message; ?></p>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <p style="color: red; font-weight: bold;"><?php echo $error; ?></p>
            <?php endif; ?>

            <form method="POST" action="global_settings.php" enctype="multipart/form-data">
                <div class="settings-form-group">
                    <label for="defaultLogoUrl">Default Logo URL:</label>
                    <input type="url" id="defaultLogoUrl" name="defaultLogoUrl" value="<?php echo htmlspecialchars($settings['defaultLogoUrl'] ?? ''); ?>" placeholder="e.g., https://yourbrand.com/logo.png">
                    <p class="description">This logo will be used as the default in new email templates.</p>
                </div>

                <div class="settings-form-group">
                    <label for="primaryBrandColor">Primary Brand Color:</label>
                    <input type="color" id="primaryBrandColor" name="primaryBrandColor" value="<?php echo htmlspecialchars($settings['primaryBrandColor'] ?? '#00353d'); ?>">
                    <input type="text" id="primaryBrandColorText" value="<?php echo htmlspecialchars($settings['primaryBrandColor'] ?? '#00353d'); ?>" oninput="document.getElementById('primaryBrandColor').value = this.value; validateColor(this);">
                    <p class="description">Used for primary elements like main buttons and key text accents. Hex format (e.g., #00353d).</p>
                </div>

                <div class="settings-form-group">
                    <label for="secondaryBrandColor">Secondary Brand Color:</label>
                    <input type="color" id="secondaryBrandColor" name="secondaryBrandColor" value="<?php echo htmlspecialchars($settings['secondaryBrandColor'] ?? '#00a399'); ?>">
                    <input type="text" id="secondaryBrandColorText" value="<?php echo htmlspecialchars($settings['secondaryBrandColor'] ?? '#00a399'); ?>" oninput="document.getElementById('secondaryBrandColor').value = this.value; validateColor(this);">
                    <p class="description">Used for secondary elements or highlights. Hex format (e.g., #00a399).</p>
                </div>

                <div class="settings-form-group">
                    <label for="customFontFile">Upload Custom Font File (.woff, .woff2, .ttf, .otf):</label>
                    <input type="file" id="customFontFile" name="customFontFile" accept=".woff,.woff2,.ttf,.otf">
                    <?php if (!empty($settings['customFontUrl'])): ?>
                        <p class="description">Currently uploaded font: <a href="<?php echo htmlspecialchars($settings['customFontUrl']); ?>" target="_blank"><?php echo htmlspecialchars(basename($settings['customFontUrl'])); ?></a></p>
                    <?php endif; ?>
                    <p class="description">Upload a custom font to use in your emails. This will override the default font stack in emails.</p>
                </div>

                <div class="settings-form-group">
                    <label for="customFontFamilyName">Custom Font Family Name (e.g., 'My Custom Font'):</label>
                    <input type="text" id="customFontFamilyName" name="customFontFamilyName" value="<?php echo htmlspecialchars($settings['customFontFamilyName'] ?? ''); ?>" placeholder="e.g., 'EPOS Custom', sans-serif">
                    <p class="description">This is the CSS 'font-family' name for your uploaded font. If left empty, the uploaded font will not be used in generated emails.</p>
                </div>

                <div class="settings-form-group">
                    <label for="defaultFontFamily">Fallback Font Family (for display):</label>
                    <input type="text" id="defaultFontFamily" name="defaultFontFamily" value="<?php echo htmlspecialchars($settings['defaultFontFamily'] ?? 'EPOS Basis, Arial, sans-serif'); ?>" placeholder="e.g., Arial, sans-serif">
                    <p class="description">The primary font stack for email content (e.g., 'EPOS Basis', Arial, sans-serif). This is used if no custom font is uploaded or as a fallback.</p>
                </div>

                <div class="settings-form-group">
                    <label for="allowedTextColors">Quill Editor - Allowed Text Colors (Comma-separated hex codes):</label>
                    <textarea id="allowedTextColors" name="allowedTextColors" rows="3" placeholder="e.g., #131313, #ffffff, #00353d, #00a399, #ff5549"><?php echo htmlspecialchars($settings['allowedTextColors'] ?? '#131313, #ffffff, #00353d, #00a399, #ff5549'); ?></textarea>
                    <p class="description">Define the hex codes for text colors users can select in the Quill editor. Ensure to include #ffffff (white) and #131313 (black) for usability.</p>
                </div>

                <div class="settings-form-group">
                    <label for="allowedBackgroundColors">Quill Editor - Allowed Background Colors (Comma-separated hex codes):</label>
                    <textarea id="allowedBackgroundColors" name="allowedBackgroundColors" rows="3" placeholder="e.g., #131313, #ffffff, #00353d, #00a399, #ff5549"><?php echo htmlspecialchars($settings['allowedBackgroundColors'] ?? '#131313, #ffffff, #00353d, #00a399, #ff5549'); ?></textarea>
                    <p class="description">Define the hex codes for background colors users can select in the Quill editor.</p>
                </div>

                <button type="submit" class="glow-button">Save Global Settings</button>
            </form>
        </div>
    </div>

    <script>
        // Synchronize color input with text input
        document.getElementById('primaryBrandColor').addEventListener('input', function() {
            document.getElementById('primaryBrandColorText').value = this.value;
        });
        document.getElementById('primaryBrandColorText').addEventListener('input', function() {
            document.getElementById('primaryBrandColor').value = this.value;
        });
        document.getElementById('secondaryBrandColor').addEventListener('input', function() {
            document.getElementById('secondaryBrandColorText').value = this.value;
        });
        document.getElementById('secondaryBrandColorText').addEventListener('input', function() {
            document.getElementById('secondaryBrandColor').value = this.value;
        });

        // Basic color validation (optional, can be more robust)
        function validateColor(inputElement) {
            const hexRegex = /^#([0-9A-F]{3}){1,2}$/i;
            if (!hexRegex.test(inputElement.value)) {
                inputElement.style.border = '2px solid red';
            } else {
                inputElement.style.border = '1px solid #ddd'; // Revert to normal
            }
        }
    </script>
</body>
</html>
