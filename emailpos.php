<?php
// The new initialization file handles all session, security, and configuration tasks.
require_once __DIR__ . '/includes/init.php';

// Ensure JSON variables are properly escaped for JavaScript
// These variables hold the JSON string representation of the PHP arrays defined in init.php
$allowedTextColorsJson = json_encode(ALLOWED_TEXT_COLORS, JSON_UNESCAPED_SLASHES);
$allowedBackgroundColorsJson = json_encode(ALLOWED_BACKGROUND_COLORS, JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>EmailPOS Editor</title>
    
    <!-- Quill.js Styles -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet"/>
    
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- Main Application Stylesheet -->
    <link rel="stylesheet" href="<?php echo BASE_APP_URL; ?>assets/css/emailpos.css"/>
    
    <!-- Custom Google Font (if defined in settings) -->
    <?php if (!empty($customFontUrl)): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($customFontUrl); ?>">
    <?php endif; ?>

</head>
<body>
    <!-- The main navigation bar -->
<nav class="main-nav">
    <img src="<?php echo BASE_APP_URL; ?>assets/images/emailpos_white.svg" alt="EmailPOS Logo" class="nav-logo">
    <span class="nav-title">EmailPOS Editor</span>
    <div class="nav-links">
        <a href="<?php echo BASE_APP_URL; ?>api/calendar.php">Calendar</a>
        <?php if ($current_user_level === 'admin'): ?>
            <a href="<?php echo BASE_APP_URL; ?>api/admin_panel.php">Manage Users</a>
            <a href="<?php echo BASE_APP_URL; ?>api/global_settings.php">Global Settings</a>
            <a href="<?php echo BASE_APP_URL; ?>api/create_user.php">Create New User</a>
        <?php endif; ?>
        <a href="<?php echo BASE_APP_URL; ?>api/logout.php">Logout</a>
    </div>
</nav>
    <!-- Main Application Content Area -->
    <div id="main-app-content">

        <!-- Step 1: Initial Setup and Email Loading -->
        <div id="step1-section" class="form-container-wrapper">
            <div class="form-container">
                <div class="step1-header">
                    <img alt="EPOS" src="<?php echo BASE_APP_URL; ?>assets/images/emailpos.svg" class="epos-logo-main">
                    <h1>Hi <?php echo $loggedInUserName; ?>, welcome to the EmailPOS Builder</h1>
                </div>
                
                <button type="button" id="openSearchModalBtn" class="action-button">🔍 Search Existing Mails</button>
                
                <div id="currentReferenceCodeDisplay" style="display: none;">
                    <label>Current Email Reference:</label>
                    <span id="displayReferenceCode"></span>
                    <label id="createdByLabel" style="display: none;">Created By:</label>
                    <span id="createdByDisplay"></span>
                </div>

                <form id="layoutForm">
                    <div class="form-group">
                        <label for="referenceCode">Reference Code (Optional)</label>
                        <input id="referenceCode" name="referenceCode" placeholder="Enter code to load existing email" type="text" autocomplete="off"/>
                    </div>

                    <div class="form-group">
                        <label>Personalization Type</label>
                        <div class="radio-group-personalization">
                            <label class="radio-label"><input type="radio" name="personalizationType" value="none" checked> <i class="fas fa-file-alt"></i> HTML Only</label>
                            <label class="radio-label"><input type="radio" name="personalizationType" value="salesforce_list"> <i class="fas fa-hand-point-right"></i> SF List Email</label>
                            <label class="radio-label"><input type="radio" name="personalizationType" value="salesforce_marketing_cloud"> <i class="fas fa-cloud"></i> SF Marketing Cloud</label>
                        </div>
                    </div>
                    <div class="button-group">
                        <button type="submit">Create / Load Email</button>
                        <button type="button" id="duplicateEmailBtn">Duplicate Email</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Step 2: Main Editor and Preview Container -->
        <div class="main-container" id="mainContainer" style="display: none;">
            <!-- Left Panel: Content Forms -->
            <div class="form-container-wrapper">
                <div class="form-container">
                    <h2>Step 2: Email Content</h2>

                    <div id="block-actions" class="form-group button-group-grid">
                        <button id="addSingleColumnButton" type="button" class="action-button"><i class="fas fa-plus-circle"></i> Add Single Column</button>
                        <button id="addDoubleColumnButton" type="button" class="action-button"><i class="fas fa-columns"></i> Add Two Column</button>
                        <button id="addSignOffButton" type="button" class="action-button"><i class="fas fa-signature"></i> Add Sign-off</button>
                        <button type="button" id="loadSavedBlockBtn" class="action-button"><i class="fas fa-folder-open"></i> Load Block</button>
                        <button type="button" id="globalTranslateButton" class="action-button"><i class="fas fa-language"></i> Translate</button>
                        <button type="button" class="format-button" id="moveUpBtn" disabled><i class="fas fa-arrow-up"></i> Move Up</button>
                        <button type="button" class="format-button" id="moveDownBtn" disabled><i class="fas fa-arrow-down"></i> Move Down</button>
                        <button type="button" class="remove-button" id="removeBtn" disabled><i class="fas fa-trash-alt"></i> Remove</button>
                    </div>

                    <form id="contentForm">
                        <!-- Dynamic Article Blocks will be inserted here by JavaScript -->
                        <div id="articleBlocks"></div>

                        <h3>General Settings</h3>
                        <div class="form-group">
                            <label for="subjectLine">Subject Line</label>
                            <input id="subjectLine" name="subjectLine" type="text" autocomplete="off"/>
                        </div>
                         <div class="form-group">
                            <label for="preheaderText">Preheader Text</label>
                            <input id="preheaderText" name="preheaderText" type="text" autocomplete="off"/>
                        </div>
                        <div class="form-group">
                            <label for="backgroundImage">Background Image URL</label>
                            <div class="input-with-button">
                                <input id="backgroundImage" name="backgroundImage" type="text" autocomplete="off">
                                <button type="button" class="select-image-button" data-target-input-id="backgroundImage">Select</button>
                            </div>
                        </div>

                        <h3>Email & Sender Details</h3>
                        <div class="form-group">
                            <label for="region">Region</label>
                            <select id="region" name="region" autocomplete="off">
                                <option value="AMER">AMER</option>
                                <option value="ANZ">ANZ</option>
                                <option value="APAC">APAC</option>
                                <option value="BNL">BNL</option>
                                <option value="CANADA">CANADA</option>
                                <option value="DACH">DACH</option>
                                <option value="FRANCE">FRANCE</option>
                                <option value="INDIA">INDIA</option>
                                <option value="NORD">NORD</option>
                                <option value="UKI">UKI</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="emailFrom">"From" Email Address</label>
                            <input id="emailFrom" name="emailFrom" type="text" autocomplete="off"/>
                        </div>
                        <div class="form-group">
                            <label for="senderName">Sender Name</label>
                            <input id="senderName" name="senderName" type="text" autocomplete="off"/>
                        </div>
                         <div class="form-group">
                            <label for="senderEmail">Sender Email</label>
                            <input id="senderEmail" name="senderEmail" type="text" autocomplete="off"/>
                        </div>
                        <div class="form-group">
                            <label for="audience">Audience</label>
                            <select id="audience" name="audience" autocomplete="off">
                                <option value="channel">Channel</option>
                                <option value="end_user">End User</option>
                                <option value="channel_end_user">Channel + End User</option>
                                <option value="internal">Internal</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="sendTime">Send Time and Date</label>
                            <input id="sendTime" name="sendTime" type="datetime-local" autocomplete="off"/>
                        </div>
                        <div class="form-group">
                            <label for="logoUrl">Logo URL</label>
                            <input id="logoUrl" name="logoUrl" type="text" value="<?php echo (string)DEFAULT_LOGO_URL; ?>" autocomplete="off"/>
                        </div>
                        <div class="form-group">
                            <label for="senderMobile">Sender Mobile Phone</label>
                            <input id="senderMobile" name="senderMobile" type="text" autocomplete="off"/>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Panel: Live Preview -->
            <div class="preview-container">
                <div class="preview-header">
                    <h3>Live Preview</h3>
                    <div class="preview-controls">
                        <button id="preview-desktop"><i class="fas fa-desktop"></i></button>
                        <button id="preview-mobile"><i class="fas fa-mobile-alt"></i></button>
                    </div>
                    <div class="personalization-dropdown-container">
                        <button id="personalizationButton" class="action-button" style="display: none;"><i class="fas fa-user-edit"></i> Personalize</button>
                        <div id="personalizationDropdown" class="personalization-dropdown-content">
                            <div id="personalizationTagList"></div><!-- IMPORTANT: Added missing div with ID for JS to target -->
                        </div>
                    </div>
                </div>
                <iframe id="live-preview-iframe"></iframe>
            </div>
        </div>

        <!-- Step 3: Generated Code and Actions -->
        <div class="code-container-wrapper" id="codeContainerWrapper" style="display: none;">
            <div class="code-container">
                <h2>Step 3: Get Your Code</h2>
                <textarea id="htmlOutput" readonly></textarea>
                <div class="button-group">
                    <button id="generateAndSaveEmailBtn" class="glow-button"><i class="fas fa-save"></i> Generate & Save</button>
                    <button id="copyHtmlBtn"><i class="fas fa-copy"></i> Copy HTML</button>
                    <button id="downloadHtmlBtn"><i class="fas fa-download"></i> Download</button>
                    <button id="saveAsEmailBtn"><i class="fas fa-file-export"></i> Save As...</button>
                </div>
                <div class="form-group">
                    <label for="testEmailRecipient">Send Test Email To:</label>
                    <div class="input-with-button">
                        <input id="testEmailRecipient" type="email" autocomplete="off"/>
                        <button id="sendTestEmailButton">Send Test</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- All Modals -->
    <?php require_once __DIR__ . '/includes/modals.html'; ?>

    <!-- Pass PHP constants/variables to JavaScript. This is CRITICAL and must be BEFORE main.js -->
    <script>
        // Define AppConfig globally
        // This block MUST be before main.js to ensure AppConfig is defined.
        window.AppConfig = {
            base_url: '<?php echo (string)BASE_APP_URL; ?>',
            logged_in_user_id: '<?php echo (string)$loggedInUserId; ?>',
            logged_in_user_name: '<?php echo (string)$loggedInUserName; ?>',
            logged_in_user_level: '<?php echo (string)$current_user_level; ?>',
            // Constants below are already HTML-escaped in init.php
            default_logo_url: '<?php echo (string)DEFAULT_LOGO_URL; ?>', 
            primary_brand_color: '<?php echo (string)PRIMARY_BRAND_COLOR; ?>',
            default_font_family: '<?php echo (string)DEFAULT_FONT_FAMILY; ?>',
            custom_font_url: '<?php echo (string)$customFontUrl; ?>',
            custom_font_family_name: '<?php echo (string)$customFontFamilyName; ?>',
            // These are already JSON-encoded strings
            allowed_text_colors: <?php echo $allowedTextColorsJson; ?>,
            allowed_bg_colors: <?php echo $allowedBackgroundColorsJson; ?>
        };
        // Log AppConfig immediately to debug its contents in the browser console
        console.log('AppConfig defined in emailpos.php:', window.AppConfig);
    </script>

    <!-- Quill.js Libraries (Load BEFORE main.js) -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/quill-emoji@0.2.0/dist/quill-emoji.js"></script>

    <!-- Main Application JavaScript (Load LAST) -->
    <script src="<?php echo BASE_APP_URL; ?>assets/js/main.js"></script>

</body>
</html>